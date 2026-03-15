<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE RESOLUTION (mirrors superadmin_sidebar.php) ─────────────────────────
function _la_resolve_role(): string {
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

$roleName = _la_resolve_role();
$roleRank = match($roleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1, // Staff / User
};

// ── PERMISSION GATES ──────────────────────────────────────────────────────────
$CAN_VIEW_ALL_ZONES    = $roleRank >= 4; // SA: all zones
$CAN_CREATE            = $roleRank >= 2; // Manager+: create assignments
$CAN_CREATE_ANY_ZONE   = $roleRank >= 4; // SA only: assign to any zone
$CAN_EDIT              = $roleRank >= 2; // Manager+: edit
$CAN_REASSIGN          = $roleRank >= 2; // Manager+: reassign within own zone
$CAN_CROSS_ZONE        = $roleRank >= 4; // SA only: cross-zone reassign
$CAN_COMPLETE          = $roleRank >= 2; // Manager+: mark complete for team
$CAN_FORCE_COMPLETE    = $roleRank >= 4; // SA only
$CAN_ESCALATE          = $roleRank >= 3; // Admin+: escalate
$CAN_BATCH_REASSIGN    = $roleRank >= 4; // SA only
$CAN_BATCH_FORCE       = $roleRank >= 4; // SA only
$CAN_AUDIT_TRAIL       = $roleRank >= 4; // SA only
$CAN_EXPORT            = $roleRank >= 3; // Admin+
$CAN_UPDATE_OWN        = $roleRank >= 1; // Staff: start/progress/complete own tasks
$CAN_ADD_NOTES         = $roleRank >= 1; // Staff: add notes to own tasks

$currentUser = [
    'user_id'   => $_SESSION['user_id']   ?? null,
    'full_name' => $_SESSION['full_name'] ?? ($_SESSION['name'] ?? 'Super Admin'),
    'zone'      => $_SESSION['zone']      ?? '',
];

// Allowed statuses by role
$ALLOWED_STATUSES = match(true) {
    $roleRank >= 4 => ['Unassigned','Assigned','In Progress','Completed','Overdue','Escalated'],
    $roleRank >= 2 => ['Unassigned','Assigned','In Progress','Completed','Overdue'],
    default        => ['Assigned','In Progress','Completed','Overdue'],
};

$jsRole = json_encode([
    'name'              => $roleName,
    'rank'              => $roleRank,
    'canViewAllZones'   => $CAN_VIEW_ALL_ZONES,
    'canCreate'         => $CAN_CREATE,
    'canCreateAnyZone'  => $CAN_CREATE_ANY_ZONE,
    'canEdit'           => $CAN_EDIT,
    'canReassign'       => $CAN_REASSIGN,
    'canCrossZone'      => $CAN_CROSS_ZONE,
    'canComplete'       => $CAN_COMPLETE,
    'canForceComplete'  => $CAN_FORCE_COMPLETE,
    'canEscalate'       => $CAN_ESCALATE,
    'canBatchReassign'  => $CAN_BATCH_REASSIGN,
    'canBatchForce'     => $CAN_BATCH_FORCE,
    'canAuditTrail'     => $CAN_AUDIT_TRAIL,
    'canExport'         => $CAN_EXPORT,
    'canUpdateOwn'      => $CAN_UPDATE_OWN,
    'canAddNotes'       => $CAN_ADD_NOTES,
    'userZone'          => $currentUser['zone'],
    'userName'          => $currentUser['full_name'],
    'allowedStatuses'   => $ALLOWED_STATUSES,
]);

// ── HELPERS ──────────────────────────────────────────────────────────────────
function la_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function la_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function la_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function la_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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
    if (!$res && $code >= 400) la_err('Supabase request failed', 500);
    $data = json_decode($res, true);
    if ($code >= 400) la_err(is_array($data) ? ($data['message'] ?? $res) : $res, 400);
    return is_array($data) ? $data : [];
}

