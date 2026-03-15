<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE RESOLUTION (mirrors sidebar pattern) ─────────────────────────────────
function rs_resolve_role(): string {
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
$roleName = rs_resolve_role();
$roleRank = match($roleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,   // Staff / User
};
$currentUserId   = $_SESSION['user_id']   ?? null;
$currentFullName = $_SESSION['full_name'] ?? ($currentUserId ?? 'User');
$currentZone     = $_SESSION['zone']      ?? '';

// ── HELPERS ──────────────────────────────────────────────────────────────────
function rs_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function rs_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function rs_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function rs_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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

function rs_next_id(): string {
    $year = date('Y');
    $rows = rs_sb('alms_repair_logs', 'GET', [
        'select'  => 'log_id',
        'log_id'  => 'like.RSL-' . $year . '-%',
        'order'   => 'id.desc',
        'limit'   => '1',
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/RSL-\d{4}-(\d+)/', $rows[0]['log_id'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return 'RSL-' . $year . '-' . sprintf('%04d', $next);
}

function rs_build(array $row): array {
    return [
        'id'             => (int)$row['id'],
        'logId'          => $row['log_id']          ?? '',
        'assetId'        => $row['asset_id']         ?? '',
        'assetName'      => $row['asset_name']       ?? '',
        'assetDbId'      => (int)($row['asset_db_id'] ?? 0),
        'zone'           => $row['zone']             ?? '',
        'issue'          => $row['issue']            ?? '',
        'dateReported'   => $row['date_reported']    ?? '',
        'dateCompleted'  => $row['date_completed']   ?? '',
        'technician'     => $row['technician']       ?? '',
        'techUserId'     => $row['tech_user_id']     ?? '',
        'provider'       => $row['provider']         ?? '',
        'supplierId'     => (int)($row['supplier_id']  ?? 0),
        'supplierRating' => $row['supplier_rating'] !== null ? (float)$row['supplier_rating'] : null,
        'repairCost'     => (float)($row['repair_cost']   ?? 0),
        'costOverridden' => (bool)($row['cost_overridden'] ?? false),
        'originalCost'   => $row['original_cost'] !== null ? (float)$row['original_cost'] : null,
        'status'         => $row['status']           ?? 'Reported',
        'remarks'        => $row['remarks']          ?? '',
        'saRemarks'      => $row['sa_remarks']       ?? '',
        'createdBy'      => $row['created_by']       ?? '',
        'createdUserId'  => $row['created_user_id']  ?? '',
        'createdAt'      => $row['created_at']       ?? '',
        'updatedAt'      => $row['updated_at']       ?? '',
    ];
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    global $roleRank, $currentUserId, $currentZone, $currentFullName;
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $currentFullName;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET assets ───────────────────────────────────────────────────────
        if ($api === 'assets' && $method === 'GET') {
            $query = [
                'select' => 'id,asset_id,name,zone',
                'status' => 'neq.Disposed',
                'order'  => 'name.asc',
            ];
            // Admin: only same-zone assets
            if ($roleRank === 3 && $currentZone) $query['zone'] = 'eq.' . $currentZone;
            $rows = rs_sb('alms_assets', 'GET', $query);
            rs_ok(array_map(fn($r) => [
                'id'      => (int)$r['id'],
                'assetId' => $r['asset_id'] ?? '',
                'name'    => $r['name']     ?? '',
                'zone'    => $r['zone']     ?? '',
            ], $rows));
        }

        // ── GET staff ────────────────────────────────────────────────────────
        if ($api === 'staff' && $method === 'GET') {
            $query = [
                'select' => 'user_id,first_name,last_name,zone',
                'status' => 'eq.Active',
                'order'  => 'first_name.asc',
            ];
            if ($roleRank === 3 && $currentZone) $query['zone'] = 'eq.' . $currentZone;
            $rows = rs_sb('users', 'GET', $query);
            $staff = array_map(fn($r) => [
                'id'   => $r['user_id'],
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'zone' => $r['zone'] ?? '',
            ], $rows);
            rs_ok(array_values(array_filter($staff, fn($s) => $s['name'] !== '')));
        }

        // ── GET suppliers ────────────────────────────────────────────────────
        if ($api === 'suppliers' && $method === 'GET') {
            if ($roleRank < 3) rs_err('Forbidden', 403);
            $rows = rs_sb('psm_suppliers', 'GET', [
                'select' => 'id,name,category,rating,accreditation,is_flagged',
                'status' => 'eq.Active',
                'order'  => 'name.asc',
            ]);
            rs_ok(array_map(fn($r) => [
                'id'            => (int)$r['id'],
                'name'          => $r['name']          ?? '',
                'category'      => $r['category']      ?? '',
                'rating'        => (float)($r['rating']      ?? 0),
                'accreditation' => $r['accreditation'] ?? 'Pending',
                'isFlagged'     => (bool)($r['is_flagged']   ?? false),
            ], $rows));
        }

        // ── GET list ─────────────────────────────────────────────────────────
        if ($api === 'list' && $method === 'GET') {
            $query = [
                'select' => 'id,log_id,asset_id,asset_name,asset_db_id,zone,issue,date_reported,date_completed,technician,tech_user_id,provider,supplier_id,supplier_rating,repair_cost,cost_overridden,original_cost,status,remarks,sa_remarks,created_by,created_user_id,created_at,updated_at',
                'order'  => 'date_reported.desc',
            ];
            // Role-based scope:
            // Super Admin (4) → all zones
            // Admin (3)       → own zone only
            // Manager (2)     → own zone only
            // Staff (1)       → only logs where tech_user_id = me
            if ($roleRank === 3 && $currentZone)       $query['zone']         = 'eq.' . $currentZone;
            if ($roleRank === 2 && $currentZone)       $query['zone']         = 'eq.' . $currentZone;
            if ($roleRank === 1 && $currentUserId)     $query['tech_user_id'] = 'eq.' . $currentUserId;

            if (!empty($_GET['status']))    $query['status']        = 'eq.' . $_GET['status'];
            if (!empty($_GET['zone']) && $roleRank === 4) $query['zone'] = 'eq.' . $_GET['zone'];
            if (!empty($_GET['provider']) && $roleRank >= 3)  $query['provider'] = 'eq.' . $_GET['provider'];
            if (!empty($_GET['date_from'])) $query['date_reported'] = 'gte.' . $_GET['date_from'];
            if (!empty($_GET['date_to']))   $query['date_reported'] = 'lte.' . $_GET['date_to'];
            $rows = rs_sb('alms_repair_logs', 'GET', $query);
            rs_ok(array_map('rs_build', $rows));
        }

        // ── GET single ───────────────────────────────────────────────────────
        if ($api === 'get' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) rs_err('Missing id', 400);
            $rows = rs_sb('alms_repair_logs', 'GET', [
                'select' => 'id,log_id,asset_id,asset_name,asset_db_id,zone,issue,date_reported,date_completed,technician,tech_user_id,provider,supplier_id,supplier_rating,repair_cost,cost_overridden,original_cost,status,remarks,sa_remarks,created_by,created_user_id,created_at,updated_at',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) rs_err('Log not found', 404);
            $log = rs_build($rows[0]);
            // Staff can only view their own
            if ($roleRank === 1 && $log['techUserId'] !== $currentUserId) rs_err('Forbidden', 403);
            // Admin/Manager cannot view cross-zone
            if (in_array($roleRank, [2, 3]) && $currentZone && $log['zone'] !== $currentZone) rs_err('Forbidden', 403);
            rs_ok($log);
        }

        // ── GET audit trail ──────────────────────────────────────────────────
        if ($api === 'audit' && $method === 'GET') {
            if ($roleRank < 3) rs_err('Forbidden', 403); // Manager+ only
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) rs_err('Missing id', 400);
            $rows = rs_sb('alms_repair_audit_log', 'GET', [
                'select'  => 'id,action_label,actor_name,actor_role,note,icon,css_class,is_super_admin,ip_address,occurred_at',
                'log_id'  => 'eq.' . $id,
                'order'   => 'occurred_at.asc',
            ]);
            rs_ok($rows);
        }

        // ── GET cost compare ─────────────────────────────────────────────────
        if ($api === 'costcompare' && $method === 'GET') {
            if ($roleRank < 4) rs_err('Forbidden', 403); // Super Admin only
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) rs_err('Missing id', 400);
            $rows = rs_sb('alms_repair_cost_compare', 'GET', [
                'select'  => 'id,provider,estimated_cost,is_actual',
                'log_id'  => 'eq.' . $id,
                'order'   => 'estimated_cost.asc',
            ]);
            rs_ok($rows);
        }

        // ── POST save (create / edit) ─────────────────────────────────────────
        if ($api === 'save' && $method === 'POST') {
            // Only Admin+ can create/edit
            if ($roleRank < 3) rs_err('Insufficient permissions to create or edit logs.', 403);

            $b             = rs_body();
            $assetId       = trim($b['assetId']       ?? '');
            $assetName     = trim($b['assetName']     ?? '');
            $assetDbId     = (int)($b['assetDbId']    ?? 0);
            $zone          = trim($b['zone']          ?? '');
            $issue         = trim($b['issue']         ?? '');
            $dateReported  = trim($b['dateReported']  ?? '') ?: null;
            $dateCompleted = trim($b['dateCompleted'] ?? '') ?: null;
            $technician    = trim($b['technician']    ?? '');
            $techUserId    = trim($b['techUserId']    ?? '') ?: null;
            $provider      = trim($b['provider']      ?? '');
            $supplierId    = (int)($b['supplierId']   ?? 0);
            $supplierRating= $b['supplierRating'] !== null ? (float)($b['supplierRating'] ?? 0) : null;
            $repairCost    = (float)($b['repairCost'] ?? 0);
            $status        = trim($b['status']        ?? 'Reported');
            $remarks       = trim($b['remarks']       ?? '');
            $editId        = (int)($b['id']           ?? 0);

            // Admin: enforce zone
            if ($roleRank === 3 && $currentZone && $zone !== $currentZone)
                rs_err('You can only create logs within your assigned zone.', 403);

            if (!$assetName)    rs_err('Asset name is required.', 400);
            if (!$zone)         rs_err('Zone is required.', 400);
            if (!$issue)        rs_err('Issue description is required.', 400);
            if (!$dateReported) rs_err('Date reported is required.', 400);
            if (!$technician)   rs_err('Technician is required.', 400);

            $allowedStatus = ['Reported', 'In Progress', 'Completed', 'Cancelled', 'Escalated'];
            if (!in_array($status, $allowedStatus, true)) $status = 'Reported';

            $now = date('Y-m-d H:i:s');
            $payload = [
                'asset_id'       => $assetId,
                'asset_name'     => $assetName,
                'asset_db_id'    => $assetDbId ?: null,
                'zone'           => $zone,
                'issue'          => $issue,
                'date_reported'  => $dateReported,
                'date_completed' => $dateCompleted,
                'technician'     => $technician,
                'tech_user_id'   => $techUserId,
                'provider'       => $provider,
                'supplier_id'    => $supplierId ?: null,
                'supplier_rating'=> $supplierRating,
                'repair_cost'    => $repairCost,
                'status'         => $status,
                'remarks'        => $remarks,
                'updated_at'     => $now,
            ];

            if ($editId) {
                $existing = rs_sb('alms_repair_logs', 'GET', [
                    'select' => 'id,log_id,status,zone',
                    'id'     => 'eq.' . $editId,
                    'limit'  => '1',
                ]);
                if (empty($existing)) rs_err('Log not found', 404);
                // Admin: cannot edit cross-zone
                if ($roleRank === 3 && $currentZone && ($existing[0]['zone'] ?? '') !== $currentZone)
                    rs_err('Cannot edit logs outside your zone.', 403);
                rs_sb('alms_repair_logs', 'PATCH', ['id' => 'eq.' . $editId], $payload);
                rs_sb('alms_repair_audit_log', 'POST', [], [[
                    'log_id'        => $editId,
                    'action_label'  => 'Log Edited',
                    'actor_name'    => $actor,
                    'actor_role'    => $roleName,
                    'note'          => 'Fields updated by ' . $actor . ' (' . $roleName . ').',
                    'icon'          => 'bx-edit',
                    'css_class'     => 'ed-s',
                    'is_super_admin'=> $roleRank === 4,
                    'ip_address'    => $ip,
                    'occurred_at'   => $now,
                ]]);
                $rows = rs_sb('alms_repair_logs', 'GET', [
                    'select' => 'id,log_id,asset_id,asset_name,asset_db_id,zone,issue,date_reported,date_completed,technician,tech_user_id,provider,supplier_id,supplier_rating,repair_cost,cost_overridden,original_cost,status,remarks,sa_remarks,created_by,created_user_id,created_at,updated_at',
                    'id'     => 'eq.' . $editId, 'limit' => '1',
                ]);
                rs_ok(rs_build($rows[0]));
            }

            $logId = rs_next_id();
            $payload['log_id']          = $logId;
            $payload['cost_overridden'] = false;
            $payload['original_cost']   = null;
            $payload['sa_remarks']      = '';
            $payload['created_by']      = $actor;
            $payload['created_user_id'] = $currentUserId;
            $payload['created_at']      = $now;

            $inserted = rs_sb('alms_repair_logs', 'POST', [], [$payload]);
            if (empty($inserted)) rs_err('Failed to create repair log', 500);
            $newId = (int)$inserted[0]['id'];

            rs_sb('alms_repair_audit_log', 'POST', [], [[
                'log_id'        => $newId,
                'action_label'  => 'Repair Logged',
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'note'          => 'Issue reported and logged for ' . $assetName . '.',
                'icon'          => 'bx-wrench',
                'css_class'     => 'ed-s',
                'is_super_admin'=> $roleRank === 4,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            $rows = rs_sb('alms_repair_logs', 'GET', [
                'select' => 'id,log_id,asset_id,asset_name,asset_db_id,zone,issue,date_reported,date_completed,technician,tech_user_id,provider,supplier_id,supplier_rating,repair_cost,cost_overridden,original_cost,status,remarks,sa_remarks,created_by,created_user_id,created_at,updated_at',
                'id'     => 'eq.' . $newId, 'limit' => '1',
            ]);
            rs_ok(rs_build($rows[0]));
        }

        // ── POST action ───────────────────────────────────────────────────────
        if ($api === 'action' && $method === 'POST') {
            $b    = rs_body();
            $id   = (int)($b['id']   ?? 0);
            $type = trim($b['type']  ?? '');
            $now  = date('Y-m-d H:i:s');

            if (!$id)   rs_err('Missing id', 400);
            if (!$type) rs_err('Missing type', 400);

            $rows = rs_sb('alms_repair_logs', 'GET', [
                'select' => 'id,log_id,asset_name,status,repair_cost,technician,tech_user_id,zone',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) rs_err('Log not found', 404);
            $log = $rows[0];

            // Zone enforcement for Admin/Manager
            if (in_array($roleRank, [2, 3]) && $currentZone && $log['zone'] !== $currentZone)
                rs_err('Cannot act on logs outside your zone.', 403);
            // Staff: only their own logs, only allowed actions
            if ($roleRank === 1) {
                if ($log['tech_user_id'] !== $currentUserId) rs_err('Forbidden', 403);
                if (!in_array($type, ['start', 'progress', 'flag'], true)) rs_err('Insufficient permissions.', 403);
            }
            // Manager: can flag/escalate only
            if ($roleRank === 2 && !in_array($type, ['flag', 'escalate'], true))
                rs_err('Managers can only flag or escalate repairs.', 403);

            $patch      = ['updated_at' => $now];
            $auditLabel = '';
            $auditNote  = trim($b['remarks'] ?? '');
            $auditIcon  = 'bx-info-circle';
            $auditClass = 'ed-s';
            $isSA       = $roleRank === 4;

            switch ($type) {

                case 'start':
                    if ($log['status'] !== 'Reported')
                        rs_err('Only Reported logs can be started.', 400);
                    $patch['status'] = 'In Progress';
                    $auditLabel = 'Work Started';
                    $auditIcon  = 'bx-cog';
                    $auditClass = 'ed-o';
                    $auditNote  = $auditNote ?: $log['technician'] . ' dispatched. Repair commenced.';
                    break;

                case 'progress':
                    // Staff: update progress note
                    if ($log['status'] !== 'In Progress')
                        rs_err('Log must be In Progress.', 400);
                    $auditLabel = 'Progress Updated';
                    $auditIcon  = 'bx-revision';
                    $auditClass = 'ed-o';
                    $auditNote  = $auditNote ?: 'Work progress updated by technician.';
                    break;

                case 'flag':
                    $patch['status'] = 'Escalated';
                    $auditLabel = 'Delay Flagged';
                    $auditIcon  = 'bx-flag';
                    $auditClass = 'ed-e';
                    $auditNote  = $auditNote ?: 'Delay flagged by ' . $roleName . '. Requires attention.';
                    break;

                case 'complete':
                    if ($roleRank < 3) rs_err('Insufficient permissions.', 403);
                    if (in_array($log['status'], ['Completed', 'Cancelled'], true))
                        rs_err('Log is already ' . strtolower($log['status']) . '.', 400);
                    $patch['status']         = 'Completed';
                    $patch['date_completed'] = date('Y-m-d');
                    $auditLabel = $roleRank === 4 ? 'Force Completed by Super Admin' : 'Repair Closed';
                    $auditIcon  = 'bx-check-circle';
                    $auditClass = 'ed-c';
                    $auditNote  = $auditNote ?: 'All repairs verified and signed off.';
                    break;

                case 'escalate':
                    if ($roleRank < 2) rs_err('Insufficient permissions.', 403);
                    if (!in_array($log['status'], ['Reported', 'In Progress'], true))
                        rs_err('Only Reported or In Progress logs can be escalated.', 400);
                    $patch['status'] = 'Escalated';
                    $auditLabel = 'Escalated to ' . ($roleRank >= 3 ? 'Super Admin' : 'Admin');
                    $auditIcon  = 'bx-error';
                    $auditClass = 'ed-e';
                    $auditNote  = $auditNote ?: 'Complexity beyond scope. Raised for review.';
                    break;

                case 'costoverride':
                    if ($roleRank < 4) rs_err('Super Admin authority required.', 403);
                    $newCost = (float)($b['newCost'] ?? -1);
                    if ($newCost < 0) rs_err('Valid new cost is required.', 400);
                    $oldCost = (float)$log['repair_cost'];
                    $patch['repair_cost']     = $newCost;
                    $patch['cost_overridden'] = true;
                    $patch['original_cost']   = $oldCost;
                    $patch['sa_remarks']      = trim($b['remarks'] ?? '');
                    $auditLabel = 'Repair Cost Overridden by Super Admin';
                    $auditIcon  = 'bx-dollar';
                    $auditClass = 'ed-o';
                    $auditNote  = 'Original: ₱' . number_format($oldCost, 2) . ' → New: ₱' . number_format($newCost, 2) . ($auditNote ? '. ' . $auditNote : '.');
                    break;

                case 'cancel':
                    if ($roleRank < 3) rs_err('Insufficient permissions.', 403);
                    if (in_array($log['status'], ['Completed', 'Cancelled'], true))
                        rs_err('Log is already ' . strtolower($log['status']) . '.', 400);
                    $patch['status']  = 'Cancelled';
                    $patch['remarks'] = trim($b['remarks'] ?? '') ?: 'Cancelled.';
                    $auditLabel = 'Repair Log Cancelled';
                    $auditIcon  = 'bx-x-circle';
                    $auditClass = 'ed-x';
                    $auditNote  = $auditNote ?: 'Cancelled per management directive.';
                    break;

                default:
                    rs_err('Unsupported action', 400);
            }

            rs_sb('alms_repair_logs', 'PATCH', ['id' => 'eq.' . $id], $patch);
            rs_sb('alms_repair_audit_log', 'POST', [], [[
                'log_id'        => $id,
                'action_label'  => $auditLabel,
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'note'          => $auditNote,
                'icon'          => $auditIcon,
                'css_class'     => $auditClass,
                'is_super_admin'=> $isSA,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            $rows = rs_sb('alms_repair_logs', 'GET', [
                'select' => 'id,log_id,asset_id,asset_name,asset_db_id,zone,issue,date_reported,date_completed,technician,tech_user_id,provider,supplier_id,supplier_rating,repair_cost,cost_overridden,original_cost,status,remarks,sa_remarks,created_by,created_user_id,created_at,updated_at',
                'id'     => 'eq.' . $id, 'limit' => '1',
            ]);
            rs_ok(rs_build($rows[0]));
        }

        // ── POST batch (Super Admin only) ─────────────────────────────────────
        if ($api === 'batch' && $method === 'POST') {
            if ($roleRank < 4) rs_err('Super Admin authority required for batch actions.', 403);
            $b    = rs_body();
            $ids  = array_map('intval', $b['ids']  ?? []);
            $type = trim($b['type'] ?? '');
            $now  = date('Y-m-d H:i:s');

            if (empty($ids)) rs_err('No log IDs provided.', 400);
            if (!$type)      rs_err('Missing batch type.', 400);

            $updated   = 0;
            $auditNote = trim($b['remarks'] ?? '');

            foreach ($ids as $id) {
                $rows = rs_sb('alms_repair_logs', 'GET', [
                    'select' => 'id,log_id,status,technician',
                    'id'     => 'eq.' . $id, 'limit' => '1',
                ]);
                if (empty($rows)) continue;
                $log = $rows[0];

                $patch      = ['updated_at' => $now];
                $auditLabel = '';
                $auditIcon  = 'bx-check-double';
                $auditClass = 'ed-c';

                if ($type === 'batch-complete') {
                    if (!in_array($log['status'], ['Reported', 'In Progress', 'Escalated'], true)) continue;
                    $patch['status']         = 'Completed';
                    $patch['date_completed'] = date('Y-m-d');
                    $auditLabel = 'Batch Force Completed by Super Admin';
                } elseif ($type === 'batch-escalate') {
                    if (!in_array($log['status'], ['Reported', 'In Progress'], true)) continue;
                    $patch['status'] = 'Escalated';
                    $auditLabel = 'Batch Escalated by Super Admin';
                    $auditIcon  = 'bx-error';
                    $auditClass = 'ed-e';
                } else {
                    continue;
                }

                rs_sb('alms_repair_logs', 'PATCH', ['id' => 'eq.' . $id], $patch);
                rs_sb('alms_repair_audit_log', 'POST', [], [[
                    'log_id'        => $id,
                    'action_label'  => $auditLabel,
                    'actor_name'    => $actor,
                    'actor_role'    => 'Super Admin',
                    'note'          => $auditNote,
                    'icon'          => $auditIcon,
                    'css_class'     => $auditClass,
                    'is_super_admin'=> true,
                    'ip_address'    => $ip,
                    'occurred_at'   => $now,
                ]]);
                $updated++;
            }
            rs_ok(['updated' => $updated]);
        }

        rs_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        rs_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── HTML PAGE RENDER ──────────────────────────────────────────────────────────
$root_html = $_SERVER['DOCUMENT_ROOT'];
include $root_html . '/includes/superadmin_sidebar.php';
include $root_html . '/includes/header.php';

// ── JS ROLE FLAGS (safe to expose — non-sensitive) ───────────────────────────
$jsRoleRank = (int)$roleRank;
$jsRoleName = htmlspecialchars($roleName, ENT_QUOTES);
$jsZone     = htmlspecialchars($currentZone, ENT_QUOTES);
$jsUserId   = htmlspecialchars($currentUserId ?? '', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Repair &amp; Service Logs — ALMS</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
#mainContent,#rsSlider,#slOverlay,#actionModal,#viewModal,.rs-toasts{--s:#fff;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);--t1:var(--text-primary);--t2:var(--text-secondary);--t3:#9EB0A2;--hbg:var(--hover-bg-light);--bg:var(--bg-color);--grn:var(--primary-color);--gdk:var(--primary-dark);--red:#DC2626;--amb:#D97706;--blu:#2563EB;--tel:#0D9488;--pur:#7C3AED;--shmd:0 4px 20px rgba(46,125,50,.12);--shlg:0 24px 60px rgba(0,0,0,.22);--rad:12px;--tr:var(--transition);}
#mainContent *,#rsSlider *,#slOverlay *,#actionModal *,#viewModal *,.rs-toasts *{box-sizing:border-box;}
.rs-wrap{max-width:1440px;margin:0 auto;padding:0 0 4rem;}
/* PAGE HEADER */
.rs-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:26px;animation:UP .4s both;}
.rs-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px;}
.rs-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15;}
.rs-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
/* ROLE BADGE */
.role-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:8px;white-space:nowrap;}
.rb-sa{background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;}
.rb-admin{background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;}
.rb-mgr{background:#F0FDF4;color:#166534;border:1px solid #BBF7D0;}
.rb-staff{background:#F3F4F6;color:#374151;border:1px solid #D1D5DB;}
/* ZONE CONTEXT BANNER (Admin/Manager/Staff) */
.zone-ctx{display:flex;align-items:center;gap:8px;background:linear-gradient(90deg,#F0FDF4,#DCFCE7);border:1px solid rgba(46,125,50,.22);border-radius:10px;padding:9px 16px;font-size:12.5px;color:#166534;font-weight:600;margin-bottom:18px;animation:UP .4s .03s both;}
.zone-ctx i{font-size:15px;}
/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.32);}.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}.btn-ghost:hover{background:var(--hbg);color:var(--t1);}
.btn-approve{background:#DCFCE7;color:#166534;border:1px solid #BBF7D0;}.btn-approve:hover{background:#BBF7D0;}
.btn-reject{background:#FEE2E2;color:var(--red);border:1px solid #FECACA;}.btn-reject:hover{background:#FCA5A5;}
.btn-override{background:#EFF6FF;color:var(--blu);border:1px solid #BFDBFE;}.btn-override:hover{background:#DBEAFE;}
.btn-cancel-rs{background:#F3F4F6;color:#374151;border:1px solid #D1D5DB;}.btn-cancel-rs:hover{background:#E5E7EB;}
.btn-escalate{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;}.btn-escalate:hover{background:#FDE68A;}
.btn-complete{background:#CCFBF1;color:#115E59;border:1px solid #99F6E4;}.btn-complete:hover{background:#99F6E4;}
.btn-start{background:#EFF6FF;color:var(--blu);border:1px solid #BFDBFE;}.btn-start:hover{background:#DBEAFE;}
.btn-flag{background:#FFF7ED;color:#C2410C;border:1px solid #FDBA74;}.btn-flag:hover{background:#FED7AA;}
.btn-progress{background:#F5F3FF;color:#6D28D9;border:1px solid #DDD6FE;}.btn-progress:hover{background:#EDE9FE;}
.btn-sm{font-size:12px;padding:6px 13px;}.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px;}
.btn.ionly{width:28px;height:28px;padding:0;justify-content:center;font-size:14px;flex-shrink:0;border-radius:6px;border:1px solid var(--bdm);background:var(--s);color:var(--t2);}
.btn.ionly:hover{background:var(--hbg);color:var(--grn);border-color:var(--grn);}
.btn.ionly.btn-complete:hover{background:#CCFBF1;color:#115E59;border-color:#99F6E4;}
.btn.ionly.btn-override:hover{background:#EFF6FF;color:var(--blu);border-color:#BFDBFE;}
.btn.ionly.btn-escalate:hover{background:#FEF3C7;color:#92400E;border-color:#FCD34D;}
.btn.ionly.btn-cancel-rs:hover{background:#F3F4F6;color:#374151;border-color:#D1D5DB;}
.btn.ionly.btn-start:hover{background:#EFF6FF;color:var(--blu);border-color:#BFDBFE;}
.btn.ionly.btn-flag:hover{background:#FFF7ED;color:#C2410C;border-color:#FDBA74;}
.btn.ionly.btn-progress:hover{background:#F5F3FF;color:#6D28D9;border-color:#DDD6FE;}
.btn:disabled{opacity:.4;pointer-events:none;}
/* STATS */
.rs-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:22px;animation:UP .4s .05s both;}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:14px 16px;box-shadow:0 1px 4px rgba(46,125,50,.07);display:flex;align-items:center;gap:12px;}
.sc-ic{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px;}
.ic-b{background:#EFF6FF;color:var(--blu)}.ic-a{background:#FEF3C7;color:var(--amb)}.ic-g{background:#DCFCE7;color:#166534}.ic-r{background:#FEE2E2;color:var(--red)}.ic-t{background:#CCFBF1;color:var(--tel)}.ic-p{background:#F5F3FF;color:#6D28D9}.ic-d{background:#F3F4F6;color:#374151}.ic-y{background:#FEF3C7;color:#92400E}
.sc-v{font-size:22px;font-weight:800;color:var(--t1);line-height:1;}.sc-l{font-size:11px;color:var(--t2);margin-top:2px;}
/* TRENDS (SA only) */
.trends-wrap{background:var(--s);border:1px solid var(--bd);border-radius:16px;padding:20px 24px;margin-bottom:18px;box-shadow:var(--shmd);animation:UP .4s .08s both;}
.trends-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;}
.trends-hdr h3{font-size:14px;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px;}
.trends-hdr h3 i{color:var(--grn);font-size:16px;}
.sa-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;border-radius:6px;padding:3px 9px;}
.trends-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.trend-item{background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;}
.trend-item .ti-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.trend-bars{display:flex;flex-direction:column;gap:6px;}
.trend-bar-row{display:flex;align-items:center;gap:8px;font-size:11.5px;}
.trend-bar-label{min-width:55px;color:var(--t2);font-weight:500;}
.trend-bar-track{flex:1;height:6px;background:var(--bd);border-radius:3px;overflow:hidden;}
.trend-bar-fill{height:100%;border-radius:3px;transition:width .5s ease;}
.trend-bar-val{min-width:42px;text-align:right;font-family:'DM Mono',monospace;font-size:11px;font-weight:600;color:var(--t1);}
.cost-compare-mini{display:flex;flex-direction:column;gap:6px;}
.ccm-row{display:flex;align-items:center;justify-content:space-between;font-size:12px;padding:6px 0;border-bottom:1px dashed rgba(46,125,50,.12);}
.ccm-row:last-child{border-bottom:none;}
.ccm-row .pname{font-weight:600;color:var(--t1);display:flex;align-items:center;gap:5px;}
.ccm-row .pamount{font-family:'DM Mono',monospace;font-weight:700;color:var(--grn);}
.ccm-row .ptag{font-size:10px;font-weight:700;padding:1px 6px;border-radius:5px;}
.ptag-best{background:#DCFCE7;color:#166534}.ptag-avg{background:#EFF6FF;color:#1D4ED8}.ptag-high{background:#FEE2E2;color:#991B1B}
/* TOOLBAR */
.rs-tb{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px;animation:UP .4s .1s both;}
.sw{position:relative;flex:1;min-width:220px;}.sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--t3);pointer-events:none;}
.si{width:100%;padding:9px 11px 9px 36px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);}
.si:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}.si::placeholder{color:var(--t3);}
.sel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 28px 9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;}
.sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}
.fi-date{font-family:'Inter',sans-serif;font-size:13px;padding:9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);}
.fi-date:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}
.date-range-wrap{display:flex;align-items:center;gap:6px;}.date-range-wrap span{font-size:12px;color:var(--t3);font-weight:500;}
/* BULK BAR (SA only) */
.bulk-bar{display:none;align-items:center;gap:10px;padding:10px 16px;background:linear-gradient(135deg,#F0FDF4,#DCFCE7);border:1px solid rgba(46,125,50,.22);border-radius:12px;margin-bottom:14px;flex-wrap:wrap;}
.bulk-bar.on{display:flex;}.bulk-count{font-size:13px;font-weight:700;color:#166534;}.bulk-sep{width:1px;height:22px;background:rgba(46,125,50,.25);}
.sa-exclusive{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;border-radius:6px;padding:2px 7px;}
/* TABLE */
.rs-card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);animation:UP .4s .13s both;}
.tbl-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;width:100%;}
.rs-tbl{width:100%;border-collapse:collapse;font-size:12.5px;table-layout:auto;}
.rs-tbl thead th{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:10px 10px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;cursor:pointer;user-select:none;}
.rs-tbl thead th.no-sort{cursor:default;}.rs-tbl thead th:hover:not(.no-sort){color:var(--grn);}.rs-tbl thead th.sorted{color:var(--grn);}
.rs-tbl thead th .sic{margin-left:3px;opacity:.4;font-size:12px;vertical-align:middle;}.rs-tbl thead th.sorted .sic{opacity:1;}
.rs-tbl thead th:first-child,.rs-tbl tbody td:first-child{padding-left:12px;padding-right:4px;}
.rs-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .13s;}.rs-tbl tbody tr:last-child{border-bottom:none;}.rs-tbl tbody tr:hover{background:var(--hbg);}.rs-tbl tbody tr.row-selected{background:#F0FDF4;}
.rs-tbl tbody td{padding:11px 10px;vertical-align:middle;cursor:pointer;overflow:hidden;text-overflow:ellipsis;}
.rs-tbl tbody td:first-child{cursor:default;}.rs-tbl tbody td:last-child{white-space:nowrap;cursor:default;overflow:visible;padding:8px;}
.rs-num{font-family:'DM Mono',monospace;font-size:11.5px;font-weight:600;color:var(--t1);white-space:nowrap;}
.rs-date{font-size:12px;color:var(--t2);white-space:nowrap;}
.rs-cost{font-family:'DM Mono',monospace;font-size:12.5px;font-weight:700;color:var(--t1);white-space:nowrap;}
.rs-cost.overridden::after{content:'★';font-size:9px;color:var(--amb);vertical-align:super;margin-left:2px;}
.asset-cell{display:flex;flex-direction:column;gap:3px;min-width:0;}
.asset-name{font-weight:600;color:var(--t1);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.asset-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.asset-id{font-family:'DM Mono',monospace;font-size:10.5px;color:var(--t3);}
.asset-zone{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:600;color:var(--t2);}
.asset-tech{font-size:10.5px;color:var(--t3);}
.issue-cell{font-size:12.5px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:220px;}
.act-cell{display:flex;gap:4px;align-items:center;}
.cb-wrap{display:flex;align-items:center;justify-content:center;}
input[type=checkbox].cb{width:15px;height:15px;accent-color:var(--grn);cursor:pointer;}
/* BADGES */
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;}
.b-reported{background:#EFF6FF;color:#1D4ED8;}.b-inprogress{background:#FEF3C7;color:#92400E;}.b-completed{background:#DCFCE7;color:#166534;}.b-cancelled{background:#F3F4F6;color:#374151;}.b-escalated{background:#FDE8D8;color:#C2410C;}
/* PAGINATION */
.rs-pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2);}
.pg-btns{display:flex;gap:5px;}.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1);}
.pgb:hover{background:var(--hbg);border-color:var(--grn);color:var(--grn);}.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff;}.pgb:disabled{opacity:.4;pointer-events:none;}
.empty{padding:72px 20px;text-align:center;color:var(--t3);}.empty i{font-size:54px;display:block;margin-bottom:14px;color:#C8E6C9;}
/* SLIDE-OVER */
#slOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;opacity:0;pointer-events:none;transition:opacity .25s;}
#slOverlay.on{opacity:1;pointer-events:all;}
#rsSlider{position:fixed;top:0;right:-620px;bottom:0;width:580px;max-width:100vw;background:var(--s);z-index:9001;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18);}
#rsSlider.on{right:0;}
.sl-hdr{display:flex;align-items:flex-start;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--bd);background:var(--bg);flex-shrink:0;}
.sl-title{font-size:17px;font-weight:700;color:var(--t1);}.sl-subtitle{font-size:12px;color:var(--t2);margin-top:2px;}
.sl-close{width:36px;height:36px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--t2);transition:var(--tr);flex-shrink:0;}
.sl-close:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA;}
.sl-body{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:16px;}
.sl-body::-webkit-scrollbar{width:4px;}.sl-body::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px;}
.sl-foot{padding:16px 24px;border-top:1px solid var(--bd);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;}
.fg{display:flex;flex-direction:column;gap:6px;}.fr{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fl{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2);}.fl span{color:var(--red);margin-left:2px;}
.fi,.fs,.fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);width:100%;}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;padding-right:30px;}
.fta{resize:vertical;min-height:70px;}
.fd{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px;}.fd::after{content:'';flex:1;height:1px;background:var(--bd);}
/* ACTION MODAL */
#actionModal{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9100;display:grid;place-content:center;opacity:0;pointer-events:none;transition:opacity .2s;padding:20px;}
#actionModal.on{opacity:1;pointer-events:all;}
.am-box{background:var(--s);border-radius:16px;padding:28px 28px 24px;width:440px;max-width:92vw;box-shadow:var(--shlg);}
.am-icon{font-size:46px;margin-bottom:10px;line-height:1;}.am-title{font-size:18px;font-weight:700;color:var(--t1);margin-bottom:6px;}
.am-body{font-size:13px;color:var(--t2);line-height:1.6;margin-bottom:16px;}
.am-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#92400E;}
.am-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px;}
.am-fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
.am-fg label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t2);}
.am-fg textarea,.am-fg input{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;width:100%;transition:var(--tr);}
.am-fg textarea{resize:vertical;min-height:68px;}
.am-fg textarea:focus,.am-fg input:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.am-acts{display:flex;gap:10px;justify-content:flex-end;}
/* VIEW MODAL */
#viewModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9050;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;}
#viewModal.on{opacity:1;pointer-events:all;}
.vm-box{background:#fff;border-radius:20px;width:820px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden;}
.vm-mhd{padding:24px 28px 0;border-bottom:1px solid rgba(46,125,50,.14);background:var(--bg-color);flex-shrink:0;}
.vm-mtp{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px;}
.vm-msi{display:flex;align-items:center;gap:16px;}
.vm-mav{width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:19px;color:#fff;flex-shrink:0;}
.vm-mnm{font-size:20px;font-weight:800;color:var(--text-primary);display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.vm-mid{font-family:'DM Mono',monospace;font-size:12px;color:var(--text-secondary);margin-top:3px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.vm-mcl{width:36px;height:36px;border-radius:8px;border:1px solid rgba(46,125,50,.22);background:#fff;cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--text-secondary);transition:all .15s;flex-shrink:0;}
.vm-mcl:hover{background:#FEE2E2;color:#DC2626;border-color:#FECACA;}
.vm-mmt{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}
.vm-mc{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--text-secondary);background:#fff;border:1px solid rgba(46,125,50,.14);border-radius:8px;padding:5px 10px;line-height:1;}
.vm-mc i{font-size:14px;color:var(--primary-color);}
.vm-mtb{display:flex;gap:4px;}
.vm-tab{font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px 8px 0 0;cursor:pointer;transition:all .15s;color:var(--text-secondary);border:none;background:transparent;display:flex;align-items:center;gap:6px;white-space:nowrap;}
.vm-tab:hover{background:var(--hover-bg-light);color:var(--text-primary);}.vm-tab.active{background:var(--primary-color);color:#fff;}.vm-tab i{font-size:14px;}
.vm-mbd{flex:1;overflow-y:auto;padding:24px 28px;background:#fff;}
.vm-mbd::-webkit-scrollbar{width:4px;}.vm-mbd::-webkit-scrollbar-thumb{background:rgba(46,125,50,.22);border-radius:4px;}
.vm-tp{display:none;flex-direction:column;gap:18px;}.vm-tp.active{display:flex;}
.vm-sbs{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.vm-sb{background:var(--bg-color);border:1px solid rgba(46,125,50,.14);border-radius:10px;padding:14px 16px;}
.vm-sb .sbv{font-size:18px;font-weight:800;color:var(--text-primary);line-height:1;}.vm-sb .sbv.mono{font-family:'DM Mono',monospace;font-size:13px;color:var(--primary-color);}.vm-sb .sbl{font-size:11px;color:var(--text-secondary);margin-top:3px;}
.vm-ig{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.vm-ii label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9EB0A2;display:block;margin-bottom:4px;}
.vm-ii .v{font-size:13px;font-weight:500;color:var(--text-primary);line-height:1.5;}.vm-ii .v.muted{font-weight:400;color:#4B5563;}.vm-full{grid-column:1/-1;}
.vm-rmk{border-radius:10px;padding:12px 16px;font-size:12.5px;line-height:1.65;}
.vm-rmk .rml{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px;opacity:.7;}
.vm-rmk-a{background:#F0FDF4;color:#166534;}.vm-rmk-r{background:#FEF2F2;color:#991B1B;}.vm-rmk-n{background:#FFFBEB;color:#92400E;}.vm-rmk-e{background:#FFF7ED;color:#C2410C;}
.vm-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400E;}
.vm-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px;}
.vm-cost-compare{background:var(--bg-color);border:1px solid var(--bd);border-radius:12px;overflow:hidden;}
.vm-cost-compare-hdr{padding:12px 16px;background:linear-gradient(135deg,#F0FDF4,#DCFCE7);border-bottom:1px solid rgba(46,125,50,.14);font-size:12px;font-weight:700;color:var(--primary-color);display:flex;align-items:center;gap:7px;}
.vm-cost-row{display:flex;align-items:center;justify-content:space-between;padding:11px 16px;border-bottom:1px solid rgba(46,125,50,.07);font-size:13px;}
.vm-cost-row:last-child{border-bottom:none;}
.vm-cost-row .cr-prov{font-weight:600;color:var(--text-primary);}
.vm-cost-row .cr-amt{font-family:'DM Mono',monospace;font-weight:700;color:var(--primary-color);}
.vm-cost-row .cr-badge{font-size:10px;font-weight:700;padding:2px 7px;border-radius:6px;}
.cr-best{background:#DCFCE7;color:#166534;}.cr-high{background:#FEE2E2;color:#991B1B;}
.vm-esc-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid rgba(46,125,50,.10);}
.vm-esc-item:last-child{border-bottom:none;padding-bottom:0;}
.vm-esc-dot{width:28px;height:28px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;}
.ed-r{background:#FEE2E2;color:#DC2626}.ed-e{background:#FFF7ED;color:#C2410C}.ed-c{background:#DCFCE7;color:#166534}.ed-s{background:#EFF6FF;color:#2563EB}.ed-o{background:#FEF3C7;color:#D97706}.ed-x{background:#F3F4F6;color:#6B7280}
.vm-esc-body{flex:1;min-width:0;}
.vm-esc-body .eu{font-size:13px;font-weight:500;color:var(--text-primary);}
.vm-esc-body .et{font-size:11px;color:#9EB0A2;margin-top:3px;font-family:'DM Mono',monospace;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.vm-esc-note{font-size:11.5px;color:#6B7280;margin-top:3px;font-style:italic;}
.vm-esc-ip{font-family:'DM Mono',monospace;font-size:10px;color:#9CA3AF;background:#F3F4F6;border-radius:4px;padding:1px 6px;}
.sa-tag{font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 5px;border:1px solid #FCD34D;}
.vm-mft{padding:16px 28px;border-top:1px solid rgba(46,125,50,.14);background:var(--bg-color);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap;}
/* TOASTS */
.rs-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:TIN .3s ease;}
.toast.ts{background:var(--grn);}.toast.tw{background:var(--amb);}.toast.td{background:var(--red);}.toast.out{animation:TOUT .3s ease forwards;}
@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}
@keyframes SHK{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-5px)}40%,80%{transform:translateX(5px)}}
/* Searchable supplier */
.cs-wrap{position:relative;width:100%;}
.cs-input{width:100%;padding:10px 12px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);}
.cs-input:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.cs-input::placeholder{color:var(--t3);}
.cs-drop{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--s);border:1px solid var(--bdm);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.13);z-index:9999;max-height:240px;overflow-y:auto;}
.cs-drop.open{display:block;}
.cs-drop::-webkit-scrollbar{width:4px;}.cs-drop::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px;}
.cs-opt{padding:9px 12px;font-size:13px;cursor:pointer;display:flex;flex-direction:column;gap:3px;transition:background .12s;}
.cs-opt:hover,.cs-opt.hl{background:var(--hbg);}
.cs-opt .cs-name{font-size:13px;color:var(--t1);font-weight:500;}
.cs-opt .cs-sub{font-size:10.5px;color:var(--t3);display:flex;align-items:center;gap:6px;}
.cs-opt.cs-none{color:var(--t3);cursor:default;font-size:12px;padding:12px;}.cs-opt.cs-none:hover{background:none;}
.acc-chip{font-size:9.5px;font-weight:700;padding:1px 6px;border-radius:4px;}
.acc-ok{background:#DCFCE7;color:#166534;}.acc-pend{background:#FEF3C7;color:#92400E;}.acc-no{background:#FEE2E2;color:#991B1B;}
/* Staff task view */
.my-task-card{background:var(--s);border:1px solid var(--bd);border-radius:14px;padding:18px 20px;display:flex;gap:16px;align-items:flex-start;transition:box-shadow .15s;}
.my-task-card:hover{box-shadow:var(--shmd);}
.tc-icon{width:44px;height:44px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:19px;}
.tc-body{flex:1;min-width:0;}
.tc-name{font-size:14px;font-weight:700;color:var(--t1);}
.tc-id{font-family:'DM Mono',monospace;font-size:11px;color:var(--t3);}
.tc-issue{font-size:12.5px;color:var(--t2);margin-top:4px;line-height:1.5;}
.tc-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
.tc-chip{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:8px;background:var(--bg);border:1px solid var(--bd);color:var(--t2);}
.tc-acts{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;}
@media(max-width:900px){.rs-stats{grid-template-columns:repeat(2,1fr)}.fr{grid-template-columns:1fr}#rsSlider{width:100vw}.vm-sbs{grid-template-columns:repeat(2,1fr)}.vm-ig{grid-template-columns:1fr}.trends-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="rs-wrap">

  <!-- PAGE HEADER -->
  <div class="rs-ph">
    <div>
      <p class="ey">ALMS · Asset Lifecycle &amp; Maintenance</p>
      <h1>Repair &amp; Service Logs</h1>
    </div>
    <div class="rs-ph-r">
      <span class="role-badge <?= match($roleName){
          'Super Admin'=>'rb-sa','Admin'=>'rb-admin','Manager'=>'rb-mgr',default=>'rb-staff'} ?>"
        ><i class="bx <?= match($roleName){'Super Admin'=>'bx-shield-quarter','Admin'=>'bx-user-check','Manager'=>'bx-briefcase',default=>'bx-user'} ?>"></i>
        <?= htmlspecialchars($roleName) ?>
      </span>
      <?php if ($roleRank >= 3): ?>
      <button class="btn btn-ghost" id="exportBtn"><i class="bx bx-export"></i> Export CSV</button>
      <?php endif; ?>
      <?php if ($roleRank >= 3): ?>
      <button class="btn btn-primary" id="createBtn"><i class="bx bx-plus"></i> Log New Repair</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ZONE CONTEXT BANNER (Admin / Manager / Staff) -->
  <?php if ($roleRank < 4 && $currentZone): ?>
  <div class="zone-ctx">
    <i class="bx bx-map-pin"></i>
    <?php if ($roleRank === 1): ?>
    Showing your assigned repair tasks · <strong><?= htmlspecialchars($currentZone) ?></strong>
    <?php elseif ($roleRank === 2): ?>
    Zone view: <strong><?= htmlspecialchars($currentZone) ?></strong> — monitoring team repairs
    <?php else: ?>
    Managing repairs for zone: <strong><?= htmlspecialchars($currentZone) ?></strong>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="rs-stats" id="statsBar"></div>

  <!-- TRENDS (Super Admin only) -->
  <?php if ($roleRank === 4): ?>
  <div class="trends-wrap" id="trendsPanel">
    <div class="trends-hdr">
      <h3><i class="bx bx-line-chart"></i> Site-wide Trends &amp; Analytics</h3>
      <span class="sa-badge"><i class="bx bx-shield-quarter"></i> Super Admin View</span>
    </div>
    <div style="color:var(--t3);font-size:13px;text-align:center;padding:20px 0">Loading analytics…</div>
  </div>
  <?php endif; ?>

  <!-- TOOLBAR -->
  <div class="rs-tb">
    <div class="sw"><i class="bx bx-search"></i>
      <input type="text" class="si" id="srch" placeholder="<?= $roleRank === 1 ? 'Search by Log ID or Asset…' : 'Search by Log ID, Asset, Technician, or Zone…' ?>">
    </div>
    <select class="sel" id="fStatus">
      <option value="">All Statuses</option>
      <option>Reported</option><option>In Progress</option>
      <option>Completed</option><option>Cancelled</option><option>Escalated</option>
    </select>
    <?php if ($roleRank === 4): ?>
    <select class="sel" id="fZone"><option value="">All Zones</option></select>
    <select class="sel" id="fProvider"><option value="">All Providers</option></select>
    <div class="date-range-wrap">
      <input type="date" class="fi-date" id="fDateFrom" title="Date From">
      <span>–</span>
      <input type="date" class="fi-date" id="fDateTo" title="Date To">
    </div>
    <?php elseif ($roleRank >= 2): ?>
    <div class="date-range-wrap">
      <input type="date" class="fi-date" id="fDateFrom" title="Date From">
      <span>–</span>
      <input type="date" class="fi-date" id="fDateTo" title="Date To">
    </div>
    <?php endif; ?>
  </div>

  <!-- BULK BAR (Super Admin only) -->
  <?php if ($roleRank === 4): ?>
  <div class="bulk-bar" id="bulkBar">
    <span class="bulk-count" id="bulkCount">0 selected</span>
    <div class="bulk-sep"></div>
    <button class="btn btn-complete btn-sm" id="batchCompleteBtn"><i class="bx bx-check-double"></i> Batch Force Complete</button>
    <button class="btn btn-escalate btn-sm" id="batchEscalateBtn"><i class="bx bx-error"></i> Batch Escalate</button>
    <button class="btn btn-ghost btn-sm" id="clearSelBtn"><i class="bx bx-x-circle"></i> Clear</button>
    <span class="sa-exclusive" style="margin-left:auto"><i class="bx bx-shield-quarter"></i> Super Admin Exclusive</span>
  </div>
  <?php endif; ?>

  <div class="rs-card">
    <div class="tbl-scroll">
    <table class="rs-tbl" id="tbl">
      <colgroup>
        <?php if ($roleRank === 4): ?><col style="width:36px"><?php endif; ?>
        <col><!-- Log ID -->
        <col><!-- Asset -->
        <?php if ($roleRank >= 3): ?><col><!-- Zone --><?php endif; ?>
        <col><!-- Issue -->
        <col><!-- Date -->
        <?php if ($roleRank >= 3): ?><col><!-- Technician --><?php endif; ?>
        <?php if ($roleRank >= 3): ?><col><!-- Provider --><?php endif; ?>
        <?php if ($roleRank !== 2): ?><col><!-- Cost --><?php endif; ?>
        <?php if ($roleRank === 2 || $roleRank === 1): ?><col><!-- Date Assigned / Reported --><?php endif; ?>
        <col><!-- Status -->
        <col style="width:140px"><!-- Actions -->
      </colgroup>
      <thead><tr>
        <?php if ($roleRank === 4): ?>
        <th class="no-sort"><div class="cb-wrap"><input type="checkbox" class="cb" id="checkAll"></div></th>
        <?php endif; ?>
        <th data-col="logId">Log ID <i class="bx bx-sort sic"></i></th>
        <th data-col="assetName">Asset<?= $roleRank >= 3 ? ' / Name' : '' ?> <i class="bx bx-sort sic"></i></th>
        <?php if ($roleRank >= 3): ?>
        <th data-col="zone">Zone <i class="bx bx-sort sic"></i></th>
        <?php endif; ?>
        <th data-col="issue">Issue Reported <i class="bx bx-sort sic"></i></th>
        <?php if ($roleRank >= 3): ?>
        <th data-col="dateReported">Date Reported <i class="bx bx-sort sic"></i></th>
        <th data-col="technician">Technician <i class="bx bx-sort sic"></i></th>
        <th data-col="provider">Provider <i class="bx bx-sort sic"></i></th>
        <th data-col="repairCost">Repair Cost <i class="bx bx-sort sic"></i></th>
        <th data-col="dateCompleted">Completed <i class="bx bx-sort sic"></i></th>
        <?php elseif ($roleRank === 2): ?>
        <th data-col="technician">Technician <i class="bx bx-sort sic"></i></th>
        <?php else: ?>
        <th data-col="dateReported">Date Assigned <i class="bx bx-sort sic"></i></th>
        <?php endif; ?>
        <th data-col="status">Status <i class="bx bx-sort sic"></i></th>
        <th class="no-sort">Actions</th>
      </tr></thead>
      <tbody id="tbody"></tbody>
    </table>
    </div>
    <div class="rs-pager" id="pager"></div>
  </div>

</div>
</main>

<div class="rs-toasts" id="toastWrap"></div>
<div id="slOverlay"></div>

<!-- CREATE / EDIT SLIDER (Admin+ only) -->
<?php if ($roleRank >= 3): ?>
<div id="rsSlider">
  <div class="sl-hdr">
    <div><div class="sl-title" id="slTitle">Log New Repair</div><div class="sl-subtitle" id="slSub">Fill in all required fields below</div></div>
    <button class="sl-close" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-body">
    <div class="fr">
      <div class="fg">
        <label class="fl">Asset ID</label>
        <select class="fs" id="fAssetSl"><option value="">Select asset…</option></select>
      </div>
      <div class="fg">
        <label class="fl">Asset Name <span>*</span></label>
        <input type="text" class="fi" id="fAssetName" placeholder="e.g. Forklift Unit 3">
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Zone <span>*</span></label>
        <select class="fs" id="fZoneSl"><option value="">Select Zone…</option></select>
      </div>
      <div class="fg">
        <label class="fl">Status <span>*</span></label>
        <select class="fs" id="fStatusSl">
          <option value="Reported">Reported</option>
          <option value="In Progress">In Progress</option>
          <option value="Completed">Completed</option>
          <option value="Cancelled">Cancelled</option>
          <?php if ($roleRank === 4): ?><option value="Escalated">Escalated</option><?php endif; ?>
        </select>
      </div>
    </div>
    <div class="fg">
      <label class="fl">Issue Reported <span>*</span></label>
      <textarea class="fta" id="fIssue" placeholder="Describe the issue or defect observed…"></textarea>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Date Reported <span>*</span></label>
        <input type="date" class="fi" id="fDateReported">
      </div>
      <div class="fg">
        <label class="fl">Date Completed</label>
        <input type="date" class="fi" id="fDateCompleted">
      </div>
    </div>
    <div class="fd">Assignment &amp; Cost</div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Assigned Technician <span>*</span></label>
        <select class="fs" id="fTechSl"><option value="">Select technician…</option></select>
      </div>
      <div class="fg">
        <label class="fl">Service Provider <span style="font-size:10px;font-weight:600;color:var(--grn);text-transform:none;letter-spacing:0">(PSM Suppliers)</span></label>
        <div class="cs-wrap">
          <input type="text" class="cs-input" id="csSupplierSearch" placeholder="Search PSM suppliers…" autocomplete="off">
          <input type="hidden" id="fSupplierId" value="">
          <input type="hidden" id="fSupplierName" value="">
          <input type="hidden" id="fProviderSl" value="">
          <div class="cs-drop" id="csSupplierDrop"></div>
        </div>
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Repair Cost (₱)</label>
        <input type="number" class="fi" id="fCost" placeholder="0.00" min="0" step="0.01">
      </div>
      <div class="fg">
        <label class="fl">Remarks / Notes</label>
        <input type="text" class="fi" id="fRemarks" placeholder="Additional notes…">
      </div>
    </div>
  </div>
  <div class="sl-foot">
    <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-save"></i> Save Log</button>
  </div>
</div>
<?php endif; ?>

<!-- ACTION MODAL -->
<div id="actionModal">
  <div class="am-box">
    <div class="am-icon" id="amIcon">✅</div>
    <div class="am-title" id="amTitle">Confirm Action</div>
    <div class="am-body" id="amBody"></div>
    <div class="am-sa-note" id="amSaNote" style="display:none"><i class="bx bx-shield-quarter"></i><span id="amSaText"></span></div>
    <div id="amDynamicInputs"></div>
    <div class="am-fg">
      <label>Remarks / Notes <span id="amRemarksLbl" style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
      <textarea id="amRemarks" placeholder="Add remarks for this action…"></textarea>
    </div>
    <div class="am-acts">
      <button class="btn btn-ghost btn-sm" id="amCancel">Cancel</button>
      <button class="btn btn-sm" id="amConfirm">Confirm</button>
    </div>
  </div>
</div>

<!-- VIEW MODAL -->
<div id="viewModal">
  <div class="vm-box">
    <div class="vm-mhd">
      <div class="vm-mtp">
        <div class="vm-msi">
          <div class="vm-mav" id="vmAvatar"></div>
          <div><div class="vm-mnm" id="vmName"></div><div class="vm-mid" id="vmMid"></div></div>
        </div>
        <button class="vm-mcl" id="vmClose"><i class="bx bx-x"></i></button>
      </div>
      <div class="vm-mmt" id="vmChips"></div>
      <div class="vm-mtb" id="vmTabs">
        <button class="vm-tab active" data-t="ov"><i class="bx bx-grid-alt"></i> Overview</button>
        <?php if ($roleRank >= 3): ?>
        <button class="vm-tab" data-t="esc"><i class="bx bx-shield-quarter"></i> Audit Log</button>
        <?php endif; ?>
        <?php if ($roleRank === 4): ?>
        <button class="vm-tab" data-t="cc"><i class="bx bx-dollar-circle"></i> Cost Comparison</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="vm-mbd">
      <div class="vm-tp active" id="vt-ov"></div>
      <?php if ($roleRank >= 3): ?>
      <div class="vm-tp" id="vt-esc"></div>
      <?php endif; ?>
      <?php if ($roleRank === 4): ?>
      <div class="vm-tp" id="vt-cc"></div>
      <?php endif; ?>
    </div>
    <div class="vm-mft" id="vmFoot"></div>
  </div>
</div>

<script>
// ── ROLE CONSTANTS (from PHP) ─────────────────────────────────────────────────
const ROLE_RANK = <?= $jsRoleRank ?>;
const ROLE_NAME = '<?= $jsRoleName ?>';
const MY_ZONE   = '<?= $jsZone ?>';
const MY_ID     = '<?= $jsUserId ?>';
const API       = '<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES) ?>';

// ── API HELPERS ───────────────────────────────────────────────────────────────
async function apiFetch(path, opts={}) {
    const r = await fetch(path, {headers:{'Content-Type':'application/json'}, ...opts});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p => apiFetch(p);
const apiPost = (p,b) => apiFetch(p, {method:'POST', body:JSON.stringify(b)});

// ── STATE ─────────────────────────────────────────────────────────────────────
let LOGS=[], ASSETS=[], STAFF=[], SUPPLIERS=[];
let sortCol='dateReported', sortDir='desc', page=1;
const PAGE=10;
let selectedIds=new Set();
let actionKey=null, actionTarget=null, actionCb=null;
let editId=null;
let currentViewId=null;

// ── LOAD ──────────────────────────────────────────────────────────────────────
async function loadAll(){
    try {
        const loaders = [apiGet(API+'?api=list')];
        if (ROLE_RANK >= 3) loaders.unshift(
            apiGet(API+'?api=assets').catch(()=>[]),
            apiGet(API+'?api=staff').catch(()=>[]),
            apiGet(API+'?api=suppliers').catch(()=>[])
        );
        if (ROLE_RANK >= 3) {
            [ASSETS, STAFF, SUPPLIERS, LOGS] = await Promise.all(loaders);
        } else {
            [LOGS] = await Promise.all(loaders);
        }
    } catch(e){ toast('Failed to load data: '+e.message,'d'); }
    if (ROLE_RANK >= 3) populateSliderDropdowns();
    renderList();
    if (ROLE_RANK === 4) renderTrends();
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc  = s=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fD   = d=>{ if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); };
const fM   = n=>'₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const ini  = n=>String(n||'').split(/[\s–\-]+/).map(w=>w[0]).join('').slice(0,2).toUpperCase();
const today= ()=>new Date().toISOString().split('T')[0];

function zoneColor(zone){
    const m={'zone a':'#2E7D32','zone b':'#2563EB','zone c':'#D97706','zone d':'#DC2626','zone e':'#0D9488','zone f':'#7C3AED'};
    const key=String(zone||'').toLowerCase().substring(0,6);
    for(const k in m){ if(key.includes(k)) return m[k]; }
    return '#6B7280';
}
function badge(s){
    const m={Reported:'b-reported','In Progress':'b-inprogress',Completed:'b-completed',Cancelled:'b-cancelled',Escalated:'b-escalated'};
    return `<span class="badge ${m[s]||''}">${esc(s)}</span>`;
}

// ── SLIDER DROPDOWNS (Admin+ only) ────────────────────────────────────────────
function populateSliderDropdowns(){
    if (ROLE_RANK < 3) return;
    const aEl=document.getElementById('fAssetSl');
    if (aEl) {
        aEl.innerHTML='<option value="">Select asset…</option>'+ASSETS.map(a=>`<option value="${esc(a.assetId)}" data-name="${esc(a.name)}" data-zone="${esc(a.zone)}" data-dbid="${a.id}">${esc(a.assetId)} — ${esc(a.name)}</option>`).join('');
    }
    const zones=[...new Set(ASSETS.map(a=>a.zone).filter(Boolean))].sort();
    const zEl=document.getElementById('fZoneSl');
    if (zEl) {
        zEl.innerHTML='<option value="">Select Zone…</option>'+zones.map(z=>`<option>${esc(z)}</option>`).join('');
        // Admin: lock to own zone
        if (ROLE_RANK === 3 && MY_ZONE) { zEl.value=MY_ZONE; zEl.disabled=true; }
    }
    const tEl=document.getElementById('fTechSl');
    if (tEl) tEl.innerHTML='<option value="">Select technician…</option>'+STAFF.map(s=>`<option value="${esc(s.id)}" data-name="${esc(s.name)}">${esc(s.name)}</option>`).join('');
    wireSupplierSearch('');
}

function wireSupplierSearch(selectedId, selectedName=''){
    if (ROLE_RANK < 3) return;
    const inp  = document.getElementById('csSupplierSearch');
    const hid  = document.getElementById('fSupplierId');
    const hidN = document.getElementById('fSupplierName');
    const drop = document.getElementById('csSupplierDrop');
    if(!inp) return;
    if(selectedId){ hid.value=selectedId; inp.value=selectedName; hidN.value=selectedName; }
    else { hid.value=''; hidN.value=''; }
    const fresh=inp.cloneNode(true);
    inp.parentNode.replaceChild(fresh,inp);
    const inp2=document.getElementById('csSupplierSearch');
    let hl=-1;
    function renderDrop(q){
        const lq=(q||'').toLowerCase();
        const filtered=SUPPLIERS.filter(s=>s.name.toLowerCase().includes(lq)||s.category.toLowerCase().includes(lq));
        if(!filtered.length){
            drop.innerHTML='<div class="cs-opt cs-none">No suppliers found</div>';
        } else {
            drop.innerHTML=filtered.map(s=>{
                const stars='★'.repeat(Math.round(s.rating))+'☆'.repeat(5-Math.round(s.rating));
                const accCls=s.accreditation==='Accredited'?'acc-ok':s.accreditation==='Pending'?'acc-pend':'acc-no';
                const flagged=s.isFlagged?`<span style="color:#DC2626;font-size:9px;font-weight:700;margin-left:4px">⚑ FLAGGED</span>`:'';
                return `<div class="cs-opt" data-id="${s.id}" data-name="${esc(s.name)}" data-rating="${s.rating}">
                    <span class="cs-name" style="display:flex;align-items:center;gap:6px;justify-content:space-between">
                        <span>${esc(s.name)}${flagged}</span>
                        <span style="color:#D97706;font-size:10px;letter-spacing:1px">${stars}</span>
                    </span>
                    <span class="cs-sub" style="display:flex;align-items:center;gap:8px">
                        <span style="color:var(--t3)">${esc(s.category||'General')}</span>
                        <span class="acc-chip ${accCls}">${esc(s.accreditation)}</span>
                    </span>
                </div>`;
            }).join('');
            drop.querySelectorAll('.cs-opt:not(.cs-none)').forEach(opt=>{
                opt.addEventListener('mousedown',e=>{
                    e.preventDefault();
                    hid.value=opt.dataset.id; hidN.value=opt.dataset.name; inp2.value=opt.dataset.name;
                    drop.classList.remove('open');
                });
            });
        }
        hl=-1;
    }
    inp2.addEventListener('focus',()=>{ renderDrop(inp2.value); drop.classList.add('open'); });
    inp2.addEventListener('input',()=>{ hid.value=''; hidN.value=''; renderDrop(inp2.value); drop.classList.add('open'); });
    inp2.addEventListener('blur', ()=>setTimeout(()=>drop.classList.remove('open'),160));
    inp2.addEventListener('keydown',e=>{
        const opts=[...drop.querySelectorAll('.cs-opt:not(.cs-none)')];
        if(e.key==='ArrowDown'){ e.preventDefault(); hl=Math.min(hl+1,opts.length-1); }
        else if(e.key==='ArrowUp'){ e.preventDefault(); hl=Math.max(hl-1,0); }
        else if(e.key==='Enter'&&hl>=0){ e.preventDefault();
            const o=opts[hl];
            if(o){ hid.value=o.dataset.id; hidN.value=o.dataset.name; inp2.value=o.dataset.name; drop.classList.remove('open'); }
        } else if(e.key==='Escape') drop.classList.remove('open');
        opts.forEach((o,i)=>o.classList.toggle('hl',i===hl));
        if(hl>=0&&opts[hl]) opts[hl].scrollIntoView({block:'nearest'});
    });
}

const fAssetSlEl = document.getElementById('fAssetSl');
if (fAssetSlEl) {
    fAssetSlEl.addEventListener('change',function(){
        const opt=this.options[this.selectedIndex];
        if(opt.value){
            document.getElementById('fAssetName').value=opt.dataset.name||'';
            const zEl=document.getElementById('fZoneSl');
            if(ROLE_RANK===4) zEl.value=opt.dataset.zone||'';
        }
    });
}

// ── FILTER DROPDOWNS (SA only) ────────────────────────────────────────────────
function buildFilterDropdowns(){
    if (ROLE_RANK < 4) return;
    const zEl=document.getElementById('fZone'); if(!zEl) return;
    const zones=[...new Set(LOGS.map(l=>l.zone).filter(Boolean))].sort();
    const zv=zEl.value;
    zEl.innerHTML='<option value="">All Zones</option>'+zones.map(z=>`<option ${z===zv?'selected':''}>${esc(z)}</option>`).join('');
    const pEl=document.getElementById('fProvider'); if(!pEl) return;
    const provs=[...new Set(LOGS.map(l=>l.provider).filter(Boolean))].sort();
    const pv=pEl.value;
    pEl.innerHTML='<option value="">All Providers</option>'+provs.map(p=>`<option ${p===pv?'selected':''}>${esc(p)}</option>`).join('');
}

// ── RENDER STATS ──────────────────────────────────────────────────────────────
function renderStats(){
    const now=new Date(), y=now.getFullYear(), m=String(now.getMonth()+1).padStart(2,'0');
    const mtd=LOGS.filter(l=>l.dateReported&&l.dateReported.startsWith(`${y}-${m}`));
    const open   =LOGS.filter(l=>l.status==='Reported').length;
    const inp    =LOGS.filter(l=>l.status==='In Progress').length;

    if (ROLE_RANK >= 3) {
        // Admin / Super Admin full stats
        const compMTD=mtd.filter(l=>l.status==='Completed').length;
        const costMTD=LOGS.filter(l=>l.status==='Completed').reduce((s,l)=>s+l.repairCost,0);
        let html = `
            <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-wrench"></i></div><div><div class="sc-v">${open}</div><div class="sc-l">Open Repairs${ROLE_RANK===3?' (Zone)':''}</div></div></div>
            <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-cog"></i></div><div><div class="sc-v">${inp}</div><div class="sc-l">In Progress</div></div></div>
            <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${compMTD}</div><div class="sc-l">Completed (MTD)</div></div></div>
            <div class="sc"><div class="sc-ic ic-t"><i class="bx bx-money-withdraw"></i></div><div><div class="sc-v" style="font-size:13px">${fM(costMTD)}</div><div class="sc-l">Total Repair Cost (MTD)</div></div></div>`;
        if (ROLE_RANK === 4) {
            const escCnt=LOGS.filter(l=>l.status==='Escalated').length;
            const overr =LOGS.filter(l=>l.costOverridden).length;
            html += `
            <div class="sc"><div class="sc-ic ic-y"><i class="bx bx-error"></i></div><div><div class="sc-v">${escCnt}</div><div class="sc-l">Escalated</div></div></div>
            <div class="sc"><div class="sc-ic ic-p"><i class="bx bx-revision"></i></div><div><div class="sc-v">${overr}</div><div class="sc-l">Cost Overrides</div></div></div>`;
        }
        document.getElementById('statsBar').innerHTML=html;
    } else if (ROLE_RANK === 2) {
        // Manager: zone-scoped cards
        const compMTD=mtd.filter(l=>l.status==='Completed').length;
        document.getElementById('statsBar').innerHTML=`
            <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-wrench"></i></div><div><div class="sc-v">${open}</div><div class="sc-l">Open Repairs (Zone)</div></div></div>
            <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-cog"></i></div><div><div class="sc-v">${inp}</div><div class="sc-l">In Progress</div></div></div>
            <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${compMTD}</div><div class="sc-l">Completed MTD (Zone)</div></div></div>`;
    } else {
        // Staff: my tasks
        const thisWeek=LOGS.filter(l=>l.status==='Completed'&&l.dateCompleted&&new Date(l.dateCompleted)>new Date(Date.now()-7*86400000)).length;
        document.getElementById('statsBar').innerHTML=`
            <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-wrench"></i></div><div><div class="sc-v">${open}</div><div class="sc-l">My Open Repairs</div></div></div>
            <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${thisWeek}</div><div class="sc-l">Completed This Week</div></div></div>`;
    }
}

// ── RENDER TRENDS (SA only) ───────────────────────────────────────────────────
function renderTrends(){
    if (ROLE_RANK < 4) return;
    const el=document.getElementById('trendsPanel'); if(!el) return;
    const statusList=['Reported','In Progress','Completed','Escalated','Cancelled'];
    const STATUS_COLORS={Reported:'#2563EB','In Progress':'#D97706',Completed:'#16A34A',Escalated:'#C2410C',Cancelled:'#6B7280'};
    const sCounts=statusList.map(s=>({label:s,val:LOGS.filter(l=>l.status===s).length}));
    const maxS=Math.max(...sCounts.map(x=>x.val),1);
    const allZones=[...new Set(LOGS.map(l=>l.zone).filter(Boolean))];
    const zoneCosts=allZones.map(z=>({label:z.split('–')[0].trim(),color:zoneColor(z),val:LOGS.filter(l=>l.zone===z).reduce((s,l)=>s+l.repairCost,0)})).filter(z=>z.val>0).sort((a,b)=>b.val-a.val).slice(0,5);
    const maxZ=Math.max(...zoneCosts.map(x=>x.val),1);
    const allProvs=[...new Set(LOGS.map(l=>l.provider).filter(Boolean))];
    const provCosts=allProvs.map(p=>({label:p,val:LOGS.filter(l=>l.provider===p).reduce((s,l)=>s+l.repairCost,0),count:LOGS.filter(l=>l.provider===p).length})).filter(x=>x.count>0).sort((a,b)=>b.val-a.val);
    const minProv=provCosts.length?provCosts[provCosts.length-1].label:'';
    const maxProv=provCosts.length?provCosts[0].label:'';
    el.innerHTML=`
        <div class="trends-hdr">
          <h3><i class="bx bx-line-chart"></i> Site-wide Trends &amp; Analytics</h3>
          <span class="sa-badge"><i class="bx bx-shield-quarter"></i> Super Admin View</span>
        </div>
        <div class="trends-grid">
          <div class="trend-item">
            <div class="ti-label"><i class="bx bx-bar-chart-alt-2" style="color:var(--grn)"></i> Repairs by Status</div>
            <div class="trend-bars">
              ${sCounts.map(s=>`<div class="trend-bar-row"><div class="trend-bar-label">${s.label.replace('In Progress','In Prog.')}</div><div class="trend-bar-track"><div class="trend-bar-fill" style="width:${Math.round(s.val/maxS*100)}%;background:${STATUS_COLORS[s.label]}"></div></div><div class="trend-bar-val">${s.val}</div></div>`).join('')}
            </div>
          </div>
          <div class="trend-item">
            <div class="ti-label"><i class="bx bx-map" style="color:var(--blu)"></i> Repair Cost by Zone</div>
            <div class="trend-bars">
              ${zoneCosts.length?zoneCosts.map(z=>`<div class="trend-bar-row"><div class="trend-bar-label">${z.label}</div><div class="trend-bar-track"><div class="trend-bar-fill" style="width:${Math.round(z.val/maxZ*100)}%;background:${z.color}"></div></div><div class="trend-bar-val" style="font-size:10px">${(z.val/1000).toFixed(0)}k</div></div>`).join(''):'<div style="color:var(--t3);font-size:12px">No data yet.</div>'}
            </div>
          </div>
          <div class="trend-item">
            <div class="ti-label"><i class="bx bx-building-house" style="color:var(--tel)"></i> Provider Cost Comparison</div>
            <div class="cost-compare-mini">
              ${provCosts.length?provCosts.map(p=>`<div class="ccm-row"><span class="pname"><span style="width:7px;height:7px;border-radius:50%;background:${p.label===minProv?'#16A34A':p.label===maxProv?'#DC2626':'#9CA3AF'};display:inline-block;flex-shrink:0"></span>${esc(p.label.replace(' Solutions','').replace(' Services',''))}</span><div style="display:flex;align-items:center;gap:6px"><span class="pamount">${(p.val/1000).toFixed(1)}k</span><span class="ptag ${p.label===minProv?'ptag-best':p.label===maxProv?'ptag-high':'ptag-avg'}">${p.label===minProv?'Best':p.label===maxProv?'High':p.count+' jobs'}</span></div></div>`).join(''):'<div style="color:var(--t3);font-size:12px">No data yet.</div>'}
            </div>
          </div>
        </div>`;
}

// ── FILTER / SORT ─────────────────────────────────────────────────────────────
function getFiltered(){
    const q  =document.getElementById('srch').value.trim().toLowerCase();
    const fs =document.getElementById('fStatus').value;
    const fz =(document.getElementById('fZone')?.value)||'';
    const fp =(document.getElementById('fProvider')?.value)||'';
    const df =(document.getElementById('fDateFrom')?.value)||'';
    const dt =(document.getElementById('fDateTo')?.value)||'';
    return LOGS.filter(l=>{
        if(q&&!l.logId.toLowerCase().includes(q)&&!l.assetName.toLowerCase().includes(q)&&!l.technician.toLowerCase().includes(q)&&!l.zone.toLowerCase().includes(q)) return false;
        if(fs&&l.status!==fs)     return false;
        if(fz&&l.zone!==fz)       return false;
        if(fp&&l.provider!==fp)   return false;
        if(df&&l.dateReported<df) return false;
        if(dt&&l.dateReported>dt) return false;
        return true;
    });
}
function getSorted(list){
    return [...list].sort((a,b)=>{
        let va=a[sortCol], vb=b[sortCol];
        if(sortCol==='repairCost') return sortDir==='asc'?va-vb:vb-va;
        va=String(va||'').toLowerCase(); vb=String(vb||'').toLowerCase();
        return sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va);
    });
}

// ── RENDER TABLE ──────────────────────────────────────────────────────────────
function renderList(){
    renderStats(); buildFilterDropdowns();
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
        tb.innerHTML=`<tr><td colspan="12"><div class="empty"><i class="bx bx-wrench"></i><p>${ROLE_RANK===1?'No assigned repair tasks found.':'No repair logs found.'}</p></div></td></tr>`;
    } else {
        tb.innerHTML=slice.map(l=>{
            const clr=zoneColor(l.zone);
            const chk=selectedIds.has(l.logId);
            const isReported=l.status==='Reported';
            const isActive=isReported||l.status==='In Progress';
            const isEsc=l.status==='Escalated', isComp=l.status==='Completed', isCancelled=l.status==='Cancelled';
            const zoneShort=l.zone.split('–')[0].trim();
            const isMyTask=l.techUserId===MY_ID;

            // Build action buttons per role
            let actBtns=`<button class="btn ionly" onclick="openView(${l.id})" title="View"><i class="bx bx-show"></i></button>`;

            if (ROLE_RANK >= 3) {
                // Admin / Super Admin
                if(isReported) actBtns+=`<button class="btn ionly btn-start" onclick="doAction('start',${l.id})" title="Start"><i class="bx bx-play"></i></button>`;
                if(isActive||isEsc) actBtns+=`<button class="btn ionly" onclick="openEdit(${l.id})" title="Edit"><i class="bx bx-edit"></i></button>`;
                if(isActive||isEsc) actBtns+=`<button class="btn ionly btn-complete" onclick="doAction('complete',${l.id})" title="Close Repair"><i class="bx bx-check"></i></button>`;
                if(ROLE_RANK===4 && (isComp||isActive||isEsc)) actBtns+=`<button class="btn ionly btn-override" onclick="doAction('costoverride',${l.id})" title="Cost Override"><i class="bx bx-dollar"></i></button>`;
                if(isActive) actBtns+=`<button class="btn ionly btn-escalate" onclick="doAction('escalate',${l.id})" title="Escalate"><i class="bx bx-error"></i></button>`;
                if(!isCancelled&&!isComp) actBtns+=`<button class="btn ionly btn-cancel-rs" onclick="doAction('cancel',${l.id})" title="Cancel"><i class="bx bx-x"></i></button>`;
            } else if (ROLE_RANK === 2) {
                // Manager: flag/escalate only on active zone repairs
                if(isActive) actBtns+=`<button class="btn ionly btn-flag" onclick="doAction('flag',${l.id})" title="Flag Delay"><i class="bx bx-flag"></i></button>`;
                if(isActive) actBtns+=`<button class="btn ionly btn-escalate" onclick="doAction('escalate',${l.id})" title="Escalate to Admin"><i class="bx bx-error"></i></button>`;
            } else {
                // Staff: start / progress update on own tasks
                if(isMyTask&&isReported) actBtns+=`<button class="btn ionly btn-start" onclick="doAction('start',${l.id})" title="Start Repair"><i class="bx bx-play"></i></button>`;
                if(isMyTask&&l.status==='In Progress') actBtns+=`<button class="btn ionly btn-progress" onclick="doAction('progress',${l.id})" title="Update Progress"><i class="bx bx-revision"></i></button>`;
            }

            // Build row cells per role
            let cells = '';
            if (ROLE_RANK === 4) cells+=`<td onclick="event.stopPropagation()"><div class="cb-wrap"><input type="checkbox" class="cb row-cb" data-id="${l.logId}" ${chk?'checked':''}></div></td>`;

            cells+=`<td onclick="openView(${l.id})"><span class="rs-num">${esc(l.logId)}</span></td>`;
            cells+=`<td onclick="openView(${l.id})">
                <div class="asset-cell">
                    <div class="asset-name">${esc(l.assetName)}</div>
                    <div class="asset-meta">
                        <span class="asset-id">${esc(l.assetId)}</span>
                    </div>
                </div></td>`;

            if (ROLE_RANK >= 3) {
                cells+=`<td onclick="openView(${l.id})"><span class="asset-zone" style="gap:5px"><span style="width:7px;height:7px;border-radius:50%;background:${clr};flex-shrink:0;display:inline-block"></span>${esc(zoneShort)}</span></td>`;
            }

            cells+=`<td onclick="openView(${l.id})"><span class="issue-cell">${esc(l.issue)}</span></td>`;

            if (ROLE_RANK >= 3) {
                cells+=`<td onclick="openView(${l.id})"><span class="rs-date">${fD(l.dateReported)}</span></td>`;
                cells+=`<td onclick="openView(${l.id})"><span class="asset-tech">${esc(l.technician)}</span></td>`;
                cells+=`<td onclick="openView(${l.id})"><span style="font-size:12px;color:var(--t2)">${l.provider?esc(l.provider):'<span style="color:var(--t3)">—</span>'}</span></td>`;
                cells+=`<td onclick="openView(${l.id})"><span class="rs-cost${l.costOverridden?' overridden':''}">${fM(l.repairCost)}</span></td>`;
                cells+=`<td onclick="openView(${l.id})"><span class="rs-date">${fD(l.dateCompleted)}</span></td>`;
            } else if (ROLE_RANK === 2) {
                cells+=`<td onclick="openView(${l.id})"><span class="asset-tech">${esc(l.technician)}</span></td>`;
            } else {
                cells+=`<td onclick="openView(${l.id})"><span class="rs-date">${fD(l.dateReported)}</span></td>`;
            }

            cells+=`<td onclick="openView(${l.id})">${badge(l.status)}</td>`;
            cells+=`<td onclick="event.stopPropagation()"><div class="act-cell">${actBtns}</div></td>`;

            return `<tr class="${chk?'row-selected':''}">${cells}</tr>`;
        }).join('');

        // Row checkbox wiring (SA only)
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
        <span>${total===0?'No results':`Showing ${s}–${e} of ${total} records`}</span>
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
['srch','fStatus','fZone','fProvider','fDateFrom','fDateTo'].forEach(id=>{
    const el=document.getElementById(id);
    if(el) el.addEventListener('input',()=>{page=1;renderList();});
});

// ── BULK (SA only) ────────────────────────────────────────────────────────────
function updateBulkBar(){
    if (ROLE_RANK < 4) return;
    const n=selectedIds.size;
    const bb=document.getElementById('bulkBar'); if(bb) bb.classList.toggle('on',n>0);
    const bc=document.getElementById('bulkCount'); if(bc) bc.textContent=n===1?'1 selected':`${n} selected`;
}
function syncCheckAll(slice){
    if (ROLE_RANK < 4) return;
    const ca=document.getElementById('checkAll'); if(!ca) return;
    const ids=slice.map(l=>l.logId);
    const all=ids.length>0&&ids.every(id=>selectedIds.has(id));
    ca.checked=all; ca.indeterminate=!all&&ids.some(id=>selectedIds.has(id));
}
const caEl=document.getElementById('checkAll');
if(caEl) caEl.addEventListener('change',function(){
    const slice=getSorted(getFiltered()).slice((page-1)*PAGE,page*PAGE);
    slice.forEach(l=>{if(this.checked) selectedIds.add(l.logId); else selectedIds.delete(l.logId);});
    renderList(); updateBulkBar();
});
const csEl=document.getElementById('clearSelBtn');
if(csEl) csEl.addEventListener('click',()=>{selectedIds.clear();renderList();updateBulkBar();});

const bcBtn=document.getElementById('batchCompleteBtn');
if(bcBtn) bcBtn.addEventListener('click',()=>{
    const valid=[...selectedIds].map(lid=>LOGS.find(l=>l.logId===lid)).filter(l=>l&&['Reported','In Progress','Escalated'].includes(l.status));
    if(!valid.length){toast('No active logs in selection.','w');return;}
    showActionModal('✅',`Force Complete ${valid.length} Log(s)`,`Mark <strong>${valid.length}</strong> active repair log(s) as Completed.`,true,'Super Admin force-complete across all zones.','btn-complete','<i class="bx bx-check-double"></i> Force Complete',null,
        async()=>{
            const rmk=document.getElementById('amRemarks').value.trim();
            try{
                const r=await apiPost(API+'?api=batch',{type:'batch-complete',ids:valid.map(l=>l.id),remarks:rmk});
                const updated=await apiGet(API+'?api=list'); LOGS=updated;
                selectedIds.clear(); renderList(); updateBulkBar(); renderTrends();
                toast(`${r.updated} log(s) force completed.`,'s');
            }catch(e){toast(e.message,'d');}
        }
    );
});
const beBtn=document.getElementById('batchEscalateBtn');
if(beBtn) beBtn.addEventListener('click',()=>{
    const valid=[...selectedIds].map(lid=>LOGS.find(l=>l.logId===lid)).filter(l=>l&&['Reported','In Progress'].includes(l.status));
    if(!valid.length){toast('No In Progress/Reported logs in selection.','w');return;}
    showActionModal('⚠️',`Escalate ${valid.length} Log(s)`,`Escalate <strong>${valid.length}</strong> repair log(s) for specialist review.`,true,'Super Admin batch escalation.','btn-escalate','<i class="bx bx-error"></i> Escalate',null,
        async()=>{
            const rmk=document.getElementById('amRemarks').value.trim();
            try{
                const r=await apiPost(API+'?api=batch',{type:'batch-escalate',ids:valid.map(l=>l.id),remarks:rmk});
                const updated=await apiGet(API+'?api=list'); LOGS=updated;
                selectedIds.clear(); renderList(); updateBulkBar(); renderTrends();
                toast(`${r.updated} log(s) escalated.`,'s');
            }catch(e){toast(e.message,'d');}
        }
    );
});

// ── ACTION MODAL ──────────────────────────────────────────────────────────────
function showActionModal(icon,title,body,sa,saText,btnCls,btnLabel,extraHtml,onConfirm=null){
    document.getElementById('amIcon').textContent=icon;
    document.getElementById('amTitle').textContent=title;
    document.getElementById('amBody').innerHTML=body;
    const san=document.getElementById('amSaNote');
    if(sa&&ROLE_RANK>=3){san.style.display='flex';document.getElementById('amSaText').textContent=saText;}
    else san.style.display='none';
    document.getElementById('amDynamicInputs').innerHTML=extraHtml||'';
    document.getElementById('amRemarks').value='';
    const cb=document.getElementById('amConfirm');
    cb.className=`btn btn-sm ${btnCls}`; cb.innerHTML=btnLabel;
    actionCb=onConfirm;
    document.getElementById('actionModal').classList.add('on');
}

// Per-role action configurations
function doAction(type,dbId){
    const l=LOGS.find(x=>x.id===dbId); if(!l) return;
    actionTarget=dbId; actionKey=type;
    const zs=l.zone.split('–')[0].trim();
    const body=`Log <strong>${esc(l.logId)}</strong> — <strong>${esc(l.assetName)}</strong> (${esc(zs)})`;

    const cfg={
        start:       {icon:'▶️',sa:false,saText:'',extra:'',btn:'btn-start',    label:'<i class="bx bx-play"></i> Start',               title:'Start Repair'},
        complete:    {icon:'✅',sa:true, saText:ROLE_RANK===4?'Super Admin force-complete bypasses standard sign-off.':'Admin closing this repair log.',extra:'',btn:'btn-complete',label:'<i class="bx bx-check"></i> '+(ROLE_RANK===4?'Force Complete':'Close Repair'),title:ROLE_RANK===4?'Force Complete':'Close Repair'},
        escalate:    {icon:'⚠️',sa:true, saText:ROLE_RANK>=3?'Escalation flags this log for specialist review.':'Flagging delay for Admin review.',extra:'',btn:'btn-escalate',label:'<i class="bx bx-error"></i> Escalate',title:ROLE_RANK===2?'Escalate to Admin':'Escalate Repair'},
        flag:        {icon:'🚩',sa:false,saText:'',extra:'',btn:'btn-flag',     label:'<i class="bx bx-flag"></i> Flag Delay',           title:'Flag Delay'},
        costoverride:{icon:'💲',sa:true, saText:'Super Admin authority to override repair cost on record.',
                      extra:`<div class="am-fg"><label>New Repair Cost (₱) <span style="color:var(--red)">*</span></label><input type="number" id="amCostInput" placeholder="0.00" min="0" step="0.01" value="${l.repairCost}"></div>`,
                      btn:'btn-override',label:'<i class="bx bx-dollar"></i> Apply Override',title:'Cost Override'},
        cancel:      {icon:'⛔',sa:false,saText:'',extra:'',btn:'btn-cancel-rs',label:'<i class="bx bx-x"></i> Cancel Log',             title:'Cancel Repair Log'},
        progress:    {icon:'🔄',sa:false,saText:'',extra:'',btn:'btn-progress', label:'<i class="bx bx-revision"></i> Update Progress',  title:'Update Progress'},
    };
    const c=cfg[type]; if(!c) return;
    showActionModal(c.icon,c.title,body,c.sa,c.saText,c.btn,c.label,c.extra);
}

document.getElementById('amConfirm').addEventListener('click',async()=>{
    if(actionCb){const res=await actionCb();if(res===false)return;document.getElementById('actionModal').classList.remove('on');actionCb=null;return;}
    const l=LOGS.find(x=>x.id===actionTarget); if(!l) return;
    const rmk=document.getElementById('amRemarks').value.trim();
    const payload={id:l.id,type:actionKey,remarks:rmk};
    if(actionKey==='costoverride'){
        const nv=parseFloat(document.getElementById('amCostInput')?.value);
        if(isNaN(nv)||nv<0){toast('Enter a valid cost amount.','w');return;}
        payload.newCost=nv;
    }
    try{
        const updated=await apiPost(API+'?api=action',payload);
        const idx=LOGS.findIndex(x=>x.id===updated.id);
        if(idx>-1) LOGS[idx]=updated;
        const msgs={start:'Work started.',complete:`${l.logId} closed.`,escalate:`${l.logId} escalated.`,flag:`${l.logId} flagged for delay.`,costoverride:'Cost overridden successfully.',cancel:`${l.logId} cancelled.`,progress:'Progress updated.'};
        toast(msgs[actionKey]||'Action applied.','s');
        document.getElementById('actionModal').classList.remove('on');
        renderList(); renderTrends();
        if(document.getElementById('viewModal').classList.contains('on')) renderDetail(LOGS.find(x=>x.id===actionTarget));
    }catch(e){toast(e.message,'d');}
});
document.getElementById('amCancel').addEventListener('click',()=>{document.getElementById('actionModal').classList.remove('on');actionCb=null;});
document.getElementById('actionModal').addEventListener('click',function(e){if(e.target===this){this.classList.remove('on');actionCb=null;}});

// ── VIEW MODAL ────────────────────────────────────────────────────────────────
function openView(dbId){
    const l=LOGS.find(x=>x.id===dbId); if(!l) return;
    currentViewId=dbId; renderDetail(l); setVmTab('ov');
    document.getElementById('viewModal').classList.add('on');
}
function closeView(){document.getElementById('viewModal').classList.remove('on');currentViewId=null;}
document.getElementById('vmClose').addEventListener('click',closeView);
document.getElementById('viewModal').addEventListener('click',function(e){if(e.target===this)closeView();});
document.querySelectorAll('.vm-tab').forEach(t=>t.addEventListener('click',()=>{
    const name=t.dataset.t;
    setVmTab(name);
    if(name==='esc'&&currentViewId&&ROLE_RANK>=3) loadAuditTrail(currentViewId);
    if(name==='cc'&&currentViewId&&ROLE_RANK===4)  loadCostCompare(currentViewId);
}));
function setVmTab(name){
    document.querySelectorAll('.vm-tab').forEach(t=>t.classList.toggle('active',t.dataset.t===name));
    document.querySelectorAll('.vm-tp').forEach(p=>p.classList.toggle('active',p.id==='vt-'+name));
}

function renderDetail(l){
    const clr=zoneColor(l.zone);
    const isReported=l.status==='Reported';
    const isActive=isReported||l.status==='In Progress';
    const isEsc=l.status==='Escalated', isComp=l.status==='Completed';
    const isMyTask=l.techUserId===MY_ID;
    const rmkCls=l.status==='Completed'?'vm-rmk-a':l.status==='Cancelled'?'vm-rmk-r':l.status==='Escalated'?'vm-rmk-e':'vm-rmk-n';

    document.getElementById('vmAvatar').style.background=clr;
    document.getElementById('vmAvatar').textContent=ini(l.assetName);
    document.getElementById('vmName').textContent=l.assetName;
    document.getElementById('vmMid').innerHTML=`<span style="font-family:'DM Mono',monospace">${esc(l.logId)}</span>&nbsp;·&nbsp;${esc(l.assetId)}&nbsp;${badge(l.status)}`;
    document.getElementById('vmChips').innerHTML=`
        <div class="vm-mc"><i class="bx bx-map"></i>${esc(l.zone)}</div>
        <div class="vm-mc"><i class="bx bx-calendar"></i>Reported ${fD(l.dateReported)}</div>
        ${l.dateCompleted?`<div class="vm-mc"><i class="bx bx-check-circle"></i>Completed ${fD(l.dateCompleted)}</div>`:''}
        ${ROLE_RANK>=3?`<div class="vm-mc"><i class="bx bx-money-withdraw"></i>${fM(l.repairCost)}${l.costOverridden?' <span style="color:#D97706;font-size:10px">★ Override</span>':''}</div>`:''}`;

    // Footer actions per role
    let foot='';
    if (ROLE_RANK >= 3) {
        if(isReported)    foot+=`<button class="btn btn-start btn-sm" onclick="closeView();doAction('start',${l.id})"><i class="bx bx-play"></i> Start</button>`;
        if(isActive||isEsc) foot+=`<button class="btn btn-complete btn-sm" onclick="closeView();doAction('complete',${l.id})"><i class="bx bx-check"></i> ${ROLE_RANK===4?'Force Complete':'Close'}</button>`;
        if(ROLE_RANK===4&&(isComp||isActive||isEsc)) foot+=`<button class="btn btn-override btn-sm" onclick="closeView();doAction('costoverride',${l.id})"><i class="bx bx-dollar"></i> Cost Override</button>`;
        if(isActive)       foot+=`<button class="btn btn-escalate btn-sm" onclick="closeView();doAction('escalate',${l.id})"><i class="bx bx-error"></i> Escalate</button>`;
        if(isActive||isEsc) foot+=`<button class="btn btn-ghost btn-sm" onclick="closeView();openEdit(${l.id})"><i class="bx bx-edit"></i> Edit</button>`;
        if(!isComp&&l.status!=='Cancelled') foot+=`<button class="btn btn-cancel-rs btn-sm" onclick="closeView();doAction('cancel',${l.id})"><i class="bx bx-x"></i> Cancel</button>`;
    } else if (ROLE_RANK === 2) {
        if(isActive) foot+=`<button class="btn btn-flag btn-sm" onclick="closeView();doAction('flag',${l.id})"><i class="bx bx-flag"></i> Flag Delay</button>`;
        if(isActive) foot+=`<button class="btn btn-escalate btn-sm" onclick="closeView();doAction('escalate',${l.id})"><i class="bx bx-error"></i> Escalate to Admin</button>`;
    } else {
        if(isMyTask&&isReported) foot+=`<button class="btn btn-start btn-sm" onclick="closeView();doAction('start',${l.id})"><i class="bx bx-play"></i> Start Repair</button>`;
        if(isMyTask&&l.status==='In Progress') foot+=`<button class="btn btn-progress btn-sm" onclick="closeView();doAction('progress',${l.id})"><i class="bx bx-revision"></i> Update Progress</button>`;
    }
    foot+=`<button class="btn btn-ghost btn-sm" onclick="closeView()">Close</button>`;
    document.getElementById('vmFoot').innerHTML=foot;

    // Overview tab content — filtered by role
    let statsHtml=`
        <div class="vm-sbs">
            <div class="vm-sb"><div class="sbv">${esc(l.status)}</div><div class="sbl">Status</div></div>
            ${ROLE_RANK>=3?`<div class="vm-sb"><div class="sbv mono">${fM(l.repairCost)}</div><div class="sbl">Repair Cost${l.costOverridden?' ★':''}</div></div>`:''}
            <div class="vm-sb"><div class="sbv" style="font-size:13px">${fD(l.dateReported)}</div><div class="sbl">Date Reported</div></div>
            <div class="vm-sb"><div class="sbv" style="font-size:13px">${l.dateCompleted?fD(l.dateCompleted):'Pending'}</div><div class="sbl">Date Completed</div></div>
        </div>
        <div class="vm-ig">
            <div class="vm-ii"><label>Asset ID</label><div class="v" style="font-family:'DM Mono',monospace;color:var(--primary-color)">${esc(l.assetId)}</div></div>
            <div class="vm-ii"><label>Zone</label><div class="v" style="color:${clr};font-weight:600">${esc(l.zone)}</div></div>
            <div class="vm-ii"><label>Assigned Technician</label><div class="v">${esc(l.technician)}</div></div>`;
    if (ROLE_RANK >= 3) {
        statsHtml+=`<div class="vm-ii"><label>Service Provider</label><div class="v">
            ${l.provider?`<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span>${esc(l.provider)}</span>
                ${l.supplierRating?`<span style="color:#D97706;font-size:11px">${'★'.repeat(Math.round(l.supplierRating))}${'☆'.repeat(5-Math.round(l.supplierRating))}</span>`:''}
                ${l.supplierId?`<span style="font-size:10px;font-weight:700;background:#DCFCE7;color:#166534;border-radius:5px;padding:1px 7px">PSM Linked</span>`:''}
            </div>`:'—'}
        </div></div>`;
        if (l.costOverridden) {
            statsHtml+=`<div class="vm-ii"><label>Original Cost</label><div class="v" style="color:#D97706;text-decoration:line-through">${fM(l.originalCost)}</div></div>
            <div class="vm-ii"><label>Override Cost</label><div class="v" style="color:var(--primary-color);font-weight:700">${fM(l.repairCost)}</div></div>`;
        }
    }
    statsHtml+=`<div class="vm-ii vm-full"><label>Issue Reported</label><div class="v muted">${esc(l.issue)}</div></div>`;
    if (l.remarks) statsHtml+=`<div class="vm-ii vm-full"><label>Remarks</label><div class="vm-rmk ${rmkCls}"><div class="rml">Remarks</div>${esc(l.remarks)}</div></div>`;
    if (l.saRemarks && ROLE_RANK >= 3) statsHtml+=`<div class="vm-ii vm-full"><label>SA Remarks</label><div class="vm-rmk vm-rmk-e"><div class="rml">Super Admin</div>${esc(l.saRemarks)}</div></div>`;
    statsHtml+=`</div>`;
    document.getElementById('vt-ov').innerHTML=statsHtml;

    // Audit log tab (Admin+)
    const escEl=document.getElementById('vt-esc');
    if(escEl) escEl.innerHTML=`
        <div class="vm-sa-note"><i class="bx bx-shield-quarter"></i><span>Full escalation and audit log — visible to Admin and above.</span></div>
        <div id="auditContent"><div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">Click the "Audit Log" tab to load.</div></div>`;

    // Cost comparison tab (SA only)
    const ccEl=document.getElementById('vt-cc');
    if(ccEl) ccEl.innerHTML=`
        <div class="vm-sa-note"><i class="bx bx-shield-quarter"></i><span>Cross-provider cost comparison — Super Admin view.</span></div>
        <div id="ccContent"><div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">Click the "Cost Comparison" tab to load.</div></div>`;
}

async function loadAuditTrail(dbId){
    const wrap=document.getElementById('auditContent'); if(!wrap) return;
    wrap.innerHTML='<div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">Loading…</div>';
    try{
        const rows=await apiGet(API+'?api=audit&id='+dbId);
        if(!rows.length){wrap.innerHTML=`<div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">No audit entries yet.</div>`;return;}
        wrap.innerHTML=rows.map(lg=>`
            <div class="vm-esc-item">
                <div class="vm-esc-dot ${lg.css_class||'ed-s'}"><i class="bx ${lg.icon||'bx-info-circle'}"></i></div>
                <div class="vm-esc-body">
                    <div class="eu">${esc(lg.action_label)} ${lg.is_super_admin?'<span class="sa-tag">Super Admin</span>':''}</div>
                    <div class="et"><i class="bx bx-user" style="font-size:11px"></i>${esc(lg.actor_name)} · ${esc(lg.actor_role)}
                        ${lg.ip_address?`<span class="vm-esc-ip">${esc(lg.ip_address)}</span>`:''}
                    </div>
                    ${lg.note?`<div class="vm-esc-note">"${esc(lg.note)}"</div>`:''}
                </div>
                <div class="vm-esc-ts">${lg.occurred_at?new Date(lg.occurred_at).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):''}</div>
            </div>`).join('');
    }catch(e){wrap.innerHTML=`<div style="text-align:center;color:var(--red);padding:24px;font-size:13px">Failed to load audit trail.</div>`;}
}

async function loadCostCompare(dbId){
    const wrap=document.getElementById('ccContent'); if(!wrap) return;
    wrap.innerHTML='<div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">Loading…</div>';
    try{
        const l=LOGS.find(x=>x.id===dbId); if(!l) return;
        let rows=await apiGet(API+'?api=costcompare&id='+dbId);
        if(!rows.length){wrap.innerHTML=`<div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">No cost comparison data on record.</div>`;return;}
        rows=rows.sort((a,b)=>a.estimated_cost-b.estimated_cost);
        const minCost=rows[0].estimated_cost, maxCost=rows[rows.length-1].estimated_cost;
        wrap.innerHTML=`
            <div class="vm-cost-compare">
                <div class="vm-cost-compare-hdr"><i class="bx bx-dollar-circle"></i> Provider Cost Benchmarking — ${esc(l.assetName)}</div>
                ${rows.map(r=>`
                    <div class="vm-cost-row">
                        <span class="cr-prov">${esc(r.provider)}${r.is_actual?' <span style="font-size:10px;color:#9EB0A2">(Actual)</span>':''}</span>
                        <div style="display:flex;align-items:center;gap:8px">
                            <span class="cr-amt">${fM(r.estimated_cost)}</span>
                            <span class="cr-badge ${r.estimated_cost===minCost?'cr-best':r.estimated_cost===maxCost?'cr-high':''}">${r.estimated_cost===minCost?'Lowest':r.estimated_cost===maxCost?'Highest':'Mid-range'}</span>
                        </div>
                    </div>`).join('')}
            </div>
            <div style="font-size:11.5px;color:#9EB0A2;margin-top:10px;font-style:italic">★ Savings potential vs. highest provider: ${fM(maxCost-minCost)}</div>`;
    }catch(e){wrap.innerHTML=`<div style="text-align:center;color:var(--red);padding:24px;font-size:13px">Failed to load cost comparison.</div>`;}
}

// ── SLIDER (Admin+ only) ──────────────────────────────────────────────────────
function openSlider(mode='create',l=null){
    if (ROLE_RANK < 3) return;
    editId=mode==='edit'?l.id:null;
    document.getElementById('slTitle').textContent=mode==='edit'?`Edit Log — ${l.logId}`:'Log New Repair';
    document.getElementById('slSub').textContent=mode==='edit'?'Update fields below':'Fill in all required fields below';
    if(mode==='edit'&&l){
        document.getElementById('fAssetSl').value=l.assetId;
        document.getElementById('fAssetName').value=l.assetName;
        const zEl=document.getElementById('fZoneSl');
        if(ROLE_RANK===4) zEl.value=l.zone;
        document.getElementById('fStatusSl').value=l.status;
        document.getElementById('fIssue').value=l.issue;
        document.getElementById('fDateReported').value=l.dateReported||'';
        document.getElementById('fDateCompleted').value=l.dateCompleted||'';
        const tEl=document.getElementById('fTechSl');
        tEl.value=l.techUserId||'';
        if(!tEl.value) [...tEl.options].forEach(o=>{if(o.dataset.name===l.technician) tEl.value=o.value;});
        wireSupplierSearch(l.supplierId||'', l.provider||'');
        document.getElementById('fCost').value=l.repairCost||'';
        document.getElementById('fRemarks').value=l.remarks||'';
    } else {
        document.getElementById('fAssetSl').value='';
        document.getElementById('fAssetName').value='';
        const zEl=document.getElementById('fZoneSl');
        if(ROLE_RANK===3&&MY_ZONE) zEl.value=MY_ZONE; else zEl.value='';
        document.getElementById('fStatusSl').value='Reported';
        document.getElementById('fIssue').value='';
        document.getElementById('fDateReported').value=today();
        document.getElementById('fDateCompleted').value='';
        document.getElementById('fTechSl').value='';
        wireSupplierSearch('','');
        document.getElementById('fCost').value='';
        document.getElementById('fRemarks').value='';
    }
    document.getElementById('rsSlider').classList.add('on');
    document.getElementById('slOverlay').classList.add('on');
    setTimeout(()=>document.getElementById('fAssetName').focus(),100);
}
function openEdit(dbId){ if(ROLE_RANK<3) return; const l=LOGS.find(x=>x.id===dbId); if(l) openSlider('edit',l); }
function closeSlider(){
    const sl=document.getElementById('rsSlider'); const ov=document.getElementById('slOverlay');
    if(sl) sl.classList.remove('on');
    if(ov) ov.classList.remove('on');
    editId=null;
}
const slOverEl=document.getElementById('slOverlay');
if(slOverEl) slOverEl.addEventListener('click',function(e){if(e.target===this)closeSlider();});
const slCloseEl=document.getElementById('slClose');
if(slCloseEl) slCloseEl.addEventListener('click',closeSlider);
const slCancelEl=document.getElementById('slCancel');
if(slCancelEl) slCancelEl.addEventListener('click',closeSlider);
const createBtnEl=document.getElementById('createBtn');
if(createBtnEl) createBtnEl.addEventListener('click',()=>openSlider('create'));

const slSubmitEl=document.getElementById('slSubmit');
if(slSubmitEl) slSubmitEl.addEventListener('click',async()=>{
    if (ROLE_RANK < 3) return;
    const btn=slSubmitEl; btn.disabled=true;
    try{
        const assetOpt =document.getElementById('fAssetSl');
        const assetSel =assetOpt.options[assetOpt.selectedIndex];
        const assetDbId=parseInt(assetSel?.dataset.dbid||0);
        const assetId  =assetOpt.value||'';
        const assetName=document.getElementById('fAssetName').value.trim();
        const zone     =document.getElementById('fZoneSl').value;
        const status   =document.getElementById('fStatusSl').value;
        const issue    =document.getElementById('fIssue').value.trim();
        const dr       =document.getElementById('fDateReported').value;
        const dc2      =document.getElementById('fDateCompleted').value||null;
        const techOpt  =document.getElementById('fTechSl');
        const techSel  =techOpt.options[techOpt.selectedIndex];
        const techUserId=techOpt.value||null;
        const tech     =techSel?.dataset.name||techSel?.text||'';
        const prov       =document.getElementById('fSupplierId').value
                            ? (document.getElementById('fSupplierName').value||document.getElementById('csSupplierSearch').value.trim())
                            : document.getElementById('csSupplierSearch').value.trim();
        const supplierId =parseInt(document.getElementById('fSupplierId').value)||0;
        const supplierObj=SUPPLIERS.find(s=>s.id===supplierId)||null;
        const supplierRating=supplierObj?supplierObj.rating:null;
        const cost     =parseFloat(document.getElementById('fCost').value)||0;
        const remarks  =document.getElementById('fRemarks').value.trim();

        if(!assetName){shk('fAssetName');  toast('Asset name is required.','w');return;}
        if(!zone)     {shk('fZoneSl');     toast('Please select a zone.','w');return;}
        if(!issue)    {shk('fIssue');      toast('Issue description is required.','w');return;}
        if(!dr)       {shk('fDateReported');toast('Date reported is required.','w');return;}
        if(!tech&&!techUserId){shk('fTechSl');toast('Please assign a technician.','w');return;}

        const payload={assetId,assetName,assetDbId,zone,status,issue,dateReported:dr,dateCompleted:dc2,technician:tech||assetName,techUserId,provider:prov,supplierId,supplierRating,repairCost:cost,remarks};
        if(editId) payload.id=editId;
        const saved=await apiPost(API+'?api=save',payload);
        const idx=LOGS.findIndex(x=>x.id===saved.id);
        if(idx>-1) LOGS[idx]=saved; else{LOGS.unshift(saved);page=1;}
        toast(`${saved.logId} ${editId?'updated':'logged'} successfully.`,'s');
        closeSlider(); renderList(); renderTrends();
    }catch(e){toast(e.message,'d');}
    finally{btn.disabled=false;}
});

// ── EXPORT (Admin+) ────────────────────────────────────────────────────────────
const exportBtnEl=document.getElementById('exportBtn');
if(exportBtnEl) exportBtnEl.addEventListener('click',()=>{
    if (ROLE_RANK < 3) return;
    const cols=ROLE_RANK>=3
        ? ['logId','assetId','assetName','zone','issue','dateReported','technician','provider','repairCost','dateCompleted','status']
        : ['logId','assetId','assetName','issue','dateReported','status'];
    const hdrs=ROLE_RANK>=3
        ? ['Log ID','Asset ID','Asset Name','Zone','Issue','Date Reported','Technician','Service Provider','Repair Cost','Date Completed','Status']
        : ['Log ID','Asset ID','Asset Name','Issue','Date Reported','Status'];
    const rows=[hdrs.join(','),...getFiltered().map(l=>cols.map(c=>`"${String(l[c]||'').replace(/"/g,'""')}"`).join(','))];
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
    a.download='repair_service_logs.csv'; a.click();
    toast('CSV exported successfully.','s');
});

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