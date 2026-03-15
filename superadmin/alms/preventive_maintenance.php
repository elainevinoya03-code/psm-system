<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE & SCOPE (mirrors includes/superadmin_sidebar.php) ─────────────────────
function pm_resolve_role(): string {
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

$pmRoleName = pm_resolve_role();
$pmRoleRank = match($pmRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,
};
$pmUserZone = $_SESSION['zone'] ?? '';
$pmUserId   = $_SESSION['user_id'] ?? null;

/**
 * Filter schedules by role: Super Admin all; Admin/Manager by zone; User by assigned tech (tech_id = current user).
 */
function pm_scope_schedules(array $rows): array {
    global $pmRoleName, $pmUserZone, $pmUserId;
    if ($pmRoleName === 'Super Admin') return $rows;
    if ($pmRoleName === 'Admin' || $pmRoleName === 'Manager') {
        $zone = trim((string)$pmUserZone);
        if ($zone === '') return $rows;
        return array_values(array_filter($rows, fn($r) => (trim($r['zone'] ?? '') === $zone)));
    }
    $uid = $pmUserId !== null ? (string)$pmUserId : '';
    if ($uid === '') return [];
    return array_values(array_filter($rows, fn($r) => (trim($r['tech_id'] ?? '') === $uid)));
}

/** Scope assets list by zone for Admin/Manager/User. */
function pm_scope_assets(array $rows): array {
    global $pmRoleName, $pmUserZone;
    if ($pmRoleName === 'Super Admin') return $rows;
    $zone = trim((string)$pmUserZone);
    if ($zone === '') return $rows;
    return array_values(array_filter($rows, fn($r) => (trim($r['zone'] ?? '') === $zone)));
}

/** Scope staff list by zone for Admin/Manager/User. */
function pm_scope_staff(array $rows): array {
    global $pmRoleName, $pmUserZone;
    if ($pmRoleName === 'Super Admin') return $rows;
    $zone = trim((string)$pmUserZone);
    if ($zone === '') return $rows;
    return array_values(array_filter($rows, fn($r) => (trim($r['zone'] ?? '') === $zone)));
}

// ── HELPERS ──────────────────────────────────────────────────────────────────
function pm_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function pm_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function pm_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function pm_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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

function pm_next_id(): string {
    $year = date('Y');
    $rows = pm_sb('alms_maintenance_schedules', 'GET', [
        'select'          => 'schedule_id',
        'schedule_id'     => 'like.SCH-' . $year . '-%',
        'order'           => 'id.desc',
        'limit'           => '1',
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/SCH-\d{4}-(\d+)/', $rows[0]['schedule_id'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return 'SCH-' . $year . '-' . sprintf('%04d', $next);
}

function pm_build(array $row): array {
    return [
        'id'            => (int)$row['id'],
        'scheduleId'    => $row['schedule_id']    ?? '',
        'assetId'       => $row['asset_id']       ?? '',
        'assetName'     => $row['asset_name']      ?? '',
        'assetDbId'     => (int)($row['asset_db_id'] ?? 0),
        'type'          => $row['type']            ?? '',
        'freq'          => $row['freq']            ?? '',
        'zone'          => $row['zone']            ?? '',
        'lastDone'      => $row['last_done']       ?? '',
        'nextDue'       => $row['next_due']        ?? '',
        'techId'        => $row['tech_id']         ?? '',
        'tech'          => $row['tech']            ?? '',
        'techColor'     => $row['tech_color']      ?? '#6B7280',
        'techZone'      => $row['tech_zone']       ?? '',
        'status'        => $row['status']          ?? 'Scheduled',
        'notes'         => $row['notes']           ?? '',
        'createdBy'     => $row['created_by']      ?? '',
        'createdAt'     => $row['created_at']      ?? '',
        'updatedAt'     => $row['updated_at']      ?? '',
    ];
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Super Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET assets list (from alms_assets), zone-scoped for Admin/Manager/User ─
        if ($api === 'assets' && $method === 'GET') {
            $rows = pm_sb('alms_assets', 'GET', [
                'select' => 'id,asset_id,name,zone',
                'status' => 'neq.Disposed',
                'order'  => 'name.asc',
            ]);
            $rows = pm_scope_assets($rows);
            pm_ok(array_map(fn($r) => [
                'id'     => (int)$r['id'],
                'assetId'=> $r['asset_id'] ?? '',
                'name'   => $r['name']     ?? '',
                'zone'   => $r['zone']     ?? '',
            ], $rows));
        }

        // ── GET staff / technicians, zone-scoped for Admin/Manager/User ───────
        if ($api === 'staff' && $method === 'GET') {
            $rows = pm_sb('users', 'GET', [
                'select' => 'user_id,first_name,last_name,zone',
                'status' => 'eq.Active',
                'order'  => 'first_name.asc',
            ]);
            $rows = pm_scope_staff($rows);
            $staff = array_map(fn($r) => [
                'id'   => $r['user_id'],
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'zone' => $r['zone'] ?? '',
            ], $rows);
            $staff = array_values(array_filter($staff, fn($s) => $s['name'] !== ''));
            pm_ok($staff);
        }

        // ── GET schedules list (role-scoped: zone for Admin/Manager, my tasks for User) ─
        if ($api === 'list' && $method === 'GET') {
            $rows = pm_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,schedule_id,asset_id,asset_name,asset_db_id,type,freq,zone,last_done,next_due,tech_id,tech,tech_color,tech_zone,status,notes,created_by,created_at,updated_at',
                'order'  => 'next_due.asc',
            ]);
            $rows = pm_scope_schedules($rows);
            pm_ok(array_map('pm_build', $rows));
        }

        // ── GET single schedule (must be in scope for role) ─────────────────────
        if ($api === 'get' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) pm_err('Missing id', 400);
            $rows = pm_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,schedule_id,asset_id,asset_name,asset_db_id,type,freq,zone,last_done,next_due,tech_id,tech,tech_color,tech_zone,status,notes,created_by,created_at,updated_at',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) pm_err('Schedule not found', 404);
            $rows = pm_scope_schedules($rows);
            if (empty($rows)) pm_err('Not authorized to view this schedule', 403);
            pm_ok(pm_build($rows[0]));
        }

        // ── GET audit log for a schedule (schedule must be in scope) ───────────
        if ($api === 'audit' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) pm_err('Missing id', 400);
            $sched = pm_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,zone,tech_id',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($sched)) pm_err('Schedule not found', 404);
            if (empty(pm_scope_schedules($sched))) pm_err('Not authorized to view this schedule', 403);
            $rows = pm_sb('alms_maintenance_audit_log', 'GET', [
                'select'      => 'id,action_label,actor_name,actor_role,note,icon,css_class,is_super_admin,ip_address,occurred_at',
                'schedule_id' => 'eq.' . $id,
                'order'       => 'occurred_at.desc',
            ]);
            pm_ok($rows);
        }

        // ── POST save schedule (create / edit). Admin + Super Admin only; Admin zone-only. ─
        if ($api === 'save' && $method === 'POST') {
            if ($pmRoleRank <= 2) pm_err('Not authorized to add or edit schedules', 403);
            $b         = pm_body();
            $assetDbId = (int)($b['assetDbId'] ?? 0);
            $assetId   = trim($b['assetId']   ?? '');
            $assetName = trim($b['assetName'] ?? '');
            $type      = trim($b['type']      ?? '');
            $freq      = trim($b['freq']      ?? '');
            $zone      = trim($b['zone']      ?? '');
            $lastDone  = trim($b['lastDone']  ?? '') ?: null;
            $nextDue   = trim($b['nextDue']   ?? '') ?: null;
            $techId    = trim($b['techId']    ?? '');
            $tech      = trim($b['tech']      ?? '');
            $techColor = trim($b['techColor'] ?? '#6B7280');
            $techZone  = trim($b['techZone']  ?? '');
            $status    = trim($b['status']    ?? 'Scheduled');
            $notes     = trim($b['notes']     ?? '');
            $editId    = (int)($b['id']       ?? 0);

            if (!$assetId)  pm_err('Asset is required', 400);
            if (!$type)     pm_err('Maintenance type is required', 400);
            if (!$freq)     pm_err('Frequency is required', 400);
            if (!$zone)     pm_err('Zone is required', 400);
            if (!$nextDue)  pm_err('Next due date is required', 400);
            if (!$tech)     pm_err('Technician is required', 400);

            $allowedStatus = ['Scheduled', 'In Progress', 'Completed', 'Overdue', 'Skipped'];
            $allowedFreq   = ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Annual'];
            $allowedType   = ['Inspection', 'Lubrication', 'Calibration', 'Cleaning', 'Replacement', 'Testing', 'Overhaul'];
            if (!in_array($status, $allowedStatus, true)) $status = 'Scheduled';
            if (!in_array($freq,   $allowedFreq,   true)) pm_err('Invalid frequency', 400);
            if (!in_array($type,   $allowedType,   true)) pm_err('Invalid type', 400);

            $now = date('Y-m-d H:i:s');
            $payload = [
                'asset_id'    => $assetId,
                'asset_name'  => $assetName,
                'asset_db_id' => $assetDbId ?: null,
                'type'        => $type,
                'freq'        => $freq,
                'zone'        => $zone,
                'last_done'   => $lastDone,
                'next_due'    => $nextDue,
                'tech_id'     => $techId,
                'tech'        => $tech,
                'tech_color'  => $techColor,
                'tech_zone'   => $techZone,
                'status'      => $status,
                'notes'       => $notes,
                'updated_at'  => $now,
            ];

            if ($editId) {
                $existing = pm_sb('alms_maintenance_schedules', 'GET', [
                    'select' => 'id,schedule_id,status,zone',
                    'id'     => 'eq.' . $editId,
                    'limit'  => '1',
                ]);
                if (empty($existing)) pm_err('Schedule not found', 404);
                if ($pmRoleRank === 3 && trim($pmUserZone ?? '') !== '' && trim($existing[0]['zone'] ?? '') !== trim($pmUserZone))
                    pm_err('Not authorized to edit schedules in another zone', 403);
                pm_sb('alms_maintenance_schedules', 'PATCH', ['id' => 'eq.' . $editId], $payload);
                pm_sb('alms_maintenance_audit_log', 'POST', [], [[
                    'schedule_id'   => $editId,
                    'action_label'  => 'Schedule Details Updated',
                    'actor_name'    => $actor,
                    'actor_role'    => 'Admin',
                    'note'          => 'Fields updated by ' . $actor . '.',
                    'icon'          => 'bx-edit',
                    'css_class'     => 'ad-s',
                    'is_super_admin'=> false,
                    'ip_address'    => $ip,
                    'occurred_at'   => $now,
                ]]);
                $rows = pm_sb('alms_maintenance_schedules', 'GET', [
                    'select' => 'id,schedule_id,asset_id,asset_name,asset_db_id,type,freq,zone,last_done,next_due,tech_id,tech,tech_color,tech_zone,status,notes,created_by,created_at,updated_at',
                    'id'     => 'eq.' . $editId, 'limit' => '1',
                ]);
                pm_ok(pm_build($rows[0]));
            }

            // Create
            $scheduleId = pm_next_id();
            $payload['schedule_id']      = $scheduleId;
            $payload['created_by']       = $actor;
            $payload['created_user_id']  = $_SESSION['user_id'] ?? null;
            $payload['created_at']       = $now;

            if ($pmRoleRank === 3 && trim($pmUserZone ?? '') !== '' && trim($zone) !== trim($pmUserZone))
                pm_err('Not authorized to create schedules in another zone', 403);

            $inserted = pm_sb('alms_maintenance_schedules', 'POST', [], [$payload]);
            if (empty($inserted)) pm_err('Failed to create schedule', 500);
            $newId = (int)$inserted[0]['id'];

            pm_sb('alms_maintenance_audit_log', 'POST', [], [[
                'schedule_id'   => $newId,
                'action_label'  => 'Maintenance Schedule Created',
                'actor_name'    => $actor,
                'actor_role'    => 'System Admin',
                'note'          => 'Initial schedule record created for ' . $assetName . '.',
                'icon'          => 'bx-calendar-plus',
                'css_class'     => 'ad-c',
                'is_super_admin'=> false,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            $rows = pm_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,schedule_id,asset_id,asset_name,asset_db_id,type,freq,zone,last_done,next_due,tech_id,tech,tech_color,tech_zone,status,notes,created_by,created_at,updated_at',
                'id'     => 'eq.' . $newId, 'limit' => '1',
            ]);
            pm_ok(pm_build($rows[0]));
        }

        // ── POST action (done / reschedule / skip / override / start). Role-gated. ─
        if ($api === 'action' && $method === 'POST') {
            $b    = pm_body();
            $id   = (int)($b['id']   ?? 0);
            $type = trim($b['type']  ?? '');
            $now  = date('Y-m-d H:i:s');

            if (!$id)   pm_err('Missing id', 400);
            if (!$type) pm_err('Missing type', 400);

            $rows = pm_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,schedule_id,asset_id,asset_name,status,freq,next_due,tech,zone,tech_id',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) pm_err('Schedule not found', 404);
            $sched = $rows[0];
            if (empty(pm_scope_schedules($rows))) pm_err('Not authorized to act on this schedule', 403);

            if ($type === 'override') {
                if ($pmRoleRank < 4) pm_err('Only Super Admin can override status', 403);
            } elseif (in_array($type, ['reschedule', 'skip'], true)) {
                if ($pmRoleRank <= 2) pm_err('Not authorized to reschedule or skip schedules', 403);
            } elseif (in_array($type, ['done', 'start'], true)) {
                if ($pmRoleRank <= 1) {
                    $uid = $pmUserId !== null ? (string)$pmUserId : '';
                    if ($uid === '' || trim($sched['tech_id'] ?? '') !== $uid)
                        pm_err('You may only start or mark done tasks assigned to you', 403);
                }
            }

            $patch      = ['updated_at' => $now];
            $auditLabel = '';
            $auditNote  = trim($b['remarks'] ?? '');
            $auditIcon  = 'bx-info-circle';
            $auditClass = 'ad-s';
            $isSA       = false;

            switch ($type) {

                case 'done':
                    if ($sched['status'] === 'Completed')
                        pm_err('Schedule is already completed.', 400);
                    $completionDate = trim($b['completionDate'] ?? '') ?: date('Y-m-d');
                    $patch['status']    = 'Completed';
                    $patch['last_done'] = $completionDate;
                    $auditLabel = 'Maintenance Completed';
                    $auditIcon  = 'bx-check-circle';
                    $auditClass = 'ad-a';
                    $auditNote  = $auditNote ?: 'Completed on ' . $completionDate . ' by ' . $sched['tech'] . '.';
                    break;

                case 'start':
                    if (!in_array($sched['status'], ['Scheduled', 'Overdue'], true))
                        pm_err('Only Scheduled or Overdue schedules can be started.', 400);
                    $patch['status'] = 'In Progress';
                    $auditLabel = 'Maintenance Started';
                    $auditIcon  = 'bx-loader-circle';
                    $auditClass = 'ad-o';
                    $auditNote  = $auditNote ?: 'Maintenance work started by ' . $sched['tech'] . '.';
                    break;

                case 'reschedule':
                    $newDate = trim($b['newDate'] ?? '');
                    if (!$newDate) pm_err('New due date is required.', 400);
                    $oldDate = $sched['next_due'];
                    $patch['next_due'] = $newDate;
                    $patch['status']   = 'Scheduled';
                    $auditLabel = 'Schedule Rescheduled';
                    $auditIcon  = 'bx-calendar-edit';
                    $auditClass = 'ad-s';
                    $auditNote  = $auditNote ?: 'Rescheduled from ' . $oldDate . ' to ' . $newDate . '.';
                    break;

                case 'skip':
                    if (!in_array($sched['status'], ['Scheduled', 'Overdue'], true))
                        pm_err('Only Scheduled or Overdue schedules can be skipped.', 400);
                    $patch['status'] = 'Skipped';
                    $auditLabel = 'Schedule Skipped';
                    $auditIcon  = 'bx-skip-next-circle';
                    $auditClass = 'ad-e';
                    $auditNote  = $auditNote ?: 'Schedule skipped for this period.';
                    break;

                case 'override':
                    $newStatus = trim($b['newStatus'] ?? '');
                    $allowed   = ['Scheduled', 'In Progress', 'Completed', 'Overdue', 'Skipped'];
                    if (!in_array($newStatus, $allowed, true)) pm_err('Invalid status.', 400);
                    $oldStatus = $sched['status'];
                    $patch['status'] = $newStatus;
                    if ($newStatus === 'Completed') $patch['last_done'] = date('Y-m-d');
                    $auditLabel = 'Status Overridden by Super Admin';
                    $auditIcon  = 'bx-shield-quarter';
                    $auditClass = 'ad-d';
                    $auditNote  = $auditNote ?: 'Status changed from ' . $oldStatus . ' to ' . $newStatus . ' by Super Admin.';
                    $isSA       = true;
                    break;

                default:
                    pm_err('Unsupported action', 400);
            }

            pm_sb('alms_maintenance_schedules', 'PATCH', ['id' => 'eq.' . $id], $patch);
            pm_sb('alms_maintenance_audit_log', 'POST', [], [[
                'schedule_id'   => $id,
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

            $rows = pm_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,schedule_id,asset_id,asset_name,asset_db_id,type,freq,zone,last_done,next_due,tech_id,tech,tech_color,tech_zone,status,notes,created_by,created_at,updated_at',
                'id'     => 'eq.' . $id, 'limit' => '1',
            ]);
            pm_ok(pm_build($rows[0]));
        }

        // ── POST batch action. Admin + Super Admin only; only in-scope schedules. ─
        if ($api === 'batch' && $method === 'POST') {
            if ($pmRoleRank <= 2) pm_err('Not authorized to perform batch actions', 403);
            $b    = pm_body();
            $ids  = array_map('intval', $b['ids']  ?? []);
            $type = trim($b['type'] ?? '');
            $now  = date('Y-m-d H:i:s');

            if (empty($ids)) pm_err('No schedule IDs provided.', 400);
            if (!$type)      pm_err('Missing batch type.', 400);
            if ($type === 'batch-override' && $pmRoleRank < 4) pm_err('Only Super Admin can batch override status', 403);

            $updated   = 0;
            $auditNote = trim($b['remarks'] ?? '');

            foreach ($ids as $id) {
                $rows = pm_sb('alms_maintenance_schedules', 'GET', [
                    'select' => 'id,schedule_id,status,tech,zone,tech_id',
                    'id'     => 'eq.' . $id, 'limit' => '1',
                ]);
                if (empty($rows)) continue;
                if (empty(pm_scope_schedules($rows))) continue;
                $sched = $rows[0];

                $patch      = ['updated_at' => $now];
                $auditLabel = '';
                $auditIcon  = 'bx-check-double';
                $auditClass = 'ad-a';
                $isSA       = false;

                if ($type === 'batch-done') {
                    if (!in_array($sched['status'], ['Scheduled', 'In Progress', 'Overdue'], true)) continue;
                    $patch['status']    = 'Completed';
                    $patch['last_done'] = date('Y-m-d');
                    $auditLabel = 'Bulk Marked as Completed';
                    $auditIcon  = 'bx-check-double';
                    $auditClass = 'ad-a';

                } elseif ($type === 'batch-reschedule') {
                    $newDate = trim($b['newDate'] ?? '');
                    if (!$newDate) continue;
                    $patch['next_due'] = $newDate;
                    $patch['status']   = 'Scheduled';
                    $auditLabel = 'Bulk Rescheduled to ' . $newDate;
                    $auditIcon  = 'bx-calendar-edit';
                    $auditClass = 'ad-s';

                } elseif ($type === 'batch-override') {
                    $newStatus = trim($b['newStatus'] ?? '');
                    $allowed   = ['Scheduled', 'In Progress', 'Completed', 'Overdue', 'Skipped'];
                    if (!in_array($newStatus, $allowed, true)) continue;
                    $patch['status'] = $newStatus;
                    if ($newStatus === 'Completed') $patch['last_done'] = date('Y-m-d');
                    $auditLabel = 'Bulk Status Override to ' . $newStatus;
                    $auditIcon  = 'bx-shield-quarter';
                    $auditClass = 'ad-d';
                    $isSA       = true;
                } else {
                    continue;
                }

                pm_sb('alms_maintenance_schedules', 'PATCH', ['id' => 'eq.' . $id], $patch);
                pm_sb('alms_maintenance_audit_log', 'POST', [], [[
                    'schedule_id'   => $id,
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

            pm_ok(['updated' => $updated]);
        }

        pm_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        pm_err('Server error: ' . $e->getMessage(), 500);
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
<title>Preventive Maintenance — ALMS</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
#mainContent,#prSlider,#slOverlay,#actionModal,#tplModal,#viewModal,.pm-toasts{--s:#fff;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);--t1:var(--text-primary);--t2:var(--text-secondary);--t3:#9EB0A2;--hbg:var(--hover-bg-light);--bg:var(--bg-color);--grn:var(--primary-color);--gdk:var(--primary-dark);--red:#DC2626;--amb:#D97706;--blu:#2563EB;--tel:#0D9488;--shmd:0 4px 20px rgba(46,125,50,.12);--shlg:0 24px 60px rgba(0,0,0,.22);--rad:12px;--tr:var(--transition);}
#mainContent *,#prSlider *,#slOverlay *,#actionModal *,#tplModal *,#viewModal *,.pm-toasts *{box-sizing:border-box;}
.pm-wrap{max-width:1440px;margin:0 auto;padding:0 0 4rem;}
.pm-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:26px;animation:UP .4s both;}
.pm-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px;}
.pm-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15;}
.pm-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.32);}.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}.btn-ghost:hover{background:var(--hbg);color:var(--t1);}
.btn-done{background:#DCFCE7;color:#166534;border:1px solid #BBF7D0;}.btn-done:hover{background:#BBF7D0;}
.btn-reschedule{background:#F5F3FF;color:#6D28D9;border:1px solid #DDD6FE;}.btn-reschedule:hover{background:#EDE9FE;}
.btn-override{background:#FFF7ED;color:#C2410C;border:1px solid #FED7AA;}.btn-override:hover{background:#FFEDD5;}
.btn-cancel-pm{background:#F3F4F6;color:#374151;border:1px solid #D1D5DB;}.btn-cancel-pm:hover{background:#E5E7EB;}
.btn-start{background:#EFF6FF;color:var(--blu);border:1px solid #BFDBFE;}.btn-start:hover{background:#DBEAFE;}
.btn-amber{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;}.btn-amber:hover{background:#FDE68A;}
.btn-sm{font-size:12px;padding:6px 13px;}.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px;}
.btn.ionly{width:28px;height:28px;padding:0;justify-content:center;font-size:14px;flex-shrink:0;border-radius:7px;border:1px solid var(--bdm);background:var(--s);color:var(--t2);}
.btn.ionly:hover{background:var(--hbg);color:var(--grn);border-color:var(--grn);}
.btn.ionly.btn-done:hover{background:#DCFCE7;color:#166534;border-color:#BBF7D0;}
.btn.ionly.btn-start:hover{background:#EFF6FF;color:var(--blu);border-color:#BFDBFE;}
.btn.ionly.btn-reschedule:hover{background:#F5F3FF;color:#6D28D9;border-color:#DDD6FE;}
.btn.ionly.btn-override:hover{background:#FFF7ED;color:#C2410C;border-color:#FED7AA;}
.btn.ionly.btn-cancel-pm:hover{background:#F3F4F6;color:#374151;border-color:#D1D5DB;}
.btn:disabled{opacity:.4;pointer-events:none;}
/* ALERT BAR */
.pm-alerts{display:flex;flex-direction:column;gap:8px;margin-bottom:18px;animation:UP .4s .03s both;}
.pm-alert{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;font-size:12.5px;font-weight:500;}
.pm-alert i{font-size:17px;flex-shrink:0;}.pm-alert .al-close{margin-left:auto;cursor:pointer;opacity:.6;font-size:16px;flex-shrink:0;transition:var(--tr);}.pm-alert .al-close:hover{opacity:1;}
.al-overdue{background:#FEF2F2;color:#991B1B;border:1px solid #FECACA;}.al-due7{background:#FFFBEB;color:#92400E;border:1px solid #FDE68A;}.al-conflict{background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;}
/* STATS */
.pm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:22px;animation:UP .4s .05s both;}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:14px 16px;box-shadow:0 1px 4px rgba(46,125,50,.07);display:flex;align-items:center;gap:12px;}
.sc-ic{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px;}
.ic-b{background:#EFF6FF;color:var(--blu)}.ic-a{background:#FEF3C7;color:var(--amb)}.ic-g{background:#DCFCE7;color:#166534}.ic-r{background:#FEE2E2;color:var(--red)}.ic-t{background:#CCFBF1;color:var(--tel)}.ic-p{background:#F5F3FF;color:#6D28D9}.ic-d{background:#F3F4F6;color:#374151}
.sc-v{font-size:22px;font-weight:800;color:var(--t1);line-height:1;}.sc-l{font-size:11px;color:var(--t2);margin-top:2px;}
/* VIEW TOGGLE */
.view-toggle{display:flex;background:var(--s);border:1px solid var(--bdm);border-radius:10px;overflow:hidden;}
.vt-btn{font-family:'Inter',sans-serif;font-size:12.5px;font-weight:600;padding:7px 14px;border:none;cursor:pointer;transition:var(--tr);color:var(--t2);background:transparent;display:flex;align-items:center;gap:5px;}
.vt-btn:not(:last-child){border-right:1px solid var(--bdm);}
.vt-btn.active{background:var(--grn);color:#fff;}.vt-btn i{font-size:14px;}
/* TOOLBAR */
.pm-tb{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px;animation:UP .4s .1s both;}
.sw{position:relative;flex:1;min-width:220px;}.sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--t3);pointer-events:none;}
.si{width:100%;padding:9px 11px 9px 36px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);}
.si:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}.si::placeholder{color:var(--t3);}
.sel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 28px 9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;}
.sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}
/* BULK BAR */
.bulk-bar{display:none;align-items:center;gap:10px;padding:10px 16px;background:linear-gradient(135deg,#F0FDF4,#DCFCE7);border:1px solid rgba(46,125,50,.22);border-radius:12px;margin-bottom:14px;flex-wrap:wrap;}
.bulk-bar.on{display:flex;}.bulk-count{font-size:13px;font-weight:700;color:#166534;}.bulk-sep{width:1px;height:22px;background:rgba(46,125,50,.25);}
.sa-exclusive{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;border-radius:6px;padding:2px 7px;}
.sa-exclusive i{font-size:11px;}
/* TABLE */
.pm-card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);animation:UP .4s .13s both;}
.pm-tbl{width:auto;min-width:100%;border-collapse:collapse;font-size:12.5px;table-layout:fixed;}
.pm-tbl thead th{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:10px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;cursor:pointer;user-select:none;overflow:hidden;}
.pm-tbl thead th.no-sort{cursor:default;}.pm-tbl thead th:hover:not(.no-sort){color:var(--grn);}.pm-tbl thead th.sorted{color:var(--grn);}
.pm-tbl thead th .sic{margin-left:3px;opacity:.4;font-size:12px;vertical-align:middle;}.pm-tbl thead th.sorted .sic{opacity:1;}
.pm-tbl thead th:first-child,.pm-tbl tbody td:first-child{padding-left:12px;padding-right:4px;}
.pm-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .13s;}.pm-tbl tbody tr:last-child{border-bottom:none;}.pm-tbl tbody tr:hover{background:var(--hbg);}.pm-tbl tbody tr.row-selected{background:#F0FDF4;}
.pm-tbl tbody td{padding:11px 10px;vertical-align:middle;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.pm-tbl tbody td:first-child{cursor:default;}.pm-tbl tbody td:last-child{overflow:visible;white-space:nowrap;cursor:default;padding:8px;}
.cb-wrap{display:flex;align-items:center;justify-content:center;}
input[type=checkbox].cb{width:15px;height:15px;accent-color:var(--grn);cursor:pointer;}
.sid-cell{font-family:'DM Mono',monospace;font-size:11.5px;font-weight:600;color:var(--t1);}
.ini-av{width:28px;height:28px;border-radius:8px;font-size:10px;font-weight:800;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.req-cell{display:flex;flex-direction:column;gap:2px;min-width:0;}
.req-name{font-weight:600;color:var(--t1);font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.req-sub{font-size:11px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.freq-chip{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 8px;border-radius:6px;white-space:nowrap;}
.fc-daily{background:#F0FDF4;color:#15803D;}.fc-weekly{background:#EFF6FF;color:#1D4ED8;}.fc-monthly{background:#F5F3FF;color:#6D28D9;}.fc-quarterly{background:#FFF7ED;color:#C2410C;}.fc-annual{background:#F3F4F6;color:#374151;}
.date-cell{font-size:11.5px;color:var(--t2);}.date-overdue{color:var(--red);font-weight:700;}.date-due7{color:var(--amb);font-weight:700;}
.tech-cell{display:flex;align-items:center;gap:6px;}.tech-av{width:22px;height:22px;border-radius:50%;font-size:8px;font-weight:800;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;}.tech-name{font-size:12px;font-weight:500;overflow:hidden;text-overflow:ellipsis;}
.act-cell{display:flex;gap:3px;align-items:center;}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;}
.b-scheduled{background:#EFF6FF;color:#1D4ED8;}.b-inprogress{background:#FEF3C7;color:#92400E;}.b-completed{background:#DCFCE7;color:#166534;}.b-overdue{background:#FEE2E2;color:#991B1B;}.b-skipped{background:#F3F4F6;color:#374151;}
/* PAGINATION */
.pm-pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2);}
.pg-btns{display:flex;gap:5px;}.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1);}
.pgb:hover{background:var(--hbg);border-color:var(--grn);color:var(--grn);}.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff;}.pgb:disabled{opacity:.4;pointer-events:none;}
.empty{padding:72px 20px;text-align:center;color:var(--t3);}.empty i{font-size:54px;display:block;margin-bottom:14px;color:#C8E6C9;}
/* CALENDAR */
.cal-hdr{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--bd);background:var(--bg);}
.cal-nav{display:flex;align-items:center;gap:10px;}.cal-month-label{font-size:16px;font-weight:800;color:var(--t1);min-width:160px;text-align:center;}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);}
.cal-dow{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2);padding:10px 0;text-align:center;border-bottom:1px solid var(--bd);background:var(--bg);}
.cal-cell{min-height:90px;padding:6px 8px;border-right:1px solid var(--bd);border-bottom:1px solid var(--bd);transition:background .13s;}.cal-cell:nth-child(7n){border-right:none;}.cal-cell:hover{background:var(--hbg);}.cal-cell.other-month{background:#FAFAFA;}.cal-cell.today{background:#F0FDF4;}
.cal-day{font-size:12px;font-weight:700;color:var(--t2);margin-bottom:4px;}.cal-cell.today .cal-day{color:var(--grn);}
.cal-ev{font-size:10px;font-weight:600;padding:2px 6px;border-radius:4px;margin-bottom:2px;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;transition:opacity .13s;}.cal-ev:hover{opacity:.8;}
.ce-sched{background:#DBEAFE;color:#1D4ED8;}.ce-prog{background:#FEF3C7;color:#92400E;}.ce-over{background:#FEE2E2;color:#991B1B;}.ce-done{background:#DCFCE7;color:#166534;}.ce-skip{background:#F3F4F6;color:#374151;}
.cal-more{font-size:10px;color:var(--t3);font-weight:600;cursor:pointer;}.cal-more:hover{color:var(--grn);}
/* SLIDE-OVER */
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
.fta{resize:vertical;min-height:70px;}
.fdiv{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px;}.fdiv::after{content:'';flex:1;height:1px;background:var(--bd);}
.sa-note-banner{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400E;}
.sa-note-banner i{font-size:15px;flex-shrink:0;margin-top:1px;}
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
.am-fg textarea,.am-fg input,.am-fg select{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;width:100%;transition:var(--tr);}
.am-fg textarea{resize:vertical;min-height:68px;}
.am-fg textarea:focus,.am-fg input:focus,.am-fg select:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.am-fg select{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;padding-right:30px;}
.am-acts{display:flex;gap:10px;justify-content:flex-end;}
/* TEMPLATE MODAL */
#tplModal{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9100;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .25s;padding:20px;}
#tplModal.on{opacity:1;pointer-events:all;}
.tpl-box{background:#fff;border-radius:20px;width:680px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:var(--shlg);overflow:hidden;}
.tpl-hdr{padding:22px 26px 16px;border-bottom:1px solid rgba(46,125,50,.14);background:var(--bg);display:flex;align-items:flex-start;justify-content:space-between;flex-shrink:0;}
.tpl-hdr-l .mh-title{font-size:18px;font-weight:800;color:var(--t1);display:flex;align-items:center;gap:8px;}
.tpl-hdr-l .mh-sub{font-size:12px;color:var(--t2);margin-top:2px;}
.tpl-close{width:34px;height:34px;border-radius:8px;border:1px solid rgba(46,125,50,.22);background:#fff;cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--t2);transition:all .15s;}
.tpl-close:hover{background:#FEE2E2;color:#DC2626;border-color:#FECACA;}
.tpl-body{flex:1;overflow-y:auto;padding:20px 26px;display:flex;flex-direction:column;gap:10px;}
.tpl-item{display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--bg);border:1.5px solid var(--bd);border-radius:12px;cursor:pointer;transition:var(--tr);}
.tpl-item:hover{border-color:var(--grn);background:#F0FDF4;}
.tpl-ic{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;}
.tpl-name{font-size:13px;font-weight:700;color:var(--t1);}.tpl-meta{font-size:11.5px;color:var(--t2);margin-top:3px;display:flex;gap:10px;flex-wrap:wrap;}
.tpl-foot{padding:16px 26px;border-top:1px solid rgba(46,125,50,.14);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;}
/* VIEW MODAL */
#viewModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9050;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;}
#viewModal.on{opacity:1;pointer-events:all;}
.vm-box{background:#fff;border-radius:20px;width:780px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden;}
.vm-mhd{padding:24px 28px 0;border-bottom:1px solid rgba(46,125,50,.14);background:var(--bg);flex-shrink:0;}
.vm-mtp{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px;}
.vm-msi{display:flex;align-items:center;gap:16px;}
.vm-mav{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:17px;color:#fff;flex-shrink:0;}
.vm-mnm{font-size:20px;font-weight:800;color:var(--text-primary);}
.vm-mid{font-family:'DM Mono',monospace;font-size:12px;color:var(--text-secondary);margin-top:3px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.vm-mcl{width:36px;height:36px;border-radius:8px;border:1px solid rgba(46,125,50,.22);background:#fff;cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--text-secondary);transition:all .15s;flex-shrink:0;}
.vm-mcl:hover{background:#FEE2E2;color:#DC2626;border-color:#FECACA;}
.vm-mmt{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}
.vm-mc{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--text-secondary);background:#fff;border:1px solid rgba(46,125,50,.14);border-radius:8px;padding:5px 10px;}
.vm-mc i{font-size:14px;color:var(--primary-color);}
.vm-mtb{display:flex;gap:4px;}
.vm-tab{font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px 8px 0 0;cursor:pointer;transition:all .15s;color:var(--text-secondary);border:none;background:transparent;display:flex;align-items:center;gap:6px;white-space:nowrap;}
.vm-tab:hover{background:var(--hover-bg-light);}.vm-tab.active{background:var(--primary-color);color:#fff;}.vm-tab i{font-size:14px;}
.vm-mbd{flex:1;overflow-y:auto;padding:24px 28px;background:#fff;}
.vm-mbd::-webkit-scrollbar{width:4px;}.vm-mbd::-webkit-scrollbar-thumb{background:rgba(46,125,50,.22);border-radius:4px;}
.vm-tp{display:none;flex-direction:column;gap:18px;}.vm-tp.active{display:flex;}
.vm-sbs{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.vm-sb{background:var(--bg-color);border:1px solid rgba(46,125,50,.14);border-radius:10px;padding:14px 16px;}
.vm-sb .sbv{font-size:18px;font-weight:800;color:var(--text-primary);line-height:1;font-family:'DM Mono',monospace;}.vm-sb .sbl{font-size:11px;color:var(--text-secondary);margin-top:3px;}
.vm-ig{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.vm-ii label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9EB0A2;display:block;margin-bottom:4px;}
.vm-ii .v{font-size:13px;font-weight:500;color:var(--text-primary);}.vm-ii .v.muted{font-weight:400;color:#4B5563;}.vm-full{grid-column:1/-1;}
.vm-audit-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid rgba(46,125,50,.14);}
.vm-audit-item:last-child{border-bottom:none;padding-bottom:0;}
.vm-audit-dot{width:28px;height:28px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;}
.ad-c{background:#DCFCE7;color:#166534}.ad-s{background:#EFF6FF;color:#2563EB}.ad-a{background:#DCFCE7;color:#166534}.ad-r{background:#FEE2E2;color:#DC2626}.ad-e{background:#F3F4F6;color:#6B7280}.ad-o{background:#FEF3C7;color:#D97706}.ad-d{background:#F5F3FF;color:#6D28D9}
.vm-audit-body .au{font-size:13px;font-weight:500;color:var(--text-primary);}
.vm-audit-body .at{font-size:11px;color:#9EB0A2;margin-top:3px;font-family:'DM Mono',monospace;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.vm-audit-note{font-size:11.5px;color:#6B7280;margin-top:3px;font-style:italic;}
.vm-audit-ip{font-family:'DM Mono',monospace;font-size:10px;color:#9CA3AF;background:#F3F4F6;border-radius:4px;padding:1px 6px;}
.vm-audit-ts{font-family:'DM Mono',monospace;font-size:10px;color:#9EB0A2;flex-shrink:0;margin-left:auto;padding-left:8px;white-space:nowrap;}
.sa-tag{font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 5px;border:1px solid #FCD34D;}
.vm-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400E;}
.vm-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px;}
.vm-mft{padding:16px 28px;border-top:1px solid rgba(46,125,50,.14);background:var(--bg-color);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap;}
/* TOASTS */
.pm-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:TIN .3s ease;}
.toast.ts{background:var(--grn);}.toast.tw{background:var(--amb);}.toast.td{background:var(--red);}.toast.out{animation:TOUT .3s ease forwards;}
@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}
@keyframes SHK{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-5px)}40%,80%{transform:translateX(5px)}}
@media(max-width:900px){.pm-stats{grid-template-columns:repeat(2,1fr)}.fr{grid-template-columns:1fr}#prSlider{width:100vw}.vm-sbs{grid-template-columns:repeat(2,1fr)}.vm-ig{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="pm-wrap">

  <div class="pm-ph">
    <div>
      <p class="ey">ALMS · Asset Lifecycle &amp; Maintenance</p>
      <h1>Preventive Maintenance</h1>
    </div>
    <div class="pm-ph-r">
      <div class="view-toggle">
        <button class="vt-btn active" id="listViewBtn"><i class="bx bx-list-ul"></i> List</button>
        <button class="vt-btn" id="calViewBtn"><i class="bx bx-calendar"></i> Calendar</button>
      </div>
      <button class="btn btn-ghost" id="tplBtn"><i class="bx bx-library"></i> Templates</button>
      <button class="btn btn-primary" id="createBtn"><i class="bx bx-plus"></i> Add Schedule</button>
    </div>
  </div>

  <div class="pm-alerts" id="alertBar"></div>
  <div class="pm-stats"  id="statsBar"></div>

  <div class="pm-tb">
    <div class="sw"><i class="bx bx-search"></i><input type="text" class="si" id="srch" placeholder="Search by asset name, schedule ID, or technician…"></div>
    <select class="sel" id="fStatus">
      <option value="">All Statuses</option>
      <option>Scheduled</option><option>In Progress</option>
      <option>Completed</option><option>Overdue</option><option>Skipped</option>
    </select>
    <select class="sel" id="fType">
      <option value="">All Types</option>
      <option>Inspection</option><option>Lubrication</option><option>Calibration</option>
      <option>Cleaning</option><option>Replacement</option><option>Testing</option><option>Overhaul</option>
    </select>
    <select class="sel" id="fFreq">
      <option value="">All Frequencies</option>
      <option>Daily</option><option>Weekly</option><option>Monthly</option>
      <option>Quarterly</option><option>Annual</option>
    </select>
  </div>

  <div class="bulk-bar" id="bulkBar">
    <span class="bulk-count" id="bulkCount">0 selected</span>
    <div class="bulk-sep"></div>
    <button class="btn btn-done btn-sm" id="batchDoneBtn"><i class="bx bx-check-double"></i> Mark Done</button>
    <button class="btn btn-reschedule btn-sm" id="batchReschedBtn"><i class="bx bx-calendar-edit"></i> Reschedule</button>
    <button class="btn btn-override btn-sm" id="batchOverrideBtn"><i class="bx bx-shield-quarter"></i> Override Status</button>
    <button class="btn btn-ghost btn-sm" id="clearSelBtn"><i class="bx bx-x-circle"></i> Clear</button>
    <span class="sa-exclusive" style="margin-left:auto"><i class="bx bx-shield-quarter"></i> Super Admin Actions Included</span>
  </div>

  <!-- LIST VIEW -->
  <div id="listView">
    <div class="pm-card">
      <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
      <table class="pm-tbl" id="tbl">
        <colgroup>
          <col style="width:38px"><col style="width:120px"><col style="width:200px">
          <col style="width:110px"><col style="width:95px"><col style="width:105px">
          <col style="width:105px"><col style="width:160px"><col style="width:90px">
          <col style="width:120px"><col style="width:155px">
        </colgroup>
        <thead><tr>
          <th class="no-sort" data-col="cb"><div class="cb-wrap"><input type="checkbox" class="cb" id="checkAll"></div></th>
          <th data-col="scheduleId">Schedule ID <i class="bx bx-sort sic"></i></th>
          <th data-col="assetName">Asset <i class="bx bx-sort sic"></i></th>
          <th data-col="type">Type <i class="bx bx-sort sic"></i></th>
          <th data-col="freq">Frequency <i class="bx bx-sort sic"></i></th>
          <th data-col="lastDone">Last Done <i class="bx bx-sort sic"></i></th>
          <th data-col="nextDue">Next Due <i class="bx bx-sort sic"></i></th>
          <th data-col="tech">Technician <i class="bx bx-sort sic"></i></th>
          <th data-col="zone">Zone <i class="bx bx-sort sic"></i></th>
          <th data-col="status">Status <i class="bx bx-sort sic"></i></th>
          <th class="no-sort">Actions</th>
        </tr></thead>
        <tbody id="tbody"></tbody>
      </table>
      </div>
      <div class="pm-pager" id="pager"></div>
    </div>
  </div>

  <!-- CALENDAR VIEW -->
  <div id="calView" style="display:none">
    <div class="pm-card">
      <div class="cal-hdr">
        <div class="cal-nav">
          <button class="btn btn-ghost btn-sm" id="calPrev"><i class="bx bx-chevron-left"></i></button>
          <span class="cal-month-label" id="calMonth"></span>
          <button class="btn btn-ghost btn-sm" id="calNext"><i class="bx bx-chevron-right"></i></button>
        </div>
        <button class="btn btn-ghost btn-sm" id="calToday">Today</button>
      </div>
      <div class="cal-grid" id="calGrid"></div>
    </div>
  </div>

</div>
</main>

<div class="pm-toasts" id="toastWrap"></div>
<div id="slOverlay"></div>

<!-- CREATE / EDIT SLIDER -->
<div id="prSlider">
  <div class="sl-hdr">
    <div><div class="sl-title" id="slTitle">Add Maintenance Schedule</div><div class="sl-subtitle" id="slSub">Fill in all required fields below</div></div>
    <button class="sl-close" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-body">
    <div class="sa-note-banner"><i class="bx bx-shield-quarter"></i><span><strong>Super Admin:</strong> You may assign technicians across zones and override schedule frequencies.</span></div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Asset <span>*</span></label>
        <select class="fs" id="fAsset"></select>
      </div>
      <div class="fg">
        <label class="fl">Maintenance Type <span>*</span></label>
        <select class="fs" id="fTypeSl">
          <option value="">Select…</option>
          <option>Inspection</option><option>Lubrication</option><option>Calibration</option>
          <option>Cleaning</option><option>Replacement</option><option>Testing</option><option>Overhaul</option>
        </select>
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Frequency <span>*</span></label>
        <select class="fs" id="fFreqSl">
          <option value="">Select…</option>
          <option>Daily</option><option>Weekly</option><option>Monthly</option>
          <option>Quarterly</option><option>Annual</option>
        </select>
      </div>
      <div class="fg">
        <label class="fl">Zone <span>*</span></label>
        <select class="fs" id="fZoneSl"><option value="">Select…</option></select>
      </div>
    </div>
    <div class="fdiv">Schedule Dates</div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Last Done Date</label>
        <input type="date" class="fi" id="fLastDone">
      </div>
      <div class="fg">
        <label class="fl">Next Due Date <span>*</span></label>
        <input type="date" class="fi" id="fNextDue">
      </div>
    </div>
    <div class="fdiv">Assignment</div>
    <div class="fg">
      <label class="fl">Assigned Technician <span>*</span></label>
      <select class="fs" id="fTech"><option value="">Select…</option></select>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Status</label>
        <select class="fs" id="fStatusSl">
          <option>Scheduled</option><option>In Progress</option>
          <option>Completed</option><option>Overdue</option><option>Skipped</option>
        </select>
      </div>
    </div>
    <div class="fg">
      <label class="fl">Notes / Instructions</label>
      <textarea class="fta" id="fNotes" placeholder="Special instructions or notes for this maintenance schedule…"></textarea>
    </div>
  </div>
  <div class="sl-foot">
    <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-save"></i> Save Schedule</button>
  </div>
</div>

<!-- ACTION MODAL -->
<div id="actionModal">
  <div class="am-box">
    <div class="am-icon" id="amIcon">✅</div>
    <div class="am-title" id="amTitle">Confirm Action</div>
    <div class="am-body"  id="amBody"></div>
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

<!-- TEMPLATE LIBRARY MODAL -->
<div id="tplModal">
  <div class="tpl-box">
    <div class="tpl-hdr">
      <div class="tpl-hdr-l">
        <div class="mh-title"><i class="bx bx-library" style="font-size:20px;color:var(--grn)"></i> Maintenance Template Library</div>
        <div class="mh-sub">Apply a template to quickly populate a new schedule</div>
      </div>
      <button class="tpl-close" id="tplClose"><i class="bx bx-x"></i></button>
    </div>
    <div class="tpl-body" id="tplBody"></div>
    <div class="tpl-foot">
      <button class="btn btn-ghost btn-sm" id="tplCancel">Close</button>
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
const ROLE = '<?= addslashes($pmRoleName) ?>';
const ROLE_RANK = <?= (int)$pmRoleRank ?>;
const USER_ZONE = '<?= addslashes($pmUserZone ?? '') ?>';
const USER_ID = '<?= addslashes((string)($pmUserId ?? '')) ?>';

// ── API ───────────────────────────────────────────────────────────────────────
async function apiFetch(path, opts={}) {
    const r = await fetch(path, {headers:{'Content-Type':'application/json'}, ...opts});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p => apiFetch(p);
const apiPost = (p,b) => apiFetch(p, {method:'POST', body:JSON.stringify(b)});

// ── ROLE-BASED UI ─────────────────────────────────────────────────────────────
function applyRoleColumnVisibility(){
    const tbl=document.getElementById('tbl');
    if(!tbl) return;
    const ths=tbl.querySelectorAll('thead th[data-col]');
    const tds=tbl.querySelectorAll('tbody td[data-col]');
    const hide=(cols)=>{
        cols.forEach(c=>{
            ths.forEach(th=>{ if(th.dataset.col===c) th.style.display='none'; });
            tds.forEach(td=>{ if(td.dataset.col===c) td.style.display='none'; });
        });
    };
    if(ROLE_RANK<=2) hide(['cb','type','freq','lastDone','zone']);
    if(ROLE_RANK<=1) hide(['tech']);
}
function canAddEdit(){ return ROLE_RANK>=3; }
function canReschedule(){ return ROLE_RANK>=3; }
function canSkip(){ return ROLE_RANK>=3; }
function canOverride(){ return ROLE_RANK>=4; }
function canMarkDoneOrStart(r){ return ROLE_RANK>=3 || (ROLE_RANK>=1 && String(r.techId)===USER_ID); }
if(ROLE_RANK<=2){
    const c=document.getElementById('createBtn'); if(c) c.style.display='none';
    const t=document.getElementById('tplBtn');   if(t) t.style.display='none';
    const b=document.getElementById('bulkBar');   if(b) b.style.display='none';
}
if(ROLE_RANK<4) document.querySelectorAll('.sa-exclusive').forEach(el=>el.style.display='none');

// ── STATE ─────────────────────────────────────────────────────────────────────
let SCHEDULES=[], ASSETS=[], STAFF=[];
let sortCol='nextDue', sortDir='asc', page=1;
const PAGE=10;
let selectedIds=new Set();
let actionKey=null, actionTarget=null, actionCb=null;
let editId=null;
let currentView='list';
let calYear=new Date().getFullYear(), calMon=new Date().getMonth();

const FREQ_ORDER = {Daily:1,Weekly:2,Monthly:3,Quarterly:4,Annual:5};
const TEMPLATES = [
    {id:'TPL-01',name:'Engine Oil & Filter Change',    type:'Replacement', freq:'Monthly',   icon:'bx-droplet',     cls:'ic-g', notes:'Check oil level, drain old oil, replace filter, fill new oil, test run.'},
    {id:'TPL-02',name:'Full Equipment Inspection',     type:'Inspection',  freq:'Weekly',    icon:'bx-search-alt',  cls:'ic-b', notes:'Visual check, fluid levels, safety guards, gauges, leaks, tire/track condition.'},
    {id:'TPL-03',name:'Hydraulic System Service',      type:'Lubrication', freq:'Quarterly', icon:'bx-trending-up', cls:'ic-t', notes:'Inspect hoses, check fluid level, flush and refill hydraulic oil, test cylinders.'},
    {id:'TPL-04',name:'Electrical System Test',        type:'Testing',     freq:'Monthly',   icon:'bx-bolt-circle', cls:'ic-a', notes:'Battery voltage, alternator output, starter motor, lighting, safety cutoffs.'},
    {id:'TPL-05',name:'Tracks & Undercarriage Check',  type:'Inspection',  freq:'Weekly',    icon:'bx-cog',         cls:'ic-d', notes:'Track tension, roller wear, sprocket teeth, idler condition, frame cracks.'},
    {id:'TPL-06',name:'Annual Major Overhaul',         type:'Overhaul',    freq:'Annual',    icon:'bx-wrench',      cls:'ic-r', notes:'Full disassembly, all wear parts replaced, OEM specs restoration, test & commission.'},
    {id:'TPL-07',name:'Air Filter & Coolant Flush',    type:'Cleaning',    freq:'Quarterly', icon:'bx-water',       cls:'ic-p', notes:'Remove & clean air filter, check coolant concentration, flush and refill.'},
    {id:'TPL-08',name:'Crane Wire Rope Inspection',    type:'Inspection',  freq:'Monthly',   icon:'bx-link',        cls:'ic-b', notes:'Inspect wire rope for kinks, corrosion, broken wires; lubricate; check drum.'},
];

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc    = s=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const ini    = n=>String(n||'').split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
const fD     = d=>{ if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); };
const today  = ()=>new Date().toISOString().split('T')[0];
const dayDiff= d=>Math.round((new Date(d+'T00:00:00')-new Date(today()+'T00:00:00'))/(864e5));

function badge(s){
    const m={Scheduled:'b-scheduled','In Progress':'b-inprogress',Completed:'b-completed',Overdue:'b-overdue',Skipped:'b-skipped'};
    return `<span class="badge ${m[s]||''}">${esc(s)}</span>`;
}
function freqChip(f){
    const m={Daily:'fc-daily',Weekly:'fc-weekly',Monthly:'fc-monthly',Quarterly:'fc-quarterly',Annual:'fc-annual'};
    return `<span class="freq-chip ${m[f]||''}">${esc(f)}</span>`;
}
function dateCell(d, status){
    if(!d) return '<span class="date-cell">—</span>';
    const diff=dayDiff(d);
    let cls='date-cell', sfx='';
    if(!['Completed','Skipped'].includes(status)){
        if(diff<0)      {cls='date-cell date-overdue'; sfx=` <span style="font-size:10px">(${Math.abs(diff)}d ago)</span>`;}
        else if(diff<=7){cls='date-cell date-due7'; sfx=` <span style="font-size:10px">(${diff}d)</span>`;}
    }
    return `<span class="${cls}">${fD(d)}${sfx}</span>`;
}

// ── LOAD ──────────────────────────────────────────────────────────────────────
async function loadAll(){
    try {
        [ASSETS, STAFF, SCHEDULES] = await Promise.all([
            apiGet(API+'?api=assets').catch(()=>[]),
            apiGet(API+'?api=staff').catch(()=>[]),
            apiGet(API+'?api=list'),
        ]);
    } catch(e){ toast('Failed to load data: '+e.message,'d'); }
    populateSliderDropdowns();
    renderList();
}

// ── SLIDER DROPDOWNS ──────────────────────────────────────────────────────────
function populateSliderDropdowns(){
    const aEl=document.getElementById('fAsset');
    aEl.innerHTML='<option value="">Select asset…</option>'+ASSETS.map(a=>`<option value="${esc(a.assetId)}" data-name="${esc(a.name)}" data-zone="${esc(a.zone)}" data-dbid="${a.id}">${esc(a.assetId)} — ${esc(a.name)}</option>`).join('');

    const zEl=document.getElementById('fZoneSl');
    const zones=[...new Set(ASSETS.map(a=>a.zone).filter(Boolean))].sort();
    zEl.innerHTML='<option value="">Select…</option>'+zones.map(z=>`<option>${esc(z)}</option>`).join('');

    const tEl=document.getElementById('fTech');
    tEl.innerHTML='<option value="">Select technician…</option>'+STAFF.map(s=>`<option value="${esc(s.id)}" data-name="${esc(s.name)}" data-zone="${esc(s.zone)}">${esc(s.name)} (${esc(s.zone)})</option>`).join('');
}

// Auto-fill zone when asset is selected
document.getElementById('fAsset').addEventListener('change', function(){
    const opt=this.options[this.selectedIndex];
    if(opt.value) document.getElementById('fZoneSl').value=opt.dataset.zone||'';
});

// ── ALERTS ────────────────────────────────────────────────────────────────────
function renderAlerts(){
    const overdue=SCHEDULES.filter(r=>r.status==='Overdue').length;
    const due7=SCHEDULES.filter(r=>{ const d=dayDiff(r.nextDue); return d>=0&&d<=7&&r.status==='Scheduled'; }).length;
    // Cross-zone conflicts: tech assigned to >1 record same day in different zone
    const techDayZone={};
    SCHEDULES.forEach(r=>{
        const k=`${r.techId}-${r.nextDue}`;
        if(!techDayZone[k]) techDayZone[k]=new Set();
        techDayZone[k].add(r.zone);
    });
    const conflicts=Object.values(techDayZone).filter(s=>s.size>1).length;
    const alerts=[];
    if(overdue>0)   alerts.push({cls:'al-overdue',  icon:'bx-error-circle',text:`<strong>${overdue} Overdue</strong> maintenance schedule${overdue>1?'s':''} require immediate attention.`});
    if(due7>0)      alerts.push({cls:'al-due7',     icon:'bx-time-five',   text:`<strong>${due7} schedule${due7>1?'s':''}</strong> due within the next 7 days.`});
    if(conflicts>0) alerts.push({cls:'al-conflict', icon:'bx-user-x',      text:`<strong>${conflicts} cross-zone technician conflict${conflicts>1?'s':''}</strong> detected. Review assignments.`});
    document.getElementById('alertBar').innerHTML=alerts.map((a,i)=>`
        <div class="pm-alert ${a.cls}" id="al${i}">
          <i class="bx ${a.icon}"></i><span>${a.text}</span>
          <i class="bx bx-x al-close" onclick="document.getElementById('al${i}').remove()"></i>
        </div>`).join('');
}

// ── STATS ─────────────────────────────────────────────────────────────────────
function renderStats(){
    const tot  =SCHEDULES.length;
    const sched=SCHEDULES.filter(r=>r.status==='Scheduled').length;
    const prog =SCHEDULES.filter(r=>r.status==='In Progress').length;
    const done =SCHEDULES.filter(r=>r.status==='Completed').length;
    const over =SCHEDULES.filter(r=>r.status==='Overdue').length;
    const skip =SCHEDULES.filter(r=>r.status==='Skipped').length;
    document.getElementById('statsBar').innerHTML=`
        <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-calendar-check"></i></div><div><div class="sc-v">${tot}</div><div class="sc-l">Total Schedules</div></div></div>
        <div class="sc"><div class="sc-ic ic-t"><i class="bx bx-time-five"></i></div><div><div class="sc-v">${sched}</div><div class="sc-l">Scheduled</div></div></div>
        <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-loader-circle"></i></div><div><div class="sc-v">${prog}</div><div class="sc-l">In Progress</div></div></div>
        <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${done}</div><div class="sc-l">Completed</div></div></div>
        <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-error-circle"></i></div><div><div class="sc-v">${over}</div><div class="sc-l">Overdue</div></div></div>
        <div class="sc"><div class="sc-ic ic-d"><i class="bx bx-skip-next-circle"></i></div><div><div class="sc-v">${skip}</div><div class="sc-l">Skipped</div></div></div>`;
}

// ── FILTER / SORT ─────────────────────────────────────────────────────────────
function getFiltered(){
    const q  =document.getElementById('srch').value.trim().toLowerCase();
    const fs =document.getElementById('fStatus').value;
    const ft =document.getElementById('fType').value;
    const ff =document.getElementById('fFreq').value;
    return SCHEDULES.filter(r=>{
        if(q&&!r.scheduleId.toLowerCase().includes(q)&&!r.assetName.toLowerCase().includes(q)&&!r.tech.toLowerCase().includes(q)) return false;
        if(fs&&r.status!==fs) return false;
        if(ft&&r.type!==ft)   return false;
        if(ff&&r.freq!==ff)   return false;
        return true;
    });
}
function getSorted(list){
    return [...list].sort((a,b)=>{
        let va=a[sortCol], vb=b[sortCol];
        if(sortCol==='freq'){ va=FREQ_ORDER[va]||99; vb=FREQ_ORDER[vb]||99; return sortDir==='asc'?va-vb:vb-va; }
        va=String(va||'').toLowerCase(); vb=String(vb||'').toLowerCase();
        return sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va);
    });
}

// ── RENDER TABLE ──────────────────────────────────────────────────────────────
function renderList(){
    renderStats(); renderAlerts();
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
        tb.innerHTML=`<tr><td colspan="11"><div class="empty"><i class="bx bx-calendar-x"></i><p>No maintenance schedules found.</p></div></td></tr>`;
    } else {
        tb.innerHTML=slice.map(r=>{
            const chk=selectedIds.has(r.scheduleId);
            const isCrossZone=r.techZone&&r.zone&&r.techZone!==r.zone;
            const canDone=['Scheduled','In Progress','Overdue'].includes(r.status);
            const canStart=['Scheduled','Overdue'].includes(r.status);
            const canEdit=r.status!=='Completed';
            const skipAllowed=['Scheduled','Overdue'].includes(r.status);
            const showEdit=canAddEdit()&&canEdit;
            const showStart=canMarkDoneOrStart(r)&&canStart;
            const showDone=canMarkDoneOrStart(r)&&canDone;
            const showResched=canReschedule()&&canEdit;
            const showSkip=canSkip()&&skipAllowed;
            const showOverride=canOverride();
            const showCb=ROLE_RANK>=3;
            return `<tr class="${chk?'row-selected':''}">
                <td data-col="cb" onclick="event.stopPropagation()"><div class="cb-wrap">${showCb?`<input type="checkbox" class="cb row-cb" data-id="${r.scheduleId}" ${chk?'checked':''}>`:''}</div></td>
                <td data-col="scheduleId" onclick="openView(${r.id})"><span class="sid-cell">${esc(r.scheduleId)}</span></td>
                <td data-col="assetName" onclick="openView(${r.id})">
                    <div style="display:flex;align-items:center;gap:7px;min-width:0;overflow:hidden">
                        <div class="ini-av" style="background:#2E7D32">${ini(r.assetName)}</div>
                        <div class="req-cell">
                            <div class="req-name">${esc(r.assetName)}</div>
                            <div class="req-sub">${esc(r.assetId)}</div>
                        </div>
                    </div>
                </td>
                <td data-col="type" onclick="openView(${r.id})"><span style="font-size:12px;font-weight:600;color:var(--t1)">${esc(r.type)}</span></td>
                <td data-col="freq" onclick="openView(${r.id})">${freqChip(r.freq)}</td>
                <td data-col="lastDone" onclick="openView(${r.id})"><span class="date-cell">${fD(r.lastDone)}</span></td>
                <td data-col="nextDue" onclick="openView(${r.id})">${dateCell(r.nextDue,r.status)}</td>
                <td data-col="tech" onclick="openView(${r.id})">
                    <div class="tech-cell">
                        <div class="tech-av" style="background:${r.techColor||'#6B7280'}">${ini(r.tech)}</div>
                        <span class="tech-name">${esc(r.tech.split(' ').slice(-1)[0]||r.tech)}</span>
                        ${isCrossZone?`<i class="bx bx-transfer" title="Cross-zone" style="font-size:12px;color:var(--amb);flex-shrink:0"></i>`:''}
                    </div>
                </td>
                <td data-col="zone" onclick="openView(${r.id})"><span style="font-size:12px;font-weight:600">${esc(r.zone)}</span></td>
                <td data-col="status" onclick="openView(${r.id})">${badge(r.status)}</td>
                <td onclick="event.stopPropagation()">
                    <div class="act-cell">
                        ${showEdit?`<button class="btn ionly" onclick="openEdit(${r.id})" title="Edit"><i class="bx bx-edit"></i></button>`:''}
                        ${showStart?`<button class="btn ionly btn-start" onclick="doAction('start',${r.id})" title="Start"><i class="bx bx-play"></i></button>`:''}
                        ${showDone?`<button class="btn ionly btn-done" onclick="doAction('done',${r.id})" title="Mark Done"><i class="bx bx-check"></i></button>`:''}
                        ${showResched?`<button class="btn ionly btn-reschedule" onclick="doAction('reschedule',${r.id})" title="Reschedule"><i class="bx bx-calendar-edit"></i></button>`:''}
                        ${showSkip?`<button class="btn ionly btn-cancel-pm" onclick="doAction('skip',${r.id})" title="Skip"><i class="bx bx-skip-next-circle"></i></button>`:''}
                        ${showOverride?`<button class="btn ionly btn-override" onclick="doAction('override',${r.id})" title="Override"><i class="bx bx-shield-quarter"></i></button>`:''}
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
    applyRoleColumnVisibility();
    const s=(page-1)*PAGE+1, e=Math.min(page*PAGE,total);
    let btns='';
    for(let i=1;i<=pages;i++){
        if(i===1||i===pages||(i>=page-2&&i<=page+2)) btns+=`<button class="pgb ${i===page?'active':''}" onclick="goPage(${i})">${i}</button>`;
        else if(i===page-3||i===page+3) btns+=`<button class="pgb" disabled>…</button>`;
    }
    document.getElementById('pager').innerHTML=`
        <span>${total===0?'No results':`Showing ${s}–${e} of ${total} schedules`}</span>
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
['srch','fStatus','fType','fFreq'].forEach(id=>
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
    const ids=slice.map(r=>r.scheduleId);
    const all=ids.length>0&&ids.every(id=>selectedIds.has(id));
    ca.checked=all; ca.indeterminate=!all&&ids.some(id=>selectedIds.has(id));
}
document.getElementById('checkAll').addEventListener('change',function(){
    const slice=getSorted(getFiltered()).slice((page-1)*PAGE,page*PAGE);
    slice.forEach(r=>{if(this.checked) selectedIds.add(r.scheduleId); else selectedIds.delete(r.scheduleId);});
    renderList(); updateBulkBar();
});
document.getElementById('clearSelBtn').addEventListener('click',()=>{selectedIds.clear();renderList();updateBulkBar();});

document.getElementById('batchDoneBtn').addEventListener('click',()=>{
    const valid=[...selectedIds].map(sid=>SCHEDULES.find(r=>r.scheduleId===sid)).filter(r=>r&&['Scheduled','In Progress','Overdue'].includes(r.status));
    if(!valid.length){toast('No eligible schedules selected.','w');return;}
    showActionModal('✅',`Mark ${valid.length} Schedule(s) Done`,`Mark <strong>${valid.length}</strong> schedule(s) as Completed.`,false,'','','btn-done','<i class="bx bx-check-double"></i> Mark All Done',
        async()=>{
            const rmk=document.getElementById('amRemarks').value.trim();
            try{
                const r=await apiPost(API+'?api=batch',{type:'batch-done',ids:valid.map(s=>s.id),remarks:rmk});
                const updated=await apiGet(API+'?api=list'); SCHEDULES=updated;
                selectedIds.clear(); renderList(); updateBulkBar();
                toast(`${r.updated} schedule(s) marked as Completed.`,'s');
            }catch(e){toast(e.message,'d');}
        }
    );
});

document.getElementById('batchReschedBtn').addEventListener('click',()=>{
    const valid=[...selectedIds].map(sid=>SCHEDULES.find(r=>r.scheduleId===sid)).filter(Boolean);
    if(!valid.length){toast('No schedules selected.','w');return;}
    showActionModal('📅',`Reschedule ${valid.length} Schedule(s)`,`Set a new due date for <strong>${valid.length}</strong> schedule(s).`,false,'',
        `<div class="am-fg"><label>New Due Date <span style="color:var(--red)">*</span></label><input type="date" id="amBatchDate" value="${today()}"></div>`,
        'btn-reschedule','<i class="bx bx-calendar-edit"></i> Reschedule All',
        async()=>{
            const nd=document.getElementById('amBatchDate')?.value;
            if(!nd){toast('Please select a date.','w');return false;}
            const rmk=document.getElementById('amRemarks').value.trim();
            try{
                const r=await apiPost(API+'?api=batch',{type:'batch-reschedule',ids:valid.map(s=>s.id),newDate:nd,remarks:rmk});
                const updated=await apiGet(API+'?api=list'); SCHEDULES=updated;
                selectedIds.clear(); renderList(); updateBulkBar();
                toast(`${r.updated} schedule(s) rescheduled.`,'s');
            }catch(e){toast(e.message,'d');}
        }
    );
});

document.getElementById('batchOverrideBtn').addEventListener('click',()=>{
    const valid=[...selectedIds].map(sid=>SCHEDULES.find(r=>r.scheduleId===sid)).filter(Boolean);
    if(!valid.length){toast('No schedules selected.','w');return;}
    showActionModal('🛡️',`Override Status — ${valid.length} Schedule(s)`,`Override status for <strong>${valid.length}</strong> schedule(s).`,true,'Super Admin override bypasses normal workflow.',
        `<div class="am-fg"><label>New Status <span style="color:var(--red)">*</span></label><select id="amBatchStatus" style="font-family:Inter,sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;width:100%"><option>Scheduled</option><option>In Progress</option><option>Completed</option><option>Overdue</option><option>Skipped</option></select></div>`,
        'btn-override','<i class="bx bx-shield-quarter"></i> Override All',
        async()=>{
            const ns=document.getElementById('amBatchStatus')?.value;
            if(!ns){toast('Please select a status.','w');return false;}
            const rmk=document.getElementById('amRemarks').value.trim();
            try{
                const r=await apiPost(API+'?api=batch',{type:'batch-override',ids:valid.map(s=>s.id),newStatus:ns,remarks:rmk});
                const updated=await apiGet(API+'?api=list'); SCHEDULES=updated;
                selectedIds.clear(); renderList(); updateBulkBar();
                toast(`${r.updated} schedule(s) status set to ${ns}.`,'s');
            }catch(e){toast(e.message,'d');}
        }
    );
});

// ── ACTION MODAL ──────────────────────────────────────────────────────────────
function showActionModal(icon,title,body,sa,saText,extraHtml,btnCls,btnLabel,onConfirm=null){
    document.getElementById('amIcon').textContent=icon;
    document.getElementById('amTitle').textContent=title;
    document.getElementById('amBody').innerHTML=body;
    const san=document.getElementById('amSaNote');
    if(sa){san.style.display='flex';document.getElementById('amSaText').textContent=saText;}
    else san.style.display='none';
    document.getElementById('amDynamicInputs').innerHTML=extraHtml||'';
    document.getElementById('amRemarks').value='';
    const cb=document.getElementById('amConfirm');
    cb.className=`btn btn-sm ${btnCls}`; cb.innerHTML=btnLabel;
    actionCb=onConfirm;
    document.getElementById('actionModal').classList.add('on');
}

function doAction(type, dbId){
    const r=SCHEDULES.find(x=>x.id===dbId); if(!r) return;
    actionTarget=dbId; actionKey=type;

    const cfg={
        done:{
            icon:'✅',title:'Mark as Completed',
            body:`Mark <strong>${esc(r.scheduleId)}</strong> — <em>${esc(r.assetName)}</em> as completed.`,
            sa:false,saText:'',
            extra:`<div class="am-fg"><label>Completion Date</label><input type="date" id="amInputDate" value="${today()}"></div>`,
            btn:'btn-done',label:'<i class="bx bx-check"></i> Mark Done',
        },
        start:{
            icon:'▶️',title:'Start Maintenance',
            body:`Mark <strong>${esc(r.scheduleId)}</strong> — <em>${esc(r.assetName)}</em> as In Progress.`,
            sa:false,saText:'',extra:'',
            btn:'btn-start',label:'<i class="bx bx-play"></i> Start',
        },
        reschedule:{
            icon:'📅',title:'Reschedule',
            body:`Reschedule <strong>${esc(r.scheduleId)}</strong> — <em>${esc(r.assetName)}</em>.`,
            sa:false,saText:'',
            extra:`<div class="am-fg"><label>New Due Date <span style="color:var(--red)">*</span></label><input type="date" id="amInputDate" value="${r.nextDue||today()}"></div>`,
            btn:'btn-reschedule',label:'<i class="bx bx-calendar-edit"></i> Reschedule',
        },
        skip:{
            icon:'⏭',title:'Skip Schedule',
            body:`Skip <strong>${esc(r.scheduleId)}</strong> for this period?`,
            sa:false,saText:'',extra:'',
            btn:'btn-cancel-pm',label:'<i class="bx bx-skip-next-circle"></i> Skip',
        },
        override:{
            icon:'🛡️',title:'Override Status',
            body:`Override status for <strong>${esc(r.scheduleId)}</strong> — <em>${esc(r.assetName)}</em>.`,
            sa:true,saText:'Super Admin override. Full audit logged.',
            extra:`<div class="am-fg"><label>New Status <span style="color:var(--red)">*</span></label><select id="amInputStatus" style="font-family:Inter,sans-serif;font-size:13px;padding:10px 30px 10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;width:100%;appearance:none"><option>Scheduled</option><option>In Progress</option><option>Completed</option><option>Overdue</option><option>Skipped</option></select></div>`,
            btn:'btn-override',label:'<i class="bx bx-shield-quarter"></i> Override',
        },
    };
    const c=cfg[type]; if(!c) return;
    showActionModal(c.icon,c.title,c.body,c.sa,c.saText,c.extra,c.btn,c.label);
}

document.getElementById('amConfirm').addEventListener('click',async()=>{
    if(actionCb){const res=await actionCb();if(res===false)return;document.getElementById('actionModal').classList.remove('on');actionCb=null;return;}
    const r=SCHEDULES.find(x=>x.id===actionTarget); if(!r) return;
    const rmk=document.getElementById('amRemarks').value.trim();
    const payload={id:r.id,type:actionKey,remarks:rmk};
    if(actionKey==='done')       payload.completionDate=document.getElementById('amInputDate')?.value||today();
    if(actionKey==='reschedule') payload.newDate=document.getElementById('amInputDate')?.value||'';
    if(actionKey==='override')   payload.newStatus=document.getElementById('amInputStatus')?.value||'';
    if(actionKey==='reschedule'&&!payload.newDate){toast('New date is required.','w');return;}
    if(actionKey==='override'&&!payload.newStatus){toast('Status is required.','w');return;}
    try{
        const updated=await apiPost(API+'?api=action',payload);
        const idx=SCHEDULES.findIndex(x=>x.id===updated.id);
        if(idx>-1) SCHEDULES[idx]=updated;
        const msgs={done:'Marked as Completed.',start:'Maintenance started.',reschedule:`Rescheduled to ${fD(payload.newDate)}.`,skip:'Schedule skipped.',override:`Status set to ${payload.newStatus}.`};
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
    const r=SCHEDULES.find(x=>x.id===dbId); if(!r) return;
    renderDetail(r); setVmTab('ov');
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

function renderDetail(r){
    currentViewId=r.id;
    const diff=dayDiff(r.nextDue);
    const daysLabel=diff<0?`${Math.abs(diff)}d overdue`:diff===0?'Due today':`Due in ${diff}d`;
    const daysColor=diff<0?'var(--red)':diff<=7?'var(--amb)':'#166534';
    const isCrossZone=r.techZone&&r.zone&&r.techZone!==r.zone;
    const canDone=['Scheduled','In Progress','Overdue'].includes(r.status);
    const canEdit=r.status!=='Completed';
    const canStart=['Scheduled','Overdue'].includes(r.status);

    document.getElementById('vmAvatar').innerHTML=ini(r.assetName);
    document.getElementById('vmAvatar').style.background='#2E7D32';
    document.getElementById('vmName').textContent=r.assetName;
    document.getElementById('vmMid').innerHTML=`<span style="font-family:'DM Mono',monospace">${esc(r.scheduleId)}</span>&nbsp;·&nbsp;${esc(r.assetId)}&nbsp;${badge(r.status)}`;
    document.getElementById('vmChips').innerHTML=`
        <div class="vm-mc"><i class="bx bx-wrench"></i>${esc(r.type)}</div>
        <div class="vm-mc"><i class="bx bx-time-five"></i>${esc(r.freq)}</div>
        <div class="vm-mc"><i class="bx bx-map-pin"></i>${esc(r.zone)}</div>
        <div class="vm-mc"><i class="bx bx-user"></i>${esc(r.tech)}</div>
        ${isCrossZone?`<div class="vm-mc" style="background:#FEF3C7;border-color:#FCD34D;color:#92400E"><i class="bx bx-transfer" style="color:#D97706"></i>Cross-zone</div>`:''}`;
    const showEdit=canAddEdit()&&canEdit;
    const showStart=canMarkDoneOrStart(r)&&canStart;
    const showDone=canMarkDoneOrStart(r)&&canDone;
    const showResched=canReschedule()&&canEdit;
    const showOverride=canOverride();
    document.getElementById('vmFoot').innerHTML=`
        ${showStart?`<button class="btn btn-start btn-sm" onclick="closeView();doAction('start',${r.id})"><i class="bx bx-play"></i> Start</button>`:''}
        ${showDone?`<button class="btn btn-done btn-sm" onclick="closeView();doAction('done',${r.id})"><i class="bx bx-check"></i> Mark Done</button>`:''}
        ${showEdit?`<button class="btn btn-ghost btn-sm" onclick="closeView();openEdit(${r.id})"><i class="bx bx-edit"></i> Edit</button>`:''}
        ${showResched?`<button class="btn btn-reschedule btn-sm" onclick="closeView();doAction('reschedule',${r.id})"><i class="bx bx-calendar-edit"></i> Reschedule</button>`:''}
        ${showOverride?`<button class="btn btn-override btn-sm" onclick="closeView();doAction('override',${r.id})"><i class="bx bx-shield-quarter"></i> Override</button>`:''}
        <button class="btn btn-ghost btn-sm" onclick="closeView()">Close</button>`;

    document.getElementById('vt-ov').innerHTML=`
        <div class="vm-sbs">
            <div class="vm-sb"><div class="sbv" style="font-size:14px">${esc(r.freq)}</div><div class="sbl">Frequency</div></div>
            <div class="vm-sb"><div class="sbv" style="color:${daysColor};font-size:13px">${daysLabel}</div><div class="sbl">Next Due</div></div>
            <div class="vm-sb"><div class="sbv" style="font-size:13px">${fD(r.lastDone)||'—'}</div><div class="sbl">Last Done</div></div>
            <div class="vm-sb"><div class="sbv" style="font-size:13px">${esc(r.status)}</div><div class="sbl">Status</div></div>
        </div>
        <div class="vm-ig">
            <div class="vm-ii"><label>Schedule ID</label><div class="v" style="font-family:'DM Mono',monospace">${esc(r.scheduleId)}</div></div>
            <div class="vm-ii"><label>Maintenance Type</label><div class="v">${esc(r.type)}</div></div>
            <div class="vm-ii"><label>Next Due Date</label><div class="v muted">${fD(r.nextDue)}</div></div>
            <div class="vm-ii"><label>Last Done Date</label><div class="v muted">${fD(r.lastDone)||'—'}</div></div>
            <div class="vm-ii"><label>Zone / Location</label><div class="v">${esc(r.zone)}</div></div>
            <div class="vm-ii"><label>Status</label><div class="v">${badge(r.status)}</div></div>
            <div class="vm-ii"><label>Assigned Technician</label>
                <div class="v" style="display:flex;align-items:center;gap:7px">
                    <div style="width:22px;height:22px;border-radius:50%;background:${r.techColor||'#6B7280'};display:flex;align-items:center;justify-content:center;font-size:8px;font-weight:800;color:#fff;flex-shrink:0">${ini(r.tech)}</div>
                    ${esc(r.tech)}
                    ${isCrossZone?`<span style="font-size:10px;background:#FEF3C7;color:#92400E;padding:1px 6px;border-radius:5px;font-weight:700">Cross-zone</span>`:''}
                </div>
            </div>
            <div class="vm-ii"><label>Technician Zone</label><div class="v muted">${esc(r.techZone)||'—'}</div></div>
            ${r.notes?`<div class="vm-ii vm-full"><label>Notes / Instructions</label><div class="v muted">${esc(r.notes)}</div></div>`:''}
        </div>
        ${isCrossZone?`<div class="vm-sa-note"><i class="bx bx-shield-quarter"></i><span><strong>Cross-zone Assignment:</strong> ${esc(r.tech)} (${esc(r.techZone)}) is assigned outside their home zone to ${esc(r.zone)}.</span></div>`:''}`;

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
                        ${lg.ip_address?`<span class="vm-audit-ip">${esc(lg.ip_address)}</span>`:''}
                    </div>
                    ${lg.note?`<div class="vm-audit-note">"${esc(lg.note)}"</div>`:''}
                </div>
                <div class="vm-audit-ts">${lg.occurred_at?new Date(lg.occurred_at).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):''}</div>
            </div>`).join('');
    }catch(e){wrap.innerHTML=`<div style="text-align:center;color:var(--red);padding:24px;font-size:13px">Failed to load audit trail.</div>`;}
}

// ── SLIDER ────────────────────────────────────────────────────────────────────
function openSlider(mode='create',r=null){
    editId=mode==='edit'?r.id:null;
    document.getElementById('slTitle').textContent=mode==='edit'?`Edit Schedule — ${r.scheduleId}`:'Add Maintenance Schedule';
    document.getElementById('slSub').textContent=mode==='edit'?'Update schedule details below':'Fill in all required fields below';
    if(mode==='edit'&&r){
        document.getElementById('fAsset').value=r.assetId;
        document.getElementById('fTypeSl').value=r.type;
        document.getElementById('fFreqSl').value=r.freq;
        document.getElementById('fZoneSl').value=r.zone;
        document.getElementById('fLastDone').value=r.lastDone||'';
        document.getElementById('fNextDue').value=r.nextDue||'';
        document.getElementById('fStatusSl').value=r.status;
        document.getElementById('fNotes').value=r.notes||'';
        // Match tech by id
        const tEl=document.getElementById('fTech');
        tEl.value=r.techId||'';
        if(!tEl.value) [...tEl.options].forEach(o=>{if(o.dataset.name===r.tech) tEl.value=o.value;});
    } else {
        ['fAsset','fTypeSl','fFreqSl','fZoneSl','fTech'].forEach(id=>document.getElementById(id).value='');
        document.getElementById('fLastDone').value='';
        document.getElementById('fNextDue').value='';
        document.getElementById('fStatusSl').value='Scheduled';
        document.getElementById('fNotes').value='';
    }
    document.getElementById('prSlider').classList.add('on');
    document.getElementById('slOverlay').classList.add('on');
    setTimeout(()=>document.getElementById('fAsset').focus(),100);
}
function openEdit(dbId){if(!canAddEdit()) return; const r=SCHEDULES.find(x=>x.id===dbId);if(r) openSlider('edit',r);}
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
        const assetOpt=document.getElementById('fAsset');
        const assetSel=assetOpt.options[assetOpt.selectedIndex];
        const assetId  =assetOpt.value;
        const assetName=assetSel.dataset.name||assetSel.text.split(' — ').slice(1).join(' — ');
        const assetDbId=parseInt(assetSel.dataset.dbid||0);
        const type     =document.getElementById('fTypeSl').value;
        const freq     =document.getElementById('fFreqSl').value;
        const zone     =document.getElementById('fZoneSl').value;
        const nextDue  =document.getElementById('fNextDue').value;
        const techOpt  =document.getElementById('fTech');
        const techSel  =techOpt.options[techOpt.selectedIndex];
        const techId   =techOpt.value;
        const tech     =techSel.dataset.name||techSel.text.split(' (')[0];
        const techZone =techSel.dataset.zone||techSel.text.match(/\(([^)]+)\)/)?.[1]||'';

        if(!assetId)  {shk('fAsset');  toast('Asset is required.','w');return;}
        if(!type)     {shk('fTypeSl'); toast('Maintenance type is required.','w');return;}
        if(!freq)     {shk('fFreqSl'); toast('Frequency is required.','w');return;}
        if(!zone)     {shk('fZoneSl'); toast('Zone is required.','w');return;}
        if(!nextDue)  {shk('fNextDue');toast('Next due date is required.','w');return;}
        if(!techId)   {shk('fTech');   toast('Technician is required.','w');return;}

        const payload={
            assetId, assetName, assetDbId, type, freq, zone, nextDue, techId, tech, techZone,
            techColor: '#2E7D32',
            lastDone : document.getElementById('fLastDone').value||null,
            status   : document.getElementById('fStatusSl').value,
            notes    : document.getElementById('fNotes').value.trim(),
        };
        if(editId) payload.id=editId;
        const saved=await apiPost(API+'?api=save',payload);
        const idx=SCHEDULES.findIndex(x=>x.id===saved.id);
        if(idx>-1) SCHEDULES[idx]=saved; else{SCHEDULES.unshift(saved);page=1;}
        toast(`${saved.scheduleId} ${editId?'updated':'created'}.`,'s');
        closeSlider(); renderList();
        if(currentView==='cal') renderCalendar();
    }catch(e){toast(e.message,'d');}
    finally{btn.disabled=false;}
});

// ── VIEW TOGGLE ───────────────────────────────────────────────────────────────
document.getElementById('listViewBtn').addEventListener('click',()=>{
    currentView='list';
    document.getElementById('listView').style.display='';
    document.getElementById('calView').style.display='none';
    document.getElementById('listViewBtn').classList.add('active');
    document.getElementById('calViewBtn').classList.remove('active');
});
document.getElementById('calViewBtn').addEventListener('click',()=>{
    currentView='cal';
    document.getElementById('listView').style.display='none';
    document.getElementById('calView').style.display='';
    document.getElementById('listViewBtn').classList.remove('active');
    document.getElementById('calViewBtn').classList.add('active');
    renderCalendar();
});

// ── CALENDAR ──────────────────────────────────────────────────────────────────
const CAL_STATUS_CLS={Scheduled:'ce-sched','In Progress':'ce-prog',Overdue:'ce-over',Completed:'ce-done',Skipped:'ce-skip'};
document.getElementById('calPrev').addEventListener('click',()=>{calMon--;if(calMon<0){calMon=11;calYear--;}renderCalendar();});
document.getElementById('calNext').addEventListener('click',()=>{calMon++;if(calMon>11){calMon=0;calYear++;}renderCalendar();});
document.getElementById('calToday').addEventListener('click',()=>{calYear=new Date().getFullYear();calMon=new Date().getMonth();renderCalendar();});

function renderCalendar(){
    const mn=['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('calMonth').textContent=`${mn[calMon]} ${calYear}`;
    const first=new Date(calYear,calMon,1).getDay();
    const days=new Date(calYear,calMon+1,0).getDate();
    const td=today();
    const DOW=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    let html=DOW.map(d=>`<div class="cal-dow">${d}</div>`).join('');
    for(let i=0;i<first;i++) html+=`<div class="cal-cell other-month"></div>`;
    for(let d=1;d<=days;d++){
        const ds=`${calYear}-${String(calMon+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        const isToday=ds===td;
        const dayRecs=SCHEDULES.filter(r=>r.nextDue===ds);
        const shown=dayRecs.slice(0,3), more=dayRecs.length-3;
        html+=`<div class="cal-cell${isToday?' today':''}">
            <div class="cal-day">${d}</div>
            ${shown.map(r=>`<div class="cal-ev ${CAL_STATUS_CLS[r.status]||'ce-sched'}" title="${r.assetName} — ${r.type}" onclick="openView(${r.id})">${r.assetName.split(' ').slice(0,2).join(' ')}</div>`).join('')}
            ${more>0?`<div class="cal-more">+${more} more</div>`:''}
        </div>`;
    }
    const trailing=(first+days)%7===0?0:7-((first+days)%7);
    for(let i=0;i<trailing;i++) html+=`<div class="cal-cell other-month"></div>`;
    document.getElementById('calGrid').innerHTML=html;
}

// ── TEMPLATE LIBRARY ──────────────────────────────────────────────────────────
document.getElementById('tplBtn').addEventListener('click',()=>{
    document.getElementById('tplBody').innerHTML=TEMPLATES.map(t=>`
        <div class="tpl-item" onclick="applyTemplate('${t.id}')">
            <div class="tpl-ic ${t.cls}"><i class="bx ${t.icon}"></i></div>
            <div style="flex:1;min-width:0">
                <div class="tpl-name">${esc(t.name)}</div>
                <div class="tpl-meta"><span><i class="bx bx-wrench"></i>${t.type}</span><span><i class="bx bx-time-five"></i>${t.freq}</span></div>
            </div>
            <button class="btn btn-primary btn-xs" onclick="event.stopPropagation();applyTemplate('${t.id}')">Use</button>
        </div>`).join('');
    document.getElementById('tplModal').classList.add('on');
});
document.getElementById('tplClose').addEventListener('click',()=>document.getElementById('tplModal').classList.remove('on'));
document.getElementById('tplCancel').addEventListener('click',()=>document.getElementById('tplModal').classList.remove('on'));
document.getElementById('tplModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('on');});

function applyTemplate(id){
    const t=TEMPLATES.find(x=>x.id===id); if(!t) return;
    document.getElementById('tplModal').classList.remove('on');
    openSlider('create');
    setTimeout(()=>{
        document.getElementById('fTypeSl').value=t.type;
        document.getElementById('fFreqSl').value=t.freq;
        document.getElementById('fNotes').value=t.notes;
        toast(`Template "${t.name}" applied.`,'s');
    },350);
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