function la_next_id(): string {
    $year = date('Y');
    $rows = la_sb('plt_assignments', 'GET', [
        'select'        => 'assignment_id',
        'assignment_id' => 'like.LA-' . $year . '-%',
        'order'         => 'id.desc',
        'limit'         => '1',
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/LA-\d{4}-(\d+)/', $rows[0]['assignment_id'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return 'LA-' . $year . '-' . sprintf('%04d', $next);
}

function la_build(array $row): array {
    return [
        'id'           => (int)$row['id'],
        'assignmentId' => $row['assignment_id']   ?? '',
        'task'         => $row['task']             ?? '',
        'assignedTo'   => $row['assigned_to']      ?? 'Unassigned',
        'zone'         => $row['zone']             ?? '',
        'priority'     => $row['priority']         ?? 'Medium',
        'dateCreated'  => $row['date_created']     ?? '',
        'dueDate'      => $row['due_date']         ?? '',
        'status'       => $row['status']           ?? 'Unassigned',
        'notes'        => $row['notes']            ?? '',
        'checklist'    => is_string($row['checklist'] ?? null)
                            ? (json_decode($row['checklist'], true) ?? [])
                            : ($row['checklist'] ?? []),
    ];
}

function requireRole(int $min): void {
    global $roleRank;
    if ($roleRank < $min) la_err('Insufficient permissions', 403);
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Super Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    $actorRole = match(true) {
        $roleRank >= 4 => 'Super Admin',
        $roleRank >= 3 => 'Admin',
        $roleRank >= 2 => 'Manager',
        default        => 'Staff',
    };

    try {

        // ── GET zones ─────────────────────────────────────────────────────────
        if ($api === 'zones' && $method === 'GET') {
            $rows = la_sb('sws_zones', 'GET', ['select' => 'id,name,color', 'order' => 'id.asc']);
            if (empty($rows)) {
                $rows = [
                    ['id' => 'Zone A – North',   'name' => 'Zone A – North',   'color' => '#2E7D32'],
                    ['id' => 'Zone B – South',   'name' => 'Zone B – South',   'color' => '#0D9488'],
                    ['id' => 'Zone C – East',    'name' => 'Zone C – East',    'color' => '#2563EB'],
                    ['id' => 'Zone D – West',    'name' => 'Zone D – West',    'color' => '#D97706'],
                    ['id' => 'Zone E – Central', 'name' => 'Zone E – Central', 'color' => '#7C3AED'],
                    ['id' => 'Zone F – Offshore','name' => 'Zone F – Offshore','color' => '#DC2626'],
                ];
            }
            la_ok($rows);
        }

        // ── GET staff ─────────────────────────────────────────────────────────
        if ($api === 'staff' && $method === 'GET') {
            $query = [
                'select' => 'user_id,first_name,last_name',
                'status' => 'eq.Active',
                'order'  => 'first_name.asc',
            ];
            // Admin/Manager: only staff in own zone
            if ($roleRank <= 3 && !empty($currentUser['zone'])) {
                $query['zone'] = 'eq.' . $currentUser['zone'];
            }
            $rows = la_sb('users', 'GET', $query);
            $staff = array_map(fn($r) => [
                'id'   => $r['user_id'],
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            ], $rows);
            la_ok(array_values(array_filter($staff, fn($s) => $s['name'] !== '')));
        }

        // ── GET assignments list ──────────────────────────────────────────────
        if ($api === 'list' && $method === 'GET') {
            $query = [
                'select' => 'id,assignment_id,task,assigned_to,zone,priority,date_created,due_date,status,notes,checklist',
                'order'  => 'id.desc',
            ];

            if ($roleRank >= 4) {
                // SA: all zones
            } elseif ($roleRank >= 2) {
                // Admin/Manager: own zone only
                $userZone = $currentUser['zone'] ?? '';
                if ($userZone) $query['zone'] = 'eq.' . $userZone;
            } else {
                // Staff: only their own assigned tasks
                $userName = $currentUser['full_name'] ?? '';
                if ($userName) $query['assigned_to'] = 'eq.' . $userName;
                else { la_ok([]); }
            }

            $rows = la_sb('plt_assignments', 'GET', $query);
            la_ok(array_map('la_build', $rows));
        }

        // ── GET single assignment ─────────────────────────────────────────────
        if ($api === 'get' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) la_err('Missing id', 400);
            $rows = la_sb('plt_assignments', 'GET', [
                'select' => 'id,assignment_id,task,assigned_to,zone,priority,date_created,due_date,status,notes,checklist',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) la_err('Assignment not found', 404);
            $asgn = $rows[0];

            // Access check
            if ($roleRank <= 3 && $roleRank >= 2) {
                $userZone = $currentUser['zone'] ?? '';
                if ($userZone && $asgn['zone'] !== $userZone) la_err('Access denied', 403);
            } elseif ($roleRank <= 1) {
                $userName = $currentUser['full_name'] ?? '';
                if ($asgn['assigned_to'] !== $userName) la_err('Access denied', 403);
            }

            la_ok(la_build($asgn));
        }

        // ── GET audit log ─────────────────────────────────────────────────────
        if ($api === 'audit' && $method === 'GET') {
            requireRole(4); // SA only
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) la_err('Missing id', 400);
            $rows = la_sb('plt_assignment_audit_log', 'GET', [
                'select'        => 'id,action_label,actor_name,actor_role,note,icon,css_class,is_super_admin,ip_address,occurred_at',
                'assignment_id' => 'eq.' . $id,
                'order'         => 'occurred_at.asc',
            ]);
            la_ok($rows);
        }

        // ── POST save assignment ──────────────────────────────────────────────
        if ($api === 'save' && $method === 'POST') {
            if (!$CAN_CREATE) la_err('Insufficient permissions', 403);

            $b          = la_body();
            $task       = trim($b['task']        ?? '');
            $assignedTo = trim($b['assignedTo']  ?? 'Unassigned');
            $zone       = trim($b['zone']         ?? '');
            $priority   = trim($b['priority']     ?? 'Medium');
            $dueDate    = trim($b['dueDate']      ?? '');
            $status     = trim($b['status']       ?? 'Unassigned');
            $notes      = trim($b['notes']        ?? '');
            $checklist  = $b['checklist']         ?? [];
            $editId     = (int)($b['id']          ?? 0);

            if (!$task)     la_err('Task description is required', 400);
            if (!$zone)     la_err('Zone is required', 400);
            if (!$priority) la_err('Priority is required', 400);
            if (!$dueDate)  la_err('Due date is required', 400);

            // Zone restriction for non-SA
            if (!$CAN_CREATE_ANY_ZONE) {
                $userZone = $currentUser['zone'] ?? '';
                if ($userZone && $zone !== $userZone) la_err('Cannot create assignments in other zones', 403);
                // Staff can only update their own, not create
                if ($roleRank <= 1) la_err('Insufficient permissions', 403);
            }

            $allowedPriority = ['Critical', 'High', 'Medium', 'Low'];
            $allowedStatus   = ['Unassigned', 'Assigned', 'In Progress', 'Completed', 'Overdue', 'Escalated'];
            if (!in_array($priority, $allowedPriority, true)) $priority = 'Medium';
            if (!in_array($status,   $allowedStatus,   true)) $status   = 'Unassigned';

            // Managers cannot set Escalated/Completed on create
            if ($roleRank <= 2 && in_array($status, ['Escalated', 'Force Completed'], true)) {
                $status = 'Assigned';
            }

            // Auto-bump status
            if ($assignedTo !== 'Unassigned' && $status === 'Unassigned') {
                $status = 'Assigned';
            }

            $now = date('Y-m-d H:i:s');
            $payload = [
                'task'        => $task,
                'assigned_to' => $assignedTo,
                'zone'        => $zone,
                'priority'    => $priority,
                'due_date'    => $dueDate,
                'status'      => $status,
                'notes'       => $notes,
                'checklist'   => json_encode($checklist),
                'updated_at'  => $now,
            ];

            if ($editId) {
                if (!$CAN_EDIT) la_err('Insufficient permissions', 403);
                $existing = la_sb('plt_assignments', 'GET', [
                    'select' => 'id,assignment_id,status,assigned_to,zone',
                    'id'     => 'eq.' . $editId,
                    'limit'  => '1',
                ]);
                if (empty($existing)) la_err('Assignment not found', 404);
                // Zone check for non-SA edit
                if (!$CAN_CREATE_ANY_ZONE) {
                    $userZone = $currentUser['zone'] ?? '';
                    if ($userZone && $existing[0]['zone'] !== $userZone) la_err('Access denied', 403);
                }

                la_sb('plt_assignments', 'PATCH', ['id' => 'eq.' . $editId], $payload);
                la_sb('plt_assignment_audit_log', 'POST', [], [[
                    'assignment_id'  => $editId,
                    'action_label'   => 'Assignment Edited',
                    'actor_name'     => $actor,
                    'actor_role'     => $actorRole,
                    'note'           => 'Fields updated by ' . $actorRole . '.',
                    'icon'           => 'bx-edit',
                    'css_class'      => 'ad-e',
                    'is_super_admin' => $roleRank >= 4,
                    'ip_address'     => $ip,
                    'occurred_at'    => $now,
                ]]);

                $rows = la_sb('plt_assignments', 'GET', [
                    'select' => 'id,assignment_id,task,assigned_to,zone,priority,date_created,due_date,status,notes,checklist',
                    'id'     => 'eq.' . $editId,
                    'limit'  => '1',
                ]);
                la_ok(la_build($rows[0]));
            }

            // Create
            $assignmentId = la_next_id();
            $payload['assignment_id']   = $assignmentId;
            $payload['date_created']    = date('Y-m-d');
            $payload['created_by']      = $actor;
            $payload['created_user_id'] = $_SESSION['user_id'] ?? null;
            $payload['created_at']      = $now;

            $inserted = la_sb('plt_assignments', 'POST', [], [$payload]);
            if (empty($inserted)) la_err('Failed to create assignment', 500);
            $newId = (int)$inserted[0]['id'];

            $auditEntries = [[
                'assignment_id'  => $newId,
                'action_label'   => 'Assignment Created',
                'actor_name'     => $actor,
                'actor_role'     => $actorRole,
                'note'           => 'New logistics assignment created.',
                'icon'           => 'bx-plus-circle',
                'css_class'      => 'ad-c',
                'is_super_admin' => $roleRank >= 4,
                'ip_address'     => $ip,
                'occurred_at'    => $now,
            ]];

            if ($assignedTo !== 'Unassigned') {
                $auditEntries[] = [
                    'assignment_id'  => $newId,
                    'action_label'   => 'Assigned to ' . $assignedTo,
                    'actor_name'     => $actor,
                    'actor_role'     => $actorRole,
                    'note'           => 'Dispatched to ' . $assignedTo . '.',
                    'icon'           => 'bx-user-check',
                    'css_class'      => 'ad-s',
                    'is_super_admin' => $roleRank >= 4,
                    'ip_address'     => $ip,
                    'occurred_at'    => $now,
                ];
            }
            la_sb('plt_assignment_audit_log', 'POST', [], $auditEntries);

            $rows = la_sb('plt_assignments', 'GET', [
                'select' => 'id,assignment_id,task,assigned_to,zone,priority,date_created,due_date,status,notes,checklist',
                'id'     => 'eq.' . $newId,
                'limit'  => '1',
            ]);
            la_ok(la_build($rows[0]));
        }

        // ── POST action ───────────────────────────────────────────────────────
        if ($api === 'action' && $method === 'POST') {
            $b    = la_body();
            $id   = (int)($b['id']   ?? 0);
            $type = trim($b['type']  ?? '');
            $now  = date('Y-m-d H:i:s');

            if (!$id)   la_err('Missing id', 400);
            if (!$type) la_err('Missing type', 400);

            // Permission gates per action type
            $gates = [
                'reassign'       => $CAN_REASSIGN,
                'complete'       => $CAN_COMPLETE,
                'force-complete' => $CAN_FORCE_COMPLETE,
                'escalate'       => $CAN_ESCALATE,
                'mark-inprogress'=> $CAN_UPDATE_OWN,
                'cancel'         => $roleRank >= 3, // Admin+
                'update-own'     => $CAN_UPDATE_OWN,
                'add-note'       => $CAN_ADD_NOTES,
            ];
            if (isset($gates[$type]) && !$gates[$type]) la_err('Insufficient permissions', 403);

            $rows = la_sb('plt_assignments', 'GET', [
                'select' => 'id,assignment_id,status,assigned_to,zone',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) la_err('Assignment not found', 404);
            $asgn = $rows[0];

            // Zone/ownership access checks
            if ($roleRank <= 3 && $roleRank >= 2) {
                $userZone = $currentUser['zone'] ?? '';
                if ($userZone && $asgn['zone'] !== $userZone) la_err('Access denied', 403);
            } elseif ($roleRank <= 1) {
                $userName = $currentUser['full_name'] ?? '';
                if ($asgn['assigned_to'] !== $userName) la_err('Access denied', 403);
            }

            $patch      = ['updated_at' => $now];
            $auditLabel = '';
            $auditNote  = trim($b['remarks'] ?? '');
            $auditIcon  = 'bx-info-circle';
            $auditClass = 'ad-s';
            $isSA       = $roleRank >= 4;

            switch ($type) {

                case 'reassign':
                    $newPerson = trim($b['assignedTo'] ?? '');
                    if (!$newPerson) la_err('Please select a person to reassign to.', 400);
                    // Non-SA: can only reassign within zone
                    if (!$CAN_CROSS_ZONE) {
                        // Just accept — zone restriction already enforced above
                    }
                    $prev = $asgn['assigned_to'];
                    $patch['assigned_to'] = $newPerson;
                    if ($asgn['status'] === 'Unassigned') $patch['status'] = 'Assigned';
                    $auditLabel = 'Reassigned: ' . $prev . ' → ' . $newPerson;
                    $auditIcon  = 'bx-transfer';
                    $auditClass = 'ad-o';
                    break;

                case 'complete':
                    if (!in_array($asgn['status'], ['Assigned', 'In Progress'], true))
                        la_err('Only Assigned or In Progress assignments can be completed.', 400);
                    $patch['status'] = 'Completed';
                    $auditLabel = 'Marked as Completed';
                    $auditIcon  = 'bx-check-circle';
                    $auditClass = 'ad-a';
                    $isSA       = false;
                    break;

                case 'force-complete':
                    requireRole(4);
                    if ($asgn['status'] !== 'Overdue')
                        la_err('Only Overdue assignments can be force-completed.', 400);
                    $patch['status'] = 'Completed';
                    $auditLabel = 'Force Completed by Super Admin';
                    $auditIcon  = 'bx-check-shield';
                    $auditClass = 'ad-fc';
                    break;

                case 'escalate':
                    if (in_array($asgn['status'], ['Completed', 'Escalated'], true))
                        la_err('Cannot escalate a Completed or already Escalated assignment.', 400);
                    $patch['status'] = 'Escalated';
                    $auditLabel = 'Escalated to Admin';
                    $auditIcon  = 'bx-up-arrow-circle';
                    $auditClass = 'ad-p';
                    break;

                case 'mark-inprogress':
                case 'update-own':
                    // Staff/Manager: start their own task
                    if ($asgn['status'] === 'Assigned') {
                        $patch['status'] = 'In Progress';
                        $auditLabel = 'Task Started — Marked In Progress';
                        $auditIcon  = 'bx-run';
                        $auditClass = 'ad-o';
                        $isSA       = false;
                    } elseif ($asgn['status'] === 'In Progress') {
                        $patch['status'] = 'Completed';
                        $auditLabel = 'Task Completed by Assignee';
                        $auditIcon  = 'bx-check-circle';
                        $auditClass = 'ad-a';
                        $isSA       = false;
                    } else {
                        la_err('Cannot update this task status.', 400);
                    }
                    break;

                case 'add-note':
                    $note = trim($b['note'] ?? '');
                    if (!$note) la_err('Note text is required', 400);
                    $existing = trim($asgn['notes'] ?? '');
                    $patch['notes'] = $existing ? $existing . "\n[" . date('Y-m-d H:i') . '] ' . $note : '[' . date('Y-m-d H:i') . '] ' . $note;
                    $auditLabel = 'Note Added by Assignee';
                    $auditNote  = $note;
                    $auditIcon  = 'bx-note';
                    $auditClass = 'ad-s';
                    $isSA       = false;
                    break;

                case 'cancel':
                    requireRole(3);
                    if (in_array($asgn['status'], ['Completed', 'Escalated'], true))
                        la_err('Cannot cancel a Completed or Escalated assignment.', 400);
                    $patch['status']      = 'Unassigned';
                    $patch['assigned_to'] = 'Unassigned';
                    $auditLabel = 'Assignment Unassigned / Reset';
                    $auditIcon  = 'bx-user-x';
                    $auditClass = 'ad-r';
                    break;

                default:
                    la_err('Unsupported action', 400);
            }

            la_sb('plt_assignments', 'PATCH', ['id' => 'eq.' . $id], $patch);
            la_sb('plt_assignment_audit_log', 'POST', [], [[
                'assignment_id'  => $id,
                'action_label'   => $auditLabel,
                'actor_name'     => $actor,
                'actor_role'     => $actorRole,
                'note'           => $auditNote,
                'icon'           => $auditIcon,
                'css_class'      => $auditClass,
                'is_super_admin' => $isSA,
                'ip_address'     => $ip,
                'occurred_at'    => $now,
            ]]);

            $rows = la_sb('plt_assignments', 'GET', [
                'select' => 'id,assignment_id,task,assigned_to,zone,priority,date_created,due_date,status,notes,checklist',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            la_ok(la_build($rows[0]));
        }

        // ── POST batch action — SA only ───────────────────────────────────────
        if ($api === 'batch' && $method === 'POST') {
            $b    = la_body();
            $ids  = array_map('intval', $b['ids']  ?? []);
            $type = trim($b['type'] ?? '');
            $now  = date('Y-m-d H:i:s');

            if (empty($ids)) la_err('No assignment IDs provided.', 400);
            if (!$type)       la_err('Missing batch type.', 400);

            if ($type === 'batch-reassign'     && !$CAN_BATCH_REASSIGN) la_err('Insufficient permissions', 403);
            if ($type === 'batch-force-complete' && !$CAN_BATCH_FORCE)  la_err('Insufficient permissions', 403);

            $updated   = 0;
            $auditNote = trim($b['remarks'] ?? '');

            foreach ($ids as $id) {
                $rows = la_sb('plt_assignments', 'GET', [
                    'select' => 'id,assignment_id,status,assigned_to',
                    'id'     => 'eq.' . $id,
                    'limit'  => '1',
                ]);
                if (empty($rows)) continue;
                $asgn = $rows[0];

                $patch      = ['updated_at' => $now];
                $auditLabel = '';
                $auditIcon  = 'bx-transfer';
                $auditClass = 'ad-s';

                if ($type === 'batch-reassign') {
                    $newPerson = trim($b['assignedTo'] ?? '');
                    if (!$newPerson || $asgn['status'] === 'Completed') continue;
                    $prev = $asgn['assigned_to'];
                    $patch['assigned_to'] = $newPerson;
                    if ($asgn['status'] === 'Unassigned') $patch['status'] = 'Assigned';
                    $auditLabel = 'Bulk Reassigned: ' . $prev . ' → ' . $newPerson;
                    $auditClass = 'ad-s';
                } elseif ($type === 'batch-force-complete') {
                    if ($asgn['status'] !== 'Overdue') continue;
                    $patch['status'] = 'Completed';
                    $auditLabel = 'Force Completed by Super Admin (Batch)';
                    $auditIcon  = 'bx-check-shield';
                    $auditClass = 'ad-fc';
                } else {
                    continue;
                }

                la_sb('plt_assignments', 'PATCH', ['id' => 'eq.' . $id], $patch);
                la_sb('plt_assignment_audit_log', 'POST', [], [[
                    'assignment_id'  => $id,
                    'action_label'   => $auditLabel,
                    'actor_name'     => $actor,
                    'actor_role'     => 'Super Admin',
                    'note'           => $auditNote,
                    'icon'           => $auditIcon,
                    'css_class'      => $auditClass,
                    'is_super_admin' => true,
                    'ip_address'     => $ip,
                    'occurred_at'    => $now,
                ]]);
                $updated++;
            }

            la_ok(['updated' => $updated]);
        }

        // ── POST checklist toggle — assignee or Manager+ ──────────────────────
        if ($api === 'checklist' && $method === 'POST') {
            $b   = la_body();
            $id  = (int)($b['id']  ?? 0);
            $idx = (int)($b['idx'] ?? -1);
            $val = (bool)($b['done'] ?? false);
            $now = date('Y-m-d H:i:s');

            if (!$id || $idx < 0) la_err('Missing id or idx', 400);

            $rows = la_sb('plt_assignments', 'GET', [
                'select' => 'id,assignment_id,checklist,assigned_to,zone',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) la_err('Assignment not found', 404);
            $row = $rows[0];

            // Access check: staff can only toggle their own tasks
            if ($roleRank <= 1 && $row['assigned_to'] !== ($currentUser['full_name'] ?? '')) {
                la_err('Access denied', 403);
            }
            if ($roleRank >= 2 && $roleRank <= 3) {
                $userZone = $currentUser['zone'] ?? '';
                if ($userZone && $row['zone'] !== $userZone) la_err('Access denied', 403);
            }

            $checklist = json_decode($row['checklist'] ?? '[]', true) ?? [];
            if (!isset($checklist[$idx])) la_err('Checklist item not found', 404);
            $checklist[$idx]['done'] = $val;

            la_sb('plt_assignments', 'PATCH', ['id' => 'eq.' . $id], [
                'checklist'  => json_encode(array_values($checklist)),
                'updated_at' => $now,
            ]);

            $rows = la_sb('plt_assignments', 'GET', [
                'select' => 'id,assignment_id,task,assigned_to,zone,priority,date_created,due_date,status,notes,checklist',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            la_ok(la_build($rows[0]));
        }

        la_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        la_err('Server error: ' . $e->getMessage(), 500);
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
<title>Logistics Assignments — PLT</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
:root{--bg:#F4F7F4;--s:#FFFFFF;--t1:#1A2E1C;--t2:#5D6F62;--t3:#9EB0A2;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);--grn:#2E7D32;--gdk:#1B5E20;--glt:#4CAF50;--gxl:#EDF7ED;--amb:#D97706;--red:#DC2626;--blu:#2563EB;--tel:#0D9488;--pur:#7C3AED;--shsm:0 1px 4px rgba(46,125,50,.08);--shmd:0 4px 20px rgba(46,125,50,.12);--shlg:0 12px 40px rgba(0,0,0,.14);--rad:14px;--tr:all .18s ease;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--t1);min-height:100vh;font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;}
::-webkit-scrollbar{width:5px;height:5px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.wrap{max-width:1440px;margin:0 auto;padding:0 0 4rem}

/* ── ACCESS BANNER ──────────────────────────────────────── */
.access-banner{display:flex;align-items:flex-start;gap:10px;padding:10px 16px;border-radius:10px;font-size:12px;margin-bottom:16px;animation:UP .4s both}
.ab-info{background:#EFF6FF;border:1px solid #BFDBFE;color:var(--blu)}
.ab-warn{background:#FEF3C7;border:1px solid #FDE68A;color:var(--amb)}
.ab-staff{background:#F3F4F6;border:1px solid #E5E7EB;color:#374151}
.access-banner i{font-size:16px;flex-shrink:0;margin-top:1px}

.ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:20px;animation:UP .4s both}
.ph-l .ey{font-size:11px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--grn);margin-bottom:5px}
.ph-l h1{font-size:28px;font-weight:800;color:var(--t1);line-height:1.15;letter-spacing:-.3px}
.ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap}
.btn i{font-size:16px}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 10px rgba(46,125,50,.28)}.btn-primary:hover{background:var(--gdk);transform:translateY(-1px)}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm)}.btn-ghost:hover{background:var(--gxl);color:var(--grn);border-color:var(--grn)}
.btn-complete{background:#DCFCE7;color:#166534;border:1px solid #BBF7D0}.btn-complete:hover{background:#BBF7D0}
.btn-reassign{background:#EFF6FF;color:var(--blu);border:1px solid #BFDBFE}.btn-reassign:hover{background:#DBEAFE}
.btn-escalate{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D}.btn-escalate:hover{background:#FDE68A}
.btn-force-complete{background:#F5F3FF;color:#6D28D9;border:1px solid #DDD6FE}.btn-force-complete:hover{background:#EDE9FE}
.btn-start{background:#CCFBF1;color:var(--tel);border:1px solid #99F6E4}.btn-start:hover{background:#99F6E4}
.btn-sm{font-size:12px;padding:6px 13px}.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px}
.btn.ionly{width:28px;height:28px;padding:0;justify-content:center;font-size:14px;flex-shrink:0;border-radius:7px;border:1px solid var(--bdm);background:var(--s);color:var(--t2)}
.btn.ionly:hover{background:var(--gxl);color:var(--grn);border-color:var(--grn)}
.btn.ionly.btn-complete:hover{background:#DCFCE7;color:#166534;border-color:#BBF7D0}
.btn.ionly.btn-reassign:hover{background:#EFF6FF;color:var(--blu);border-color:#BFDBFE}
.btn.ionly.btn-escalate:hover{background:#FEF3C7;color:#92400E;border-color:#FCD34D}
.btn.ionly.btn-force-complete:hover{background:#F5F3FF;color:#6D28D9;border-color:#DDD6FE}
.btn.ionly.btn-start:hover{background:#CCFBF1;color:var(--tel);border-color:#99F6E4}
.btn:disabled{opacity:.4;pointer-events:none}
.sum-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:12px;margin-bottom:20px;animation:UP .4s .06s both}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:14px 16px;box-shadow:var(--shsm);display:flex;align-items:center;gap:10px;transition:var(--tr)}
.sc:hover{box-shadow:var(--shmd);transform:translateY(-2px)}
.sc-ic{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px}
.ic-b{background:#EFF6FF;color:var(--blu)}.ic-a{background:#FEF3C7;color:var(--amb)}.ic-g{background:#DCFCE7;color:#166534}.ic-r{background:#FEE2E2;color:var(--red)}.ic-t{background:#CCFBF1;color:var(--tel)}.ic-p{background:#F5F3FF;color:var(--pur)}.ic-d{background:#F3F4F6;color:#6B7280}
.sc-info{flex:1;min-width:0}.sc-v{font-size:20px;font-weight:800;color:var(--t1);line-height:1;font-variant-numeric:tabular-nums}.sc-l{font-size:11px;color:var(--t2);margin-top:2px;font-weight:500}
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;animation:UP .4s .09s both}
.sw{position:relative;flex:1;min-width:220px}
.sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--t3);pointer-events:none}
.si{width:100%;padding:9px 11px 9px 36px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.si:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}.si::placeholder{color:var(--t3)}
.sel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 28px 9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center}
.sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}
.fi-date{font-family:'Inter',sans-serif;font-size:13px;padding:9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.fi-date:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}
.date-wrap{display:flex;align-items:center;gap:6px}.date-wrap span{font-size:12px;color:var(--t3);font-weight:500}
.bulk-bar{display:none;align-items:center;gap:10px;padding:10px 16px;background:linear-gradient(135deg,#EFF6FF,#DBEAFE);border:1px solid rgba(37,99,235,.22);border-radius:12px;margin-bottom:12px;flex-wrap:wrap}
.bulk-bar.on{display:flex}.bulk-ct{font-size:13px;font-weight:700;color:#1D4ED8}.bulk-sep{width:1px;height:20px;background:rgba(37,99,235,.25)}
.sa-tag-bar{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;border-radius:6px;padding:2px 7px;margin-left:auto}
.sa-tag-bar i{font-size:11px}
.tbl-card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);animation:UP .4s .13s both}
.inv-tbl{width:100%;border-collapse:collapse;font-size:12px}
.inv-tbl thead th{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--t2);padding:8px 10px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;cursor:pointer;user-select:none}
.inv-tbl thead th.ns{cursor:default}.inv-tbl thead th:hover:not(.ns){color:var(--grn)}.inv-tbl thead th.sorted{color:var(--grn)}
.inv-tbl thead th .si-c{margin-left:2px;opacity:.4;font-size:10px;vertical-align:middle}.inv-tbl thead th.sorted .si-c{opacity:1}
.inv-tbl thead th:first-child{width:34px;padding-left:12px;padding-right:4px}
.inv-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .12s}
.inv-tbl tbody tr:last-child{border-bottom:none}.inv-tbl tbody tr:hover{background:#F7FBF7}.inv-tbl tbody tr.row-sel{background:#EFF6FF}
.inv-tbl tbody td{padding:10px 10px;vertical-align:middle;white-space:nowrap}
.inv-tbl tbody td:first-child{cursor:default;padding-left:12px;padding-right:4px;width:34px}
.inv-tbl tbody td:last-child{white-space:nowrap;cursor:default;padding:6px 8px}
.cb-wrap{display:flex;align-items:center;justify-content:center}
input[type=checkbox].cb{width:15px;height:15px;accent-color:var(--grn);cursor:pointer}
.mono{font-family:'DM Mono',monospace}.id-cell{font-family:'DM Mono',monospace;font-size:11.5px;font-weight:600;color:var(--grn)}
.task-cell{font-weight:600;color:var(--t1);font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis}
.asgn-cell{display:flex;align-items:center;gap:7px}.asgn-av{width:26px;height:26px;border-radius:50%;font-size:9px;font-weight:700;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.asgn-name{font-weight:600;color:var(--t1);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px}
.my-task-dot{display:inline-flex;align-items:center;font-size:9px;font-weight:700;background:#EDE9FE;color:#6D28D9;border-radius:4px;padding:1px 5px;margin-left:4px}
.sub-cell{font-size:11px;color:var(--t3);margin-top:1px}.date-cell{font-size:11.5px;color:var(--t2)}
.zone-pill{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:600}.zone-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.act-cell{display:flex;gap:3px;align-items:center}
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0}
.b-unassigned{background:#F3F4F6;color:#6B7280}.b-assigned{background:#EFF6FF;color:#1D4ED8}.b-inprogress{background:#FEF3C7;color:#92400E}.b-completed{background:#DCFCE7;color:#166534}.b-overdue{background:#FEE2E2;color:#991B1B}.b-escalated{background:#F5F3FF;color:#6D28D9}
.pri-badge{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;padding:3px 8px;border-radius:6px;white-space:nowrap}
.p-critical{background:#FEE2E2;color:#991B1B}.p-high{background:#FEF3C7;color:#92400E}.p-medium{background:#EFF6FF;color:#1D4ED8}.p-low{background:#F3F4F6;color:#6B7280}
.pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2)}
.pg-btns{display:flex;gap:5px}
.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1)}
.pgb:hover{background:var(--gxl);border-color:var(--grn);color:var(--grn)}.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff}.pgb:disabled{opacity:.4;pointer-events:none}
.empty{padding:64px 20px;text-align:center;color:var(--t3)}.empty i{font-size:48px;display:block;margin-bottom:12px;color:#C8E6C9}
#slOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;opacity:0;pointer-events:none;transition:opacity .25s}
#slOverlay.on{opacity:1;pointer-events:all}
#mainSlider{position:fixed;top:0;right:-620px;bottom:0;width:580px;max-width:100vw;background:var(--s);z-index:9001;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18)}
#mainSlider.on{right:0}
.sl-hd{display:flex;align-items:flex-start;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--bd);flex-shrink:0;background:var(--bg)}
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
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:30px}
.fta{resize:vertical;min-height:68px}
.fdiv{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px}
.fdiv::after{content:'';flex:1;height:1px;background:var(--bd)}
.cs-wrap{position:relative;width:100%}
.cs-input{width:100%;padding:10px 12px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.cs-input:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11)}
.cs-input::placeholder{color:var(--t3)}
.cs-drop{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--s);border:1px solid var(--bdm);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.13);z-index:9999;max-height:220px;overflow-y:auto}
.cs-drop.open{display:block}
.cs-drop::-webkit-scrollbar{width:4px}.cs-drop::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.cs-opt{padding:9px 12px;font-size:13px;cursor:pointer;display:flex;flex-direction:column;gap:2px;transition:background .12s}
.cs-opt:hover,.cs-opt.hl{background:var(--gxl)}
.cs-opt .cs-name{font-size:13px;color:var(--t1);font-weight:500}
.cs-opt.cs-none{color:var(--t3);cursor:default;font-size:12px;padding:12px}.cs-opt.cs-none:hover{background:none}
.cl-rows{display:flex;flex-direction:column;gap:8px}
.cl-row{display:flex;align-items:center;gap:10px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:10px 13px}
.cl-rm{width:24px;height:24px;border-radius:6px;border:1px solid #FECACA;background:#FEE2E2;cursor:pointer;display:grid;place-content:center;font-size:14px;color:var(--red);flex-shrink:0;transition:var(--tr);line-height:1}
.cl-rm:hover{background:#FCA5A5}
.add-cl{display:flex;align-items:center;justify-content:center;gap:7px;padding:10px;border:1.5px dashed var(--bdm);border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;color:var(--t3);background:transparent;transition:var(--tr);font-family:'Inter',sans-serif;width:100%}
.add-cl:hover{border-color:var(--grn);color:var(--grn);background:#F0FAF0}
.vp-section{background:var(--bg);border:1px solid var(--bd);border-radius:12px;padding:18px 20px}
.vp-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--t3);margin-bottom:12px}
.vp-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.vp-item label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);display:block;margin-bottom:3px}
.vp-item .v{font-size:13px;font-weight:500;color:var(--t1)}.vp-item .vm{font-size:13px;color:var(--t2)}.vp-full{grid-column:1/-1}
.vp-statbox{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.vp-sb{background:var(--s);border:1px solid var(--bd);border-radius:10px;padding:14px;text-align:center}
.vp-sb .sbv{font-size:20px;font-weight:800;color:var(--t1);line-height:1;font-variant-numeric:tabular-nums}
.vp-sb .sbl{font-size:11px;color:var(--t2);margin-top:4px}
.prog-bar-wrap{height:6px;background:var(--bd);border-radius:3px;overflow:hidden;margin-top:8px}
.prog-bar-fill{height:100%;border-radius:3px;transition:width .4s}
.sa-banner{background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:10px 14px;display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#92400E}
.sa-banner i{font-size:15px;flex-shrink:0;margin-top:1px}
/* Add note form */
.note-form{background:#F0FDF4;border:1px solid rgba(46,125,50,.2);border-radius:10px;padding:14px;display:flex;flex-direction:column;gap:10px}
.note-form-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grn)}
.audit-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--bd)}
.audit-item:last-child{border-bottom:none;padding-bottom:0}
.audit-dot{width:28px;height:28px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px}
.ad-c{background:#DCFCE7;color:#166534}.ad-s{background:#EFF6FF;color:#2563EB}.ad-a{background:#DCFCE7;color:#166534}.ad-r{background:#FEE2E2;color:#DC2626}.ad-e{background:#F3F4F6;color:#6B7280}.ad-o{background:#FEF3C7;color:#D97706}.ad-p{background:#F5F3FF;color:#7C3AED}.ad-fc{background:#CCFBF1;color:#0D9488}
.audit-body{flex:1;min-width:0}
.audit-body .au{font-size:13px;font-weight:500;color:var(--t1)}
.audit-body .at{font-size:11px;color:var(--t3);margin-top:3px;font-family:'DM Mono',monospace;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.audit-note{font-size:11.5px;color:#6B7280;margin-top:3px;font-style:italic}
.audit-ts{font-family:'DM Mono',monospace;font-size:10px;color:var(--t3);flex-shrink:0;margin-left:auto;padding-left:8px;white-space:nowrap}
.sa-tag-small{font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 5px;border:1px solid #FCD34D}
.vt-bar{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--bd);padding-bottom:4px}
.vt-tab{font-family:'Inter',sans-serif;font-size:12px;font-weight:600;padding:6px 14px;border-radius:8px 8px 0 0;cursor:pointer;transition:var(--tr);color:var(--t2);border:none;background:transparent;display:flex;align-items:center;gap:5px}
.vt-tab:hover{background:var(--gxl);color:var(--grn)}.vt-tab.active{background:var(--grn);color:#fff}
.vt-panel{display:none;flex-direction:column;gap:14px}.vt-panel.active{display:flex}
#confirmModal{position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:9100;display:grid;place-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
#confirmModal.on{opacity:1;pointer-events:all}
.cm-box{background:var(--s);border-radius:14px;padding:26px 26px 22px;width:460px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,.22)}
.cm-icon{font-size:44px;margin-bottom:8px;line-height:1}.cm-title{font-size:17px;font-weight:700;color:var(--t1);margin-bottom:6px}
.cm-body{font-size:13px;color:var(--t2);line-height:1.6;margin-bottom:16px}
.cm-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#92400E}
.cm-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px}
.cm-extra{display:flex;flex-direction:column;gap:10px;margin-bottom:14px}
.cm-fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.cm-fg label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t2)}
.cm-fg textarea,.cm-fg input,.cm-fg select{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;width:100%;transition:var(--tr)}
.cm-fg textarea{resize:vertical;min-height:68px}
.cm-fg textarea:focus,.cm-fg input:focus,.cm-fg select:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11)}
.cm-fg select{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;padding-right:30px}
.cm-acts{display:flex;gap:10px;justify-content:flex-end}
#toastWrap{position:fixed;bottom:26px;right:26px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast{background:#0A1F0D;color:#fff;padding:12px 16px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:210px;animation:TIN .3s ease}
.toast.ts{background:var(--grn)}.toast.tw{background:var(--amb)}.toast.td{background:var(--red)}.toast.out{animation:TOUT .3s ease forwards}
@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}
@keyframes SHK{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-5px)}40%,80%{transform:translateX(5px)}}
@media(max-width:1200px){.sum-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:900px){.sum-grid{grid-template-columns:repeat(2,1fr)}.fg2,.fg3{grid-template-columns:1fr}.vp-grid{grid-template-columns:1fr}}
@media(max-width:600px){.wrap{padding:0 0 2rem}#mainSlider{width:100vw}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="wrap">

  <div class="ph">
    <div class="ph-l">
      <p class="ey">PLT · Project Logistics Tracker</p>
      <h1>Logistics Assignments</h1>
    </div>
    <div class="ph-r">
      <?php if ($CAN_EXPORT): ?>
      <button class="btn btn-ghost" onclick="doExport()"><i class="bx bx-export"></i> Export</button>
      <?php endif; ?>
      <?php if ($CAN_CREATE): ?>
      <button class="btn btn-primary" onclick="openSlider('create',null)"><i class="bx bx-plus"></i> Create Assignment</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ACCESS BANNERS -->
  <?php if ($roleName === 'Admin'): ?>
  <div class="access-banner ab-info"><i class='bx bx-info-circle'></i><div>You have <strong>Admin access</strong> — showing your zone's assignments. You can create, reassign within your zone, escalate, and mark complete. Cross-zone operations and force-complete require Super Admin.</div></div>
  <?php elseif ($roleName === 'Manager'): ?>
  <div class="access-banner ab-warn"><i class='bx bx-lock-open-alt'></i><div>You have <strong>Manager access</strong> — showing your zone's assignments. You can create zone assignments, reassign team members, and mark tasks complete. Force-complete and cross-zone actions require Admin or Super Admin.</div></div>
  <?php elseif ($roleRank <= 1): ?>
  <div class="access-banner ab-staff"><i class='bx bx-user-circle'></i><div>You have <strong>Staff access</strong> — showing only your assigned tasks. You can start tasks, update progress, mark complete, and add notes.</div></div>
  <?php endif; ?>

  <div class="sum-grid" id="sumGrid"></div>

  <div class="toolbar">
    <div class="sw"><i class="bx bx-search"></i><input type="text" class="si" id="srch" placeholder="Search by ID, task<?= $roleRank >= 2 ? ', assignee, or zone' : '' ?>…"></div>
    <select class="sel" id="fStatus">
      <option value="">All Statuses</option>
      <?php foreach ($ALLOWED_STATUSES as $st): ?>
      <option><?= htmlspecialchars($st) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($roleRank >= 2): ?>
    <select class="sel" id="fZone"><option value="">All Zones</option></select>
    <select class="sel" id="fPriority">
      <option value="">All Priorities</option>
      <option>Critical</option><option>High</option><option>Medium</option><option>Low</option>
    </select>
    <?php endif; ?>
    <?php if ($roleRank >= 3): ?>
    <div class="date-wrap">
      <input type="date" class="fi-date" id="fFrom" title="Due From">
      <span>–</span>
      <input type="date" class="fi-date" id="fTo" title="Due To">
    </div>
    <?php endif; ?>
  </div>

  <!-- BULK BAR — SA only -->
  <?php if ($CAN_BATCH_REASSIGN || $CAN_BATCH_FORCE): ?>
  <div class="bulk-bar" id="bulkBar">
    <span class="bulk-ct" id="bulkCt">0 selected</span>
    <div class="bulk-sep"></div>
    <?php if ($CAN_BATCH_REASSIGN): ?>
    <button class="btn btn-reassign btn-sm" id="bBatchReassign"><i class="bx bx-transfer"></i> Bulk Reassign</button>
    <?php endif; ?>
    <?php if ($CAN_BATCH_FORCE): ?>
    <button class="btn btn-force-complete btn-sm" id="bBatchForce"><i class="bx bx-check-shield"></i> Force Complete Overdue</button>
    <?php endif; ?>
    <button class="btn btn-ghost btn-sm" id="clearSelBtn"><i class="bx bx-x"></i> Clear</button>
    <span class="sa-tag-bar" style="margin-left:auto"><i class="bx bx-shield-quarter"></i> Super Admin Exclusive</span>
  </div>
  <?php endif; ?>

  <div class="tbl-card">
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;min-width:0;"><div style="min-width:<?= $roleRank >= 2 ? '1100px' : '700px' ?>;">
    <table class="inv-tbl" id="tbl">
      <thead><tr>
        <?php if ($CAN_BATCH_REASSIGN || $CAN_BATCH_FORCE): ?>
        <th class="ns"><div class="cb-wrap"><input type="checkbox" class="cb" id="checkAll"></div></th>
        <?php else: ?>
        <th class="ns" style="width:12px"></th>
        <?php endif; ?>
        <th data-col="assignmentId">Assignment ID <i class="bx bx-sort si-c"></i></th>
        <th data-col="task">Task Description <i class="bx bx-sort si-c"></i></th>
        <?php if ($roleRank >= 2): ?>
        <th data-col="assignedTo">Assigned To <i class="bx bx-sort si-c"></i></th>
        <th data-col="zone">Zone <i class="bx bx-sort si-c"></i></th>
        <?php endif; ?>
        <th data-col="priority">Priority <i class="bx bx-sort si-c"></i></th>
        <th data-col="dueDate">Due Date <i class="bx bx-sort si-c"></i></th>
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
  <div class="sl-hd" id="slHd">
    <div><div class="sl-title" id="slTitle">Create Assignment</div><div class="sl-sub" id="slSub">Fill in all required fields below</div></div>
    <button class="sl-cl" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-bd" id="slBody"></div>
  <div class="sl-ft" id="slFoot">
    <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-send"></i> Save Assignment</button>
  </div>
</div>

<div id="confirmModal">
  <div class="cm-box">
    <div class="cm-icon" id="cmIcon">⚠️</div>
    <div class="cm-title" id="cmTitle">Confirm</div>
    <div class="cm-body" id="cmBody"></div>
    <div class="cm-sa-note" id="cmSaNote" style="display:none"><i class="bx bx-shield-quarter"></i><span id="cmSaText"></span></div>
    <div id="cmExtra" class="cm-extra"></div>
    <div class="cm-fg">
      <label>Remarks / Notes (optional)</label>
      <textarea id="cmRemarks" placeholder="Add remarks for this action…"></textarea>
    </div>
    <div class="cm-acts">
      <button class="btn btn-ghost btn-sm" id="cmCancel">Cancel</button>
      <button class="btn btn-sm" id="cmConfirm">Confirm</button>
    </div>
  </div>
</div>

<script>
// ── ROLE from PHP ─────────────────────────────────────────────────────────────
const ROLE = <?= $jsRole ?>;

const API = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';

const ZONE_COLORS = {
    'ZN-A01':'#2E7D32','ZN-B02':'#0D9488','ZN-C03':'#DC2626',
    'ZN-D04':'#2563EB','ZN-E05':'#7C3AED','ZN-F06':'#D97706','ZN-G07':'#059669',
    'Zone A – North':'#2E7D32','Zone B – South':'#0D9488','Zone C – East':'#2563EB',
    'Zone D – West':'#D97706','Zone E – Central':'#7C3AED','Zone F – Offshore':'#DC2626',
};

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
let ASGN=[], ZONES=[], STAFF=[];
let sortCol='dueDate', sortDir='asc', page=1;
const PAGE=10;
let selectedIds=new Set();
let sliderMode=null, sliderTargetId=null, confirmCb=null;
let clItems=[];
let viewTabState='ov';

// ── LOAD ──────────────────────────────────────────────────────────────────────
async function loadAll() {
    try {
        [ZONES, STAFF, ASGN] = await Promise.all([
            apiGet(API+'?api=zones'),
            ROLE.canCreate ? apiGet(API+'?api=staff').catch(()=>[]) : Promise.resolve([]),
            apiGet(API+'?api=list'),
        ]);
    } catch(e) { toast('Failed to load data: '+e.message,'d'); }
    if (!STAFF.length && ROLE.canCreate) STAFF = [
        {id:'s1',name:'Marco Villanueva'},{id:'s2',name:'Lito Ramos'},
        {id:'s3',name:'Sheila Torres'},{id:'s4',name:'Dante Cruz'},
        {id:'s5',name:'Rina Dela Peña'},{id:'s6',name:'Bong Soriano'},
    ];
    renderList();
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const ini = n => String(n||'').split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
const fD  = d => { if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); };
const zc  = z => ZONE_COLORS[z] || '#6B7280';
const today = () => new Date().toISOString().slice(0,10);

function badge(s) {
    const m = {Unassigned:'b-unassigned',Assigned:'b-assigned','In Progress':'b-inprogress',Completed:'b-completed',Overdue:'b-overdue',Escalated:'b-escalated'};
    return `<span class="badge ${m[s]||''}">${esc(s)}</span>`;
}
function priBadge(p) {
    const m = {Critical:'p-critical',High:'p-high',Medium:'p-medium',Low:'p-low'};
    const ic = {Critical:'bx-error',High:'bx-chevrons-up',Medium:'bx-minus',Low:'bx-chevrons-down'};
    return `<span class="pri-badge ${m[p]||''}"><i class="bx ${ic[p]||'bx-minus'}"></i>${esc(p)}</span>`;
}

// ── STATS — scaled by role ────────────────────────────────────────────────────
function renderStats() {
    const c = s => ASGN.filter(a=>a.status===s).length;

    if (ROLE.rank <= 1) {
        // Staff: my tasks summary
        document.getElementById('sumGrid').innerHTML=`
            <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-task"></i></div><div class="sc-info"><div class="sc-v">${ASGN.length}</div><div class="sc-l">My Tasks</div></div></div>
            <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-user-check"></i></div><div class="sc-info"><div class="sc-v">${c('Assigned')}</div><div class="sc-l">Assigned</div></div></div>
            <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-run"></i></div><div class="sc-info"><div class="sc-v">${c('In Progress')}</div><div class="sc-l">In Progress</div></div></div>
            <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div class="sc-info"><div class="sc-v">${c('Completed')}</div><div class="sc-l">Completed</div></div></div>
            <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-alarm-exclamation"></i></div><div class="sc-info"><div class="sc-v">${c('Overdue')}</div><div class="sc-l">Overdue</div></div></div>`;
        return;
    }
    if (ROLE.rank === 2) {
        // Manager: zone team stats (no Escalated)
        document.getElementById('sumGrid').innerHTML=`
            <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-task"></i></div><div class="sc-info"><div class="sc-v">${ASGN.length}</div><div class="sc-l">Zone Tasks</div></div></div>
            <div class="sc"><div class="sc-ic ic-d"><i class="bx bx-user-x"></i></div><div class="sc-info"><div class="sc-v">${c('Unassigned')}</div><div class="sc-l">Unassigned</div></div></div>
            <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-user-check"></i></div><div class="sc-info"><div class="sc-v">${c('Assigned')}</div><div class="sc-l">Assigned</div></div></div>
            <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-run"></i></div><div class="sc-info"><div class="sc-v">${c('In Progress')}</div><div class="sc-l">In Progress</div></div></div>
            <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div class="sc-info"><div class="sc-v">${c('Completed')}</div><div class="sc-l">Completed</div></div></div>
            <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-alarm-exclamation"></i></div><div class="sc-info"><div class="sc-v">${c('Overdue')}</div><div class="sc-l">Overdue</div></div></div>`;
        return;
    }
    // Admin+: full stats
    document.getElementById('sumGrid').innerHTML=`
        <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-task"></i></div><div class="sc-info"><div class="sc-v">${ASGN.length}</div><div class="sc-l">Total</div></div></div>
        <div class="sc"><div class="sc-ic ic-d"><i class="bx bx-user-x"></i></div><div class="sc-info"><div class="sc-v">${c('Unassigned')}</div><div class="sc-l">Unassigned</div></div></div>
        <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-user-check"></i></div><div class="sc-info"><div class="sc-v">${c('Assigned')}</div><div class="sc-l">Assigned</div></div></div>
        <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-run"></i></div><div class="sc-info"><div class="sc-v">${c('In Progress')}</div><div class="sc-l">In Progress</div></div></div>
        <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div class="sc-info"><div class="sc-v">${c('Completed')}</div><div class="sc-l">Completed</div></div></div>
        <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-alarm-exclamation"></i></div><div class="sc-info"><div class="sc-v">${c('Overdue')}</div><div class="sc-l">Overdue</div></div></div>
        <div class="sc"><div class="sc-ic ic-p"><i class="bx bx-up-arrow-circle"></i></div><div class="sc-info"><div class="sc-v">${c('Escalated')}</div><div class="sc-l">Escalated</div></div></div>`;
}

// ── FILTER / SORT ─────────────────────────────────────────────────────────────
function getFiltered() {
    const q  = document.getElementById('srch').value.trim().toLowerCase();
    const fs = document.getElementById('fStatus').value;
    const fz = document.getElementById('fZone')?.value || '';
    const fp = document.getElementById('fPriority')?.value || '';
    const ff = document.getElementById('fFrom')?.value || '';
    const ft = document.getElementById('fTo')?.value || '';
    return ASGN.filter(a => {
        if (q && !a.assignmentId.toLowerCase().includes(q) && !a.task.toLowerCase().includes(q) &&
            !a.assignedTo.toLowerCase().includes(q) && !a.zone.toLowerCase().includes(q)) return false;
        if (fs && a.status !== fs) return false;
        if (fz && a.zone !== fz)   return false;
        if (fp && a.priority !== fp) return false;
        if (ff && a.dueDate < ff) return false;
        if (ft && a.dueDate > ft) return false;
        return true;
    });
}
function getSorted(list) {
    const PRI = {Critical:0,High:1,Medium:2,Low:3};
    return [...list].sort((a,b) => {
        let va=a[sortCol], vb=b[sortCol];
        if (sortCol==='priority') { va=PRI[va]??9; vb=PRI[vb]??9; return sortDir==='asc'?va-vb:vb-va; }
        va=String(va||'').toLowerCase(); vb=String(vb||'').toLowerCase();
        return sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va);
    });
}

