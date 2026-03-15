<?php

// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE RESOLUTION (mirrors superadmin_sidebar.php) ─────────────────────────
function _ds_resolve_role(): string {
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

$roleName = _ds_resolve_role();
$roleRank = match($roleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1, // Staff / User
};

// ── PERMISSION GATES ──────────────────────────────────────────────────────────
$CAN_VIEW_ALL_ZONES    = $roleRank >= 4; // SA: all zones
$CAN_CREATE_SCHEDULE   = $roleRank >= 3; // Admin+
$CAN_RESCHEDULE        = $roleRank >= 2; // Manager+ (minor reschedule)
$CAN_FORCE_COMPLETE    = $roleRank >= 4; // SA only
$CAN_CROSS_ZONE        = $roleRank >= 4; // SA only: cross-zone reassign/tracking
$CAN_CANCEL            = $roleRank >= 3; // Admin+
$CAN_FLAG_DELAY        = $roleRank >= 2; // Manager+
$CAN_GPS_FULL          = $roleRank >= 3; // Admin+: track GPS zone deliveries
$CAN_GPS_OVERRIDE      = $roleRank >= 4; // SA only
$CAN_BATCH_DELIVER     = $roleRank >= 4; // SA only
$CAN_BATCH_REASSIGN    = $roleRank >= 4; // SA only
$CAN_EXPORT            = $roleRank >= 3; // Admin+
$CAN_AUDIT_TRAIL       = $roleRank >= 4; // SA only
$CAN_DEPART_CONFIRM    = $roleRank >= 1; // All roles (driver updates)
$CAN_UPDATE_STATUS_OWN = $roleRank >= 1; // Staff: update own delivery status
$CAN_REASSIGN_DRIVER   = $roleRank >= 2; // Manager+: reassign zone drivers

// Staff-visible statuses
$ALLOWED_STATUSES = match(true) {
    $roleRank >= 4 => ['Scheduled','In Transit','Delivered','Delayed','Cancelled','Force Completed'],
    $roleRank >= 3 => ['Scheduled','In Transit','Delivered','Delayed','Cancelled'],
    $roleRank >= 2 => ['Scheduled','In Transit','Delivered','Delayed'],
    default        => ['Scheduled','In Transit','Delivered','Delayed'],
};

$currentUser = [
    'user_id'   => $_SESSION['user_id']   ?? null,
    'full_name' => $_SESSION['full_name'] ?? ($_SESSION['name'] ?? 'Super Admin'),
    'email'     => $_SESSION['email']     ?? '',
    'zone'      => $_SESSION['zone']      ?? '',
];

// ── JS ROLE CAPABILITIES ──────────────────────────────────────────────────────
$jsRole = json_encode([
    'name'               => $roleName,
    'rank'               => $roleRank,
    'canViewAllZones'    => $CAN_VIEW_ALL_ZONES,
    'canCreateSchedule'  => $CAN_CREATE_SCHEDULE,
    'canReschedule'      => $CAN_RESCHEDULE,
    'canForceComplete'   => $CAN_FORCE_COMPLETE,
    'canCrossZone'       => $CAN_CROSS_ZONE,
    'canCancel'          => $CAN_CANCEL,
    'canFlagDelay'       => $CAN_FLAG_DELAY,
    'canGpsFull'         => $CAN_GPS_FULL,
    'canGpsOverride'     => $CAN_GPS_OVERRIDE,
    'canBatchDeliver'    => $CAN_BATCH_DELIVER,
    'canBatchReassign'   => $CAN_BATCH_REASSIGN,
    'canExport'          => $CAN_EXPORT,
    'canAuditTrail'      => $CAN_AUDIT_TRAIL,
    'canDepartConfirm'   => $CAN_DEPART_CONFIRM,
    'canUpdateStatusOwn' => $CAN_UPDATE_STATUS_OWN,
    'canReassignDriver'  => $CAN_REASSIGN_DRIVER,
    'userZone'           => $currentUser['zone'],
    'userName'           => $currentUser['full_name'],
    'allowedStatuses'    => $ALLOWED_STATUSES,
]);

// ── HELPERS ──────────────────────────────────────────────────────────────────
function ds_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function ds_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function ds_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    if ($d === null && json_last_error() !== JSON_ERROR_NONE) ds_err('Invalid JSON', 400);
    return is_array($d) ? $d : [];
}
function ds_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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
        if ($code >= 400) ds_err('Supabase request failed', 500);
        return [];
    }
    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = is_array($data) ? ($data['message'] ?? $data['hint'] ?? $res) : $res;
        ds_err('Supabase: ' . $msg, 400);
    }
    return is_array($data) ? $data : [];
}

function ds_build_delivery(array $row): array {
    $items = [];
    if (!empty($row['items'])) {
        $decoded = json_decode($row['items'], true);
        $items = is_array($decoded) ? $decoded : [$row['items']];
    }
    $gps = null;
    if (!empty($row['gps_data'])) {
        $gps = json_decode($row['gps_data'], true);
    }
    return [
        'id'           => $row['delivery_id']   ?? '',
        'dbId'         => (int)($row['id']       ?? 0),
        'supplier'     => $row['supplier']       ?? '',
        'supplierType' => $row['supplier_type']  ?? 'Supplier',
        'ref'          => $row['po_ref']         ?? '',
        'project'      => $row['project']        ?? '',
        'zone'         => $row['zone']           ?? '',
        'assignedTo'   => $row['assigned_to']    ?? '',
        'expectedDate' => $row['expected_date']  ?? '',
        'actualDate'   => $row['actual_date']    ?? '',
        'isLate'       => (bool)($row['is_late'] ?? false),
        'status'       => $row['status']         ?? 'Scheduled',
        'items'        => $items,
        'itemCount'    => count($items),
        'notes'        => $row['notes']          ?? '',
        'gps'          => $gps,
        'createdBy'    => $row['created_by']     ?? 'system',
        'createdAt'    => $row['created_at']     ?? '',
    ];
}

function ds_build_audit(array $row): array {
    return [
        'id'   => (int)($row['id']          ?? 0),
        'act'  => $row['action_label']       ?? '',
        'by'   => $row['actor_name']         ?? '',
        'role' => $row['actor_role']         ?? '',
        'ts'   => $row['occurred_at']        ?? '',
        'icon' => $row['icon']               ?? 'bx-info-circle',
        'cls'  => $row['css_class']          ?? 'ad-b',
        'note' => $row['note']               ?? '',
        'ip'   => $row['ip_address']         ?? '',
        'isSA' => (bool)($row['is_super_admin'] ?? false),
    ];
}