function buildZoneDropdown() {
    const zEl = document.getElementById('fZone'); if(!zEl) return;
    const zones = [...new Set(ASGN.map(a=>a.zone))].sort();
    const v = zEl.value;
    zEl.innerHTML = '<option value="">All Zones</option>' + zones.map(z=>`<option ${z===v?'selected':''}>${esc(z)}</option>`).join('');
}

// ── RENDER TABLE ──────────────────────────────────────────────────────────────
function renderList() {
    renderStats(); buildZoneDropdown();
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

    const colCount = document.querySelectorAll('#tbl thead th').length;
    const tb=document.getElementById('tbody');
    if(!slice.length){
        tb.innerHTML=`<tr><td colspan="${colCount}"><div class="empty"><i class="bx bx-task"></i><p>No assignments found.</p></div></td></tr>`;
    } else {
        tb.innerHTML=slice.map(a=>{
            const chk=selectedIds.has(a.assignmentId);
            const color=zc(a.zone);
            const isDone=a.status==='Completed';
            const isOver=a.status==='Overdue';
            const isEsc=a.status==='Escalated';
            const isAssigned=a.status==='Assigned';
            const isInProg=a.status==='In Progress';
            const isUnassigned=a.status==='Unassigned';
            const duePast=a.dueDate<today()&&!isDone;
            const cl=a.checklist||[], clDone=cl.filter(c=>c.done).length;
            const isMyTask = a.assignedTo === ROLE.userName;

            // Checkbox — SA only
            const cbCell = (ROLE.canBatchReassign || ROLE.canBatchForce)
                ? `<td onclick="event.stopPropagation()"><div class="cb-wrap"><input type="checkbox" class="cb row-cb" data-id="${a.assignmentId}" ${chk?'checked':''}></div></td>`
                : `<td></td>`;

            // Assignee column — Manager+ only
            const assigneeCol = ROLE.rank >= 2 ? `<td onclick="openView('${a.assignmentId}')">
                ${a.assignedTo!=='Unassigned'
                    ?`<div class="asgn-cell"><div class="asgn-av" style="background:${color}">${ini(a.assignedTo)}</div>
                      <div class="asgn-name">${esc(a.assignedTo)}${isMyTask?'<span class="my-task-dot">Me</span>':''}</div></div>`
                    :`<span style="font-size:12px;color:#9CA3AF;font-style:italic">— Unassigned —</span>`}
            </td>` : '';

            // Zone column — Manager+ only
            const zoneCol = ROLE.rank >= 2
                ? `<td onclick="openView('${a.assignmentId}')"><span class="zone-pill"><span class="zone-dot" style="background:${color}"></span>${esc(a.zone.split('–')[0].trim()||a.zone)}</span></td>`
                : '';

            // Action buttons — gated by role
            let actions = `<button class="btn ionly" onclick="openView('${a.assignmentId}')" title="View"><i class="bx bx-show"></i></button>`;

            // Edit — Manager+, not for completed
            if (ROLE.canEdit && !isDone)
                actions += ` <button class="btn ionly" onclick="openSlider('edit','${a.assignmentId}')" title="Edit"><i class="bx bx-edit"></i></button>`;

            // Reassign — Manager+ (zone restricted server-side); SA gets cross-zone
            if (ROLE.canReassign && (isUnassigned||isAssigned||isInProg))
                actions += ` <button class="btn ionly btn-reassign" onclick="doAction('reassign','${a.assignmentId}')" title="Reassign"><i class="bx bx-transfer"></i></button>`;

            // Complete — Manager+
            if (ROLE.canComplete && (isAssigned||isInProg))
                actions += ` <button class="btn ionly btn-complete" onclick="doAction('complete','${a.assignmentId}')" title="Complete"><i class="bx bx-check"></i></button>`;

            // Force complete — SA only
            if (ROLE.canForceComplete && isOver)
                actions += ` <button class="btn ionly btn-force-complete" onclick="doAction('force-complete','${a.assignmentId}')" title="Force Complete"><i class="bx bx-check-shield"></i></button>`;

            // Escalate — Admin+
            if (ROLE.canEscalate && !isEsc && !isDone)
                actions += ` <button class="btn ionly btn-escalate" onclick="doAction('escalate','${a.assignmentId}')" title="Escalate"><i class="bx bx-up-arrow-circle"></i></button>`;

            // Staff: start own task (Assigned → In Progress) or complete (In Progress → Done)
            if (ROLE.rank <= 1 && isMyTask) {
                if (isAssigned)
                    actions += ` <button class="btn ionly btn-start" onclick="doAction('mark-inprogress','${a.assignmentId}')" title="Start Task"><i class="bx bx-run"></i></button>`;
                if (isInProg)
                    actions += ` <button class="btn ionly btn-complete" onclick="doAction('update-own','${a.assignmentId}')" title="Mark Complete"><i class="bx bx-check"></i></button>`;
            }

            return `<tr class="${chk?'row-sel':''}" data-id="${a.assignmentId}">
                ${cbCell}
                <td onclick="openView('${a.assignmentId}')"><span class="id-cell">${esc(a.assignmentId)}</span></td>
                <td onclick="openView('${a.assignmentId}')">
                    <div class="task-cell" title="${esc(a.task)}">${esc(a.task)}
                    ${ROLE.rank <= 1 && isMyTask ? '<span class="my-task-dot" style="margin-left:4px">Mine</span>' : ''}
                    </div>
                    ${cl.length?`<div class="sub-cell">${clDone}/${cl.length} checklist</div>`:''}
                </td>
                ${assigneeCol}
                ${zoneCol}
                <td onclick="openView('${a.assignmentId}')">${priBadge(a.priority)}</td>
                <td onclick="openView('${a.assignmentId}')">
                    <span class="date-cell" style="${duePast?'color:#DC2626;font-weight:700':''}">${fD(a.dueDate)}${duePast&&!isDone?' ⚠':''}</span>
                </td>
                <td onclick="openView('${a.assignmentId}')">${badge(a.status)}</td>
                <td onclick="event.stopPropagation()"><div class="act-cell">${actions}</div></td>
            </tr>`;
        }).join('');

        if (ROLE.canBatchReassign || ROLE.canBatchForce) {
            document.querySelectorAll('.row-cb').forEach(cb=>{
                cb.addEventListener('change',function(){
                    const id=this.dataset.id;
                    if(this.checked) selectedIds.add(id); else selectedIds.delete(id);
                    this.closest('tr').classList.toggle('row-sel',this.checked);
                    updateBulkBar(); syncCheckAll(slice);
                });
            });
        }
    }
    syncCheckAll(slice);
    const s=(page-1)*PAGE+1, e=Math.min(page*PAGE,total);
    let btns='';
    for(let i=1;i<=pages;i++){
        if(i===1||i===pages||(i>=page-2&&i<=page+2)) btns+=`<button class="pgb ${i===page?'active':''}" onclick="goPage(${i})">${i}</button>`;
        else if(i===page-3||i===page+3) btns+=`<button class="pgb" disabled>…</button>`;
    }
    document.getElementById('pager').innerHTML=`
        <span>${total===0?'No results':`Showing ${s}–${e} of ${total} assignments`}</span>
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
['srch','fStatus','fZone','fPriority','fFrom','fTo'].forEach(id=>
    document.getElementById(id)?.addEventListener('input',()=>{page=1;renderList();})
);

// ── BULK ──────────────────────────────────────────────────────────────────────
function updateBulkBar(){
    const bulkBar = document.getElementById('bulkBar'); if(!bulkBar) return;
    const n=selectedIds.size;
    bulkBar.classList.toggle('on',n>0);
    document.getElementById('bulkCt').textContent=n===1?'1 selected':`${n} selected`;
}
function syncCheckAll(slice){
    const ca=document.getElementById('checkAll'); if(!ca) return;
    const ids=slice.map(a=>a.assignmentId);
    const all=ids.length>0&&ids.every(id=>selectedIds.has(id));
    const some=ids.some(id=>selectedIds.has(id));
    ca.checked=all; ca.indeterminate=!all&&some;
}
document.getElementById('checkAll')?.addEventListener('change',function(){
    const slice=getSorted(getFiltered()).slice((page-1)*PAGE,page*PAGE);
    slice.forEach(a=>{if(this.checked) selectedIds.add(a.assignmentId); else selectedIds.delete(a.assignmentId);});
    renderList(); updateBulkBar();
});
document.getElementById('clearSelBtn')?.addEventListener('click',()=>{selectedIds.clear();renderList();updateBulkBar();});

// Bulk reassign — SA only
document.getElementById('bBatchReassign')?.addEventListener('click',()=>{
    if(!ROLE.canBatchReassign) return toast('Insufficient permissions','w');
    const valid=[...selectedIds].filter(id=>{const a=ASGN.find(x=>x.assignmentId===id);return a&&a.status!=='Completed';});
    if(!valid.length){toast('No reassignable assignments selected.','w');return;}
    showConfirmModal({
        icon:'🔄', title:`Bulk Reassign ${valid.length} Assignment(s)`,
        body:`Reassign <strong>${valid.length}</strong> assignment(s) to a new person.`,
        sa:true, saText:'Super Admin cross-zone reassignment authority.',
        extra:`<div class="cm-fg"><label>Reassign To</label><select id="cmReassignTo" class="fs"><option value="">Select personnel…</option>${STAFF.map(s=>`<option>${esc(s.name)}</option>`).join('')}</select></div>`,
        btnClass:'btn-reassign', btnLabel:'<i class="bx bx-transfer"></i> Bulk Reassign',
        onConfirm: async() => {
            const to=document.getElementById('cmReassignTo')?.value;
            if(!to){toast('Please select a person.','w');return false;}
            const rmk=document.getElementById('cmRemarks').value.trim();
            try{
                const r=await apiPost(API+'?api=batch',{type:'batch-reassign',ids:valid.map(id=>ASGN.find(x=>x.assignmentId===id)?.id).filter(Boolean),assignedTo:to,remarks:rmk});
                const updated=await apiGet(API+'?api=list');
                ASGN=updated; selectedIds.clear(); renderList(); updateBulkBar();
                toast(`${r.updated} assignment(s) reassigned to ${to}.`,'s');
            }catch(e){toast(e.message,'d');}
        }
    });
});

// Bulk force complete — SA only
document.getElementById('bBatchForce')?.addEventListener('click',()=>{
    if(!ROLE.canBatchForce) return toast('Insufficient permissions','w');
    const overdueIds=[...selectedIds].filter(id=>{const a=ASGN.find(x=>x.assignmentId===id);return a&&a.status==='Overdue';});
    if(!overdueIds.length){toast('No Overdue assignments in selection.','w');return;}
    showConfirmModal({
        icon:'⚡', title:`Force Complete ${overdueIds.length} Overdue`,
        body:`Force-mark <strong>${overdueIds.length}</strong> overdue assignment(s) as Completed.`,
        sa:true, saText:'Super Admin override — marks overdue tasks complete without field sign-off.',
        extra:'',
        btnClass:'btn-force-complete', btnLabel:'<i class="bx bx-check-shield"></i> Force Complete',
        onConfirm: async() => {
            const rmk=document.getElementById('cmRemarks').value.trim();
            try{
                const r=await apiPost(API+'?api=batch',{type:'batch-force-complete',ids:overdueIds.map(id=>ASGN.find(x=>x.assignmentId===id)?.id).filter(Boolean),remarks:rmk});
                const updated=await apiGet(API+'?api=list');
                ASGN=updated; selectedIds.clear(); renderList(); updateBulkBar();
                toast(`${r.updated} overdue assignment(s) force-completed.`,'s');
            }catch(e){toast(e.message,'d');}
        }
    });
});

// ── SLIDER ────────────────────────────────────────────────────────────────────
function openSlider(mode, asgnId) {
    if (mode === 'create' && !ROLE.canCreate) { toast('Insufficient permissions','w'); return; }
    sliderMode=mode; sliderTargetId=asgnId;
    const a=asgnId?ASGN.find(x=>x.assignmentId===asgnId):null;
    const cfg={
        create:{title:'Create Assignment',sub:'Fill in all required fields below'},
        edit:  {title:'Edit Assignment',  sub:`Editing ${a?a.assignmentId:'—'}`},
        view:  {title:'Assignment Details',sub:a?`${a.assignmentId} · ${badge(a.status)}`:''},
    };
    document.getElementById('slTitle').textContent=cfg[mode]?.title||'';
    document.getElementById('slSub').innerHTML=cfg[mode]?.sub||'';

    const body=document.getElementById('slBody');
    const foot=document.getElementById('slFoot');

    if(mode==='view'){
        viewTabState='ov';
        renderViewBody(a, body, foot);
    } else {
        if(mode==='edit'&&a){
            clItems=a.checklist?a.checklist.map((c,i)=>({...c,_id:i})):[];
        } else {
            clItems=[{_id:Date.now(),text:'',done:false}];
        }
        body.innerHTML=buildFormBody(a);
        foot.innerHTML=`<button class="btn btn-ghost btn-sm" onclick="closeSlider()">Cancel</button><button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-send"></i> Save Assignment</button>`;
        document.getElementById('slSubmit').onclick=submitForm;
        wireStaffSearch(a);
        renderClItems();
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

// ── FORM BODY — Manager+ ──────────────────────────────────────────────────────
function buildFormBody(a){
    // Status options: limited for Manager
    const statusOpts = ROLE.rank >= 4
        ? ['Unassigned','Assigned','In Progress','Completed','Overdue','Escalated']
        : ['Unassigned','Assigned','In Progress'];
    const statHtml = statusOpts.map(s=>`<option ${a&&a.status===s?'selected':''}>${s}</option>`).join('');
    const priOpts=['Critical','High','Medium','Low'].map(p=>`<option ${a&&a.priority===p?'selected':''}>${p}</option>`).join('');

    // Zone options: SA sees all; others locked to own zone
    let zoneHtml;
    if (ROLE.canCreateAnyZone) {
        zoneHtml = ZONES.map(z=>`<option value="${esc(z.id)}" ${a&&a.zone===z.id?'selected':''}>${esc(z.name)}</option>`).join('');
    } else {
        const uz = ROLE.userZone || '';
        zoneHtml = `<option value="${esc(uz)}" selected>${esc(uz)}</option>`;
    }

    return `
        <div class="fdiv">Assignment Details</div>
        <div class="fg">
            <label class="fl">Task Description <span>*</span></label>
            <input type="text" class="fi" id="fTask" value="${a?esc(a.task):''}" placeholder="e.g. Deliver materials to Site B — Zone North">
        </div>
        <div class="fg2">
            <div class="fg"><label class="fl">Assigned To</label>
                <div class="cs-wrap">
                    <input type="text" class="cs-input" id="csStaffSearch" placeholder="Search staff…" autocomplete="off" value="${a&&a.assignedTo!=='Unassigned'?esc(a.assignedTo):''}">
                    <input type="hidden" id="fAssigned" value="${a?esc(a.assignedTo):'Unassigned'}">
                    <div class="cs-drop" id="csStaffDrop"></div>
                </div>
            </div>
            <div class="fg"><label class="fl">Zone <span>*</span></label>
                <select class="fs" id="fZoneSl" ${!ROLE.canCreateAnyZone?'disabled style="background:#F9FAFB;color:#6B7280"':''}>
                    <option value="">Select zone…</option>${zoneHtml}
                </select>
            </div>
        </div>
        <div class="fg3">
            <div class="fg"><label class="fl">Priority <span>*</span></label><select class="fs" id="fPrioritySl">${priOpts}</select></div>
            <div class="fg"><label class="fl">Due Date <span>*</span></label><input type="date" class="fi" id="fDue" value="${a?a.dueDate:''}"></div>
            <div class="fg"><label class="fl">Status</label><select class="fs" id="fStatusSl">${statHtml}</select></div>
        </div>
        <div class="fg"><label class="fl">Notes / Instructions</label><textarea class="fta" id="fNotes" placeholder="Special instructions, hazards, access requirements…">${a?esc(a.notes):''}</textarea></div>
        <div class="fdiv">Checklist Items</div>
        <div class="cl-rows" id="clRows"></div>
        <button class="add-cl" id="addClBtn" type="button"><i class="bx bx-plus"></i> Add Checklist Item</button>`;
}

function wireStaffSearch(a){
    setTimeout(()=>{
        const csInput=document.getElementById('csStaffSearch');
        const csHidden=document.getElementById('fAssigned');
        const csDrop=document.getElementById('csStaffDrop');
        if(!csInput) return;
        let csHl=-1;
        const unassignedOpt = {id:'',name:'Unassigned'};
        const all = [unassignedOpt, ...STAFF];

        function csRender(q){
            const lq=(q||'').toLowerCase();
            const filtered=all.filter(s=>s.name.toLowerCase().includes(lq));
            csDrop.innerHTML=filtered.length
                ?filtered.map(s=>`<div class="cs-opt" data-name="${esc(s.name)}"><span class="cs-name">${esc(s.name)}</span></div>`).join('')
                :'<div class="cs-opt cs-none">No staff found</div>';
            csDrop.querySelectorAll('.cs-opt:not(.cs-none)').forEach(opt=>{
                opt.addEventListener('mousedown',e=>{e.preventDefault();csSelect(opt.dataset.name);});
            });
            csHl=-1;
        }
        function csSelect(name){
            csHidden.value=name||'Unassigned';
            csInput.value=name==='Unassigned'?'':name;
            csDrop.classList.remove('open');
            const fStatus=document.getElementById('fStatusSl');
            if(fStatus&&fStatus.value==='Unassigned'&&name&&name!=='Unassigned') fStatus.value='Assigned';
            if(fStatus&&(!name||name==='Unassigned')) fStatus.value='Unassigned';
        }
        csInput.addEventListener('focus',()=>{csRender(csInput.value);csDrop.classList.add('open');});
        csInput.addEventListener('input',()=>{csHidden.value='Unassigned';csRender(csInput.value);csDrop.classList.add('open');});
        csInput.addEventListener('blur',()=>setTimeout(()=>csDrop.classList.remove('open'),150));
        csInput.addEventListener('keydown',e=>{
            const opts=[...csDrop.querySelectorAll('.cs-opt:not(.cs-none)')];
            if(e.key==='ArrowDown'){e.preventDefault();csHl=Math.min(csHl+1,opts.length-1);}
            else if(e.key==='ArrowUp'){e.preventDefault();csHl=Math.max(csHl-1,0);}
            else if(e.key==='Enter'&&csHl>=0){e.preventDefault();const o=opts[csHl];if(o)csSelect(o.dataset.name);}
            else if(e.key==='Escape'){csDrop.classList.remove('open');}
            opts.forEach((o,i)=>o.classList.toggle('hl',i===csHl));
            if(csHl>=0&&opts[csHl]) opts[csHl].scrollIntoView({block:'nearest'});
        });
        document.getElementById('addClBtn')?.addEventListener('click',()=>{
            clItems.push({_id:Date.now(),text:'',done:false}); renderClItems();
        });
    },100);
}

function renderClItems(){
    const wrap=document.getElementById('clRows'); if(!wrap) return;
    wrap.innerHTML=clItems.map((c,i)=>`
        <div class="cl-row" id="cl${c._id}">
            <input type="checkbox" class="cb" ${c.done?'checked':''} onchange="updateClItem(${c._id},'done',this.checked)">
            <input type="text" class="fi" style="flex:1;margin:0" placeholder="Checklist item ${i+1}" value="${esc(c.text)}" oninput="updateClItem(${c._id},'text',this.value)">
            ${clItems.length>1?`<button class="cl-rm" onclick="removeClItem(${c._id})"><i class="bx bx-trash" style="font-size:13px"></i></button>`:''}
        </div>`).join('');
}
function updateClItem(id,k,v){const c=clItems.find(x=>x._id===id);if(c){c[k]=v;}}
function removeClItem(id){clItems=clItems.filter(c=>c._id!==id);renderClItems();}

// ── VIEW BODY ─────────────────────────────────────────────────────────────────
function renderViewBody(a, bodyEl, footEl){
    if(!a){bodyEl.innerHTML='<p>Not found.</p>';return;}
    const color=zc(a.zone);
    const cl=a.checklist||[], clDone=cl.filter(c=>c.done).length, clPct=cl.length>0?Math.round(clDone/cl.length*100):0;
    const isDone=a.status==='Completed', isOver=a.status==='Overdue', isEsc=a.status==='Escalated';
    const isAssigned=a.status==='Assigned', isInProg=a.status==='In Progress', isUnassigned=a.status==='Unassigned';
    const isMyTask = a.assignedTo === ROLE.userName;

    // Tabs: Staff only see Overview + Checklist; SA gets Audit Trail too
    const auditTab = ROLE.canAuditTrail
        ? `<button class="vt-tab ${viewTabState==='au'?'active':''}" onclick="switchViewTab('au','${a.assignmentId}')"><i class="bx bx-shield-quarter"></i> Audit Trail</button>`
        : '';

    bodyEl.innerHTML=`
        <div class="vt-bar">
            <button class="vt-tab ${viewTabState==='ov'?'active':''}" onclick="switchViewTab('ov','${a.assignmentId}')"><i class="bx bx-grid-alt"></i> Overview</button>
            <button class="vt-tab ${viewTabState==='cl'?'active':''}" onclick="switchViewTab('cl','${a.assignmentId}')"><i class="bx bx-check-square"></i> Checklist</button>
            ${auditTab}
        </div>
        <div class="vt-panel ${viewTabState==='ov'?'active':''}" id="vpOv">
            <div class="vp-statbox">
                <div class="vp-sb"><div class="sbv">${cl.length}</div><div class="sbl">Checklist</div></div>
                <div class="vp-sb"><div class="sbv">${clDone}</div><div class="sbl">Done</div></div>
                <div class="vp-sb"><div class="sbv">${clPct}%</div><div class="sbl">Progress</div></div>
                <div class="vp-sb"><div class="sbv" style="font-size:14px">${priBadge(a.priority)}</div><div class="sbl" style="margin-top:6px">Priority</div></div>
            </div>
            <div class="prog-bar-wrap"><div class="prog-bar-fill" style="width:${clPct}%;background:${clPct===100?'#22C55E':clPct>50?'#F59E0B':'#EF4444'}"></div></div>
            <div class="vp-section">
                <div class="vp-section-title">Assignment Info</div>
                <div class="vp-grid">
                    <div class="vp-item"><label>Assignment ID</label><div class="v mono" style="color:var(--grn)">${esc(a.assignmentId)}</div></div>
                    ${ROLE.rank >= 2 ? `<div class="vp-item"><label>Assigned To</label><div class="v">${esc(a.assignedTo)}</div></div>` : ''}
                    ${ROLE.rank >= 2 ? `<div class="vp-item"><label>Zone</label><div class="v" style="color:${color};font-weight:600">${esc(a.zone)}</div></div>` : ''}
                    <div class="vp-item"><label>Status</label><div class="v">${badge(a.status)}</div></div>
                    ${ROLE.rank >= 2 ? `<div class="vp-item"><label>Date Created</label><div class="vm">${fD(a.dateCreated)}</div></div>` : ''}
                    <div class="vp-item"><label>Due Date</label><div class="vm" style="${a.dueDate<today()&&!isDone?'color:#DC2626;font-weight:700':''}">${fD(a.dueDate)}</div></div>
                </div>
            </div>
            ${a.notes?`<div class="vp-section"><div class="vp-section-title">Notes</div><div style="font-size:13px;color:var(--t2);line-height:1.6">${esc(a.notes)}</div></div>`:''}
            ${ROLE.canAddNotes && isMyTask && !isDone ? `
            <div class="note-form">
                <div class="note-form-title"><i class="bx bx-note" style="vertical-align:-1px;margin-right:4px"></i>Add Note / Update</div>
                <textarea class="fta" id="staffNoteInput" placeholder="Add a progress note or update…" style="min-height:60px"></textarea>
                <button class="btn btn-primary btn-sm" onclick="staffAddNote('${a.assignmentId}')"><i class="bx bx-save"></i> Save Note</button>
            </div>` : ''}
        </div>
        <div class="vt-panel ${viewTabState==='cl'?'active':''}" id="vpCl">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                <div style="font-size:13px;font-weight:700;color:var(--t1)">${clDone} of ${cl.length} completed</div>
                <div style="font-size:12px;color:${clPct===100?'#166534':'#9CA3AF'};font-weight:600">${clPct}%</div>
            </div>
            <div class="prog-bar-wrap" style="margin-bottom:16px"><div class="prog-bar-fill" style="width:${clPct}%;background:${clPct===100?'#22C55E':clPct>50?'#F59E0B':'#EF4444'}"></div></div>
            ${cl.length===0?`<div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">No checklist items.</div>`:''}
            ${cl.map((c,i)=>`
                <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:${c.done?'#F0FDF4':'var(--bg)'};border:1px solid ${c.done?'#BBF7D0':'var(--bd)'};border-radius:10px;margin-bottom:8px">
                    <input type="checkbox" class="cb" ${c.done?'checked':''} onchange="toggleChecklist('${a.assignmentId}',${i},this.checked)">
                    <span style="font-size:13px;color:${c.done?'#166534':'var(--t1)'};font-weight:${c.done?600:400};text-decoration:${c.done?'line-through':''};flex:1">${esc(c.text)}</span>
                    ${c.done?'<i class="bx bx-check" style="font-size:16px;color:#22C55E;flex-shrink:0"></i>':''}
                </div>`).join('')}
        </div>
        ${ROLE.canAuditTrail ? `<div class="vt-panel ${viewTabState==='au'?'active':''}" id="vpAu" data-asgn-id="${a.id}">
            <div class="sa-banner"><i class="bx bx-shield-quarter"></i><span>Full audit trail — visible to Super Admin only. All timestamps and IPs are read-only.</span></div>
            <div id="auditContent"><div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">Loading audit trail…</div></div>
        </div>` : ''}`;

    // Footer buttons
    const btns = [];
    if (ROLE.canEdit && !isDone) btns.push(`<button class="btn btn-ghost btn-sm" onclick="closeSlider();openSlider('edit','${a.assignmentId}')"><i class="bx bx-edit"></i> Edit</button>`);
    if (ROLE.canReassign && (isUnassigned||isAssigned||isInProg)) btns.push(`<button class="btn btn-reassign btn-sm" onclick="closeSlider();doAction('reassign','${a.assignmentId}')"><i class="bx bx-transfer"></i> Reassign</button>`);
    if (ROLE.canComplete && (isAssigned||isInProg)) btns.push(`<button class="btn btn-complete btn-sm" onclick="closeSlider();doAction('complete','${a.assignmentId}')"><i class="bx bx-check"></i> Complete</button>`);
    if (ROLE.canForceComplete && isOver) btns.push(`<button class="btn btn-force-complete btn-sm" onclick="closeSlider();doAction('force-complete','${a.assignmentId}')"><i class="bx bx-check-shield"></i> Force Complete</button>`);
    if (ROLE.canEscalate && !isEsc && !isDone) btns.push(`<button class="btn btn-escalate btn-sm" onclick="closeSlider();doAction('escalate','${a.assignmentId}')"><i class="bx bx-up-arrow-circle"></i> Escalate</button>`);
    // Staff: start / complete own task
    if (ROLE.rank <= 1 && isMyTask) {
        if (isAssigned) btns.push(`<button class="btn btn-start btn-sm" onclick="closeSlider();doAction('mark-inprogress','${a.assignmentId}')"><i class="bx bx-run"></i> Start Task</button>`);
        if (isInProg) btns.push(`<button class="btn btn-complete btn-sm" onclick="closeSlider();doAction('update-own','${a.assignmentId}')"><i class="bx bx-check"></i> Mark Complete</button>`);
    }
    btns.push(`<button class="btn btn-ghost btn-sm" onclick="closeSlider()">Close</button>`);
    footEl.innerHTML = btns.join('');

    if(viewTabState==='au' && ROLE.canAuditTrail) loadAuditTrail(a.id);
}

async function switchViewTab(tab, asgnId){
    viewTabState=tab;
    document.querySelectorAll('.vt-tab').forEach(t=>t.classList.toggle('active',t.getAttribute('onclick').includes(`'${tab}'`)));
    document.querySelectorAll('.vt-panel').forEach(p=>p.classList.remove('active'));
    document.getElementById('vp'+tab.charAt(0).toUpperCase()+tab.slice(1))?.classList.add('active');
    if(tab==='au' && ROLE.canAuditTrail){
        const a=ASGN.find(x=>x.assignmentId===asgnId);
        if(a) await loadAuditTrail(a.id);
    }
}

async function loadAuditTrail(dbId){
    const wrap=document.getElementById('auditContent'); if(!wrap) return;
    try{
        const rows=await apiGet(API+'?api=audit&id='+dbId);
        if(!rows.length){wrap.innerHTML=`<div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">No audit entries yet.</div>`;return;}
        wrap.innerHTML=rows.map(lg=>`
            <div class="audit-item">
                <div class="audit-dot ${lg.css_class||'ad-s'}"><i class="bx ${lg.icon||'bx-info-circle'}"></i></div>
                <div class="audit-body">
                    <div class="au">${esc(lg.action_label)} ${lg.is_super_admin?'<span class="sa-tag-small">Super Admin</span>':''}</div>
                    <div class="at">
                        <i class="bx bx-user" style="font-size:11px"></i>${esc(lg.actor_name)} · ${esc(lg.actor_role)}
                        ${lg.ip_address?`<span class="audit-ip"><i class="bx bx-desktop" style="font-size:10px;margin-right:2px"></i>${esc(lg.ip_address)}</span>`:''}
                    </div>
                    ${lg.note?`<div class="audit-note">"${esc(lg.note)}"</div>`:''}
                </div>
                <div class="audit-ts">${lg.occurred_at?new Date(lg.occurred_at).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):''}</div>
            </div>`).join('');
    }catch(e){wrap.innerHTML=`<div style="text-align:center;color:var(--red);padding:24px;font-size:13px">Failed to load audit trail.</div>`;}
}