function ds_next_id(): string {
    $rows = ds_sb('plt_deliveries', 'GET', [
        'select' => 'delivery_id',
        'order'  => 'id.desc',
        'limit'  => '1',
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/DS-\d{4}-(\d+)/', $rows[0]['delivery_id'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return sprintf('DS-%s-%04d', date('Y'), $next);
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Super Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET delivery list ─────────────────────────────────────────────────
        if ($api === 'deliveries' && $method === 'GET') {
            $query = [
                'select' => 'id,delivery_id,supplier,supplier_type,po_ref,project,zone,assigned_to,expected_date,actual_date,is_late,status,items,gps_data,notes,created_by,created_at',
                'order'  => 'expected_date.desc,id.desc',
            ];

            // Zone scoping
            if ($roleRank <= 3 && $roleRank >= 2) {
                // Admin/Manager: own zone only
                $userZone = $currentUser['zone'] ?? '';
                if ($userZone) $query['zone'] = 'eq.' . $userZone;
            } elseif ($roleRank <= 1) {
                // Staff: only deliveries assigned to them
                $userName = $currentUser['full_name'] ?? '';
                if ($userName) $query['assigned_to'] = 'eq.' . $userName;
                else { ds_ok([]); }
            }

            $rows = ds_sb('plt_deliveries', 'GET', $query);
            ds_ok(array_map('ds_build_delivery', $rows));
        }

        // ── GET single delivery ───────────────────────────────────────────────
        if ($api === 'delivery' && $method === 'GET') {
            $id = trim($_GET['id'] ?? '');
            if (!$id) ds_err('Missing id', 400);
            $rows = ds_sb('plt_deliveries', 'GET', [
                'select'      => 'id,delivery_id,supplier,supplier_type,po_ref,project,zone,assigned_to,expected_date,actual_date,is_late,status,items,gps_data,notes,created_by,created_at',
                'delivery_id' => 'eq.' . $id,
                'limit'       => '1',
            ]);
            if (empty($rows)) ds_err('Delivery not found', 404);
            $del = $rows[0];

            // Access check
            if ($roleRank <= 3 && $roleRank >= 2) {
                $userZone = $currentUser['zone'] ?? '';
                if ($userZone && $del['zone'] !== $userZone) ds_err('Access denied', 403);
            } elseif ($roleRank <= 1) {
                $userName = $currentUser['full_name'] ?? '';
                if ($del['assigned_to'] !== $userName) ds_err('Access denied', 403);
            }

            ds_ok(ds_build_delivery($del));
        }

        // ── GET audit log ─────────────────────────────────────────────────────
        if ($api === 'audit_log' && $method === 'GET') {
            if (!$CAN_AUDIT_TRAIL) ds_err('Insufficient permissions', 403);
            $id = trim($_GET['id'] ?? '');
            if (!$id) ds_err('Missing delivery_id', 400);
            $delRows = ds_sb('plt_deliveries', 'GET', [
                'select'      => 'id',
                'delivery_id' => 'eq.' . $id,
                'limit'       => '1',
            ]);
            if (empty($delRows)) ds_err('Delivery not found', 404);
            $dbId = (int)$delRows[0]['id'];
            $rows = ds_sb('plt_delivery_audit_log', 'GET', [
                'select'      => 'id,action_label,actor_name,actor_role,occurred_at,icon,css_class,note,ip_address,is_super_admin',
                'delivery_id' => 'eq.' . $dbId,
                'order'       => 'occurred_at.asc',
            ]);
            ds_ok(array_map('ds_build_audit', $rows));
        }

        // ── POST save delivery ────────────────────────────────────────────────
        if ($api === 'save_delivery' && $method === 'POST') {
            $b = ds_body();
            $editDbId = (int)($b['dbId'] ?? 0);

            // Creating: Admin+ only
            if (!$editDbId && !$CAN_CREATE_SCHEDULE) ds_err('Insufficient permissions', 403);
            // Editing/rescheduling: Manager+ only
            if ($editDbId && !$CAN_RESCHEDULE) ds_err('Insufficient permissions', 403);

            $supplier     = trim($b['supplier']     ?? '');
            $supplierType = trim($b['supplierType'] ?? 'Supplier');
            $ref          = trim($b['ref']          ?? '');
            $project      = trim($b['project']      ?? '');
            $zone         = trim($b['zone']         ?? '');
            $assignedTo   = trim($b['assignedTo']   ?? '');
            $expectedDate = trim($b['expectedDate'] ?? '');
            $actualDate   = trim($b['actualDate']   ?? '') ?: null;
            $status       = trim($b['status']       ?? 'Scheduled');
            $items        = $b['items'] ?? [];
            $notes        = trim($b['notes'] ?? '');
            $now          = date('Y-m-d H:i:s');

            if ($supplier === '')     ds_err('Supplier name is required', 400);
            if ($ref === '')          ds_err('PO/PR reference is required', 400);
            if ($zone === '')         ds_err('Zone is required', 400);
            if ($assignedTo === '')   ds_err('Assigned to is required', 400);
            if ($expectedDate === '') ds_err('Expected date is required', 400);

            // Managers cannot change zone to another zone
            if ($roleRank <= 2) {
                $userZone = $currentUser['zone'] ?? '';
                if ($userZone && $zone !== $userZone) ds_err('Cannot assign to a different zone', 403);
            }

            $allowedStatus = ['Scheduled','In Transit','Delivered','Delayed','Cancelled','Force Completed'];
            if (!in_array($status, $allowedStatus, true)) $status = 'Scheduled';

            // Staff/Manager cannot set terminal statuses
            if ($roleRank <= 2 && in_array($status, ['Cancelled','Force Completed'], true)) {
                $status = 'Scheduled';
            }

            $isLate = $actualDate && $actualDate > $expectedDate;
            if (!is_array($items)) $items = [$items];
            $items = array_values(array_filter(array_map('trim', $items)));
            if (empty($items)) $items = ['Items TBD'];

            $payload = [
                'supplier'      => $supplier,
                'supplier_type' => $supplierType,
                'po_ref'        => $ref,
                'project'       => $project,
                'zone'          => $zone,
                'assigned_to'   => $assignedTo,
                'expected_date' => $expectedDate,
                'actual_date'   => $actualDate,
                'is_late'       => $isLate,
                'status'        => $status,
                'items'         => json_encode($items),
                'notes'         => $notes,
                'updated_at'    => $now,
            ];

            $actorRole = match(true) {
                $roleRank >= 4 => 'Super Admin',
                $roleRank >= 3 => 'Admin',
                $roleRank >= 2 => 'Manager',
                default        => 'Staff',
            };

            if ($editDbId) {
                ds_sb('plt_deliveries', 'PATCH', ['id' => 'eq.' . $editDbId], $payload);
                ds_sb('plt_delivery_audit_log', 'POST', [], [[
                    'delivery_id'    => $editDbId,
                    'action_label'   => 'Delivery Updated',
                    'actor_name'     => $actor,
                    'actor_role'     => $actorRole,
                    'note'           => "Expected: {$expectedDate}." . ($notes ? " {$notes}" : ''),
                    'icon'           => 'bx-calendar-edit',
                    'css_class'      => 'ad-s',
                    'is_super_admin' => $roleRank >= 4,
                    'ip_address'     => $ip,
                    'occurred_at'    => $now,
                ]]);
                $rows = ds_sb('plt_deliveries', 'GET', [
                    'select' => 'id,delivery_id,supplier,supplier_type,po_ref,project,zone,assigned_to,expected_date,actual_date,is_late,status,items,gps_data,notes,created_by,created_at',
                    'id'     => 'eq.' . $editDbId,
                    'limit'  => '1',
                ]);
                ds_ok(ds_build_delivery($rows[0]));
            }

            // CREATE
            $payload['delivery_id']     = ds_next_id();
            $payload['created_by']      = $actor;
            $payload['created_user_id'] = $_SESSION['user_id'] ?? null;
            $payload['created_at']      = $now;

            $inserted = ds_sb('plt_deliveries', 'POST', [], [$payload]);
            if (empty($inserted)) ds_err('Failed to create delivery', 500);
            $newDbId = (int)$inserted[0]['id'];

            ds_sb('plt_delivery_audit_log', 'POST', [], [[
                'delivery_id'    => $newDbId,
                'action_label'   => 'Delivery Scheduled',
                'actor_name'     => $actor,
                'actor_role'     => $actorRole,
                'note'           => "Supplier: {$supplier}. Zone: {$zone}.",
                'icon'           => 'bx-calendar-plus',
                'css_class'      => 'ad-c',
                'is_super_admin' => $roleRank >= 4,
                'ip_address'     => $ip,
                'occurred_at'    => $now,
            ]]);
            ds_sb('plt_delivery_audit_log', 'POST', [], [[
                'delivery_id'    => $newDbId,
                'action_label'   => 'Assigned to Driver',
                'actor_name'     => $assignedTo,
                'actor_role'     => 'Logistics Staff',
                'note'           => "Assigned to {$assignedTo}.",
                'icon'           => 'bx-user-check',
                'css_class'      => 'ad-s',
                'is_super_admin' => false,
                'ip_address'     => $ip,
                'occurred_at'    => $now,
            ]]);

            $rows = ds_sb('plt_deliveries', 'GET', [
                'select' => 'id,delivery_id,supplier,supplier_type,po_ref,project,zone,assigned_to,expected_date,actual_date,is_late,status,items,gps_data,notes,created_by,created_at',
                'id'     => 'eq.' . $newDbId,
                'limit'  => '1',
            ]);
            ds_ok(ds_build_delivery($rows[0]));
        }

        // ── POST delivery action ───────────────────────────────────────────────
        if ($api === 'delivery_action' && $method === 'POST') {
            $b       = ds_body();
            $id      = trim($b['id']      ?? '');
            $type    = trim($b['type']    ?? '');
            $remarks = trim($b['remarks'] ?? '');
            $now     = date('Y-m-d H:i:s');

            if (!$id)   ds_err('Missing id', 400);
            if (!$type) ds_err('Missing action type', 400);

            // Permission gates per action type
            $actionGates = [
                'deliver'       => $CAN_FORCE_COMPLETE,
                'cancel'        => $CAN_CANCEL,
                'reassign'      => $CAN_CROSS_ZONE,
                'reassign_driver' => $CAN_REASSIGN_DRIVER,
                'depart'        => $CAN_DEPART_CONFIRM,
                'delay'         => $CAN_FLAG_DELAY,
                'status_update' => $CAN_UPDATE_STATUS_OWN,
            ];
            if (isset($actionGates[$type]) && !$actionGates[$type]) {
                ds_err('Insufficient permissions for this action', 403);
            }

            $delRows = ds_sb('plt_deliveries', 'GET', [
                'select'      => 'id,delivery_id,supplier,zone,status,assigned_to',
                'delivery_id' => 'eq.' . $id,
                'limit'       => '1',
            ]);
            if (empty($delRows)) ds_err('Delivery not found', 404);
            $del  = $delRows[0];
            $dbId = (int)$del['id'];

            // Zone/ownership scoping
            if ($roleRank <= 3 && $roleRank >= 2) {
                $userZone = $currentUser['zone'] ?? '';
                if ($userZone && $del['zone'] !== $userZone) ds_err('Access denied to this delivery', 403);
            }
            if ($roleRank <= 1) {
                $userName = $currentUser['full_name'] ?? '';
                if ($del['assigned_to'] !== $userName) ds_err('Access denied', 403);
            }

            $patch      = ['updated_at' => $now];
            $auditEntry = null;
            $actorRole  = match(true) {
                $roleRank >= 4 => 'Super Admin',
                $roleRank >= 3 => 'Admin',
                $roleRank >= 2 => 'Manager',
                default        => 'Logistics Staff',
            };

            switch ($type) {
                case 'deliver':
                    $patch['status']      = 'Force Completed';
                    $patch['actual_date'] = date('Y-m-d');
                    $auditEntry = [
                        'action_label'   => 'Force Marked Delivered',
                        'actor_name'     => $actor,
                        'actor_role'     => 'Super Admin',
                        'note'           => $remarks ?: 'Super Admin override — marked as Force Completed without driver confirmation.',
                        'icon'           => 'bx-check-shield',
                        'css_class'      => 'ad-a',
                        'is_super_admin' => true,
                    ];
                    break;

                case 'cancel':
                    $patch['status'] = 'Cancelled';
                    $auditEntry = [
                        'action_label'   => 'Delivery Cancelled',
                        'actor_name'     => $actor,
                        'actor_role'     => $actorRole,
                        'note'           => $remarks ?: 'Delivery cancelled.',
                        'icon'           => 'bx-x-circle',
                        'css_class'      => 'ad-r',
                        'is_super_admin' => $roleRank >= 4,
                    ];
                    break;

                case 'depart':
                    $patch['status'] = 'In Transit';
                    $auditEntry = [
                        'action_label'   => 'Departure Confirmed',
                        'actor_name'     => $del['assigned_to'] ?? $actor,
                        'actor_role'     => 'Logistics Staff',
                        'note'           => $remarks ?: 'Vehicle departed from supplier warehouse.',
                        'icon'           => 'bx-trip',
                        'css_class'      => 'ad-a',
                        'is_super_admin' => false,
                    ];
                    break;

                case 'reassign':
                    // SA: cross-zone reassign
                    $newZone = trim($b['newZone'] ?? '');
                    if (!$newZone) ds_err('New zone is required for reassign', 400);
                    $oldZone       = $del['zone'];
                    $patch['zone'] = $newZone;
                    $auditEntry = [
                        'action_label'   => "Cross-Zone Reassigned: {$oldZone} → {$newZone}",
                        'actor_name'     => $actor,
                        'actor_role'     => 'Super Admin',
                        'note'           => $remarks ?: '',
                        'icon'           => 'bx-transfer-alt',
                        'css_class'      => 'ad-o',
                        'is_super_admin' => true,
                    ];
                    break;

                case 'reassign_driver':
                    // Manager+: reassign within own zone
                    $newDriver = trim($b['newDriver'] ?? '');
                    if (!$newDriver) ds_err('New driver name is required', 400);
                    $oldDriver           = $del['assigned_to'];
                    $patch['assigned_to'] = $newDriver;
                    $auditEntry = [
                        'action_label'   => "Driver Reassigned: {$oldDriver} → {$newDriver}",
                        'actor_name'     => $actor,
                        'actor_role'     => $actorRole,
                        'note'           => $remarks ?: '',
                        'icon'           => 'bx-user-check',
                        'css_class'      => 'ad-s',
                        'is_super_admin' => false,
                    ];
                    break;

                case 'delay':
                    $patch['status'] = 'Delayed';
                    $auditEntry = [
                        'action_label'   => 'Delay Flagged',
                        'actor_name'     => $actor,
                        'actor_role'     => $actorRole,
                        'note'           => $remarks ?: 'Expected delivery not met.',
                        'icon'           => 'bx-alarm-exclamation',
                        'css_class'      => 'ad-r',
                        'is_super_admin' => $roleRank >= 4,
                    ];
                    break;

                case 'status_update':
                    // Staff: can only transition In Transit → Delivered
                    $newStatus = trim($b['newStatus'] ?? '');
                    if (!in_array($newStatus, ['In Transit','Delivered'], true)) ds_err('Invalid status transition', 400);
                    if ($del['status'] !== 'In Transit' && $newStatus === 'Delivered') ds_err('Can only mark delivered when In Transit', 400);
                    $patch['status'] = $newStatus;
                    if ($newStatus === 'Delivered') $patch['actual_date'] = date('Y-m-d');
                    $auditEntry = [
                        'action_label'   => "Status Updated to {$newStatus}",
                        'actor_name'     => $actor,
                        'actor_role'     => 'Logistics Staff',
                        'note'           => $remarks ?: '',
                        'icon'           => $newStatus === 'Delivered' ? 'bx-check-circle' : 'bx-trip',
                        'css_class'      => $newStatus === 'Delivered' ? 'ad-a' : 'ad-s',
                        'is_super_admin' => false,
                    ];
                    break;

                default:
                    ds_err('Unsupported action type', 400);
            }

            ds_sb('plt_deliveries', 'PATCH', ['id' => 'eq.' . $dbId], $patch);
            if ($auditEntry) {
                $auditEntry['delivery_id'] = $dbId;
                $auditEntry['ip_address']  = $ip;
                $auditEntry['occurred_at'] = $now;
                ds_sb('plt_delivery_audit_log', 'POST', [], [$auditEntry]);
            }

            $rows = ds_sb('plt_deliveries', 'GET', [
                'select' => 'id,delivery_id,supplier,supplier_type,po_ref,project,zone,assigned_to,expected_date,actual_date,is_late,status,items,gps_data,notes,created_by,created_at',
                'id'     => 'eq.' . $dbId,
                'limit'  => '1',
            ]);
            ds_ok(ds_build_delivery($rows[0]));
        }

        // ── POST batch action — SA only ───────────────────────────────────────
        if ($api === 'batch_action' && $method === 'POST') {
            $b       = ds_body();
            $ids     = $b['ids']     ?? [];
            $type    = trim($b['type']    ?? '');
            $remarks = trim($b['remarks'] ?? '');
            $newZone = trim($b['newZone'] ?? '');
            $now     = date('Y-m-d H:i:s');

            if (empty($ids))  ds_err('No IDs provided', 400);
            if (!$type)       ds_err('Missing action type', 400);

            if ($type === 'batch-deliver'  && !$CAN_BATCH_DELIVER)  ds_err('Insufficient permissions', 403);
            if ($type === 'batch-reassign' && !$CAN_BATCH_REASSIGN) ds_err('Insufficient permissions', 403);

            if (!is_array($ids)) $ids = [$ids];
            $updated = [];

            foreach ($ids as $deliveryId) {
                $delRows = ds_sb('plt_deliveries', 'GET', [
                    'select'      => 'id,delivery_id,zone,status,assigned_to',
                    'delivery_id' => 'eq.' . $deliveryId,
                    'limit'       => '1',
                ]);
                if (empty($delRows)) continue;
                $del   = $delRows[0];
                $dbId  = (int)$del['id'];
                $patch = ['updated_at' => $now];
                $auditEntry = null;

                if ($type === 'batch-deliver') {
                    if (in_array($del['status'], ['Delivered','Force Completed','Cancelled'], true)) continue;
                    $patch['status']      = 'Force Completed';
                    $patch['actual_date'] = date('Y-m-d');
                    $auditEntry = [
                        'delivery_id'    => $dbId,
                        'action_label'   => 'Force Marked Delivered',
                        'actor_name'     => $actor,
                        'actor_role'     => 'Super Admin',
                        'note'           => $remarks ?: 'Batch override — Super Admin force-marked as delivered.',
                        'icon'           => 'bx-check-shield',
                        'css_class'      => 'ad-a',
                        'is_super_admin' => true,
                        'ip_address'     => $ip,
                        'occurred_at'    => $now,
                    ];
                } elseif ($type === 'batch-reassign') {
                    if (!$newZone) ds_err('New zone is required for batch-reassign', 400);
                    if (in_array($del['status'], ['Delivered','Force Completed','Cancelled'], true)) continue;
                    $oldZone       = $del['zone'];
                    $patch['zone'] = $newZone;
                    $auditEntry = [
                        'delivery_id'    => $dbId,
                        'action_label'   => "Cross-Zone Reassigned: {$oldZone} → {$newZone}",
                        'actor_name'     => $actor,
                        'actor_role'     => 'Super Admin',
                        'note'           => $remarks ?: '',
                        'icon'           => 'bx-transfer-alt',
                        'css_class'      => 'ad-o',
                        'is_super_admin' => true,
                        'ip_address'     => $ip,
                        'occurred_at'    => $now,
                    ];
                } else {
                    ds_err('Unsupported batch action type', 400);
                }

                ds_sb('plt_deliveries', 'PATCH', ['id' => 'eq.' . $dbId], $patch);
                if ($auditEntry) ds_sb('plt_delivery_audit_log', 'POST', [], [$auditEntry]);
                $refreshed = ds_sb('plt_deliveries', 'GET', [
                    'select' => 'id,delivery_id,supplier,supplier_type,po_ref,project,zone,assigned_to,expected_date,actual_date,is_late,status,items,gps_data,notes,created_by,created_at',
                    'id'     => 'eq.' . $dbId,
                    'limit'  => '1',
                ]);
                if (!empty($refreshed)) $updated[] = ds_build_delivery($refreshed[0]);
            }

            ds_ok(['updated' => $updated, 'count' => count($updated)]);
        }

        // ── GET suppliers ─────────────────────────────────────────────────────
        if ($api === 'suppliers' && $method === 'GET') {
            $rows = ds_sb('psm_suppliers', 'GET', [
                'select' => 'id,name,category',
                'status' => 'eq.Active',
                'order'  => 'name.asc'
            ]);
            ds_ok($rows);
        }

        // ── GET zones (SWS) ───────────────────────────────────────────────────
        if ($api === 'zones' && $method === 'GET') {
            $rows = ds_sb('sws_zones', 'GET', [
                'select' => 'id,name,color',
                'order'  => 'name.asc'
            ]);
            ds_ok($rows);
        }

        // ── GET projects (PLT) ───────────────────────────────────────────────
        if ($api === 'projects' && $method === 'GET') {
            $rows = ds_sb('plt_projects', 'GET', [
                'select' => 'project_id,name',
                'status' => 'in.(Planning,Active,On Hold)',
                'order'  => 'name.asc'
            ]);
            ds_ok($rows);
        }

        // ── GET users ─────────────────────────────────────────────────────
        if ($api === 'users' && $method === 'GET') {
            $rows = ds_sb('users', 'GET', [
                'select' => 'user_id,first_name,last_name',
                'status' => 'eq.Active',
                'order'  => 'last_name.asc,first_name.asc'
            ]);
            // Build full_name array for the frontend
            $names = array_map(fn($u) => [
                'id'        => $u['user_id'],
                'full_name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))
            ], $rows);
            ds_ok($names);
        }

        // ── GET purchase orders ───────────────────────────────────────────────
        if ($api === 'purchase_orders' && $method === 'GET') {
            $rows = ds_sb('psm_purchase_orders', 'GET', [
                'select' => 'id,po_number,pr_reference,supplier_name,supplier_category,total_amount',
                'status' => 'in.(Confirmed,Partially Fulfilled)',
                'order'  => 'po_number.desc'
            ]);
            ds_ok($rows);
        }

        // ── GET purchase requests ────────────────────────────────────────────
        if ($api === 'purchase_requests' && $method === 'GET') {
            $rows = ds_sb('psm_purchase_requests', 'GET', [
                'select' => 'id,pr_number,requestor_name,department,total_amount,purpose',
                'status' => 'eq.Approved',
                'order'  => 'pr_number.desc'
            ]);
            ds_ok($rows);
        }

        // ── GET ref details ──────────────────────────────────────────────────
        if ($api === 'ref_details' && $method === 'GET') {
            $type = $_GET['type'] ?? ''; 
            $ref  = $_GET['ref']  ?? '';
            if (!$type || !$ref) ds_err('Missing type or ref', 400);
            
            if ($type === 'po') {
                $poArr = ds_sb('psm_purchase_orders', 'GET', ['po_number' => 'eq.' . $ref, 'limit' => 1]);
                if (empty($poArr)) ds_err('PO not found', 404);
                $poId = $poArr[0]['id'];
                $items = ds_sb('psm_po_items', 'GET', ['po_id' => 'eq.' . $poId, 'order' => 'line_no.asc']);
                ds_ok(['items' => $items]);
            } else {
                $prArr = ds_sb('psm_purchase_requests', 'GET', ['pr_number' => 'eq.' . $ref, 'limit' => 1]);
                if (empty($prArr)) ds_err('PR not found', 404);
                $prId = $prArr[0]['id'];
                $items = ds_sb('psm_pr_items', 'GET', ['pr_id' => 'eq.' . $prId, 'order' => 'line_no.asc']);
                ds_ok(['items' => $items]);
            }
        }


        // ── POST update GPS — Admin+ ───────────────────────────────────────────
        if ($api === 'update_gps' && $method === 'POST') {
            if (!$CAN_GPS_FULL) ds_err('Insufficient permissions', 403);
            $b   = ds_body();
            $id  = trim($b['id']  ?? '');
            $lat = trim($b['lat'] ?? '');
            $lng = trim($b['lng'] ?? '');
            $loc = trim($b['loc'] ?? '');
            $now = date('Y-m-d H:i:s');

            if (!$id)  ds_err('Missing delivery id', 400);
            if (!$lat) ds_err('Latitude is required', 400);
            if (!$lng) ds_err('Longitude is required', 400);

            $delRows = ds_sb('plt_deliveries', 'GET', [
                'select'      => 'id',
                'delivery_id' => 'eq.' . $id,
                'limit'       => '1',
            ]);
            if (empty($delRows)) ds_err('Delivery not found', 404);
            $dbId = (int)$delRows[0]['id'];

            ds_sb('plt_deliveries', 'PATCH', ['id' => 'eq.' . $dbId], [
                'gps_data'   => json_encode(['lat' => $lat, 'lng' => $lng, 'loc' => $loc]),
                'updated_at' => $now,
            ]);

            $rows = ds_sb('plt_deliveries', 'GET', [
                'select' => 'id,delivery_id,supplier,supplier_type,po_ref,project,zone,assigned_to,expected_date,actual_date,is_late,status,items,gps_data,notes,created_by,created_at',
                'id'     => 'eq.' . $dbId,
                'limit'  => '1',
            ]);
            ds_ok(ds_build_delivery($rows[0]));
        }

        ds_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        ds_err('Server error: ' . $e->getMessage(), 500);
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
<title>Delivery Schedule — PLT</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
:root{
  --primary-color:#2E7D32;--primary-dark:#1B5E20;
  --text-primary:#1A2E1C;--text-secondary:#5D6F62;
  --bg-color:#F5F7F5;--hover-bg-light:#EEF5EE;
  --transition:all .18s ease;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg-color);color:var(--text-primary);}
.main-content{padding:0;}

#mainContent,#dsSlider,#slOverlay,#actionModal,#viewModal,.ds-toasts{
  --s:#fff;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);
  --t1:var(--text-primary);--t2:var(--text-secondary);--t3:#9EB0A2;
  --hbg:var(--hover-bg-light);--bg:var(--bg-color);
  --grn:var(--primary-color);--gdk:var(--primary-dark);
  --red:#DC2626;--amb:#D97706;--blu:#2563EB;--tel:#0D9488;
  --shmd:0 4px 20px rgba(46,125,50,.12);--shlg:0 24px 60px rgba(0,0,0,.22);
  --rad:12px;--tr:var(--transition);
}
#mainContent *,#dsSlider *,#slOverlay *,#actionModal *,#viewModal *,.ds-toasts *{box-sizing:border-box;}

/* ── ACCESS BANNER ──────────────────────────────────────── */
.access-banner{display:flex;align-items:flex-start;gap:10px;padding:10px 16px;border-radius:10px;font-size:12px;margin-bottom:16px;animation:UP .4s both}
.ab-info{background:#EFF6FF;border:1px solid #BFDBFE;color:var(--blu)}
.ab-warn{background:#FEF3C7;border:1px solid #FDE68A;color:var(--amb)}
.ab-staff{background:#F3F4F6;border:1px solid #E5E7EB;color:#374151}
.access-banner i{font-size:16px;flex-shrink:0;margin-top:1px}

.ds-wrap{max-width:1440px;margin:0 auto;padding:24px 28px 3rem;}
.ds-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:26px;animation:UP .4s both;}
.ds-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px;}
.ds-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15;}
.ds-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.32);}
.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}
.btn-ghost:hover{background:var(--hbg);color:var(--t1);}
.btn-approve{background:#DCFCE7;color:#166534;border:1px solid #BBF7D0;}
.btn-approve:hover{background:#BBF7D0;}
.btn-reject{background:#FEE2E2;color:var(--red);border:1px solid #FECACA;}
.btn-reject:hover{background:#FCA5A5;}
.btn-override{background:#EFF6FF;color:var(--blu);border:1px solid #BFDBFE;}
.btn-override:hover{background:#DBEAFE;}
.btn-warn{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;}
.btn-warn:hover{background:#FDE68A;}
.btn-orange{background:var(--amb);color:#fff;}
.btn-orange:hover{background:#B45309;transform:translateY(-1px);}
.btn-sm{font-size:12px;padding:6px 13px;}
.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px;}
.btn.ionly{width:26px;height:26px;padding:0;justify-content:center;font-size:14px;flex-shrink:0;border-radius:6px;}
.btn:disabled{opacity:.45;pointer-events:none;}
.view-toggle{display:flex;background:var(--s);border:1px solid var(--bdm);border-radius:10px;overflow:hidden;}
.vt-btn{display:flex;align-items:center;gap:6px;padding:8px 14px;font-size:12.5px;font-weight:600;color:var(--t2);cursor:pointer;border:none;background:transparent;font-family:'Inter',sans-serif;transition:var(--tr);}
.vt-btn:hover{background:var(--hbg);color:var(--t1);}
.vt-btn.active{background:var(--grn);color:#fff;}
.vt-btn i{font-size:15px;}
.ds-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px;margin-bottom:22px;animation:UP .4s .05s both;}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:14px 16px;box-shadow:0 1px 4px rgba(46,125,50,.07);display:flex;align-items:center;gap:12px;}
.sc-ic{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px;}
.ic-b{background:#EFF6FF;color:var(--blu)}.ic-a{background:#FEF3C7;color:var(--amb)}
.ic-g{background:#DCFCE7;color:#166534}.ic-r{background:#FEE2E2;color:var(--red)}
.ic-t{background:#CCFBF1;color:var(--tel)}.ic-p{background:#F5F3FF;color:#6D28D9}
.ic-d{background:#F3F4F6;color:#374151}.ic-o{background:#FFF7ED;color:#C2410C}
.sc-v{font-size:22px;font-weight:800;color:var(--t1);line-height:1;}
.sc-l{font-size:11px;color:var(--t2);margin-top:2px;}
.ds-tb{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px;animation:UP .4s .1s both;}
.sw{position:relative;flex:1;min-width:220px;}
.sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--t3);pointer-events:none;}
.si{width:100%;padding:9px 11px 9px 36px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);}
.si:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}
.si::placeholder{color:var(--t3);}
.sel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 28px 9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;}
.sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}
.date-range-wrap{display:flex;align-items:center;gap:6px;}
.date-range-wrap span{font-size:12px;color:var(--t3);font-weight:500;}
.fi-date{font-family:'Inter',sans-serif;font-size:13px;padding:9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);}
.fi-date:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}
.bulk-bar{display:none;align-items:center;gap:10px;padding:10px 16px;background:linear-gradient(135deg,#F0FDF4,#DCFCE7);border:1px solid rgba(46,125,50,.22);border-radius:12px;margin-bottom:14px;flex-wrap:wrap;animation:UP .25s both;}
.bulk-bar.on{display:flex;}
.bulk-count{font-size:13px;font-weight:700;color:#166534;}
.bulk-sep{width:1px;height:22px;background:rgba(46,125,50,.25);}
.sa-exclusive{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;border-radius:6px;padding:2px 7px;}
.sa-exclusive i{font-size:11px;}
.ds-card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);animation:UP .4s .13s both;}
.ds-tbl{width:100%;border-collapse:collapse;font-size:12.5px;table-layout:fixed;}
.ds-tbl thead th{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:10px 10px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;cursor:pointer;user-select:none;overflow:hidden;}
.ds-tbl thead th.no-sort{cursor:default;}
.ds-tbl thead th:hover:not(.no-sort){color:var(--grn);}
.ds-tbl thead th.sorted{color:var(--grn);}
.ds-tbl thead th .sic{margin-left:3px;opacity:.4;font-size:12px;vertical-align:middle;}
.ds-tbl thead th.sorted .sic{opacity:1;}
.ds-tbl col.col-cb   {width:36px;}
.ds-tbl col.col-id   {width:130px;}
.ds-tbl col.col-sup  {width:150px;}
.ds-tbl col.col-ref  {width:120px;}
.ds-tbl col.col-items{width:80px;}
.ds-tbl col.col-exp  {width:110px;}
.ds-tbl col.col-act  {width:110px;}
.ds-tbl col.col-asgn {width:130px;}
.ds-tbl col.col-gps  {width:100px;}
.ds-tbl col.col-zone {width:110px;}
.ds-tbl col.col-stat {width:130px;}
.ds-tbl col.col-actn {width:200px;}
.ds-tbl thead th:first-child,.ds-tbl tbody td:first-child{padding-left:12px;padding-right:4px;}
.ds-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .13s;}
.ds-tbl tbody tr:last-child{border-bottom:none;}
.ds-tbl tbody tr:hover{background:var(--hbg);}
.ds-tbl tbody tr.row-selected{background:#F0FDF4;}
.ds-tbl tbody td{padding:11px 10px;vertical-align:middle;cursor:pointer;max-width:0;overflow:hidden;text-overflow:ellipsis;}
.ds-tbl tbody td:first-child{cursor:default;}
.ds-tbl tbody td:last-child{white-space:nowrap;cursor:default;overflow:visible;padding:9px 8px;max-width:none;}
.del-id{font-family:'DM Mono',monospace;font-size:11.5px;font-weight:600;color:var(--t1);white-space:nowrap;}
.sup-cell{display:flex;flex-direction:column;gap:1px;}
.sup-name{font-weight:700;font-size:12.5px;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sup-type{font-size:10.5px;color:var(--t3);}
.ref-tag{font-family:'DM Mono',monospace;font-size:11px;color:var(--t2);}
.date-cell{font-size:11.5px;color:var(--t2);white-space:nowrap;line-height:1.5;}
.date-actual{font-size:11px;color:var(--t3);}
.date-actual.late{color:var(--red);font-weight:600;}
.asgn-cell{display:flex;align-items:center;gap:6px;}
.asgn-av{width:24px;height:24px;border-radius:50%;font-size:9px;font-weight:700;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.asgn-name{font-size:12px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.zone-dot{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;}
.items-pill{display:inline-flex;align-items:center;justify-content:center;width:26px;height:22px;background:var(--bg);border:1px solid var(--bdm);border-radius:6px;font-size:11.5px;font-weight:700;color:var(--t1);}
.gps-link{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:600;color:var(--blu);cursor:pointer;padding:3px 7px;background:#EFF6FF;border-radius:6px;border:1px solid #BFDBFE;transition:var(--tr);}
.gps-link:hover{background:#DBEAFE;}
.gps-na{font-size:11px;color:var(--t3);}
.cb-wrap{display:flex;align-items:center;justify-content:center;}
input[type=checkbox].cb{width:15px;height:15px;accent-color:var(--grn);cursor:pointer;}
.act-cell{display:flex;gap:4px;align-items:center;}
.my-delivery-badge{display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:700;background:#EDE9FE;color:#6D28D9;border-radius:5px;padding:2px 7px;}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;}
.b-scheduled    {background:#EFF6FF;color:#1D4ED8;}
.b-intransit    {background:#FEF3C7;color:#92400E;}
.b-delivered    {background:#DCFCE7;color:#166534;}
.b-delayed      {background:#FEE2E2;color:#991B1B;}
.b-cancelled    {background:#F3F4F6;color:#374151;}
.b-forcecompleted{background:#F0FDF4;color:#15803D;}
.ds-pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2);}
.pg-btns{display:flex;gap:5px;}
.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1);}
.pgb:hover{background:var(--hbg);border-color:var(--grn);color:var(--grn);}
.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff;}
.pgb:disabled{opacity:.4;pointer-events:none;}
.empty{padding:72px 20px;text-align:center;color:var(--t3);}
.empty i{font-size:54px;display:block;margin-bottom:14px;color:#C8E6C9;}
#calView{display:none;}
#calView.on{display:block;animation:UP .3s both;}
.cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.cal-nav-title{font-size:18px;font-weight:800;color:var(--t1);}
.cal-nav-btns{display:flex;gap:8px;}
.cal-header{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:4px;}
.cal-header .ch{text-align:center;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:6px 0;}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;}
.cal-cell{min-height:90px;background:var(--s);border:1px solid var(--bd);border-radius:8px;padding:6px;cursor:pointer;transition:var(--tr);}
.cal-cell:hover{background:var(--hbg);border-color:rgba(46,125,50,.3);}
.cal-cell.other-month{background:#FAFAFA;opacity:.5;}
.cal-cell.today{border-color:var(--grn);border-width:2px;}
.cal-day{font-size:12px;font-weight:700;color:var(--t2);margin-bottom:4px;display:flex;align-items:center;justify-content:space-between;}
.cal-cell.today .cal-day{color:var(--grn);}
.cal-today-dot{width:18px;height:18px;border-radius:50%;background:var(--grn);color:#fff;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;}
.cal-event{border-radius:4px;padding:2px 6px;font-size:10px;font-weight:700;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;transition:var(--tr);}
.cal-event:hover{filter:brightness(.92);}
.ce-scheduled{background:#DBEAFE;color:#1D4ED8;}.ce-intransit{background:#FEF3C7;color:#92400E;}
.ce-delivered{background:#DCFCE7;color:#166534;}.ce-delayed{background:#FEE2E2;color:#991B1B;}
.ce-cancelled{background:#F3F4F6;color:#374151;}.ce-forcecompleted{background:#BBF7D0;color:#14532D;}
.cal-more{font-size:10px;color:var(--grn);font-weight:700;cursor:pointer;padding:1px 4px;}
.cal-legend{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px;}
.leg-item{display:flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;color:var(--t2);}
.leg-dot{width:10px;height:10px;border-radius:3px;}
#viewModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9050;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;}
#viewModal.on{opacity:1;pointer-events:all;}
.vm-box{background:#fff;border-radius:20px;width:780px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden;}
.vm-mhd{padding:24px 28px 0;border-bottom:1px solid rgba(46,125,50,.14);background:var(--bg-color);flex-shrink:0;}
.vm-mtp{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;}
.vm-msi{display:flex;align-items:center;gap:14px;}
.vm-mav{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:#fff;flex-shrink:0;}
.vm-mnm{font-size:18px;font-weight:800;color:var(--text-primary);display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.vm-mid{font-family:'DM Mono',monospace;font-size:12px;color:var(--text-secondary);margin-top:3px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.vm-mcl{width:34px;height:34px;border-radius:8px;border:1px solid rgba(46,125,50,.22);background:#fff;cursor:pointer;display:grid;place-content:center;font-size:19px;color:var(--text-secondary);transition:all .15s;flex-shrink:0;}
.vm-mcl:hover{background:#FEE2E2;color:#DC2626;border-color:#FECACA;}
.vm-mmt{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px;}
.vm-mc{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--text-secondary);background:#fff;border:1px solid rgba(46,125,50,.14);border-radius:8px;padding:4px 9px;line-height:1;}
.vm-mc i{font-size:13px;color:var(--primary-color);}
.vm-mtb{display:flex;gap:4px;}
.vm-tab{font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:7px 14px;border-radius:8px 8px 0 0;cursor:pointer;transition:all .15s;color:var(--text-secondary);border:none;background:transparent;display:flex;align-items:center;gap:6px;}
.vm-tab:hover{background:var(--hover-bg-light);color:var(--text-primary);}
.vm-tab.active{background:var(--primary-color);color:#fff;}
.vm-tab i{font-size:14px;}
.vm-mbd{flex:1;overflow-y:auto;padding:22px 28px;background:#fff;}
.vm-mbd::-webkit-scrollbar{width:4px;}
.vm-mbd::-webkit-scrollbar-thumb{background:rgba(46,125,50,.22);border-radius:4px;}
.vm-tp{display:none;flex-direction:column;gap:16px;}
.vm-tp.active{display:flex;}
.vm-ig{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
.vm-ii label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9EB0A2;display:block;margin-bottom:4px;}
.vm-ii .v{font-size:13px;font-weight:500;color:var(--text-primary);line-height:1.5;}
.vm-ii .v.muted{font-weight:400;color:#4B5563;}
.vm-ii .v.mono{font-family:'DM Mono',monospace;font-size:12px;}
.vm-full{grid-column:1/-1;}
.vm-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400E;}
.vm-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px;}
.vm-audit-item{display:flex;gap:12px;padding:11px 0;border-bottom:1px solid rgba(46,125,50,.1);}
.vm-audit-item:last-child{border-bottom:none;padding-bottom:0;}
.vm-audit-dot{width:26px;height:26px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;}
.ad-c{background:#DCFCE7;color:#166534}.ad-s{background:#EFF6FF;color:#2563EB}
.ad-a{background:#DCFCE7;color:#166534}.ad-r{background:#FEE2E2;color:#DC2626}
.ad-e{background:#F3F4F6;color:#6B7280}.ad-o{background:#FEF3C7;color:#D97706}
.vm-audit-body{flex:1;min-width:0;}
.vm-audit-body .au{font-size:12.5px;font-weight:500;color:var(--text-primary);}
.vm-audit-body .at{font-size:11px;color:#9EB0A2;margin-top:2px;font-family:'DM Mono',monospace;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.vm-audit-note{font-size:11.5px;color:#6B7280;margin-top:3px;font-style:italic;}
.vm-audit-ip{font-family:'DM Mono',monospace;font-size:10px;color:#9CA3AF;background:#F3F4F6;border-radius:4px;padding:1px 6px;}
.vm-audit-ts{font-family:'DM Mono',monospace;font-size:10px;color:#9EB0A2;flex-shrink:0;margin-left:auto;padding-left:8px;white-space:nowrap;}
.sa-tag{font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 5px;border:1px solid #FCD34D;}
.vm-mft{padding:14px 28px;border-top:1px solid rgba(46,125,50,.14);background:var(--bg-color);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap;}
.gps-map-mock{width:100%;height:160px;background:linear-gradient(135deg,#E8F5E9 0%,#C8E6C9 50%,#A5D6A7 100%);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;border:1px solid rgba(46,125,50,.2);position:relative;overflow:hidden;}
.gps-map-mock::before{content:'';position:absolute;inset:0;background:repeating-linear-gradient(0deg,transparent,transparent 20px,rgba(46,125,50,.06) 20px,rgba(46,125,50,.06) 21px),repeating-linear-gradient(90deg,transparent,transparent 20px,rgba(46,125,50,.06) 20px,rgba(46,125,50,.06) 21px);}
.gps-pin{font-size:32px;position:relative;z-index:1;filter:drop-shadow(0 2px 4px rgba(0,0,0,.2));}
.gps-coords{font-family:'DM Mono',monospace;font-size:11px;color:#1B5E20;font-weight:600;position:relative;z-index:1;}
.gps-status-live{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;color:#166534;background:#DCFCE7;border-radius:20px;padding:3px 10px;position:relative;z-index:1;}
.gps-status-live::before{content:'';width:6px;height:6px;border-radius:50%;background:#22C55E;animation:pulse 1.5s infinite;}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.8)}}
#slOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;opacity:0;pointer-events:none;transition:opacity .25s;}
#slOverlay.on{opacity:1;pointer-events:all;}
#dsSlider{position:fixed;top:0;right:-580px;bottom:0;width:540px;max-width:100vw;background:var(--s);z-index:9001;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18);}
#dsSlider.on{right:0;}
.sl-hdr{display:flex;align-items:flex-start;justify-content:space-between;padding:18px 22px 16px;border-bottom:1px solid var(--bd);background:var(--bg);flex-shrink:0;}
.sl-title{font-size:16px;font-weight:700;color:var(--t1);}
.sl-subtitle{font-size:12px;color:var(--t2);margin-top:2px;}
.sl-close{width:34px;height:34px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:19px;color:var(--t2);transition:var(--tr);flex-shrink:0;}
.sl-close:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA;}
.sl-body{flex:1;overflow-y:auto;padding:22px;display:flex;flex-direction:column;gap:16px;}
.sl-body::-webkit-scrollbar{width:4px;}
.sl-body::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px;}
.sl-foot{padding:14px 22px;border-top:1px solid var(--bd);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fr{display:grid;grid-template-columns:1fr 1fr;gap:13px;}
.fl{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2);}
.fl span{color:var(--red);margin-left:2px;}
.fi,.fs,.fta{font-family:'Inter',sans-serif;font-size:13px;padding:9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);width:100%;}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;padding-right:30px;}
.fta{resize:vertical;min-height:65px;}
.fd{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px;}
.fd::after{content:'';flex:1;height:1px;background:var(--bd);}
.sl-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 13px;font-size:12px;color:#92400E;}
.sl-sa-note i{font-size:14px;flex-shrink:0;margin-top:1px;}
#actionModal{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9100;display:grid;place-content:center;opacity:0;pointer-events:none;transition:opacity .2s;}
#actionModal.on{opacity:1;pointer-events:all;}
.am-box{background:var(--s);border-radius:16px;padding:26px 26px 22px;width:410px;max-width:92vw;box-shadow:var(--shlg);}
.am-icon{font-size:44px;margin-bottom:8px;line-height:1;}
.am-title{font-size:17px;font-weight:700;color:var(--t1);margin-bottom:5px;}
.am-body{font-size:13px;color:var(--t2);line-height:1.6;margin-bottom:14px;}
.am-fg{display:flex;flex-direction:column;gap:5px;margin-bottom:16px;}
.am-fg label{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2);}
.am-fg textarea,.am-fg input,.am-fg select{font-family:'Inter',sans-serif;font-size:13px;padding:9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;width:100%;transition:var(--tr);}
.am-fg textarea{resize:vertical;min-height:68px;}
.am-fg textarea:focus,.am-fg input:focus,.am-fg select:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.am-acts{display:flex;gap:10px;justify-content:flex-end;}
.am-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;padding:9px 11px;margin-bottom:13px;font-size:12px;color:#92400E;}
.am-sa-note i{font-size:14px;flex-shrink:0;margin-top:1px;}
.ds-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:11px 17px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:TIN .3s ease;}
.toast.ts{background:var(--grn)}.toast.tw{background:var(--amb)}.toast.td{background:var(--red)}
.toast.out{animation:TOUT .3s ease forwards;}
@keyframes UP   {from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN  {from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)}}
@keyframes TOUT {from{opacity:1;transform:translateY(0)}   to{opacity:0;transform:translateY(8px)}}
@media(max-width:768px){
  #dsSlider{width:100vw;}
  .fr{grid-template-columns:1fr;}
  .ds-stats{grid-template-columns:repeat(2,1fr);}
  .vm-ig{grid-template-columns:1fr;}
}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="ds-wrap">

  <!-- PAGE HEADER -->
  <div class="ds-ph">
    <div>
      <p class="ey">PLT · Project Logistics Tracker</p>
      <h1>Delivery Schedule</h1>
    </div>
    <div class="ds-ph-r">
      <!-- Calendar/List toggle — Manager+ see both views -->
      <?php if ($roleRank >= 2): ?>
      <div class="view-toggle">
        <button class="vt-btn active" id="btnList" onclick="switchView('list')"><i class="bx bx-list-ul"></i> List</button>
        <button class="vt-btn" id="btnCal"  onclick="switchView('cal')"><i class="bx bx-calendar"></i> Calendar</button>
      </div>
      <?php endif; ?>
      <?php if ($CAN_EXPORT): ?>
      <button class="btn btn-ghost" id="exportBtn"><i class="bx bx-export"></i> Export CSV</button>
      <?php endif; ?>
      <?php if ($CAN_CREATE_SCHEDULE): ?>
      <button class="btn btn-primary" id="createBtn"><i class="bx bx-plus"></i> Add Schedule</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ACCESS BANNERS -->
  <?php if ($roleName === 'Admin'): ?>
  <div class="access-banner ab-info"><i class='bx bx-info-circle'></i><div>You have <strong>Admin access</strong> — showing your zone's deliveries. You can add schedules, reschedule, track GPS, flag delays, and cancel. Cross-zone delivery tracking and force-complete require Super Admin.</div></div>
  <?php elseif ($roleName === 'Manager'): ?>
  <div class="access-banner ab-warn"><i class='bx bx-lock-open-alt'></i><div>You have <strong>Manager access</strong> — showing zone deliveries. You can reschedule, reassign zone drivers, and flag delays. Force-complete and cross-zone actions require Admin or Super Admin.</div></div>
  <?php elseif ($roleRank <= 1): ?>
  <div class="access-banner ab-staff"><i class='bx bx-user-circle'></i><div>You have <strong>Staff access</strong> — showing only your assigned deliveries. You can update delivery status (In Transit → Delivered) and report delays.</div></div>
  <?php endif; ?>

  <!-- STAT CARDS -->
  <div class="ds-stats" id="statsBar"></div>

  <!-- FILTERS -->
  <div class="ds-tb">
    <div class="sw">
      <i class="bx bx-search"></i>
      <input type="text" class="si" id="srch" placeholder="Search by delivery ID, supplier<?= $roleRank >= 2 ? ', assignee' : '' ?>…">
    </div>
    <select class="sel" id="fStatus">
      <option value="">All Statuses</option>
      <?php foreach ($ALLOWED_STATUSES as $st): ?>
      <option><?= htmlspecialchars($st) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($roleRank >= 2): // Zone filter for Manager+ ?>
    <select class="sel" id="fZone"><option value="">All Zones</option></select>
    <select class="sel" id="fProject"><option value="">All Projects</option></select>
    <?php endif; ?>
    <?php if ($roleRank >= 3): // Date range for Admin+ ?>
    <div class="date-range-wrap">
      <input type="date" class="fi-date" id="fDateFrom" title="Expected From">
      <span>–</span>
      <input type="date" class="fi-date" id="fDateTo" title="Expected To">
    </div>
    <?php endif; ?>
  </div>

  <!-- BULK BAR — SA only -->
  <?php if ($CAN_BATCH_DELIVER || $CAN_BATCH_REASSIGN): ?>
  <div class="bulk-bar" id="bulkBar">
    <span class="bulk-count" id="bulkCount">0 selected</span>
    <div class="bulk-sep"></div>
    <?php if ($CAN_BATCH_DELIVER): ?>
    <button class="btn btn-approve btn-sm" id="batchDeliverBtn"><i class="bx bx-check-double"></i> Force Mark Delivered</button>
    <?php endif; ?>
    <?php if ($CAN_BATCH_REASSIGN): ?>
    <button class="btn btn-warn btn-sm" id="batchReassignBtn"><i class="bx bx-transfer-alt"></i> Cross-Zone Reassign</button>
    <?php endif; ?>
    <button class="btn btn-ghost btn-sm" id="clearSelBtn"><i class="bx bx-x-circle"></i> Clear</button>
    <span class="sa-exclusive" style="margin-left:auto"><i class="bx bx-shield-quarter"></i> Super Admin Exclusive</span>
  </div>
  <?php endif; ?>

  <!-- LIST VIEW -->
  <div id="listView">
    <div class="ds-card">
      <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="ds-tbl" id="tbl">
          <colgroup>
            <?php if ($CAN_BATCH_DELIVER || $CAN_BATCH_REASSIGN): ?>
            <col class="col-cb">
            <?php endif; ?>
            <col class="col-id">
            <col class="col-sup">
            <?php if ($roleRank >= 2): ?><col class="col-ref"><?php endif; ?>
            <col class="col-items">
            <col class="col-exp">
            <?php if ($roleRank >= 2): ?><col class="col-act"><?php endif; ?>
            <col class="col-asgn">
            <?php if ($CAN_GPS_FULL): ?><col class="col-gps"><?php endif; ?>
            <?php if ($roleRank >= 2): ?><col class="col-zone"><?php endif; ?>
            <col class="col-stat">
            <col class="col-actn">
          </colgroup>
          <thead><tr>
            <?php if ($CAN_BATCH_DELIVER || $CAN_BATCH_REASSIGN): ?>
            <th class="no-sort"><div class="cb-wrap"><input type="checkbox" class="cb" id="checkAll"></div></th>
            <?php endif; ?>
            <th data-col="id">Delivery ID <i class="bx bx-sort sic"></i></th>
            <th data-col="supplier">Supplier/Vendor <i class="bx bx-sort sic"></i></th>
            <?php if ($roleRank >= 2): ?>
            <th data-col="ref">PO/PR Ref <i class="bx bx-sort sic"></i></th>
            <?php endif; ?>
            <th data-col="items" class="no-sort">Items</th>
            <th data-col="expectedDate">Expected Date <i class="bx bx-sort sic"></i></th>
            <?php if ($roleRank >= 2): ?>
            <th data-col="actualDate">Actual Date <i class="bx bx-sort sic"></i></th>
            <?php endif; ?>
            <th data-col="assignedTo">Assigned To <i class="bx bx-sort sic"></i></th>
            <?php if ($CAN_GPS_FULL): ?>
            <th class="no-sort">GPS</th>
            <?php endif; ?>
            <?php if ($roleRank >= 2): ?>
            <th data-col="zone">Zone <i class="bx bx-sort sic"></i></th>
            <?php endif; ?>
            <th data-col="status">Status <i class="bx bx-sort sic"></i></th>
            <th class="no-sort">Actions</th>
          </tr></thead>
          <tbody id="tbody"></tbody>
        </table>
      </div>
      <div class="ds-pager" id="pager"></div>
    </div>
  </div>

  <!-- CALENDAR VIEW — Manager+ -->
  <?php if ($roleRank >= 2): ?>
  <div id="calView">
    <div class="cal-legend" id="calLegend">
      <span class="leg-item"><span class="leg-dot" style="background:#DBEAFE"></span>Scheduled</span>
      <span class="leg-item"><span class="leg-dot" style="background:#FEF3C7"></span>In Transit</span>
      <span class="leg-item"><span class="leg-dot" style="background:#DCFCE7"></span>Delivered</span>
      <span class="leg-item"><span class="leg-dot" style="background:#FEE2E2"></span>Delayed</span>
      <?php if ($roleRank >= 3): ?>
      <span class="leg-item"><span class="leg-dot" style="background:#F3F4F6"></span>Cancelled</span>
      <?php endif; ?>
      <?php if ($roleRank >= 4): ?>
      <span class="leg-item"><span class="leg-dot" style="background:#BBF7D0"></span>Force Completed</span>
      <?php endif; ?>
    </div>
    <div class="ds-card" style="padding:20px;">
      <div class="cal-nav">
        <div class="cal-nav-btns">
          <button class="btn btn-ghost btn-sm" id="calPrev"><i class="bx bx-chevron-left"></i></button>
          <button class="btn btn-ghost btn-sm" id="calToday">Today</button>
          <button class="btn btn-ghost btn-sm" id="calNext"><i class="bx bx-chevron-right"></i></button>
        </div>
        <div class="cal-nav-title" id="calTitle"></div>
        <div style="width:140px"></div>
      </div>
      <div class="cal-header">
        <div class="ch">Sun</div><div class="ch">Mon</div><div class="ch">Tue</div>
        <div class="ch">Wed</div><div class="ch">Thu</div><div class="ch">Fri</div><div class="ch">Sat</div>
      </div>
      <div class="cal-grid" id="calGrid"></div>
    </div>
  </div>
  <?php endif; ?>

</div>
</main>

<div class="ds-toasts" id="toastWrap"></div>

<!-- SLIDER — Admin+ only -->
<?php if ($CAN_CREATE_SCHEDULE || $CAN_RESCHEDULE): ?>
<div id="slOverlay">
<div id="dsSlider">
  <div class="sl-hdr">
    <div>
      <div class="sl-title" id="slTitle">Add Delivery Schedule</div>
      <div class="sl-subtitle" id="slSub">Fill in all required fields</div>
    </div>
    <button class="sl-close" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-body" id="slBody"></div>
  <div class="sl-foot">
    <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-save"></i> Save</button>
  </div>
</div>
</div>
<?php endif; ?>

<!-- ACTION CONFIRM MODAL -->
<div id="actionModal">
  <div class="am-box">
    <div class="am-icon" id="amIcon">⚠️</div>
    <div class="am-title" id="amTitle">Confirm Action</div>
    <div class="am-body"  id="amBody"></div>
    <div class="am-sa-note" id="amSaNote" style="display:none">
      <i class="bx bx-shield-quarter"></i><span id="amSaText"></span>
    </div>
    <div id="amExtra"></div>
    <div class="am-fg">
      <label>Remarks / Notes</label>
      <textarea id="amRemarks" placeholder="Add remarks…"></textarea>
    </div>
    <div class="am-acts">
      <button class="btn btn-ghost btn-sm" id="amCancel">Cancel</button>
      <button class="btn btn-sm" id="amConfirm">Confirm</button>
    </div>
  </div>
</div>

<!-- VIEW DETAIL MODAL -->
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
      <div class="vm-mtb">
        <button class="vm-tab active" data-t="ov"><i class="bx bx-detail"></i> Details</button>
        <?php if ($CAN_GPS_FULL): ?>
        <button class="vm-tab" data-t="gps"><i class="bx bx-map-pin"></i> GPS Tracking</button>
        <?php endif; ?>
        <?php if ($CAN_AUDIT_TRAIL): ?>
        <button class="vm-tab" data-t="au"><i class="bx bx-shield-quarter"></i> Audit Trail</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="vm-mbd" id="vmBody">
      <div class="vm-tp active" id="vt-ov"></div>
      <?php if ($CAN_GPS_FULL): ?>
      <div class="vm-tp" id="vt-gps"></div>
      <?php endif; ?>
      <?php if ($CAN_AUDIT_TRAIL): ?>
      <div class="vm-tp" id="vt-au"></div>
      <?php endif; ?>
    </div>
    <div class="vm-mft" id="vmFoot"></div>
  </div>
</div>

<script>
// ── ROLE from PHP ─────────────────────────────────────────────────────────────
const ROLE = <?= $jsRole ?>;

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
let DELIVERIES = [];
let sortCol = 'expectedDate', sortDir = 'asc', page = 1;
const PAGE_SIZE = 10;
let selectedIds  = new Set();
let actionTarget = null, actionKey = null, actionCb = null;
let editDbId     = null;
let calYear, calMonth;
let currentView  = 'list';

(()=>{ const n=new Date(); calYear=n.getFullYear(); calMonth=n.getMonth(); })();

// ── CONSTANTS ─────────────────────────────────────────────────────────────────
let ZONES = [];
const SUPPLIERS = ['Global Tech Supply Co.','Mendoza Freight Services','Apex Industrial Parts','SunRise Logistics PH','Prime Construction Materials','EastWest Trading Corp.','Metro Office Supplies','BuildRight Inc.','Pacific Hardware Depot','Northern Electrical Supply'];
const SUP_TYPES = ['Supplier','Vendor','Contractor','Distributor'];
const PROJECTS  = ['Road Widening Phase 3','IT Infrastructure Upgrade','Warehouse Expansion','Solar Panel Installation','Bridge Deck Replacement','Office Renovation'];

let DB_SUPPLIERS = [];
let DB_POS       = [];
let DB_PRS       = [];
let DB_PROJECTS  = [];
let DB_USERS     = []; // [{id, full_name}]

// ── LOAD ALL ──────────────────────────────────────────────────────────────────
async function loadAll() {
    try {
        const [dels, sups, pos, prs, zones, projs, users] = await Promise.all([
            apiGet(API + '?api=deliveries').catch(e => { console.error('deliveries error', e); return []; }),
            apiGet(API + '?api=suppliers').catch(e => { console.error('suppliers error', e); return []; }),
            apiGet(API + '?api=purchase_orders').catch(e => { console.error('pos error', e); return []; }),
            apiGet(API + '?api=purchase_requests').catch(e => { console.error('prs error', e); return []; }),
            apiGet(API + '?api=zones').catch(e => { console.error('zones error', e); return []; }),
            apiGet(API + '?api=projects').catch(e => { console.error('projects error', e); return []; }),
            apiGet(API + '?api=users').catch(e => { console.error('users error', e); return []; })
        ]);
        DELIVERIES   = dels;
        DB_SUPPLIERS = sups || [];
        DB_POS       = pos || [];
        DB_PRS       = prs || [];
        DB_PROJECTS  = projs || [];
        DB_USERS     = users || [];
        
        if (zones && zones.length) {
            ZONES = zones;
        } else {
            // Hardcoded fallback if SWS zones are missing
            ZONES = [
                {id:'ZN-A01', name:'Zone A — Raw Materials', color:'#2E7D32'},
                {id:'ZN-B02', name:'Zone B — Safety & PPE',  color:'#0D9488'},
                {id:'ZN-C03', name:'Zone C — Fuels & Lubricants', color:'#DC2626'},
                {id:'ZN-D04', name:'Zone D — Office Supplies', color:'#2563EB'},
                {id:'ZN-E05', name:'Zone E — Electrical & IT', color:'#7C3AED'},
                {id:'ZN-F06', name:'Zone F — Tools & Equipment', color:'#D97706'}
            ];
        }

        renderList();
        buildDataLists();
    } catch(e) {
        toast('Initialize failed: ' + e.message, 'd');
    }
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const ini = n => String(n||'').split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
const zn  = id => ZONES.find(z => (typeof z === 'string' ? z : z.id) === id) || {name:id||'—', color:'#6B7280'};
const zc  = id => { const z = zn(id); return z.color || '#6B7280'; };
const fD  = d => {
    if(!d) return '—';
    const p=String(d).split('-');
    if(p.length!==3) return '—';
    const [y,m,dy]=[+p[0],+p[1],+p[2]];
    if(!y||m<1||m>12||dy<1||dy>31) return '—';
    return new Date(y,m-1,dy).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
};

function badge(s) {
    const m = {'Scheduled':'b-scheduled','In Transit':'b-intransit','Delivered':'b-delivered','Delayed':'b-delayed','Cancelled':'b-cancelled','Force Completed':'b-forcecompleted'};
    return `<span class="badge ${m[s]||''}">${esc(s)}</span>`;
}
function ceClass(s) {
    const m = {'Scheduled':'ce-scheduled','In Transit':'ce-intransit','Delivered':'ce-delivered','Delayed':'ce-delayed','Cancelled':'ce-cancelled','Force Completed':'ce-forcecompleted'};
    return m[s] || 'ce-scheduled';
}

function buildDataLists() {
    let supDL = document.getElementById('dlSuppliers');
    if (!supDL) {
        supDL = document.createElement('datalist');
        supDL.id = 'dlSuppliers';
        document.body.appendChild(supDL);
    }
    supDL.innerHTML = DB_SUPPLIERS.map(s => `<option value="${esc(s.name)}">${esc(s.category)}</option>`).join('');

    let refDL = document.getElementById('dlRefs');
    if (!refDL) {
        refDL = document.createElement('datalist');
        refDL.id = 'dlRefs';
        document.body.appendChild(refDL);
    }
    const poOpts = DB_POS.map(p => `<option value="${esc(p.po_number)}">PO · ${esc(p.supplier_name)}</option>`).join('');
    const prOpts = DB_PRS.map(p => `<option value="${esc(p.pr_number)}">PR · ${esc(p.requestor_name)}</option>`).join('');
    refDL.innerHTML = poOpts + prOpts;
}

// ── FILTER / SORT ─────────────────────────────────────────────────────────────
function getFiltered() {
    const q  = document.getElementById('srch').value.trim().toLowerCase();
    const st = document.getElementById('fStatus').value;
    const zn = document.getElementById('fZone')?.value || '';
    const pj = document.getElementById('fProject')?.value || '';
    const df = document.getElementById('fDateFrom')?.value || '';
    const dt = document.getElementById('fDateTo')?.value || '';
    return DELIVERIES.filter(d => {
        if(q && !d.id.toLowerCase().includes(q) && !d.supplier.toLowerCase().includes(q) && !(d.assignedTo||'').toLowerCase().includes(q)) return false;
        if(st && d.status !== st) return false;
        if(zn && d.zone   !== zn) return false;
        if(pj && d.project!== pj) return false;
        if(df && d.expectedDate < df) return false;
        if(dt && d.expectedDate > dt) return false;
        return true;
    });
}
function getSorted(list) {
    return [...list].sort((a,b) => {
        let va=a[sortCol], vb=b[sortCol];
        va=String(va||'').toLowerCase(); vb=String(vb||'').toLowerCase();
        return sortDir==='asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
}

// ── RENDER STATS ──────────────────────────────────────────────────────────────
function renderStats() {
    const total     = DELIVERIES.length;
    const sched     = DELIVERIES.filter(d=>d.status==='Scheduled').length;
    const transit   = DELIVERIES.filter(d=>d.status==='In Transit').length;
    const delivered = DELIVERIES.filter(d=>d.status==='Delivered'||d.status==='Force Completed').length;
    const delayed   = DELIVERIES.filter(d=>d.status==='Delayed').length;

    if (ROLE.rank <= 1) {
        // Staff: minimal stats — my deliveries only
        document.getElementById('statsBar').innerHTML=`
            <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-package"></i></div><div><div class="sc-v">${total}</div><div class="sc-l">My Deliveries</div></div></div>
            <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-calendar-check"></i></div><div><div class="sc-v">${sched}</div><div class="sc-l">Scheduled</div></div></div>
            <div class="sc"><div class="sc-ic ic-t"><i class="bx bx-trip"></i></div><div><div class="sc-v">${transit}</div><div class="sc-l">In Transit</div></div></div>
            <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-double"></i></div><div><div class="sc-v">${delivered}</div><div class="sc-l">Delivered</div></div></div>
            <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-alarm-exclamation"></i></div><div><div class="sc-v">${delayed}</div><div class="sc-l">Delayed</div></div></div>`;
        return;
    }
    if (ROLE.rank === 2) {
        // Manager: zone stats
        document.getElementById('statsBar').innerHTML=`
            <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-package"></i></div><div><div class="sc-v">${total}</div><div class="sc-l">Zone Deliveries</div></div></div>
            <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-calendar-check"></i></div><div><div class="sc-v">${sched}</div><div class="sc-l">Scheduled</div></div></div>
            <div class="sc"><div class="sc-ic ic-t"><i class="bx bx-trip"></i></div><div><div class="sc-v">${transit}</div><div class="sc-l">In Transit</div></div></div>
            <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-double"></i></div><div><div class="sc-v">${delivered}</div><div class="sc-l">Delivered</div></div></div>
            <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-alarm-exclamation"></i></div><div><div class="sc-v">${delayed}</div><div class="sc-l">Delayed</div></div></div>`;
        return;
    }
    // Admin+: full stats
    const cancelled = DELIVERIES.filter(d=>d.status==='Cancelled').length;
    const lateCount = DELIVERIES.filter(d=>d.isLate).length;
    document.getElementById('statsBar').innerHTML=`
        <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-package"></i></div><div><div class="sc-v">${total}</div><div class="sc-l">Total Deliveries</div></div></div>
        <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-calendar-check"></i></div><div><div class="sc-v">${sched}</div><div class="sc-l">Scheduled</div></div></div>
        <div class="sc"><div class="sc-ic ic-t"><i class="bx bx-trip"></i></div><div><div class="sc-v">${transit}</div><div class="sc-l">In Transit</div></div></div>
        <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-double"></i></div><div><div class="sc-v">${delivered}</div><div class="sc-l">Delivered</div></div></div>
        <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-alarm-exclamation"></i></div><div><div class="sc-v">${delayed}</div><div class="sc-l">Delayed</div></div></div>
        <div class="sc"><div class="sc-ic ic-d"><i class="bx bx-x-circle"></i></div><div><div class="sc-v">${cancelled}</div><div class="sc-l">Cancelled</div></div></div>
        <div class="sc"><div class="sc-ic ic-o"><i class="bx bx-error"></i></div><div><div class="sc-v">${lateCount}</div><div class="sc-l">Late Arrivals</div></div></div>`;
}

function buildDropdowns() {
    const zEl = document.getElementById('fZone');
    const pEl = document.getElementById('fProject');
    if (zEl) {
        const zv = zEl.value;
        const opts = ZONES.map(z => {
            if (typeof z === 'string') return `<option value="${z}" ${z===zv?'selected':''}>${z}</option>`;
            return `<option value="${z.id}" ${z.id===zv?'selected':''}>${z.name}</option>`;
        }).join('');
        zEl.innerHTML='<option value="">All Zones</option>'+opts;
    }
    if (pEl) {
        // Use DB_PROJECTS (live), merged with any projects already in DELIVERIES
        const liveNames = DB_PROJECTS.map(p => p.name);
        const deliveryProjs = DELIVERIES.map(d => d.project).filter(Boolean);
        const allProjs = [...new Set([...liveNames, ...deliveryProjs])].sort();
        const pv = pEl.value;
        pEl.innerHTML='<option value="">All Projects</option>'+allProjs.map(p=>`<option ${p===pv?'selected':''}>${esc(p)}</option>`).join('');
    }
}

// ── BULK BAR ──────────────────────────────────────────────────────────────────
function updateBulkBar() {
    const bulkBar = document.getElementById('bulkBar');
    if (!bulkBar) return;
    const n=selectedIds.size;
    bulkBar.classList.toggle('on',n>0);
    document.getElementById('bulkCount').textContent=n===1?'1 selected':`${n} selected`;
}
function syncCheckAll(slice) {
    const ca=document.getElementById('checkAll');
    if(!ca) return;
    const pageIds=slice.map(d=>d.id);
    const allChecked=pageIds.length>0&&pageIds.every(id=>selectedIds.has(id));
    const someChecked=pageIds.some(id=>selectedIds.has(id));
    ca.checked=allChecked; ca.indeterminate=!allChecked&&someChecked;
}

// ── RENDER LIST ───────────────────────────────────────────────────────────────
function renderList() {
    renderStats(); buildDropdowns();
    const data  = getSorted(getFiltered());
    const total = data.length;
    const pages = Math.max(1,Math.ceil(total/PAGE_SIZE));
    if(page>pages) page=pages;
    const slice = data.slice((page-1)*PAGE_SIZE, page*PAGE_SIZE);

    document.querySelectorAll('#tbl thead th[data-col]').forEach(th=>{
        const c=th.dataset.col;
        th.classList.toggle('sorted',c===sortCol);
        const ic=th.querySelector('.sic');
        if(ic) ic.className=`bx ${c===sortCol?(sortDir==='asc'?'bx-sort-up':'bx-sort-down'):'bx-sort'} sic`;
    });

    const tb=document.getElementById('tbody');
    const colCount = document.querySelectorAll('#tbl thead th').length;

    if(!slice.length){
        tb.innerHTML=`<tr><td colspan="${colCount}"><div class="empty"><i class="bx bx-package"></i><p>No deliveries found.</p></div></td></tr>`;
    } else {
        tb.innerHTML=slice.map(d=>{
            const clr=zc(d.zone);
            const chk=selectedIds.has(d.id);
            const isMyDelivery = d.assignedTo === ROLE.userName;

            // Action predicates per role
            const canRescheduleThis = ROLE.canReschedule && ['Scheduled','In Transit','Delayed'].includes(d.status);
            const canForceComplete  = ROLE.canForceComplete && !['Delivered','Cancelled','Force Completed'].includes(d.status);
            const canCancelThis     = ROLE.canCancel && !['Delivered','Cancelled','Force Completed'].includes(d.status);
            const canFlagDelayThis  = ROLE.canFlagDelay && d.status === 'In Transit';
            const canDepartThis     = ROLE.canDepartConfirm && d.status === 'Scheduled' && (ROLE.rank >= 2 || isMyDelivery);
            const canDriverDeliver  = ROLE.rank <= 1 && isMyDelivery && d.status === 'In Transit';
            const canReassignDriver = ROLE.canReassignDriver && ['Scheduled','In Transit'].includes(d.status);

            // Checkbox — SA only
            const cbCell = (ROLE.canBatchDeliver || ROLE.canBatchReassign)
                ? `<td onclick="event.stopPropagation()"><div class="cb-wrap"><input type="checkbox" class="cb row-cb" data-id="${d.id}" ${chk?'checked':''}></div></td>`
                : '';

            // PO Ref — Manager+
            const refCell = ROLE.rank >= 2 ? `<td onclick="openView('${d.id}')"><span class="ref-tag">${esc(d.ref)}</span></td>` : '';

            // Actual date — Manager+
            const actDateCell = ROLE.rank >= 2
                ? `<td onclick="openView('${d.id}')"><div class="date-actual ${d.isLate?'late':''}">${d.actualDate?fD(d.actualDate):'—'}</div></td>`
                : '';

            // GPS — Admin+
            const gpsCell = ROLE.canGpsFull
                ? `<td onclick="event.stopPropagation()">${d.gps?`<span class="gps-link" onclick="openGPSModal('${d.id}')"><i class="bx bx-map-pin"></i>Live</span>`:`<span class="gps-na">—</span>`}</td>`
                : '';

            // Zone — Manager+
            const zoneCell = ROLE.rank >= 2
                ? `<td onclick="openView('${d.id}')"><div class="zone-dot"><span style="width:7px;height:7px;border-radius:50%;background:${clr};flex-shrink:0;display:inline-block"></span> ${esc(zn(d.zone).name)}</div></td>`
                : '';

            // Build action buttons
            let actions = `<button class="btn btn-ghost btn-xs ionly" onclick="openView('${d.id}')" title="View"><i class="bx bx-show"></i></button>`;

            if (canRescheduleThis && ROLE.rank >= 3) // Admin+ can edit/reschedule
                actions += ` <button class="btn btn-ghost btn-xs ionly" onclick="openEdit('${d.id}')" title="Edit / Reschedule"><i class="bx bx-edit"></i></button>`;
            else if (canRescheduleThis && ROLE.rank === 2) // Manager: reschedule only
                actions += ` <button class="btn btn-ghost btn-xs ionly" onclick="openEdit('${d.id}')" title="Reschedule"><i class="bx bx-calendar-edit"></i></button>`;

            if (canDepartThis)
                actions += ` <button class="btn btn-approve btn-xs ionly" onclick="promptAct('${d.id}','depart')" title="Confirm Departure"><i class="bx bx-trip"></i></button>`;
            if (canFlagDelayThis)
                actions += ` <button class="btn btn-warn btn-xs ionly" onclick="promptAct('${d.id}','delay')" title="Flag Delay"><i class="bx bx-alarm-exclamation"></i></button>`;
            if (canReassignDriver)
                actions += ` <button class="btn btn-override btn-xs ionly" onclick="promptAct('${d.id}','reassign_driver')" title="Reassign Driver"><i class="bx bx-user-check"></i></button>`;
            if (ROLE.canCrossZone && canRescheduleThis)
                actions += ` <button class="btn btn-override btn-xs ionly" onclick="promptAct('${d.id}','reassign')" title="Cross-Zone Reassign"><i class="bx bx-transfer-alt"></i></button>`;
            if (canForceComplete)
                actions += ` <button class="btn btn-approve btn-xs ionly" onclick="promptAct('${d.id}','deliver')" title="Force Mark Delivered"><i class="bx bx-check-double"></i></button>`;
            if (canCancelThis)
                actions += ` <button class="btn btn-reject btn-xs ionly" onclick="promptAct('${d.id}','cancel')" title="Cancel"><i class="bx bx-x-circle"></i></button>`;
            // Staff: mark own delivery delivered
            if (canDriverDeliver)
                actions += ` <button class="btn btn-approve btn-xs ionly" onclick="promptAct('${d.id}','status_update','Delivered')" title="Mark Delivered"><i class="bx bx-check-circle"></i></button>`;

            // "My delivery" indicator for staff
            const myBadge = ROLE.rank <= 1 && isMyDelivery
                ? `<span class="my-delivery-badge" style="margin-left:4px;display:inline-flex;align-items:center;gap:2px;font-size:9px;font-weight:700;background:#EDE9FE;color:#6D28D9;border-radius:4px;padding:1px 5px;">Me</span>`
                : '';

            return `<tr class="${chk?'row-selected':''}">
                ${cbCell}
                <td onclick="openView('${d.id}')"><span class="del-id">${esc(d.id)}</span></td>
                <td onclick="openView('${d.id}')"><div class="sup-cell"><span class="sup-name">${esc(d.supplier)}</span><span class="sup-type">${esc(d.supplierType)}</span></div></td>
                ${refCell}
                <td onclick="openView('${d.id}')"><span class="items-pill">${d.itemCount}</span></td>
                <td onclick="openView('${d.id}')"><div class="date-cell">${fD(d.expectedDate)}</div></td>
                ${actDateCell}
                <td onclick="openView('${d.id}')"><div class="asgn-cell"><div class="asgn-av" style="background:${clr}">${ini(d.assignedTo)}</div><span class="asgn-name">${esc(d.assignedTo)}${myBadge}</span></div></td>
                ${gpsCell}
                ${zoneCell}
                <td onclick="openView('${d.id}')">${badge(d.status)}</td>
                <td onclick="event.stopPropagation()"><div class="act-cell">${actions}</div></td>
            </tr>`;
        }).join('');

        if (ROLE.canBatchDeliver || ROLE.canBatchReassign) {
            document.querySelectorAll('.row-cb').forEach(cb=>{
                cb.addEventListener('change',function(){
                    const id=this.dataset.id;
                    if(this.checked) selectedIds.add(id); else selectedIds.delete(id);
                    this.closest('tr').classList.toggle('row-selected',this.checked);
                    updateBulkBar(); syncCheckAll(slice);
                });
            });
        }
    }
    syncCheckAll(slice);

    const s=(page-1)*PAGE_SIZE+1, e=Math.min(page*PAGE_SIZE,total);
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
function goPage(p){ page=p; renderList(); }

document.querySelectorAll('#tbl thead th[data-col]').forEach(th=>{
    th.addEventListener('click',()=>{
        const c=th.dataset.col;
        sortDir=sortCol===c?(sortDir==='asc'?'desc':'asc'):'asc';
        sortCol=c; page=1; renderList();
    });
});
['srch','fStatus','fZone','fProject','fDateFrom','fDateTo'].forEach(id=>
    document.getElementById(id)?.addEventListener('input',()=>{page=1;renderList();if(currentView==='cal')renderCal();})
);

// Check All — SA only
document.getElementById('checkAll')?.addEventListener('change',function(){
    const slice=getSorted(getFiltered()).slice((page-1)*PAGE_SIZE,page*PAGE_SIZE);
    slice.forEach(d=>{if(this.checked) selectedIds.add(d.id); else selectedIds.delete(d.id);});
    renderList(); updateBulkBar();
});
document.getElementById('clearSelBtn')?.addEventListener('click',()=>{selectedIds.clear();renderList();updateBulkBar();});

// ── BATCH ACTIONS — SA only ───────────────────────────────────────────────────
document.getElementById('batchDeliverBtn')?.addEventListener('click',()=>{
    if(!ROLE.canBatchDeliver) return toast('Insufficient permissions','w');
    const ids=[...selectedIds].filter(id=>DELIVERIES.find(d=>d.id===id&&!['Delivered','Force Completed','Cancelled'].includes(d.status)));
    if(!ids.length) return toast('No eligible deliveries in selection.','w');
    actionKey='batch-deliver';
    document.getElementById('amExtra').innerHTML='';
    showActionModal('✅',`Force Mark ${ids.length} Delivered`,
        `Mark <strong>${ids.length}</strong> delivery/deliveries as Force Completed. This bypasses driver confirmation.`,
        true,'Super Admin override — force-marks deliveries without driver sign-off.',
        'btn-approve','<i class="bx bx-check-double"></i> Force Mark Delivered');
    actionCb = async(remarks) => {
        const btn=document.getElementById('amConfirm'); btn.disabled=true;
        try {
            const result = await apiPost(API+'?api=batch_action',{ids,type:'batch-deliver',remarks});
            result.updated.forEach(u=>{ const idx=DELIVERIES.findIndex(d=>d.id===u.id); if(idx>-1) DELIVERIES[idx]=u; });
            selectedIds.clear(); updateBulkBar();
            toast(`${result.count} delivery/deliveries force-marked delivered.`,'s');
            renderList(); if(currentView==='cal') renderCal();
        } catch(e){ toast(e.message,'d'); }
        finally{ btn.disabled=false; document.getElementById('actionModal').classList.remove('on'); }
    };
});

document.getElementById('batchReassignBtn')?.addEventListener('click',()=>{
    if(!ROLE.canBatchReassign) return toast('Insufficient permissions','w');
    const ids=[...selectedIds].filter(id=>DELIVERIES.find(d=>d.id===id&&!['Delivered','Force Completed','Cancelled'].includes(d.status)));
    if(!ids.length) return toast('No eligible deliveries in selection.','w');
    actionKey='batch-reassign';
    document.getElementById('amExtra').innerHTML=`
        <div class="am-fg">
            <label>New Zone <span style="color:#DC2626">*</span></label>
            <select id="amNewZone">
                <option value="">Select new zone…</option>
                ${ZONES.map(z => {
                    if (typeof z === 'string') return `<option value="${z}">${z}</option>`;
                    return `<option value="${z.id}">${z.name}</option>`;
                }).join('')}
            </select>
        </div>`;
    showActionModal('🔄',`Cross-Zone Reassign (${ids.length})`,
        `Reassign <strong>${ids.length}</strong> delivery/deliveries to a new zone.`,
        true,'Super Admin exclusive — cross-zone reassignment bypasses department restrictions.',
        'btn-override','<i class="bx bx-transfer-alt"></i> Reassign');
    actionCb = async(remarks) => {
        const newZone=document.getElementById('amNewZone')?.value;
        if(!newZone) return toast('Please select a new zone.','w');
        const btn=document.getElementById('amConfirm'); btn.disabled=true;
        try {
            const result = await apiPost(API+'?api=batch_action',{ids,type:'batch-reassign',newZone,remarks});
            result.updated.forEach(u=>{ const idx=DELIVERIES.findIndex(d=>d.id===u.id); if(idx>-1) DELIVERIES[idx]=u; });
            selectedIds.clear(); updateBulkBar();
            toast(`${result.count} delivery/deliveries reassigned to ${newZone}.`,'s');
            renderList();
        } catch(e){ toast(e.message,'d'); }
        finally{ btn.disabled=false; document.getElementById('actionModal').classList.remove('on'); }
    };
});

// ── ACTION MODAL ──────────────────────────────────────────────────────────────
function showActionModal(icon,title,body,sa,saText,btnClass,btnLabel){
    document.getElementById('amIcon').textContent=icon;
    document.getElementById('amTitle').textContent=title;
    document.getElementById('amBody').innerHTML=body;
    const saNote=document.getElementById('amSaNote');
    if(sa){ saNote.style.display='flex'; document.getElementById('amSaText').textContent=saText; }
    else saNote.style.display='none';
    document.getElementById('amRemarks').value='';
    const cb=document.getElementById('amConfirm');
    cb.className=`btn btn-sm ${btnClass}`; cb.innerHTML=btnLabel;
    document.getElementById('actionModal').classList.add('on');
}

function promptAct(id, type, extraArg) {
    const del=DELIVERIES.find(d=>d.id===id); if(!del) return;
    actionTarget=id; actionKey=type;
    document.getElementById('amExtra').innerHTML='';

    const cfg = {
        deliver:        {icon:'✅',title:'Force Mark Delivered',sa:true,saText:'Super Admin override — marks as Force Completed without driver confirmation.',btn:'btn-approve',label:'<i class="bx bx-check-double"></i> Force Mark Delivered'},
        cancel:         {icon:'⛔',title:'Cancel Delivery',sa:false,saText:'',btn:'btn-reject',label:'<i class="bx bx-x-circle"></i> Cancel Delivery'},
        reassign:       {icon:'🔄',title:'Cross-Zone Reassign',sa:true,saText:'Super Admin can reassign deliveries across zones.',btn:'btn-override',label:'<i class="bx bx-transfer-alt"></i> Reassign'},
        reassign_driver:{icon:'👤',title:'Reassign Zone Driver',sa:false,saText:'',btn:'btn-override',label:'<i class="bx bx-user-check"></i> Reassign Driver'},
        depart:         {icon:'🚛',title:'Confirm Departure',sa:false,saText:'',btn:'btn-approve',label:'<i class="bx bx-trip"></i> Confirm Departure'},
        delay:          {icon:'⏱️',title:'Flag as Delayed',sa:ROLE.rank>=4,saText:ROLE.rank>=4?'Super Admin escalation — delivery marked as delayed.':'',btn:'btn-warn',label:'<i class="bx bx-alarm-exclamation"></i> Flag Delay'},
        status_update:  {icon:'✅',title:'Mark as Delivered',sa:false,saText:'',btn:'btn-approve',label:'<i class="bx bx-check-circle"></i> Mark Delivered'},
    };
    const c=cfg[type]; if(!c) return;

    if(type==='reassign'){
        document.getElementById('amExtra').innerHTML=`
            <div class="am-fg">
                <label>New Zone <span style="color:#DC2626">*</span></label>
                <select id="amNewZone">
                    <option value="">Select new zone…</option>
                    ${ZONES.filter(z => (z.id || z) !== del.zone).map(z => {
                        if (typeof z === 'string') return `<option value="${z}">${z}</option>`;
                        return `<option value="${z.id}">${z.name}</option>`;
                    }).join('')}
                </select>
            </div>`;
    } else if(type==='reassign_driver'){
        document.getElementById('amExtra').innerHTML=`
            <div class="am-fg">
                <label>Reassign To <span style="color:#DC2626">*</span></label>
                <input type="text" id="amNewDriver" placeholder="New driver/assignee name…" value="">
            </div>`;
    }

    showActionModal(c.icon,c.title,
        `<strong>${esc(del.id)}</strong> — ${esc(del.supplier)}<br><span style="font-size:12px;color:#9EB0A2">${esc(zn(del.zone).name)} · ${fD(del.expectedDate)}</span>`,
        c.sa,c.saText,c.btn,c.label);

    actionCb = async(remarks) => {
        const payload = {id:del.id, type, remarks};
        if(type==='reassign'){
            const newZone=document.getElementById('amNewZone')?.value;
            if(!newZone) return toast('Please select a new zone.','w');
            payload.newZone=newZone;
        } else if(type==='reassign_driver'){
            const newDriver=document.getElementById('amNewDriver')?.value?.trim();
            if(!newDriver) return toast('Please enter a driver name.','w');
            payload.newDriver=newDriver;
        } else if(type==='status_update'){
            payload.newStatus=extraArg||'Delivered';
        }
        const btn=document.getElementById('amConfirm'); btn.disabled=true;
        try {
            const updated = await apiPost(API+'?api=delivery_action', payload);
            const idx=DELIVERIES.findIndex(d=>d.id===updated.id);
            if(idx>-1) DELIVERIES[idx]=updated;
            const msgs = {deliver:'force-marked delivered',cancel:'cancelled',reassign:'cross-zone reassigned',reassign_driver:'driver reassigned',depart:'departure confirmed',delay:'flagged as delayed',status_update:'marked as delivered'};
            toast(`${del.id} ${msgs[type]||'updated'}.`,'s');
            renderList(); if(currentView==='cal') renderCal();
            if(document.getElementById('viewModal').classList.contains('on')) renderDetail(updated);
        } catch(e){ toast(e.message,'d'); }
        finally{ btn.disabled=false; document.getElementById('actionModal').classList.remove('on'); }
    };
}
window.promptAct = promptAct;

document.getElementById('amConfirm').addEventListener('click',async()=>{
    const remarks=document.getElementById('amRemarks').value.trim();
    if(actionCb) await actionCb(remarks);
});
document.getElementById('amCancel').addEventListener('click',()=>{
    document.getElementById('actionModal').classList.remove('on'); actionCb=null;
});
document.getElementById('actionModal').addEventListener('click',function(e){
    if(e.target===this){ this.classList.remove('on'); actionCb=null; }
});

// ── VIEW DETAIL MODAL ─────────────────────────────────────────────────────────
function setVmTab(name){
    document.querySelectorAll('.vm-tab').forEach(t=>t.classList.toggle('active',t.dataset.t===name));
    document.querySelectorAll('.vm-tp').forEach(p=>p.classList.toggle('active',p.id==='vt-'+name));
}
document.querySelectorAll('.vm-tab').forEach(t=>t.addEventListener('click',()=>setVmTab(t.dataset.t)));
document.getElementById('vmClose').addEventListener('click',closeView);
document.getElementById('viewModal').addEventListener('click',function(e){if(e.target===this) closeView();});

async function openView(id){
    const del=DELIVERIES.find(d=>d.id===id); if(!del) return;
    renderDetail(del);
    setVmTab('ov');
    document.getElementById('viewModal').classList.add('on');
    // Load audit log — SA only
    if (ROLE.canAuditTrail) {
        try {
            const auditRows = await apiGet(API + '?api=audit_log&id=' + encodeURIComponent(id));
            del._auditLog = auditRows;
            renderAuditTab(del);
        } catch(e) {
            const vtAu = document.getElementById('vt-au');
            if(vtAu) vtAu.innerHTML=`<p style="font-size:12px;color:#9EB0A2;padding:16px">Failed to load audit log.</p>`;
        }
    }
}
window.openView = openView;
function closeView(){ document.getElementById('viewModal').classList.remove('on'); }

function renderDetail(del){
    const clr=zc(del.zone);
    const isMyDelivery = del.assignedTo === ROLE.userName;

    // Footer buttons — gated by role
    const canRescheduleThis = ROLE.canReschedule && ['Scheduled','In Transit','Delayed'].includes(del.status);
    const canForceComplete  = ROLE.canForceComplete && !['Delivered','Cancelled','Force Completed'].includes(del.status);
    const canCancelThis     = ROLE.canCancel && !['Delivered','Cancelled','Force Completed'].includes(del.status);
    const canFlagDelayThis  = ROLE.canFlagDelay && del.status === 'In Transit';
    const canDriverDeliver  = ROLE.rank <= 1 && isMyDelivery && del.status === 'In Transit';
    const canReassignDriver = ROLE.canReassignDriver && ['Scheduled','In Transit'].includes(del.status);

    document.getElementById('vmAvatar').textContent=ini(del.supplier);
    document.getElementById('vmAvatar').style.background=clr;
    document.getElementById('vmName').innerHTML=esc(del.supplier);
    document.getElementById('vmMid').innerHTML=
        `<span style="font-family:'DM Mono',monospace">${esc(del.id)}</span>${ROLE.rank>=2?' · '+esc(del.ref):''} ${badge(del.status)}`;

    let chips = `<div class="vm-mc"><i class="bx bx-calendar"></i>${fD(del.expectedDate)}</div>`;
    chips += `<div class="vm-mc"><i class="bx bx-user"></i>${esc(del.assignedTo)}${isMyDelivery&&ROLE.rank<=1?' <span class="my-delivery-badge">Me</span>':''}</div>`;
    if (ROLE.rank >= 2) chips += `<div class="vm-mc"><i class="bx bx-map-pin"></i>${esc(zn(del.zone).name)}</div>`;
    if (ROLE.rank >= 2) chips += `<div class="vm-mc"><i class="bx bx-briefcase"></i>${esc(del.project)}</div>`;
    if (del.gps && ROLE.canGpsFull) chips += `<div class="vm-mc" style="color:#2563EB;border-color:#BFDBFE;background:#EFF6FF;cursor:pointer" onclick="openGPSModal('${del.id}')"><i class="bx bx-map-pin" style="color:#2563EB"></i>GPS Live</div>`;
    document.getElementById('vmChips').innerHTML = chips;

    // Footer buttons
    const btns = [];
    if (canRescheduleThis) btns.push(`<button class="btn btn-ghost btn-sm" onclick="closeView();openEdit('${del.id}')"><i class="bx bx-calendar-edit"></i> Reschedule</button>`);
    if (canFlagDelayThis)  btns.push(`<button class="btn btn-warn btn-sm" onclick="closeView();promptAct('${del.id}','delay')"><i class="bx bx-alarm-exclamation"></i> Flag Delay</button>`);
    if (canReassignDriver) btns.push(`<button class="btn btn-override btn-sm" onclick="closeView();promptAct('${del.id}','reassign_driver')"><i class="bx bx-user-check"></i> Reassign Driver</button>`);
    if (ROLE.canCrossZone && canRescheduleThis) btns.push(`<button class="btn btn-override btn-sm" onclick="closeView();promptAct('${del.id}','reassign')"><i class="bx bx-transfer-alt"></i> Cross-Zone</button>`);
    if (canForceComplete)  btns.push(`<button class="btn btn-approve btn-sm" onclick="closeView();promptAct('${del.id}','deliver')"><i class="bx bx-check-double"></i> Force Complete</button>`);
    if (canCancelThis)     btns.push(`<button class="btn btn-reject btn-sm" onclick="closeView();promptAct('${del.id}','cancel')"><i class="bx bx-x-circle"></i> Cancel</button>`);
    if (canDriverDeliver)  btns.push(`<button class="btn btn-approve btn-sm" onclick="closeView();promptAct('${del.id}','status_update','Delivered')"><i class="bx bx-check-circle"></i> Mark Delivered</button>`);
    btns.push(`<button class="btn btn-ghost btn-sm" onclick="closeView()">Close</button>`);
    document.getElementById('vmFoot').innerHTML = btns.join('');

    // Info grid — varies by role
    let infoFields = `
        <div class="vm-ii"><label>Delivery ID</label><div class="v mono">${esc(del.id)}</div></div>`;
    if (ROLE.rank >= 2) infoFields += `<div class="vm-ii"><label>PO/PR Reference</label><div class="v mono">${esc(del.ref)}</div></div>`;
    infoFields += `<div class="vm-ii"><label>Supplier / Vendor</label><div class="v">${esc(del.supplier)} <span style="font-size:11px;color:#9EB0A2">(${esc(del.supplierType)})</span></div></div>`;
    if (ROLE.rank >= 2) infoFields += `<div class="vm-ii"><label>Zone</label><div class="v" style="color:${clr}">${esc(zn(del.zone).name)}</div></div>`;
    infoFields += `<div class="vm-ii"><label>Assigned To</label><div class="v">${esc(del.assignedTo)}</div></div>`;
    if (ROLE.rank >= 2) infoFields += `<div class="vm-ii"><label>Project</label><div class="v muted">${esc(del.project)}</div></div>`;
    infoFields += `<div class="vm-ii"><label>Expected Date</label><div class="v muted">${fD(del.expectedDate)}</div></div>`;
    if (ROLE.rank >= 2) infoFields += `<div class="vm-ii"><label>Actual Date</label><div class="v ${del.isLate?'':'muted'}" style="${del.isLate?'color:#DC2626;font-weight:700':''}">${del.actualDate?fD(del.actualDate):'—'}${del.isLate?' <span style="font-size:10px;background:#FEE2E2;color:#DC2626;border-radius:4px;padding:1px 5px;font-weight:700">LATE</span>':''}</div></div>`;
    infoFields += `<div class="vm-ii vm-full"><label>Items (${del.itemCount})</label><div class="v muted">${(del.items||[]).map(x=>esc(x)).join(' · ')}</div></div>`;
    if (del.notes) infoFields += `<div class="vm-ii vm-full"><label>Notes</label><div class="v muted">${esc(del.notes)}</div></div>`;

    const saNoteHtml = ROLE.rank >= 4
        ? `<div class="vm-sa-note"><i class="bx bx-shield-quarter"></i><span>Super Admin view — you can Force Mark Delivered, Cross-Zone Reassign, Reschedule, or Cancel this delivery.</span></div>`
        : '';

    document.getElementById('vt-ov').innerHTML=`<div class="vm-ig">${infoFields}</div>${saNoteHtml}`;

    // GPS tab — Admin+
    const vtGps = document.getElementById('vt-gps');
    if(vtGps){
        if(del.gps){
            vtGps.innerHTML=`
                <div class="gps-map-mock">
                    <span class="gps-pin">🚛</span>
                    <span class="gps-coords">${del.gps.lat} / ${del.gps.lng}</span>
                    <span class="gps-status-live">Live Tracking Active</span>
                </div>
                <div class="vm-ig">
                    <div class="vm-ii"><label>Current Location</label><div class="v">${esc(del.gps.loc||'—')}</div></div>
                    <div class="vm-ii"><label>Coordinates</label><div class="v mono">${esc(del.gps.lat)}, ${esc(del.gps.lng)}</div></div>
                    <div class="vm-ii"><label>Assigned Driver</label><div class="v">${esc(del.assignedTo)}</div></div>
                    <div class="vm-ii"><label>ETA</label><div class="v muted">${del.status==='In Transit'?'~2h 30min':'—'}</div></div>
                </div>`;
        } else {
            vtGps.innerHTML=`
                <div style="text-align:center;padding:40px 20px;color:#9EB0A2;">
                    <i class="bx bx-map-pin" style="font-size:48px;display:block;margin-bottom:12px;color:#D1FAE5;"></i>
                    <p style="font-size:13px;">GPS tracking is only available for <strong>In Transit</strong> deliveries.</p>
                </div>`;
        }
    }

    // Audit tab — SA only
    const vtAu = document.getElementById('vt-au');
    if(vtAu) vtAu.innerHTML=`<p style="font-size:12px;color:#9EB0A2;padding:16px">Loading audit trail…</p>`;
}

function renderAuditTab(del){
    const vtAu = document.getElementById('vt-au');
    if(!vtAu) return;
    const log = del._auditLog || [];
    if(!log.length){
        vtAu.innerHTML=`<p style="font-size:12px;color:#9EB0A2;padding:16px">No audit entries found.</p>`;
        return;
    }
    vtAu.innerHTML=`
        <div class="vm-sa-note"><i class="bx bx-shield-quarter"></i><span>Full audit trail — visible to Super Admin only. Read-only and immutable.</span></div>
        <div>${log.map(a=>`
            <div class="vm-audit-item">
                <div class="vm-audit-dot ${a.cls}"><i class="bx ${a.icon}"></i></div>
                <div class="vm-audit-body">
                    <div class="au">${esc(a.act)} ${a.isSA?'<span class="sa-tag">Super Admin</span>':''}</div>
                    <div class="at">
                        <i class="bx bx-user" style="font-size:11px"></i>${esc(a.by)} · ${esc(a.role)}
                        ${a.ip?`<span class="vm-audit-ip"><i class="bx bx-desktop" style="font-size:10px;margin-right:2px"></i>${esc(a.ip)}</span>`:''}
                    </div>
                    ${a.note?`<div class="vm-audit-note">"${esc(a.note)}"</div>`:''}
                </div>
                <div class="vm-audit-ts">${esc(a.ts)}</div>
            </div>`).join('')}
        </div>`;
}

// ── GPS QUICK MODAL — Admin+ ──────────────────────────────────────────────────
function openGPSModal(id){
    if(!ROLE.canGpsFull){ toast('Insufficient permissions','w'); return; }
    const del=DELIVERIES.find(d=>d.id===id);
    if(!del||!del.gps) return;
    alert(`GPS — ${del.id}\n\nLocation: ${del.gps.loc}\nCoords: ${del.gps.lat}, ${del.gps.lng}\nDriver: ${del.assignedTo}`);
}
window.openGPSModal = openGPSModal;

// ── SLIDER (Add / Edit / Reschedule) — Admin+/Manager ─────────────────────────
<?php if ($CAN_CREATE_SCHEDULE || $CAN_RESCHEDULE): ?>
function buildSliderForm(mode, del=null){
    const isReschedule = mode === 'reschedule';
    const isManager    = ROLE.rank === 2;

    // Zone options: SA sees all zones; Admin/Manager locked to their zone
    let zoneOpts;
    if (ROLE.canViewAllZones) {
        zoneOpts = ZONES.map(z => {
            if (typeof z === 'string') return `<option value="${z}" ${del&&del.zone===z?'selected':''}>${z}</option>`;
            return `<option value="${z.id}" ${del&&del.zone===z.id?'selected':''}>${z.name}</option>`;
        }).join('');
    } else {
        // Lock to user zone
        const uz = ROLE.userZone || '';
        const znm = uz ? zn(uz).name : 'Unassigned Zone';
        zoneOpts = `<option value="${uz}" selected>${znm}</option>`;
    }

    const supTypeOpts = ['Supplier','Vendor','Contractor','Distributor'].map(t=>`<option ${del&&del.supplierType===t?'selected':''}>${t}</option>`).join('');

    // Build project options from DB_PROJECTS (live) + any project already on the delivery
    const projList = DB_PROJECTS.length
        ? DB_PROJECTS.map(p => p.name)
        : ['Road Widening Phase 3','IT Infrastructure Upgrade','Warehouse Expansion','Solar Panel Installation','Bridge Deck Replacement','Office Renovation'];
    // Ensure current project (if editing) is included even if not in the fetched list
    if (del && del.project && !projList.includes(del.project)) projList.push(del.project);
    const projOpts = projList.map(p=>`<option ${del&&del.project===p?'selected':''}>${esc(p)}</option>`).join('');

    // Status options: limited for Manager
    const allowedSts = isManager
        ? ['Scheduled','In Transit','Delayed']
        : ['Scheduled','In Transit','Delivered','Delayed','Cancelled','Force Completed'];
    const statusOpts = allowedSts.map(s=>`<option ${del&&del.status===s?'selected':''}>${s}</option>`).join('');

    return `
        ${isReschedule?`<div class="sl-sa-note"><i class="bx bx-calendar-edit"></i><span>Rescheduling <strong>${del.id}</strong>. Original date: <strong>${fD(del.expectedDate)}</strong>.</span></div>`:''}
        <div class="fr">
            <div class="fg"><label class="fl">Supplier / Vendor <span>*</span></label>
                <input type="text" class="fi" id="fSupplier" value="${del?esc(del.supplier):''}" placeholder="e.g. Global Tech Supply"
                    list="dlSuppliers" ${isReschedule?'readonly style="background:#F9FAFB;color:#6B7280"':''}></div>
            <div class="fg"><label class="fl">Supplier Type</label><select class="fs" id="fSupType">${supTypeOpts}</select></div>
        </div>
        <div class="fr">
            <div class="fg"><label class="fl">PO / PR Reference <span>*</span></label>
                <input type="text" class="fi" id="fRef" value="${del?esc(del.ref):''}" placeholder="PO-2025-XXXX" list="dlRefs"></div>
            <div class="fg"><label class="fl">Project</label>
                <select class="fs" id="fProjSl"><option value="">Select project…</option>${projOpts}</select></div>
        </div>
        <div class="fr">
            <div class="fg"><label class="fl">Zone <span>*</span></label>
                <select class="fs" id="fZoneSl" ${!ROLE.canViewAllZones?'disabled style="background:#F9FAFB;color:#6B7280"':''}><option value="">Select zone…</option>${zoneOpts}</select></div>
            <div class="fg"><label class="fl">Assigned To <span>*</span></label>
                <input type="text" class="fi" id="fAssigned" value="${del?esc(del.assignedTo):''}" placeholder="e.g. Carlo Mendoza" list="dlAssignees"></div>
        </div>
        <datalist id="dlAssignees">${DB_USERS.map(u=>`<option value="${esc(u.full_name)}"></option>`).join('')}</datalist>
        <div class="fg"><span class="fd">Schedule</span></div>
        <div class="fr">
            <div class="fg"><label class="fl">Expected Date <span>*</span></label><input type="date" class="fi" id="fExpDate" value="${del?del.expectedDate:''}"></div>
            <div class="fg"><label class="fl">Actual Date</label><input type="date" class="fi" id="fActDate" value="${del?del.actualDate:''}"></div>
        </div>
        <div class="fg"><span class="fd">Status &amp; Items</span></div>
        <div class="fg"><label class="fl">Delivery Status <span>*</span></label><select class="fs" id="fDelStatus">${statusOpts}</select></div>
        <div class="fg"><label class="fl">Item List <span>*</span></label>
            <textarea class="fta" id="fItems" placeholder="One item per line…">${del&&del.items?del.items.join('\n'):''}</textarea></div>
        <div class="fg"><label class="fl">Notes</label>
            <textarea class="fta" id="fNotes" placeholder="Optional notes…" style="min-height:55px">${del?esc(del.notes):''}</textarea></div>
        ${ROLE.canCrossZone&&isReschedule?`<div class="sl-sa-note"><i class="bx bx-shield-quarter"></i><span>Super Admin — cross-zone rescheduling is allowed regardless of original department.</span></div>`:''}`;
}

function openSlider(mode='create', del=null){
    editDbId = del ? del.dbId : null;
    const titles = {create:'Add Delivery Schedule',edit:'Edit Delivery',reschedule:'Reschedule Delivery'};
    const subs   = {create:'Fill in all required fields',edit:'Update delivery details',reschedule:'Update schedule and zone'};
    document.getElementById('slTitle').textContent=titles[mode];
    document.getElementById('slSub').textContent=subs[mode];
    document.getElementById('slBody').innerHTML=buildSliderForm(mode,del);
    document.getElementById('dsSlider').classList.add('on');
    document.getElementById('slOverlay').classList.add('on');
    
    // Add Auto-fill Listeners
    const fRef = document.getElementById('fRef');
    if (fRef) {
        fRef.addEventListener('change', async function() {
            const val = this.value.trim();
            if (!val) return;
            
            const po = DB_POS.find(p => p.po_number === val);
            const pr = DB_PRS.find(p => p.pr_number === val);
            const ref = po || pr;
            const type = po ? 'po' : 'pr';
            
            if (ref) {
                // Auto-fill supplier
                const fSup = document.getElementById('fSupplier');
                if (fSup && !fSup.readOnly) {
                    fSup.value = po ? ref.supplier_name : (ref.requestor_name + ' (Internal)');
                    // Set sup type
                    const fSt = document.getElementById('fSupType');
                    if (fSt) fSt.value = po ? (ref.supplier_category || 'Supplier') : 'Supplier';
                }
                
                // Fetch items
                toast('Fetching items for ' + val + '...', 's');
                try {
                    const data = await apiGet(API + `?api=ref_details&type=${type}&ref=${val}`);
                    if (data.items) {
                        const items = data.items.map(i => `${i.item_description} (${i.quantity} ${i.uom})`);
                        const fItems = document.getElementById('fItems');
                        if (fItems) fItems.value = items.join('\n');
                    }
                } catch(e) { toast('Could not fetch items: ' + e.message, 'w'); }
            }
        });
    }

    const fSup = document.getElementById('fSupplier');
    if (fSup) {
        fSup.addEventListener('change', function() {
            const val = this.value.trim();
            const sup = DB_SUPPLIERS.find(s => s.name === val);
            if (sup) {
                const fSt = document.getElementById('fSupType');
                if (fSt) fSt.value = sup.category || 'Supplier';
            }
        });
    }

    setTimeout(()=>document.getElementById('fSupplier')?.focus(),320);
}

function openEdit(id){
    if(!ROLE.canReschedule){ toast('Insufficient permissions','w'); return; }
    const del=DELIVERIES.find(d=>d.id===id); if(!del) return;
    openSlider(['Scheduled','In Transit','Delayed'].includes(del.status)?'reschedule':'edit', del);
}
window.openEdit = openEdit;

function closeSlider(){
    document.getElementById('dsSlider').classList.remove('on');
    document.getElementById('slOverlay').classList.remove('on');
    editDbId=null;
}

document.getElementById('slOverlay').addEventListener('click',function(e){if(e.target===this) closeSlider();});
document.getElementById('slClose').addEventListener('click',closeSlider);
document.getElementById('slCancel').addEventListener('click',closeSlider);

document.getElementById('createBtn')?.addEventListener('click',()=>{
    if(!ROLE.canCreateSchedule){ toast('Insufficient permissions','w'); return; }
    openSlider('create');
});

document.getElementById('slSubmit').addEventListener('click',async()=>{
    const supplier   = document.getElementById('fSupplier').value.trim();
    const supType    = document.getElementById('fSupType').value;
    const ref        = document.getElementById('fRef').value.trim();
    const project    = document.getElementById('fProjSl').value;
    const zone       = document.getElementById('fZoneSl').value || ROLE.userZone;
    const assignedTo = document.getElementById('fAssigned').value;
    const expDate    = document.getElementById('fExpDate').value;
    const actDate    = document.getElementById('fActDate').value;
    const status     = document.getElementById('fDelStatus').value;
    const itemsRaw   = document.getElementById('fItems').value.trim();
    const notes      = document.getElementById('fNotes').value.trim();

    if(!supplier)  return toast('Supplier name is required.','w');
    if(!ref)       return toast('PO/PR reference is required.','w');
    if(!zone)      return toast('Please select a zone.','w');
    if(!assignedTo)return toast('Please assign a person.','w');
    if(!expDate)   return toast('Expected date is required.','w');

    const items = itemsRaw ? itemsRaw.split('\n').map(x=>x.trim()).filter(Boolean) : ['Items TBD'];
    const btn=document.getElementById('slSubmit'); btn.disabled=true;
    try {
        const payload = {supplier,supplierType:supType,ref,project,zone,assignedTo,expectedDate:expDate,actualDate:actDate,status,items,notes};
        if(editDbId) payload.dbId=editDbId;
        const saved = await apiPost(API+'?api=save_delivery', payload);
        const idx=DELIVERIES.findIndex(d=>d.id===saved.id);
        if(idx>-1) DELIVERIES[idx]=saved; else DELIVERIES.unshift(saved);
        toast(`${saved.id} ${editDbId?'updated':'scheduled'} successfully.`,'s');
        closeSlider(); renderList(); if(currentView==='cal') renderCal();
    } catch(e){ toast(e.message,'d'); }
    finally{ btn.disabled=false; }
});
<?php else: ?>
window.openEdit = id => toast('Insufficient permissions','w');
<?php endif; ?>

// ── CALENDAR VIEW — Manager+ ──────────────────────────────────────────────────
<?php if ($roleRank >= 2): ?>
function switchView(v){
    currentView=v;
    document.getElementById('listView').style.display = v==='list'?'block':'none';
    document.getElementById('calView').classList.toggle('on', v==='cal');
    document.getElementById('btnList').classList.toggle('active', v==='list');
    document.getElementById('btnCal').classList.toggle('active',  v==='cal');
    if(v==='cal') renderCal();
}

function renderCal(){
    const filtered = getFiltered();
    const monthNames=['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('calTitle').textContent=`${monthNames[calMonth]} ${calYear}`;
    const firstDay    = new Date(calYear,calMonth,1).getDay();
    const daysInMonth = new Date(calYear,calMonth+1,0).getDate();
    const daysInPrev  = new Date(calYear,calMonth,0).getDate();
    const today       = new Date();
    const byDate      = {};
    filtered.forEach(d=>{ if(!d.expectedDate) return; byDate[d.expectedDate]=byDate[d.expectedDate]||[]; byDate[d.expectedDate].push(d); });
    let cells='';
    for(let i=0;i<firstDay;i++){
        cells+=`<div class="cal-cell other-month"><div class="cal-day">${daysInPrev-firstDay+1+i}</div></div>`;
    }
    for(let d=1;d<=daysInMonth;d++){
        const dateStr=`${calYear}-${String(calMonth+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        const isToday=today.getFullYear()===calYear&&today.getMonth()===calMonth&&today.getDate()===d;
        const events=byDate[dateStr]||[];
        const maxShow=3;
        const evHtml=events.slice(0,maxShow).map(e=>`<div class="cal-event ${ceClass(e.status)}" onclick="event.stopPropagation();openView('${e.id}')" title="${e.id} — ${e.supplier}">${e.id.split('-').pop()} ${e.supplier.split(' ')[0]}</div>`).join('');
        const moreHtml=events.length>maxShow?`<div class="cal-more">+${events.length-maxShow} more</div>`:'';
        cells+=`<div class="cal-cell${isToday?' today':''}"><div class="cal-day">${d}${isToday?`<span class="cal-today-dot">${d}</span>`:''}</div>${evHtml}${moreHtml}</div>`;
    }
    const total=firstDay+daysInMonth;
    const nextCells=total%7===0?0:7-(total%7);
    for(let d=1;d<=nextCells;d++) cells+=`<div class="cal-cell other-month"><div class="cal-day">${d}</div></div>`;
    document.getElementById('calGrid').innerHTML=cells;
}

document.getElementById('calPrev').addEventListener('click',()=>{ calMonth--; if(calMonth<0){calMonth=11;calYear--;} renderCal(); });
document.getElementById('calNext').addEventListener('click',()=>{ calMonth++; if(calMonth>11){calMonth=0;calYear++;} renderCal(); });
document.getElementById('calToday').addEventListener('click',()=>{ const n=new Date(); calYear=n.getFullYear(); calMonth=n.getMonth(); renderCal(); });
<?php else: ?>
// Staff: no calendar view
function switchView(v){} // no-op
<?php endif; ?>

// ── EXPORT — Admin+ ───────────────────────────────────────────────────────────
<?php if ($CAN_EXPORT): ?>
document.getElementById('exportBtn')?.addEventListener('click',()=>{
    const cols = ROLE.rank >= 3
        ? ['id','supplier','ref','project','zone','assignedTo','expectedDate','actualDate','status']
        : ['id','supplier','assignedTo','expectedDate','status'];
    const hdrs = ROLE.rank >= 3
        ? ['Delivery ID','Supplier','PO/PR Ref','Project','Zone','Assigned To','Expected Date','Actual Date','Status']
        : ['Delivery ID','Supplier','Assigned To','Expected Date','Status'];
    const rows=[hdrs.join(','),...getFiltered().map(d=>cols.map(c=>`"${String(d[c]||'').replace(/"/g,'""')}"`).join(','))];
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
    a.download='delivery_schedule.csv'; a.click();
    toast('CSV exported.','s');
});
<?php endif; ?>

// ── TOAST ─────────────────────────────────────────────────────────────────────
function toast(msg, type='s'){
    const ic={s:'bx-check-circle',w:'bx-error',d:'bx-error-circle'};
    const el=document.createElement('div');
    el.className=`toast t${type}`;
    el.innerHTML=`<i class="bx ${ic[type]}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(()=>{ el.classList.add('out'); setTimeout(()=>el.remove(),320); },3500);
}

// ── INIT ──────────────────────────────────────────────────────────────────────
loadAll();
</script>
</body>
</html>