// ── STAFF: ADD NOTE ───────────────────────────────────────────────────────────
window.staffAddNote = async (asgnId) => {
    if (!ROLE.canAddNotes) return;
    const a = ASGN.find(x=>x.assignmentId===asgnId); if(!a) return;
    const noteEl = document.getElementById('staffNoteInput');
    const note = noteEl?.value?.trim();
    if (!note) return toast('Please enter a note.','w');
    try {
        const updated = await apiPost(API+'?api=action', {id:a.id, type:'add-note', note});
        const idx = ASGN.findIndex(x=>x.id===updated.id);
        if(idx>-1) ASGN[idx]=updated;
        noteEl.value='';
        toast('Note saved.','s');
        renderList();
    } catch(e) { toast(e.message,'d'); }
};

// ── CHECKLIST TOGGLE ──────────────────────────────────────────────────────────
async function toggleChecklist(asgnId, idx, val){
    const a=ASGN.find(x=>x.assignmentId===asgnId); if(!a) return;
    try{
        const updated=await apiPost(API+'?api=checklist',{id:a.id,idx,done:val});
        const i=ASGN.findIndex(x=>x.id===updated.id); if(i>-1) ASGN[i]=updated;
        renderList();
    }catch(e){toast(e.message,'d');}
}

// ── OPEN VIEW ─────────────────────────────────────────────────────────────────
function openView(asgnId){openSlider('view',asgnId);}
window.openView = openView;

// ── SUBMIT FORM — Manager+ ────────────────────────────────────────────────────
async function submitForm(){
    if (!ROLE.canCreate) { toast('Insufficient permissions','w'); return; }
    const btn=document.getElementById('slSubmit'); btn.disabled=true;
    try{
        const task      = document.getElementById('fTask')?.value.trim();
        const assignedTo= document.getElementById('fAssigned')?.value||'Unassigned';
        const zone      = document.getElementById('fZoneSl')?.value || ROLE.userZone;
        const priority  = document.getElementById('fPrioritySl')?.value;
        const dueDate   = document.getElementById('fDue')?.value;
        const status    = document.getElementById('fStatusSl')?.value||'Unassigned';
        const notes     = document.getElementById('fNotes')?.value.trim();
        if(!task)    {shk('fTask');toast('Task description is required.','w');return;}
        if(!zone)    {shk('fZoneSl');toast('Please select a zone.','w');return;}
        if(!priority){shk('fPrioritySl');toast('Please select a priority.','w');return;}
        if(!dueDate) {shk('fDue');toast('Due date is required.','w');return;}
        if(clItems.some(c=>!c.text.trim())){toast('All checklist items must have text.','w');return;}

        const checklist=clItems.map((c,i)=>({id:i+1,text:c.text,done:c.done}));
        const a=sliderTargetId?ASGN.find(x=>x.assignmentId===sliderTargetId):null;
        const payload={task,assignedTo,zone,priority,dueDate,status,notes,checklist};
        if(a) payload.id=a.id;

        const saved=await apiPost(API+'?api=save',payload);
        const idx=ASGN.findIndex(x=>x.id===saved.id);
        if(idx>-1) ASGN[idx]=saved; else ASGN.unshift(saved);
        toast(`${saved.assignmentId} ${a?'updated':'created'}.`,'s');
        closeSlider(); renderList();
    }catch(e){toast(e.message,'d');}
    finally{btn.disabled=false;}
}

// ── ACTION CONFIRM ────────────────────────────────────────────────────────────
function doAction(type, asgnId){
    const a=ASGN.find(x=>x.assignmentId===asgnId); if(!a) return;

    // Permission checks
    if (type==='reassign' && !ROLE.canReassign) return toast('Insufficient permissions','w');
    if (type==='complete' && !ROLE.canComplete) return toast('Insufficient permissions','w');
    if (type==='force-complete' && !ROLE.canForceComplete) return toast('Insufficient permissions','w');
    if (type==='escalate' && !ROLE.canEscalate) return toast('Insufficient permissions','w');
    if ((type==='mark-inprogress'||type==='update-own') && !ROLE.canUpdateOwn) return toast('Insufficient permissions','w');

    const reassignExtra=`<div class="cm-fg"><label>Reassign To <span style="color:var(--red)">*</span></label><select id="cmReassignTo"><option value="">Select personnel…</option>${STAFF.map(s=>`<option ${s.name===a.assignedTo?'selected':''}>${esc(s.name)}</option>`).join('')}</select></div>`;

    const cfg={
        reassign:        {icon:'🔄',title:'Reassign Assignment',  body:`<strong>${esc(a.assignmentId)}</strong> — ${esc(a.task.slice(0,60))}`,sa:ROLE.canCrossZone,saText:'Reassign across any zone as Super Admin.',extra:reassignExtra,btn:'btn-reassign',label:'<i class="bx bx-transfer"></i> Reassign'},
        complete:        {icon:'✅',title:'Mark as Completed',    body:`<strong>${esc(a.assignmentId)}</strong> — ${esc(a.task.slice(0,60))}`,sa:false,saText:'',extra:'',btn:'btn-complete',label:'<i class="bx bx-check"></i> Complete'},
        'force-complete':{icon:'⚡',title:'Force Complete Overdue',body:`<strong>${esc(a.assignmentId)}</strong> — ${esc(a.task.slice(0,60))}`,sa:true,saText:'Super Admin authority to force-complete without field confirmation.',extra:'',btn:'btn-force-complete',label:'<i class="bx bx-check-shield"></i> Force Complete'},
        escalate:        {icon:'🚨',title:'Escalate Assignment',  body:`<strong>${esc(a.assignmentId)}</strong> — ${esc(a.task.slice(0,60))}`,sa:ROLE.rank>=4,saText:'Escalating will flag this for immediate Admin review.',extra:'',btn:'btn-escalate',label:'<i class="bx bx-up-arrow-circle"></i> Escalate'},
        'mark-inprogress':{icon:'🚀',title:'Start Task',         body:`<strong>${esc(a.assignmentId)}</strong> — ${esc(a.task.slice(0,60))}`,sa:false,saText:'',extra:'',btn:'btn-start',label:'<i class="bx bx-run"></i> Start Task'},
        'update-own':    {icon:'✅',title:'Mark as Completed',   body:`<strong>${esc(a.assignmentId)}</strong> — ${esc(a.task.slice(0,60))}`,sa:false,saText:'',extra:'',btn:'btn-complete',label:'<i class="bx bx-check"></i> Mark Complete'},
    };
    const c=cfg[type]; if(!c) return;
    showConfirmModal({
        icon:c.icon,title:c.title,body:c.body,sa:c.sa,saText:c.saText,extra:c.extra,
        btnClass:c.btn,btnLabel:c.label,
        onConfirm:async()=>{
            const rmk=document.getElementById('cmRemarks').value.trim();
            const payload={id:a.id,type,remarks:rmk};
            if(type==='reassign'){
                const to=document.getElementById('cmReassignTo')?.value;
                if(!to){toast('Please select a person.','w');return false;}
                payload.assignedTo=to;
            }
            try{
                const updated=await apiPost(API+'?api=action',payload);
                const idx=ASGN.findIndex(x=>x.id===updated.id); if(idx>-1) ASGN[idx]=updated;
                const msgs={escalate:'escalated',reassign:'reassigned','complete':'completed','force-complete':'force-completed','mark-inprogress':'started','update-own':'completed'};
                toast(`${a.assignmentId} ${msgs[type]||'updated'}.`,'s');
                renderList();
            }catch(e){toast(e.message,'d');}
        }
    });
}
window.doAction = doAction;

// ── CONFIRM MODAL ─────────────────────────────────────────────────────────────
function showConfirmModal({icon,title,body,sa,saText,extra,btnClass,btnLabel,onConfirm}){
    document.getElementById('cmIcon').textContent=icon;
    document.getElementById('cmTitle').textContent=title;
    document.getElementById('cmBody').innerHTML=body;
    const san=document.getElementById('cmSaNote');
    if(sa){san.style.display='flex';document.getElementById('cmSaText').textContent=saText;}
    else san.style.display='none';
    document.getElementById('cmExtra').innerHTML=extra||'';
    document.getElementById('cmRemarks').value='';
    const cb=document.getElementById('cmConfirm');
    cb.className=`btn btn-sm ${btnClass}`; cb.innerHTML=btnLabel;
    confirmCb=onConfirm;
    document.getElementById('confirmModal').classList.add('on');
}

document.getElementById('cmConfirm').addEventListener('click',async()=>{
    if(confirmCb){
        const result=await confirmCb();
        if(result===false) return;
    }
    document.getElementById('confirmModal').classList.remove('on');
    confirmCb=null;
});
document.getElementById('cmCancel').addEventListener('click',()=>{document.getElementById('confirmModal').classList.remove('on');confirmCb=null;});
document.getElementById('confirmModal').addEventListener('click',function(e){if(e.target===this){this.classList.remove('on');confirmCb=null;}});

// ── EXPORT — Admin+ ───────────────────────────────────────────────────────────
<?php if ($CAN_EXPORT): ?>
function doExport(){
    const list=getFiltered();
    const cols = ROLE.rank >= 2
        ? ['assignmentId','task','assignedTo','zone','priority','dateCreated','dueDate','status','notes']
        : ['assignmentId','task','priority','dueDate','status'];
    const hdrs = ROLE.rank >= 2
        ? ['Assignment ID','Task','Assigned To','Zone','Priority','Date Created','Due Date','Status','Notes']
        : ['Assignment ID','Task','Priority','Due Date','Status'];
    const rows=[hdrs.join(','),...list.map(a=>cols.map(c=>`"${String(a[c]||'').replace(/"/g,'""')}"`).join(','))];
    const el=document.createElement('a');
    el.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
    el.download='logistics_assignments.csv'; el.click();
    toast('Assignments exported.','s');
}
<?php else: ?>
function doExport(){ toast('Insufficient permissions','w'); }
<?php endif; ?>

function shk(id){
    const el=document.getElementById(id); if(!el) return;
    el.style.borderColor='var(--red)'; el.style.animation='none';
    el.offsetHeight; el.style.animation='SHK .3s ease';
    setTimeout(()=>{el.style.borderColor='';el.style.animation='';},600);
}

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