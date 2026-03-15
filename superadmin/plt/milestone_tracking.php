<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE RESOLUTION (mirrors superadmin_sidebar.php pattern) ─────────────────
function mt_resolve_role(): string {
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

$roleName = mt_resolve_role();
$roleRank = match($roleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,   // Staff / User
};

// ── ROLE CAPABILITY FLAGS ─────────────────────────────────────────────────────
$cap = [
    // Views
    'canViewGantt'          => $roleRank >= 3,       // Super Admin + Admin only
    'canViewAllZones'        => $roleRank >= 4,       // Super Admin only
    'canViewCrossDeps'       => $roleRank >= 4,       // Super Admin only
    'canViewOtherUsers'      => $roleRank >= 2,       // Manager + above

    // Columns
    'colProject'             => $roleRank >= 3,       // Admin + above (Admin sees zone projects)
    'colZone'                => $roleRank >= 3,       // Admin + above
    'colDependencies'        => $roleRank >= 4,       // Super Admin only
    'colCompletionDate'      => $roleRank >= 3,       // Admin + above
    'colAssignedTasks'       => $roleRank === 1,      // Staff only

    // Statuses visible
    'statusOverdue'          => $roleRank >= 2,       // Manager + above
    'statusSkipped'          => $roleRank >= 3,       // Admin + above
    'statusForceCompleted'   => $roleRank >= 4,       // Super Admin only

    // Actions
    'canAdd'                 => $roleRank >= 3,       // Admin + above
    'canEdit'                => $roleRank >= 3,       // Admin + above
    'canComplete'            => $roleRank >= 3,       // Admin + above (Staff submits evidence instead)
    'canFlag'                => $roleRank >= 2,       // Manager + above
    'canOverrideDeps'        => $roleRank >= 4,       // Super Admin only
    'canSkip'                => $roleRank >= 4,       // Super Admin only
    'canForceComplete'       => $roleRank >= 4,       // Super Admin only
    'canUpdateProgress'      => $roleRank >= 2,       // Manager + above
    'canEscalate'            => $roleRank === 2,      // Manager only
    'canSubmitEvidence'      => $roleRank === 1,      // Staff only
    'canExport'              => $roleRank >= 3,       // Admin + above
    'canViewStats'           => $roleRank >= 2,       // Manager + above
];

// ── HELPERS ──────────────────────────────────────────────────────────────────
function ms_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function ms_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function ms_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function ms_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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
    if (!$res && $code >= 400) ms_err('Supabase request failed', 500);
    $data = json_decode($res, true);
    if ($code >= 400) ms_err(is_array($data) ? ($data['message'] ?? $res) : $res, 400);
    return is_array($data) ? $data : [];
}

function ms_next_id(): string {
    $year = date('Y');
    $rows = ms_sb('plt_milestones_ext', 'GET', [
        'select'       => 'milestone_id',
        'milestone_id' => 'like.MS-' . $year . '-%',
        'order'        => 'id.desc',
        'limit'        => '1',
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/MS-\d{4}-(\d+)/', $rows[0]['milestone_id'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return 'MS-' . $year . '-' . sprintf('%04d', $next);
}

function ms_build(array $row): array {
    return [
        'id'             => (int)$row['id'],
        'milestoneId'    => $row['milestone_id']      ?? '',
        'name'           => $row['name']               ?? '',
        'project'        => $row['project']            ?? '',
        'zone'           => $row['zone']               ?? '',
        'targetDate'     => $row['target_date']        ?? '',
        'completionDate' => $row['completion_date']    ?? null,
        'progress'       => (int)($row['progress']     ?? 0),
        'status'         => $row['status']             ?? 'Pending',
        'notes'          => $row['notes']              ?? '',
        'assignedTo'     => $row['assigned_to']        ?? null,
        'deps'           => (function($v){
                                if (is_array($v))  return array_values($v);
                                if (!is_string($v) || $v === '' || $v === 'null') return [];
                                $d = json_decode($v, true);
                                if (is_string($d)) $d = json_decode($d, true);
                                return is_array($d) ? array_values($d) : [];
                            })($row['deps'] ?? null),
    ];
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    global $roleRank, $cap;
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'User');
    $userId = $_SESSION['user_id'] ?? null;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET zones ────────────────────────────────────────────────────────
        if ($api === 'zones' && $method === 'GET') {
            $rows = ms_sb('sws_zones', 'GET', ['select' => 'id,name,color', 'order' => 'id.asc']);
            if (empty($rows)) {
                $rows = [
                    ['id' => 'ZN-A01', 'name' => 'Zone A — Raw Materials',       'color' => '#2E7D32'],
                    ['id' => 'ZN-B02', 'name' => 'Zone B — Safety & PPE',        'color' => '#0D9488'],
                    ['id' => 'ZN-C03', 'name' => 'Zone C — Fuels & Lubricants',  'color' => '#DC2626'],
                    ['id' => 'ZN-D04', 'name' => 'Zone D — Office Supplies',     'color' => '#2563EB'],
                    ['id' => 'ZN-E05', 'name' => 'Zone E — Electrical & IT',     'color' => '#7C3AED'],
                    ['id' => 'ZN-F06', 'name' => 'Zone F — Tools & Equipment',   'color' => '#D97706'],
                    ['id' => 'ZN-G07', 'name' => 'Zone G — Finished Goods',      'color' => '#059669'],
                ];
            }
            ms_ok($rows);
        }

        // ── GET projects ─────────────────────────────────────────────────────
        if ($api === 'projects' && $method === 'GET') {
            $query = ['select' => 'project_id,name,status', 'status' => 'neq.Terminated', 'order' => 'name.asc'];
            // Admin: filter to their zone's projects
            if ($roleRank === 3 && !empty($_SESSION['zone'])) {
                $query['zone'] = 'eq.' . $_SESSION['zone'];
            }
            $rows = ms_sb('plt_projects', 'GET', $query);
            if (empty($rows)) {
                $mrows = ms_sb('plt_milestones_ext', 'GET', ['select' => 'project', 'order' => 'project.asc']);
                $names = array_values(array_unique(array_filter(array_column($mrows, 'project'))));
                $rows  = array_map(fn($n) => ['project_id' => '', 'name' => $n, 'status' => 'Active'], $names);
            }
            ms_ok(array_map(fn($r) => [
                'id'     => $r['project_id'] ?? '',
                'name'   => $r['name']        ?? '',
                'status' => $r['status']       ?? 'Active',
            ], $rows));
        }

        // ── GET milestones list ───────────────────────────────────────────────
        if ($api === 'list' && $method === 'GET') {
            $query = [
                'select' => 'id,milestone_id,name,project,zone,target_date,completion_date,progress,status,notes,deps,assigned_to',
                'order'  => 'target_date.asc,id.asc',
            ];

            // RBAC: scope what rows each role sees
            if ($roleRank === 1) {
                // Staff: only milestones assigned to them
                if ($userId) $query['assigned_to'] = 'eq.' . $userId;
            } elseif ($roleRank === 2) {
                // Manager: all milestones (site-wide view filtered client-side)
                // Could scope to $query['zone'] = 'eq.'.$_SESSION['zone'] if needed
            } elseif ($roleRank === 3) {
                // Admin: milestones within their zone only
                if (!empty($_SESSION['zone'])) $query['zone'] = 'eq.' . $_SESSION['zone'];
            }
            // Super Admin: no filter

            $rows = ms_sb('plt_milestones_ext', 'GET', $query);

            // RBAC: strip cross-zone dep info for non-super-admin
            if ($roleRank < 4) {
                $rows = array_map(function($r) {
                    $r['deps'] = '[]'; // hide cross-zone deps
                    return $r;
                }, $rows);
            }

            ms_ok(array_map('ms_build', $rows));
        }

        // ── GET single milestone ──────────────────────────────────────────────
        if ($api === 'get' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) ms_err('Missing id', 400);
            $rows = ms_sb('plt_milestones_ext', 'GET', [
                'select' => 'id,milestone_id,name,project,zone,target_date,completion_date,progress,status,notes,deps,assigned_to',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) ms_err('Milestone not found', 404);
            $ms = ms_build($rows[0]);
            // Staff: block viewing milestones not assigned to them
            if ($roleRank === 1 && $ms['assignedTo'] !== $userId) ms_err('Access denied', 403);
            if ($roleRank < 4) $ms['deps'] = [];
            ms_ok($ms);
        }

        // ── GET audit log ─────────────────────────────────────────────────────
        if ($api === 'audit' && $method === 'GET') {
            // Only Super Admin sees full audit trail
            if ($roleRank < 4) ms_err('Insufficient permissions', 403);
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) ms_err('Missing id', 400);
            $rows = ms_sb('plt_milestone_audit_log', 'GET', [
                'select'       => 'id,action_label,actor_name,actor_role,note,icon,css_class,is_super_admin,ip_address,occurred_at',
                'milestone_id' => 'eq.' . $id,
                'order'        => 'occurred_at.asc',
            ]);
            ms_ok($rows);
        }

        // ── POST save (create / edit) ─────────────────────────────────────────
        if ($api === 'save' && $method === 'POST') {
            if (!$cap['canAdd'] && !$cap['canEdit']) ms_err('Insufficient permissions', 403);
            $b              = ms_body();
            $editId         = (int)($b['id'] ?? 0);
            if ($editId && !$cap['canEdit']) ms_err('Insufficient permissions', 403);
            if (!$editId && !$cap['canAdd']) ms_err('Insufficient permissions', 403);

            $name           = trim($b['name']           ?? '');
            $project        = trim($b['project']        ?? '');
            $zone           = trim($b['zone']           ?? '');
            $targetDate     = trim($b['targetDate']     ?? '');
            $completionDate = trim($b['completionDate'] ?? '') ?: null;
            $progress       = max(0, min(100, (int)($b['progress'] ?? 0)));
            $status         = trim($b['status']         ?? 'Pending');
            $notes          = trim($b['notes']          ?? '');
            $raw_deps       = $b['deps'] ?? [];
            if (is_string($raw_deps)) $raw_deps = json_decode($raw_deps, true) ?? [];
            // Only Super Admin can save cross-zone deps
            $deps = ($roleRank >= 4 && is_array($raw_deps))
                ? array_values(array_filter($raw_deps, 'is_string'))
                : [];

            if (!$name)       ms_err('Milestone name is required', 400);
            if (!$project)    ms_err('Project is required', 400);
            if (!$zone)       ms_err('Zone is required', 400);
            if (!$targetDate) ms_err('Target date is required', 400);

            // Allowed statuses per role
            $allowedStatus = ['Pending', 'In Progress', 'Completed'];
            if ($roleRank >= 3) $allowedStatus[] = 'Skipped';
            if ($roleRank >= 4) $allowedStatus[] = 'Overdue';
            if ($roleRank >= 4) $allowedStatus[] = 'Force Completed';
            if (!in_array($status, $allowedStatus, true)) $status = 'Pending';

            if (in_array($status, ['Completed', 'Force Completed']) && !$completionDate)
                $completionDate = date('Y-m-d');
            if (in_array($status, ['Completed', 'Force Completed']))
                $progress = 100;

            $now = date('Y-m-d H:i:s');
            $payload = [
                'name'            => $name,
                'project'         => $project,
                'zone'            => $zone,
                'target_date'     => $targetDate,
                'completion_date' => $completionDate,
                'progress'        => $progress,
                'status'          => $status,
                'notes'           => $notes,
                'deps'            => json_encode(array_values($deps)),
                'updated_at'      => $now,
            ];

            if ($editId) {
                $existing = ms_sb('plt_milestones_ext', 'GET', ['select' => 'id,milestone_id,status', 'id' => 'eq.' . $editId, 'limit' => '1']);
                if (empty($existing)) ms_err('Milestone not found', 404);
                ms_sb('plt_milestones_ext', 'PATCH', ['id' => 'eq.' . $editId], $payload);
                ms_sb('plt_milestone_audit_log', 'POST', [], [[
                    'milestone_id'  => $editId,
                    'action_label'  => 'Milestone Edited',
                    'actor_name'    => $actor,
                    'actor_role'    => $roleName,
                    'note'          => 'Fields updated.',
                    'icon'          => 'bx-edit',
                    'css_class'     => 'ad-s',
                    'is_super_admin'=> $roleRank >= 4,
                    'ip_address'    => $ip,
                    'occurred_at'   => $now,
                ]]);
                $rows = ms_sb('plt_milestones_ext', 'GET', ['select' => 'id,milestone_id,name,project,zone,target_date,completion_date,progress,status,notes,deps,assigned_to', 'id' => 'eq.' . $editId, 'limit' => '1']);
                $built = ms_build($rows[0]);
                if ($roleRank < 4) $built['deps'] = [];
                ms_ok($built);
            }

            $milestoneId = ms_next_id();
            $payload['milestone_id']    = $milestoneId;
            $payload['created_by']      = $actor;
            $payload['created_user_id'] = $userId;
            $payload['created_at']      = $now;

            $inserted = ms_sb('plt_milestones_ext', 'POST', [], [$payload]);
            if (empty($inserted)) ms_err('Failed to create milestone', 500);
            $newId = (int)$inserted[0]['id'];

            ms_sb('plt_milestone_audit_log', 'POST', [], [[
                'milestone_id'  => $newId,
                'action_label'  => 'Milestone Created',
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'note'          => 'New milestone added.',
                'icon'          => 'bx-plus-circle',
                'css_class'     => 'ad-c',
                'is_super_admin'=> $roleRank >= 4,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            $rows = ms_sb('plt_milestones_ext', 'GET', ['select' => 'id,milestone_id,name,project,zone,target_date,completion_date,progress,status,notes,deps,assigned_to', 'id' => 'eq.' . $newId, 'limit' => '1']);
            $built = ms_build($rows[0]);
            if ($roleRank < 4) $built['deps'] = [];
            ms_ok($built);
        }

        // ── POST progress update (Manager/Staff) ──────────────────────────────
        if ($api === 'progress' && $method === 'POST') {
            if ($roleRank < 2) ms_err('Insufficient permissions', 403);
            $b        = ms_body();
            $id       = (int)($b['id']       ?? 0);
            $progress = max(0, min(100, (int)($b['progress'] ?? 0)));
            $notes    = trim($b['notes'] ?? '');
            if (!$id) ms_err('Missing id', 400);

            $rows = ms_sb('plt_milestones_ext', 'GET', ['select' => 'id,milestone_id,status,assigned_to', 'id' => 'eq.' . $id, 'limit' => '1']);
            if (empty($rows)) ms_err('Milestone not found', 404);
            $ms = $rows[0];

            // Staff can only update their own milestones
            if ($roleRank === 1 && $ms['assigned_to'] !== $userId) ms_err('Access denied', 403);

            $now   = date('Y-m-d H:i:s');
            $patch = ['progress' => $progress, 'updated_at' => $now];
            if ($notes) $patch['notes'] = $notes;
            // Auto-set In Progress if was Pending and progress > 0
            if ($ms['status'] === 'Pending' && $progress > 0) $patch['status'] = 'In Progress';

            ms_sb('plt_milestones_ext', 'PATCH', ['id' => 'eq.' . $id], $patch);
            ms_sb('plt_milestone_audit_log', 'POST', [], [[
                'milestone_id'  => $id,
                'action_label'  => 'Progress Updated to ' . $progress . '%',
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'note'          => $notes ?: 'Progress updated.',
                'icon'          => 'bx-trending-up',
                'css_class'     => 'ad-o',
                'is_super_admin'=> false,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            $rows  = ms_sb('plt_milestones_ext', 'GET', ['select' => 'id,milestone_id,name,project,zone,target_date,completion_date,progress,status,notes,deps,assigned_to', 'id' => 'eq.' . $id, 'limit' => '1']);
            $built = ms_build($rows[0]);
            if ($roleRank < 4) $built['deps'] = [];
            ms_ok($built);
        }

        // ── POST evidence submission (Staff) ──────────────────────────────────
        if ($api === 'evidence' && $method === 'POST') {
            if ($roleRank !== 1) ms_err('Insufficient permissions', 403);
            $b     = ms_body();
            $id    = (int)($b['id']    ?? 0);
            $note  = trim($b['note']   ?? '');
            $link  = trim($b['link']   ?? '');
            if (!$id)   ms_err('Missing id', 400);
            if (!$note && !$link) ms_err('Evidence note or link required', 400);

            $rows = ms_sb('plt_milestones_ext', 'GET', ['select' => 'id,assigned_to', 'id' => 'eq.' . $id, 'limit' => '1']);
            if (empty($rows)) ms_err('Milestone not found', 404);
            if ($rows[0]['assigned_to'] !== $userId) ms_err('Access denied', 403);

            $now = date('Y-m-d H:i:s');
            ms_sb('plt_milestone_audit_log', 'POST', [], [[
                'milestone_id'  => $id,
                'action_label'  => 'Completion Evidence Submitted',
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'note'          => ($note ?: '') . ($link ? ' Link: ' . $link : ''),
                'icon'          => 'bx-upload',
                'css_class'     => 'ad-c',
                'is_super_admin'=> false,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);
            ms_sb('plt_milestones_ext', 'PATCH', ['id' => 'eq.' . $id], ['updated_at' => $now, 'notes' => $note]);
            ms_ok(['submitted' => true]);
        }

        // ── POST escalate (Manager) ───────────────────────────────────────────
        if ($api === 'escalate' && $method === 'POST') {
            if ($roleRank !== 2) ms_err('Insufficient permissions', 403);
            $b    = ms_body();
            $id   = (int)($b['id']   ?? 0);
            $note = trim($b['note']  ?? '');
            if (!$id)   ms_err('Missing id', 400);
            if (!$note) ms_err('Escalation reason required', 400);

            $now = date('Y-m-d H:i:s');
            ms_sb('plt_milestone_audit_log', 'POST', [], [[
                'milestone_id'  => $id,
                'action_label'  => 'Blocker Escalated by Manager',
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'note'          => $note,
                'icon'          => 'bx-error-circle',
                'css_class'     => 'ad-r',
                'is_super_admin'=> false,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);
            ms_ok(['escalated' => true]);
        }

        // ── POST action (complete / flag / override / skip / force-complete) ──
        if ($api === 'action' && $method === 'POST') {
            $b    = ms_body();
            $id   = (int)($b['id']   ?? 0);
            $type = trim($b['type']  ?? '');
            $now  = date('Y-m-d H:i:s');

            if (!$id)   ms_err('Missing id', 400);
            if (!$type) ms_err('Missing type', 400);

            // Gate by role
            $roleGate = [
                'complete'       => $cap['canComplete'],
                'flag'           => $cap['canFlag'],
                'override'       => $cap['canOverrideDeps'],
                'skip'           => $cap['canSkip'],
                'force-complete' => $cap['canForceComplete'],
            ];
            if (!($roleGate[$type] ?? false)) ms_err('Insufficient permissions', 403);

            $rows = ms_sb('plt_milestones_ext', 'GET', ['select' => 'id,milestone_id,status,progress,deps', 'id' => 'eq.' . $id, 'limit' => '1']);
            if (empty($rows)) ms_err('Milestone not found', 404);
            $ms = $rows[0];

            $patch       = ['updated_at' => $now];
            $auditLabel  = '';
            $auditNote   = trim($b['remarks'] ?? '');
            $auditIcon   = 'bx-info-circle';
            $auditClass  = 'ad-s';
            $isSA        = $roleRank >= 4;

            switch ($type) {
                case 'complete':
                    if (in_array($ms['status'], ['Completed', 'Force Completed', 'Skipped'], true))
                        ms_err('Already completed or skipped.', 400);
                    $patch['status']          = 'Completed';
                    $patch['completion_date'] = date('Y-m-d');
                    $patch['progress']        = 100;
                    $auditLabel = 'Marked as Completed';
                    $auditIcon  = 'bx-check-circle';
                    $auditClass = 'ad-a';
                    break;
                case 'flag':
                    if (in_array($ms['status'], ['Completed', 'Force Completed', 'Skipped'], true))
                        ms_err('Cannot flag completed or skipped milestone.', 400);
                    $patch['status'] = 'Overdue';
                    $auditLabel = 'Flagged as Delayed / Overdue';
                    $auditIcon  = 'bx-flag';
                    $auditClass = 'ad-r';
                    break;
                case 'override':
                    $patch['deps'] = '[]';
                    $auditLabel = 'Dependencies Overridden by Super Admin';
                    $auditIcon  = 'bx-git-branch';
                    $auditClass = 'ad-p';
                    break;
                case 'skip':
                    if (in_array($ms['status'], ['Completed', 'Force Completed'], true))
                        ms_err('Cannot skip completed milestone.', 400);
                    $patch['status'] = 'Skipped';
                    $auditLabel = 'Skipped by Super Admin';
                    $auditIcon  = 'bx-skip-next';
                    $auditClass = 'ad-x';
                    break;
                case 'force-complete':
                    if (in_array($ms['status'], ['Completed', 'Force Completed'], true))
                        ms_err('Already completed.', 400);
                    $patch['status']          = 'Force Completed';
                    $patch['completion_date'] = date('Y-m-d');
                    $patch['progress']        = 100;
                    $auditLabel = 'Force Completed by Super Admin';
                    $auditIcon  = 'bx-check-shield';
                    $auditClass = 'ad-t';
                    break;
                default:
                    ms_err('Unsupported action', 400);
            }

            ms_sb('plt_milestones_ext', 'PATCH', ['id' => 'eq.' . $id], $patch);
            ms_sb('plt_milestone_audit_log', 'POST', [], [[
                'milestone_id'  => $id,
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

            $rows  = ms_sb('plt_milestones_ext', 'GET', ['select' => 'id,milestone_id,name,project,zone,target_date,completion_date,progress,status,notes,deps,assigned_to', 'id' => 'eq.' . $id, 'limit' => '1']);
            $built = ms_build($rows[0]);
            if ($roleRank < 4) $built['deps'] = [];
            ms_ok($built);
        }

        ms_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        ms_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── HTML PAGE RENDER ──────────────────────────────────────────────────────────
include $_SERVER['DOCUMENT_ROOT'] . '/includes/superadmin_sidebar.php';
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

// Pass role caps as JSON for JS
$capJson     = json_encode($cap);
$roleNameEsc = htmlspecialchars($roleName, ENT_QUOTES);
$roleRankJs  = (int)$roleRank;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Milestone Tracking — LOG1</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
:root{--primary-color:#2E7D32;--primary-dark:#1B5E20;--text-primary:#1A2E1C;--text-secondary:#5D6F62;--bg-color:#F4F7F4;--hover-bg-light:#EDF7ED;--transition:all .18s ease;}
#mainContent,#msSlider,#slOverlay,#actionModal,#viewModal,#progressModal,#evidenceModal,#escalateModal,.ms-toasts{--s:#fff;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);--t1:var(--text-primary);--t2:var(--text-secondary);--t3:#9EB0A2;--hbg:var(--hover-bg-light);--bg:var(--bg-color);--grn:var(--primary-color);--gdk:var(--primary-dark);--red:#DC2626;--amb:#D97706;--blu:#2563EB;--tel:#0D9488;--shmd:0 4px 20px rgba(46,125,50,.12);--shlg:0 24px 60px rgba(0,0,0,.22);--rad:12px;--tr:var(--transition);}
#mainContent *,#msSlider *,#slOverlay *,#actionModal *,#viewModal *,#progressModal *,#evidenceModal *,#escalateModal *,.ms-toasts *{box-sizing:border-box;}

/* ── ROLE BADGE ─── */
.role-pill{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;letter-spacing:.08em;padding:4px 12px;border-radius:20px;text-transform:uppercase;}
.rp-sa{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;}
.rp-ad{background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;}
.rp-mg{background:#F0FDF4;color:#166534;border:1px solid #BBF7D0;}
.rp-st{background:#F3F4F6;color:#374151;border:1px solid #D1D5DB;}

/* ── RESTRICTED BANNER ── */
.rb-banner{display:flex;align-items:center;gap:10px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:12px;padding:10px 16px;font-size:12.5px;color:#92400E;margin-bottom:18px;animation:UP .4s both;}
.rb-banner i{font-size:18px;flex-shrink:0;}

.ms-wrap{max-width:1440px;margin:0 auto;padding:0 0 4rem;}
.ms-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:26px;animation:UP .4s both;}
.ms-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px;}
.ms-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15;}
.ms-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.view-toggle{display:flex;background:var(--s);border:1px solid var(--bdm);border-radius:10px;padding:3px;gap:2px;}
.vt-btn{font-family:'Inter',sans-serif;font-size:12px;font-weight:600;padding:7px 14px;border-radius:8px;border:none;cursor:pointer;transition:var(--tr);display:flex;align-items:center;gap:6px;color:var(--t2);background:transparent;white-space:nowrap;}
.vt-btn i{font-size:15px;}.vt-btn.active{background:var(--grn);color:#fff;box-shadow:0 2px 6px rgba(46,125,50,.28);}.vt-btn:not(.active):hover{background:var(--hbg);color:var(--t1);}
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.32);}.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}.btn-ghost:hover{background:var(--hbg);color:var(--t1);}
.btn-complete{background:#DCFCE7;color:#166534;border:1px solid #BBF7D0;}.btn-complete:hover{background:#BBF7D0;}
.btn-flag{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;}.btn-flag:hover{background:#FDE68A;}
.btn-override{background:#F5F3FF;color:#6D28D9;border:1px solid #DDD6FE;}.btn-override:hover{background:#EDE9FE;}
.btn-skip{background:#F3F4F6;color:#374151;border:1px solid #D1D5DB;}.btn-skip:hover{background:#E5E7EB;}
.btn-fc{background:#CCFBF1;color:#0D9488;border:1px solid #99F6E4;}.btn-fc:hover{background:#99F6E4;}
.btn-prog{background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;}.btn-prog:hover{background:#DBEAFE;}
.btn-esc{background:#FEF2F2;color:#991B1B;border:1px solid #FECACA;}.btn-esc:hover{background:#FECACA;}
.btn-evid{background:#F0FDF4;color:#166534;border:1px solid #BBF7D0;}.btn-evid:hover{background:#BBF7D0;}
.btn-sm{font-size:12px;padding:6px 13px;}.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px;}
.btn:disabled{opacity:.4;pointer-events:none;}

.ms-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:22px;animation:UP .4s .05s both;}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:14px 16px;box-shadow:0 1px 4px rgba(46,125,50,.07);display:flex;align-items:center;gap:12px;}
.sc-ic{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px;}
.ic-b{background:#EFF6FF;color:var(--blu)}.ic-a{background:#FEF3C7;color:var(--amb)}.ic-g{background:#DCFCE7;color:#166534}.ic-r{background:#FEE2E2;color:var(--red)}.ic-t{background:#CCFBF1;color:var(--tel)}.ic-p{background:#F5F3FF;color:#6D28D9}.ic-d{background:#F3F4F6;color:#374151}
.sc-v{font-size:22px;font-weight:800;color:var(--t1);line-height:1;}.sc-l{font-size:11px;color:var(--t2);margin-top:2px;}

.ms-tb{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px;animation:UP .4s .1s both;}
.sw{position:relative;flex:1;min-width:220px;}.sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--t3);pointer-events:none;}
.si{width:100%;padding:9px 11px 9px 36px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);}
.si:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}.si::placeholder{color:var(--t3);}
.sel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 28px 9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;}
.sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}

.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;}
.b-pending{background:#EFF6FF;color:#1D4ED8;}.b-inprogress{background:#FEF3C7;color:#92400E;}.b-completed{background:#DCFCE7;color:#166534;}.b-overdue{background:#FEE2E2;color:#991B1B;}.b-skipped{background:#F3F4F6;color:#6B7280;}.b-forcecompleted{background:#CCFBF1;color:#0D9488;}

.ms-card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);animation:UP .4s .13s both;}
.ms-tbl{width:auto;min-width:100%;border-collapse:collapse;font-size:12.5px;table-layout:fixed;}
.ms-tbl thead th{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:10px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;cursor:pointer;user-select:none;overflow:hidden;}
.ms-tbl thead th.no-sort{cursor:default;}.ms-tbl thead th:hover:not(.no-sort){color:var(--grn);}.ms-tbl thead th.sorted{color:var(--grn);}
.ms-tbl thead th .sic{margin-left:3px;opacity:.4;font-size:12px;vertical-align:middle;}.ms-tbl thead th.sorted .sic{opacity:1;}
.ms-tbl thead th:first-child,.ms-tbl tbody td:first-child{padding-left:16px;}
.ms-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .13s;}.ms-tbl tbody tr:last-child{border-bottom:none;}.ms-tbl tbody tr:hover{background:var(--hbg);}
.ms-tbl tbody td{padding:11px 10px;vertical-align:middle;cursor:pointer;overflow:hidden;}
.ms-tbl tbody td:last-child{overflow:visible;white-space:nowrap;cursor:default;padding:8px;text-align:center;}
.ms-id{font-family:'DM Mono',monospace;font-size:11.5px;font-weight:600;color:var(--t1);white-space:nowrap;display:block;overflow:hidden;text-overflow:ellipsis;}
.ms-date{font-size:11.5px;color:var(--t2);white-space:nowrap;}.ms-date.overdue{color:var(--red);font-weight:700;}
.proj-cell{display:flex;align-items:center;gap:6px;width:100%;min-width:0;overflow:hidden;}
.proj-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.proj-name{font-size:12.5px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0;}
.ms-name-cell{font-size:12.5px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;width:100%;}
.prog-wrap{display:flex;align-items:center;gap:6px;width:100%;overflow:hidden;}
.prog-bar{flex:1;height:6px;background:#E5E7EB;border-radius:3px;overflow:hidden;min-width:30px;max-width:80px;}
.prog-fill{height:100%;border-radius:3px;transition:width .4s;}
.prog-lbl{font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--t2);white-space:nowrap;flex-shrink:0;}
.dep-cell{display:flex;flex-wrap:wrap;align-items:center;gap:3px;width:100%;}
.dep-tag{display:inline-flex;align-items:center;font-family:'DM Mono',monospace;font-size:10px;font-weight:600;background:#F3F4F6;color:#6B7280;border:1px solid #E5E7EB;border-radius:4px;padding:2px 6px;white-space:nowrap;}
.dep-tag.blocked{background:#FEF2F2;color:#DC2626;border-color:#FECACA;}.dep-tag.met{background:#F0FDF4;color:#166534;border-color:#BBF7D0;}
.zone-dot{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:100%;}
.task-cell{font-size:12px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;}
.act-cell{display:flex;justify-content:center;position:relative;overflow:visible;}
.act-btn{width:28px;height:28px;border-radius:6px;border:1px solid var(--bdm);background:var(--s);color:var(--t2);cursor:pointer;transition:var(--tr);display:grid;place-content:center;font-size:16px;margin:auto;}
.act-btn:hover{background:var(--hbg);color:var(--grn);border-color:var(--grn);}
.act-menu{position:fixed;background:#fff;border:1px solid var(--bdm);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.15);padding:6px;min-width:192px;opacity:0;pointer-events:none;transition:opacity .15s,transform .15s;z-index:500;display:flex;flex-direction:column;gap:2px;transform:scale(.97) translateY(-4px);transform-origin:top right;}
.act-menu.open{opacity:1;pointer-events:all;transform:scale(1) translateY(0);}
.act-item{display:flex;align-items:center;gap:8px;font-family:'Inter',sans-serif;font-size:12px;font-weight:600;padding:8px 10px;border-radius:6px;border:none;background:transparent;color:var(--t1);cursor:pointer;width:100%;text-align:left;transition:var(--tr);white-space:nowrap;}
.act-item:hover{background:var(--hbg);color:var(--grn);}.act-item i{font-size:15px;}
.act-item.danger{color:#991B1B;}.act-item.danger i{color:#DC2626;}.act-item.danger:hover{background:#FEE2E2;color:#B91C1C;}
.act-item.success{color:#166534;}.act-item.success i{color:#22C55E;}.act-item.success:hover{background:#DCFCE7;color:#15803D;}
.act-item.warning{color:#92400E;}.act-item.warning i{color:#F59E0B;}.act-item.warning:hover{background:#FEF3C7;color:#B45309;}
.act-sep{height:1px;background:var(--bd);margin:3px 0;}

.ms-pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2);}
.pg-btns{display:flex;gap:5px;}
.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1);}
.pgb:hover{background:var(--hbg);border-color:var(--grn);color:var(--grn);}.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff;}.pgb:disabled{opacity:.4;pointer-events:none;}

.empty{padding:72px 20px;text-align:center;color:var(--t3);}.empty i{font-size:54px;display:block;margin-bottom:14px;color:#C8E6C9;}

/* Gantt */
#ganttView{display:none;}#ganttView.active{display:block;}#listView.active{display:block;}#listView{display:none;}
.gantt-wrap{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);animation:UP .4s .13s both;}
.gantt-hdr{display:flex;align-items:center;gap:12px;padding:14px 20px;background:var(--bg);border-bottom:1px solid var(--bd);flex-wrap:wrap;}
.gantt-hdr .gh-title{font-size:13px;font-weight:700;color:var(--t1);}
.gantt-nav{display:flex;align-items:center;gap:6px;}
.gantt-nav .gn-btn{width:30px;height:30px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:16px;color:var(--t2);transition:var(--tr);}
.gantt-nav .gn-btn:hover{background:var(--hbg);border-color:var(--grn);color:var(--grn);}
.gantt-nav .gn-period{font-family:'DM Mono',monospace;font-size:12px;font-weight:600;color:var(--t1);min-width:120px;text-align:center;}
.gantt-zoom{display:flex;background:var(--s);border:1px solid var(--bdm);border-radius:8px;overflow:hidden;}
.gz-btn{font-family:'Inter',sans-serif;font-size:11px;font-weight:600;padding:5px 10px;border:none;cursor:pointer;transition:var(--tr);color:var(--t2);background:transparent;}
.gz-btn.active{background:var(--grn);color:#fff;}.gz-btn:not(.active):hover{background:var(--hbg);}
.gantt-legend{display:flex;gap:12px;flex-wrap:wrap;margin-left:auto;}
.gl-item{display:flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:var(--t2);}
.gl-dot{width:10px;height:10px;border-radius:3px;}
.gantt-body{display:flex;overflow:hidden;}
.gantt-labels{width:280px;flex-shrink:0;border-right:1px solid var(--bd);}
.gantt-labels .gl-hdr{height:52px;border-bottom:1px solid var(--bd);background:var(--bg);display:flex;align-items:center;padding:0 14px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--t3);}
.gantt-labels .gl-row{height:46px;border-bottom:1px solid var(--bd);display:flex;align-items:center;padding:0 14px;gap:8px;transition:background .12s;cursor:pointer;}
.gantt-labels .gl-row:last-child{border-bottom:none;}.gantt-labels .gl-row:hover{background:var(--hbg);}
.gl-row-proj{width:8px;height:8px;border-radius:50%;flex-shrink:0;}.gl-row-info{min-width:0;}
.gl-row-name{font-size:12px;font-weight:700;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;}
.gl-row-sub{font-size:10px;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;font-family:'DM Mono',monospace;}
.gantt-timeline{flex:1;overflow-x:auto;position:relative;}
.gantt-timeline::-webkit-scrollbar{height:4px;}.gantt-timeline::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px;}
.gantt-dates{height:52px;display:flex;border-bottom:1px solid var(--bd);background:var(--bg);position:sticky;top:0;z-index:2;}
.gantt-date-col{border-right:1px solid rgba(46,125,50,.08);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;}
.gdc-month{font-size:9px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);line-height:1;}
.gdc-day{font-size:12px;font-weight:700;color:var(--t1);margin-top:2px;font-family:'DM Mono',monospace;}
.gdc-today{background:rgba(46,125,50,.07);}.gdc-today .gdc-day{color:var(--grn);}
.gantt-rows{position:relative;}
.gantt-row{height:46px;border-bottom:1px solid rgba(46,125,50,.07);display:flex;align-items:center;position:relative;}
.gantt-row:last-child{border-bottom:none;}
.gantt-grid-col{border-right:1px solid rgba(46,125,50,.06);height:100%;flex-shrink:0;}
.gantt-grid-today{background:rgba(46,125,50,.04);border-right:1px solid rgba(46,125,50,.2)!important;}
.gantt-bar{position:absolute;height:26px;border-radius:6px;display:flex;align-items:center;padding:0 8px;font-size:10px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;transition:opacity .15s,transform .15s;z-index:1;top:10px;}
.gantt-bar:hover{opacity:.88;transform:scaleY(1.04);}
.gb-pending{background:rgba(37,99,235,.18);color:#1D4ED8;border:1px solid rgba(37,99,235,.3);}
.gb-inprogress{background:rgba(217,119,6,.18);color:#92400E;border:1px solid rgba(217,119,6,.35);}
.gb-completed{background:rgba(22,101,52,.18);color:#166534;border:1px solid rgba(22,101,52,.3);}
.gb-overdue{background:rgba(220,38,38,.18);color:#991B1B;border:1px solid rgba(220,38,38,.3);}
.gb-skipped{background:rgba(107,114,128,.14);color:#374151;border:1px solid rgba(107,114,128,.25);}
.gb-forcecompleted{background:rgba(13,148,136,.18);color:#0D9488;border:1px solid rgba(13,148,136,.3);}
.today-line{position:absolute;top:0;bottom:0;width:2px;background:var(--grn);opacity:.5;z-index:3;pointer-events:none;}
.today-label{position:absolute;top:2px;font-size:9px;font-weight:700;color:var(--grn);background:#fff;border:1px solid rgba(46,125,50,.3);border-radius:3px;padding:1px 4px;z-index:4;transform:translateX(-50%);white-space:nowrap;}

/* View Modal */
#viewModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9050;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;}
#viewModal.on{opacity:1;pointer-events:all;}
.vm-box{background:#fff;border-radius:20px;width:780px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden;}
.vm-mhd{padding:24px 28px 0;border-bottom:1px solid rgba(46,125,50,.14);background:var(--bg-color);flex-shrink:0;}
.vm-mtp{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px;}
.vm-msi{display:flex;align-items:center;gap:16px;}
.vm-mav{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px;color:#fff;flex-shrink:0;}
.vm-mnm{font-size:18px;font-weight:800;color:var(--text-primary);display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
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
.vm-sb .sbv{font-size:18px;font-weight:800;color:var(--text-primary);line-height:1;}.vm-sb .sbl{font-size:11px;color:var(--text-secondary);margin-top:3px;}
.vm-ig{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.vm-ii label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9EB0A2;display:block;margin-bottom:4px;}
.vm-ii .v{font-size:13px;font-weight:500;color:var(--text-primary);line-height:1.5;}.vm-ii .v.muted{font-weight:400;color:#4B5563;}.vm-full{grid-column:1/-1;}
.vm-rmk{border-radius:10px;padding:12px 16px;font-size:12.5px;line-height:1.65;}.vm-rmk .rml{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px;opacity:.7;}
.vm-rmk-a{background:#F0FDF4;color:#166534;}.vm-rmk-r{background:#FEF2F2;color:#991B1B;}.vm-rmk-n{background:#FFFBEB;color:#92400E;}.vm-rmk-p{background:#F5F3FF;color:#5B21B6;}.vm-rmk-t{background:#CCFBF1;color:#0D9488;}
.vm-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400E;}
.vm-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px;}
.vm-audit-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid rgba(46,125,50,.14);}
.vm-audit-item:last-child{border-bottom:none;padding-bottom:0;}
.vm-audit-dot{width:28px;height:28px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;}
.ad-c{background:#DCFCE7;color:#166534}.ad-s{background:#EFF6FF;color:#2563EB}.ad-a{background:#DCFCE7;color:#166534}.ad-r{background:#FEE2E2;color:#DC2626}.ad-o{background:#FEF3C7;color:#D97706}.ad-p{background:#F5F3FF;color:#7C3AED}.ad-t{background:#CCFBF1;color:#0D9488}.ad-x{background:#F3F4F6;color:#374151}
.vm-audit-body{flex:1;min-width:0;}.vm-audit-body .au{font-size:13px;font-weight:500;color:var(--text-primary);}
.vm-audit-body .at{font-size:11px;color:#9EB0A2;margin-top:3px;font-family:'DM Mono',monospace;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.vm-audit-note{font-size:11.5px;color:#6B7280;margin-top:3px;font-style:italic;}
.vm-audit-ip{font-family:'DM Mono',monospace;font-size:10px;color:#9CA3AF;background:#F3F4F6;border-radius:4px;padding:1px 6px;}
.vm-audit-ts{font-family:'DM Mono',monospace;font-size:10px;color:#9EB0A2;flex-shrink:0;margin-left:auto;padding-left:8px;white-space:nowrap;}
.sa-tag{font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 5px;border:1px solid #FCD34D;}
.vm-mft{padding:16px 28px;border-top:1px solid rgba(46,125,50,.14);background:var(--bg-color);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap;}
.dep-list{display:flex;flex-direction:column;gap:10px;}
.dep-item{display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg-color);border:1px solid rgba(46,125,50,.14);border-radius:10px;}
.dep-item-id{font-family:'DM Mono',monospace;font-size:12px;font-weight:700;color:var(--primary-color);flex-shrink:0;}
.dep-item-name{font-size:13px;font-weight:600;color:var(--text-primary);flex:1;}
.dep-item-stat{flex-shrink:0;}.dep-arrow-ic{font-size:16px;color:var(--t3);flex-shrink:0;}

/* Slider */
#slOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;opacity:0;pointer-events:none;transition:opacity .25s;}
#slOverlay.on{opacity:1;pointer-events:all;}
#msSlider{position:fixed;top:0;right:-600px;bottom:0;width:560px;max-width:100vw;background:var(--s);z-index:9001;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18);}
#msSlider.on{right:0;}
.sl-hdr{display:flex;align-items:flex-start;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--bd);background:var(--bg);flex-shrink:0;}
.sl-title{font-size:17px;font-weight:700;color:var(--t1);}.sl-subtitle{font-size:12px;color:var(--t2);margin-top:2px;}
.sl-close{width:36px;height:36px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--t2);transition:var(--tr);flex-shrink:0;}
.sl-close:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA;}
.sl-body{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:18px;}
.sl-body::-webkit-scrollbar{width:4px;}.sl-body::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px;}
.sl-foot{padding:16px 24px;border-top:1px solid var(--bd);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;}
.fg{display:flex;flex-direction:column;gap:6px;}.fr{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fl{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2);}.fl span{color:var(--red);margin-left:2px;}
.fi,.fs,.fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);width:100%;}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;padding-right:30px;}
.fta{resize:vertical;min-height:70px;}
.fd{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px;}
.fd::after{content:'';flex:1;height:1px;background:var(--bd);}
.prog-slider-wrap{display:flex;align-items:center;gap:12px;}.prog-slider-wrap input[type=range]{flex:1;accent-color:var(--grn);}.prog-slider-val{font-family:'DM Mono',monospace;font-size:13px;font-weight:700;color:var(--grn);min-width:36px;text-align:right;}
.cs-wrap{position:relative;width:100%;}
.cs-input{width:100%;padding:10px 12px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);}
.cs-input:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.cs-input::placeholder{color:var(--t3);}
.cs-drop{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--s);border:1px solid var(--bdm);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.13);z-index:9999;max-height:240px;overflow-y:auto;}
.cs-drop.open{display:block;}
.cs-drop::-webkit-scrollbar{width:4px;}.cs-drop::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px;}
.cs-opt{padding:9px 12px;font-size:13px;cursor:pointer;display:flex;flex-direction:column;gap:2px;transition:background .12s;}
.cs-opt:hover,.cs-opt.hl{background:var(--hbg);}
.cs-opt .cs-name{font-size:13px;color:var(--t1);font-weight:500;}
.cs-opt .cs-sub{font-size:10.5px;color:var(--t3);}
.cs-opt.cs-none{color:var(--t3);cursor:default;font-size:12px;padding:12px;}.cs-opt.cs-none:hover{background:none;}
.dep-select-wrap{display:flex;flex-direction:column;gap:6px;}
.dep-sel-item{display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg);border:1px solid var(--bd);border-radius:8px;margin-top:4px;}
.dep-sel-item .ds-id{font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--grn);flex-shrink:0;}
.dep-sel-item .ds-nm{font-size:12px;color:var(--t1);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.dep-rm{width:22px;height:22px;border-radius:5px;border:1px solid #FECACA;background:#FEE2E2;cursor:pointer;display:grid;place-content:center;font-size:13px;color:var(--red);transition:var(--tr);}
.dep-rm:hover{background:#FCA5A5;}

/* Generic modal */
.gm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9100;display:grid;place-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s;}
.gm-overlay.on{opacity:1;pointer-events:all;}
.gm-box{background:var(--s);border-radius:16px;padding:28px 28px 24px;width:440px;max-width:92vw;box-shadow:var(--shlg);}
.gm-icon{font-size:46px;margin-bottom:10px;line-height:1;}.gm-title{font-size:18px;font-weight:700;color:var(--t1);margin-bottom:6px;}
.gm-body{font-size:13px;color:var(--t2);line-height:1.6;margin-bottom:16px;}
.gm-fg{display:flex;flex-direction:column;gap:5px;margin-bottom:18px;}
.gm-fg label{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2);}
.gm-fg textarea,.gm-fg input,.gm-fg select{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;width:100%;transition:var(--tr);}
.gm-fg textarea{resize:vertical;min-height:72px;}
.gm-fg textarea:focus,.gm-fg input:focus,.gm-fg select:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.gm-acts{display:flex;gap:10px;justify-content:flex-end;}
.gm-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#92400E;}
.gm-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px;}
.gm-info-note{display:flex;align-items:flex-start;gap:8px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#1D4ED8;}
.gm-info-note i{font-size:15px;flex-shrink:0;margin-top:1px;}

.ms-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:TIN .3s ease;}
.toast.ts{background:var(--grn);}.toast.tw{background:var(--amb);}.toast.td{background:var(--red);}.toast.out{animation:TOUT .3s ease forwards;}

@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}
@keyframes SHK{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-5px)}40%,80%{transform:translateX(5px)}}
@media(max-width:900px){#msSlider{width:100vw;}.fr{grid-template-columns:1fr;}.ms-stats{grid-template-columns:repeat(2,1fr);}.vm-sbs{grid-template-columns:repeat(2,1fr);}.vm-ig{grid-template-columns:1fr;}.gantt-labels{width:200px;}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="ms-wrap">

  <!-- PAGE HEADER -->
  <div class="ms-ph">
    <div>
      <p class="ey">LOG1 · Smart Supply Chain &amp; Procurement Management</p>
      <h1>Milestone Tracking
        <span class="role-pill <?= match($roleName){
            'Super Admin'=>'rp-sa','Admin'=>'rp-ad','Manager'=>'rp-mg',default=>'rp-st'
        } ?>" style="font-size:12px;vertical-align:middle;margin-left:10px">
          <i class="bx <?= match($roleName){
              'Super Admin'=>'bx-shield-quarter','Admin'=>'bx-cog','Manager'=>'bx-user-voice',default=>'bx-user'
          } ?>" style="font-size:13px"></i>
          <?= $roleNameEsc ?>
        </span>
      </h1>
    </div>
    <div class="ms-ph-r">
      <?php if ($cap['canViewGantt']): ?>
      <div class="view-toggle">
        <button class="vt-btn active" id="btnList"><i class="bx bx-list-ul"></i> List</button>
        <button class="vt-btn" id="btnGantt"><i class="bx bx-bar-chart-alt-2"></i> Gantt</button>
      </div>
      <?php endif; ?>
      <?php if ($cap['canExport']): ?>
      <button class="btn btn-ghost" id="exportBtn"><i class="bx bx-export"></i> Export CSV</button>
      <?php endif; ?>
      <?php if ($cap['canAdd']): ?>
      <button class="btn btn-primary" id="createBtn"><i class="bx bx-plus"></i> Add Milestone</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ROLE BANNERS -->
  <?php if ($roleRank === 2): ?>
  <div class="rb-banner">
    <i class="bx bx-info-circle"></i>
    <span>You are viewing milestones as <strong>Manager</strong>. You can update progress, flag delays, and escalate blockers. List view only — Gantt is available for Admins and above.</span>
  </div>
  <?php elseif ($roleRank === 1): ?>
  <div class="rb-banner">
    <i class="bx bx-user"></i>
    <span>Showing only milestones <strong>assigned to you</strong>. You can update your task progress and submit completion evidence.</span>
  </div>
  <?php endif; ?>

  <?php if ($cap['canViewStats']): ?>
  <div class="ms-stats" id="statsBar"></div>
  <?php endif; ?>

  <!-- TOOLBAR -->
  <div class="ms-tb">
    <div class="sw"><i class="bx bx-search"></i>
      <input type="text" class="si" id="srch" placeholder="<?= $roleRank === 1 ? 'Search your milestones…' : 'Search by ID, milestone, project, or zone…' ?>">
    </div>
    <select class="sel" id="fStatus">
      <option value="">All Statuses</option>
      <option>Pending</option>
      <option>In Progress</option>
      <option>Completed</option>
      <?php if ($cap['statusOverdue']): ?><option>Overdue</option><?php endif; ?>
      <?php if ($cap['statusSkipped']): ?><option>Skipped</option><?php endif; ?>
      <?php if ($cap['statusForceCompleted']): ?><option>Force Completed</option><?php endif; ?>
    </select>
    <?php if ($cap['colProject']): ?>
    <select class="sel" id="fProject"><option value="">All Projects</option></select>
    <?php endif; ?>
    <?php if ($cap['colZone']): ?>
    <select class="sel" id="fZone"><option value="">All Zones</option></select>
    <?php endif; ?>
  </div>

  <!-- LIST VIEW -->
  <div id="listView" class="active">
    <div class="ms-card">
      <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
      <table class="ms-tbl" id="tbl">
        <colgroup>
          <col style="width:120px">
          <?php if ($cap['colProject']): ?><col style="width:160px"><?php endif; ?>
          <col style="width:200px">
          <col style="width:105px">
          <?php if ($cap['colCompletionDate']): ?><col style="width:105px"><?php endif; ?>
          <col style="width:135px">
          <?php if ($cap['colAssignedTasks']): ?><col style="width:130px"><?php endif; ?>
          <?php if ($cap['colDependencies']): ?><col style="width:145px"><?php endif; ?>
          <?php if ($cap['colZone']): ?><col style="width:120px"><?php endif; ?>
          <col style="width:100px">
          <col style="width:55px">
        </colgroup>
        <thead><tr>
          <th data-col="milestoneId">Milestone ID <i class="bx bx-sort sic"></i></th>
          <?php if ($cap['colProject']): ?><th data-col="project">Project <i class="bx bx-sort sic"></i></th><?php endif; ?>
          <th data-col="name">Milestone Name <i class="bx bx-sort sic"></i></th>
          <th data-col="targetDate">Target Date <i class="bx bx-sort sic"></i></th>
          <?php if ($cap['colCompletionDate']): ?><th data-col="completionDate">Completion <i class="bx bx-sort sic"></i></th><?php endif; ?>
          <th data-col="progress">Progress <i class="bx bx-sort sic"></i></th>
          <?php if ($cap['colAssignedTasks']): ?><th class="no-sort">My Tasks</th><?php endif; ?>
          <?php if ($cap['colDependencies']): ?><th class="no-sort">Dependencies</th><?php endif; ?>
          <?php if ($cap['colZone']): ?><th data-col="zone">Zone <i class="bx bx-sort sic"></i></th><?php endif; ?>
          <th data-col="status">Status <i class="bx bx-sort sic"></i></th>
          <th class="no-sort">Actions</th>
        </tr></thead>
        <tbody id="tbody"></tbody>
      </table>
      </div>
      <div class="ms-pager" id="pager"></div>
    </div>
  </div>

  <!-- GANTT VIEW (Admin + Super Admin only) -->
  <?php if ($cap['canViewGantt']): ?>
  <div id="ganttView">
    <div class="gantt-wrap">
      <div class="gantt-hdr">
        <span class="gh-title">Timeline</span>
        <div class="gantt-nav">
          <button class="gn-btn" id="gnPrev"><i class="bx bx-chevron-left"></i></button>
          <span class="gn-period" id="gnPeriod"></span>
          <button class="gn-btn" id="gnNext"><i class="bx bx-chevron-right"></i></button>
          <button class="gn-btn btn-ghost btn-sm" id="gnToday" style="width:auto;padding:0 10px;font-size:11px;font-weight:700;font-family:'Inter',sans-serif;border-radius:8px;border:1px solid var(--bdm);">Today</button>
        </div>
        <div class="gantt-zoom">
          <button class="gz-btn" data-zoom="week">Week</button>
          <button class="gz-btn active" data-zoom="month">Month</button>
          <button class="gz-btn" data-zoom="quarter">Quarter</button>
        </div>
        <div class="gantt-legend">
          <div class="gl-item"><div class="gl-dot" style="background:rgba(37,99,235,.5)"></div>Pending</div>
          <div class="gl-item"><div class="gl-dot" style="background:rgba(217,119,6,.5)"></div>In Progress</div>
          <div class="gl-item"><div class="gl-dot" style="background:rgba(22,101,52,.5)"></div>Completed</div>
          <div class="gl-item"><div class="gl-dot" style="background:rgba(220,38,38,.5)"></div>Overdue</div>
          <div class="gl-item"><div class="gl-dot" style="background:rgba(13,148,136,.5)"></div>Force Completed</div>
        </div>
      </div>
      <div class="gantt-body" id="ganttBody"></div>
    </div>
  </div>
  <?php endif; ?>

</div>
</main>

<div class="ms-toasts" id="toastWrap"></div>
<div id="slOverlay"></div>

<!-- ADD / EDIT SLIDER (Admin + Super Admin) -->
<?php if ($cap['canAdd'] || $cap['canEdit']): ?>
<div id="msSlider">
  <div class="sl-hdr">
    <div><div class="sl-title" id="slTitle">Add Milestone</div><div class="sl-subtitle" id="slSub">Fill in all required fields below</div></div>
    <button class="sl-close" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-body" id="slBody">
    <div class="fg">
      <label class="fl">Milestone Name <span>*</span></label>
      <input type="text" class="fi" id="fName" placeholder="e.g. Site Mobilization Complete">
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Project <span>*</span></label>
        <div class="cs-wrap">
          <input type="text" class="cs-input" id="csProjSearch" placeholder="Search project…" autocomplete="off">
          <input type="hidden" id="fProj" value="">
          <div class="cs-drop" id="csProjDrop"></div>
        </div>
      </div>
      <div class="fg">
        <label class="fl">Zone <span>*</span></label>
        <select class="fs" id="fZoneSl"><option value="">Select zone…</option></select>
      </div>
    </div>
    <div class="fr">
      <div class="fg"><label class="fl">Target Date <span>*</span></label><input type="date" class="fi" id="fTarget"></div>
      <div class="fg"><label class="fl">Completion Date</label><input type="date" class="fi" id="fCompletion"></div>
    </div>
    <div class="fg">
      <label class="fl">Progress % (0–100)</label>
      <div class="prog-slider-wrap">
        <input type="range" min="0" max="100" value="0" id="fProgress" oninput="document.getElementById('fProgVal').textContent=this.value+'%'">
        <span class="prog-slider-val" id="fProgVal">0%</span>
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Status</label>
        <select class="fs" id="fStatusSl">
          <option value="Pending">Pending</option>
          <option value="In Progress">In Progress</option>
          <option value="Completed">Completed</option>
          <?php if ($roleRank >= 3): ?><option value="Skipped">Skipped</option><?php endif; ?>
        </select>
      </div>
      <div class="fg"><label class="fl">Notes</label><input type="text" class="fi" id="fNotes" placeholder="Optional notes…"></div>
    </div>
    <?php if ($cap['canViewCrossDeps']): ?>
    <div class="fd">Dependencies</div>
    <div class="dep-select-wrap" id="depSelWrap">
      <div class="cs-wrap" style="margin-bottom:6px">
        <input type="text" class="cs-input" id="depSearch" placeholder="Search milestone to add as dependency…" autocomplete="off">
        <div class="cs-drop" id="depDrop"></div>
      </div>
      <div id="depSelList"></div>
    </div>
    <?php endif; ?>
  </div>
  <div class="sl-foot">
    <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-send"></i> Save Milestone</button>
  </div>
</div>
<?php endif; ?>

<!-- ACTION MODAL (complete/flag/override/skip/force-complete) -->
<div class="gm-overlay" id="actionModal">
  <div class="gm-box">
    <div class="gm-icon" id="amIcon">✅</div>
    <div class="gm-title" id="amTitle">Confirm</div>
    <div class="gm-body" id="amBody"></div>
    <div class="gm-sa-note" id="amSaNote" style="display:none"><i class="bx bx-shield-quarter"></i><span id="amSaText"></span></div>
    <div id="amExtra"></div>
    <div class="gm-fg"><label>Remarks (optional)</label><textarea id="amRemarks" placeholder="Add remarks…"></textarea></div>
    <div class="gm-acts">
      <button class="btn btn-ghost btn-sm" id="amCancel">Cancel</button>
      <button class="btn btn-sm" id="amConfirm">Confirm</button>
    </div>
  </div>
</div>

<!-- PROGRESS MODAL (Manager + Staff) -->
<?php if ($cap['canUpdateProgress']): ?>
<div class="gm-overlay" id="progressModal">
  <div class="gm-box">
    <div class="gm-icon">📊</div>
    <div class="gm-title">Update Progress</div>
    <div class="gm-body" id="pmBody"></div>
    <div class="gm-fg">
      <label>Progress %</label>
      <div class="prog-slider-wrap" style="margin-top:4px">
        <input type="range" min="0" max="100" value="0" id="pmRange" oninput="document.getElementById('pmVal').textContent=this.value+'%'">
        <span class="prog-slider-val" id="pmVal">0%</span>
      </div>
    </div>
    <div class="gm-fg"><label>Notes (optional)</label><textarea id="pmNotes" placeholder="What was accomplished?"></textarea></div>
    <div class="gm-acts">
      <button class="btn btn-ghost btn-sm" id="pmCancel">Cancel</button>
      <button class="btn btn-prog btn-sm" id="pmConfirm"><i class="bx bx-trending-up"></i> Update Progress</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- EVIDENCE MODAL (Staff) -->
<?php if ($cap['canSubmitEvidence']): ?>
<div class="gm-overlay" id="evidenceModal">
  <div class="gm-box">
    <div class="gm-icon">📎</div>
    <div class="gm-title">Submit Completion Evidence</div>
    <div class="gm-body" id="emBody"></div>
    <div class="gm-info-note"><i class="bx bx-info-circle"></i>Evidence will be reviewed by your Admin/Manager before the milestone is marked complete.</div>
    <div class="gm-fg"><label>Description / Notes <span style="color:var(--red)">*</span></label><textarea id="emNote" placeholder="Describe what was completed…"></textarea></div>
    <div class="gm-fg"><label>Reference Link (optional)</label><input type="text" id="emLink" placeholder="https://…"></div>
    <div class="gm-acts">
      <button class="btn btn-ghost btn-sm" id="emCancel">Cancel</button>
      <button class="btn btn-evid btn-sm" id="emConfirm"><i class="bx bx-upload"></i> Submit Evidence</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ESCALATE MODAL (Manager) -->
<?php if ($cap['canEscalate']): ?>
<div class="gm-overlay" id="escalateModal">
  <div class="gm-box">
    <div class="gm-icon">🚨</div>
    <div class="gm-title">Escalate Blocker</div>
    <div class="gm-body" id="escBody"></div>
    <div class="gm-sa-note" style="display:flex"><i class="bx bx-error-circle"></i>Escalation is logged and visible to Admins and Super Admins.</div>
    <div class="gm-fg"><label>Reason for Escalation <span style="color:var(--red)">*</span></label><textarea id="escNote" placeholder="Describe the blocker and what you've attempted…"></textarea></div>
    <div class="gm-acts">
      <button class="btn btn-ghost btn-sm" id="escCancel">Cancel</button>
      <button class="btn btn-esc btn-sm" id="escConfirm"><i class="bx bx-error-circle"></i> Escalate</button>
    </div>
  </div>
</div>
<?php endif; ?>

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
        <?php if ($cap['canViewCrossDeps']): ?>
        <button class="vm-tab" data-t="dp"><i class="bx bx-git-branch"></i> Dependencies</button>
        <?php endif; ?>
        <?php if ($roleRank >= 4): ?>
        <button class="vm-tab" data-t="au"><i class="bx bx-shield-quarter"></i> Audit Trail</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="vm-mbd">
      <div class="vm-tp active" id="vt-ov"></div>
      <?php if ($cap['canViewCrossDeps']): ?><div class="vm-tp" id="vt-dp"></div><?php endif; ?>
      <?php if ($roleRank >= 4): ?><div class="vm-tp" id="vt-au"></div><?php endif; ?>
    </div>
    <div class="vm-mft" id="vmFoot"></div>
  </div>
</div>

<script>
// ── ROLE CAPS (from PHP) ──────────────────────────────────────────────────────
const CAP  = <?= $capJson ?>;
const RANK = <?= $roleRankJs ?>;
const ROLE = <?= json_encode($roleName) ?>;
const API  = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';

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
let MS=[], PROJECTS=[], ZONES=[];
let sortCol='targetDate', sortDir='asc', page=1;
const PAGE_SIZE=10;
let actionTarget=null, actionKey=null, actionCb=null;
let editId=null, selectedDeps=[];
let currentView='list';
let ganttZoom='month', ganttOffset=0;
let progressTarget=null, evidenceTarget=null, escalateTarget=null;

let PROJ_COLORS={};
let ZONE_COLORS={};
const PALETTE=['#2E7D32','#0D9488','#2563EB','#D97706','#7C3AED','#DC2626','#059669','#DB2777','#EA580C','#0369A1'];

// ── LOAD ──────────────────────────────────────────────────────────────────────
async function loadAll() {
    try {
        const loads = [apiGet(API+'?api=zones'), apiGet(API+'?api=list')];
        if (CAP.colProject) loads.splice(1,0,apiGet(API+'?api=projects'));
        const results = await Promise.all(loads);
        ZONES    = results[0];
        PROJECTS = CAP.colProject ? results[1] : [];
        MS       = results[CAP.colProject ? 2 : 1];

        ZONES.forEach(z => { ZONE_COLORS[z.name]=z.color; ZONE_COLORS[z.id]=z.color; });
        PROJECTS.forEach((p,i) => { PROJ_COLORS[p.name] = PROJ_COLORS[p.name] || PALETTE[i % PALETTE.length]; });
    } catch(e) { toast('Failed to load data: '+e.message,'d'); }
    renderList();
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc = s=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fD  = d=>{ if(!d||d==='—') return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); };
const pc  = p=>PROJ_COLORS[p]||'#6B7280';
const zc  = z=>ZONE_COLORS[z]||'#6B7280';
const today = ()=>new Date().toISOString().slice(0,10);

function badge(s){
    const m={'Pending':'b-pending','In Progress':'b-inprogress','Completed':'b-completed','Overdue':'b-overdue','Skipped':'b-skipped','Force Completed':'b-forcecompleted'};
    return `<span class="badge ${m[s]||''}">${esc(s)}</span>`;
}
function progColor(p,status){
    if(['Completed','Force Completed'].includes(status)) return '#22C55E';
    if(status==='Overdue') return '#EF4444';
    if(p>=70) return '#F59E0B';
    return '#2563EB';
}

// ── FILTER / SORT ─────────────────────────────────────────────────────────────
function getFiltered(){
    const q  = document.getElementById('srch').value.trim().toLowerCase();
    const st = document.getElementById('fStatus').value;
    const pj = CAP.colProject ? document.getElementById('fProject').value : '';
    const zn = CAP.colZone    ? document.getElementById('fZone').value    : '';
    // Staff: only show allowed statuses (Pending, In Progress, Completed)
    const allowedStatuses = RANK === 1 ? ['Pending','In Progress','Completed'] : null;
    return MS.filter(m=>{
        if(allowedStatuses && !allowedStatuses.includes(m.status)) return false;
        if(q&&!m.milestoneId.toLowerCase().includes(q)&&!m.name.toLowerCase().includes(q)&&
           !m.project.toLowerCase().includes(q)&&!m.zone.toLowerCase().includes(q)) return false;
        if(st&&m.status!==st) return false;
        if(pj&&m.project!==pj) return false;
        if(zn&&m.zone!==zn) return false;
        return true;
    });
}
function getSorted(list){
    return [...list].sort((a,b)=>{
        let va=a[sortCol], vb=b[sortCol];
        if(sortCol==='progress') return sortDir==='asc'?va-vb:vb-va;
        va=String(va||'').toLowerCase(); vb=String(vb||'').toLowerCase();
        return sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va);
    });
}

// ── BUILD DROPDOWNS ───────────────────────────────────────────────────────────
function buildDropdowns(){
    if(CAP.colProject){
        const liveNames=new Set(PROJECTS.map(p=>p.name));
        const allProjs=[...liveNames,...[...new Set(MS.map(m=>m.project))].filter(n=>n&&!liveNames.has(n))].sort();
        const pEl=document.getElementById('fProject');
        if(pEl){const pv=pEl.value;pEl.innerHTML='<option value="">All Projects</option>'+allProjs.map(p=>`<option ${p===pv?'selected':''}>${esc(p)}</option>`).join('');}
    }
    if(CAP.colZone){
        const liveZones=new Set(ZONES.map(z=>z.name));
        const allZones=[...liveZones,...[...new Set(MS.map(m=>m.zone))].filter(z=>z&&!liveZones.has(z))].sort();
        const zEl=document.getElementById('fZone');
        if(zEl){const zv=zEl.value;zEl.innerHTML='<option value="">All Zones</option>'+allZones.map(z=>`<option ${z===zv?'selected':''}>${esc(z)}</option>`).join('');}
    }
}

// ── RENDER STATS ──────────────────────────────────────────────────────────────
function renderStats(){
    const sb=document.getElementById('statsBar'); if(!sb||!CAP.canViewStats) return;
    const tot=MS.length, pend=MS.filter(m=>m.status==='Pending').length,
          inp=MS.filter(m=>m.status==='In Progress').length,
          done=MS.filter(m=>['Completed','Force Completed'].includes(m.status)).length,
          over=MS.filter(m=>m.status==='Overdue').length,
          skip=MS.filter(m=>m.status==='Skipped').length,
          avgP=tot?Math.round(MS.reduce((s,m)=>s+m.progress,0)/tot):0;
    let html=`<div class="sc"><div class="sc-ic ic-b"><i class="bx bx-trophy"></i></div><div><div class="sc-v">${tot}</div><div class="sc-l">Total</div></div></div>`;
    html+=`<div class="sc"><div class="sc-ic ic-b"><i class="bx bx-time-five"></i></div><div><div class="sc-v">${pend}</div><div class="sc-l">Pending</div></div></div>`;
    html+=`<div class="sc"><div class="sc-ic ic-a"><i class="bx bx-run"></i></div><div><div class="sc-v">${inp}</div><div class="sc-l">In Progress</div></div></div>`;
    html+=`<div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${done}</div><div class="sc-l">Completed</div></div></div>`;
    if(CAP.statusOverdue) html+=`<div class="sc"><div class="sc-ic ic-r"><i class="bx bx-alarm-exclamation"></i></div><div><div class="sc-v">${over}</div><div class="sc-l">Overdue</div></div></div>`;
    if(CAP.statusSkipped) html+=`<div class="sc"><div class="sc-ic ic-d"><i class="bx bx-skip-next"></i></div><div><div class="sc-v">${skip}</div><div class="sc-l">Skipped</div></div></div>`;
    html+=`<div class="sc"><div class="sc-ic ic-t"><i class="bx bx-trending-up"></i></div><div><div class="sc-v">${avgP}%</div><div class="sc-l">Avg Progress</div></div></div>`;
    sb.innerHTML=html;
}

// ── RENDER LIST ───────────────────────────────────────────────────────────────
function renderList(){
    closeAllMenus();
    renderStats(); buildDropdowns();
    if(currentView!=='list') return;
    const data=getSorted(getFiltered());
    const total=data.length, pages=Math.max(1,Math.ceil(total/PAGE_SIZE));
    if(page>pages) page=pages;
    const slice=data.slice((page-1)*PAGE_SIZE,page*PAGE_SIZE);

    document.querySelectorAll('#tbl thead th[data-col]').forEach(th=>{
        const c=th.dataset.col; th.classList.toggle('sorted',c===sortCol);
        const ic=th.querySelector('.sic');
        if(ic) ic.className=`bx ${c===sortCol?(sortDir==='asc'?'bx-sort-up':'bx-sort-down'):'bx-sort'} sic`;
    });

    const tb=document.getElementById('tbody');
    if(!slice.length){
        tb.innerHTML=`<tr><td colspan="20"><div class="empty"><i class="bx bx-trophy"></i><p>No milestones found.</p></div></td></tr>`;
    } else {
        const tod=today();
        tb.innerHTML=slice.map(m=>{
            const clr=pc(m.project), zcl=zc(m.zone);
            const isDone=['Completed','Force Completed'].includes(m.status);
            const isSk=m.status==='Skipped';
            const duePast=m.targetDate<tod&&!isDone&&!isSk;
            const deps=Array.isArray(m.deps)?m.deps:[];
            const pClr=progColor(m.progress,m.status);

            const depsHtml=CAP.colDependencies
                ?(deps.length
                    ?deps.slice(0,3).map(d=>{const dm=MS.find(x=>x.milestoneId===d);const met=dm&&['Completed','Force Completed'].includes(dm.status);return`<span class="dep-tag ${dm?(met?'met':'blocked'):''}" title="${dm?esc(dm.name):esc(d)}">${esc(d)}</span>`;}).join('')+(deps.length>3?`<span class="dep-tag">+${deps.length-3}</span>`:'')
                    :'<span style="font-size:11px;color:var(--t3)">—</span>')
                :'';

            return `<tr>
                <td onclick="openView('${m.milestoneId}')"><span class="ms-id">${esc(m.milestoneId)}</span></td>
                ${CAP.colProject?`<td onclick="openView('${m.milestoneId}')"><div class="proj-cell"><span class="proj-dot" style="background:${clr}"></span><span class="proj-name" title="${esc(m.project)}">${esc(m.project)}</span></div></td>`:''}
                <td onclick="openView('${m.milestoneId}')"><span class="ms-name-cell" title="${esc(m.name)}">${esc(m.name)}</span></td>
                <td onclick="openView('${m.milestoneId}')"><span class="ms-date ${duePast?'overdue':''}">${fD(m.targetDate)}${duePast?' ⚠':''}</span></td>
                ${CAP.colCompletionDate?`<td onclick="openView('${m.milestoneId}')"><span class="ms-date">${m.completionDate?fD(m.completionDate):'—'}</span></td>`:''}
                <td onclick="openView('${m.milestoneId}')"><div class="prog-wrap"><div class="prog-bar"><div class="prog-fill" style="width:${m.progress}%;background:${pClr}"></div></div><span class="prog-lbl">${m.progress}%</span></div></td>
                ${CAP.colAssignedTasks?`<td onclick="openView('${m.milestoneId}')"><span class="task-cell">${m.notes||'—'}</span></td>`:''}
                ${CAP.colDependencies?`<td onclick="openView('${m.milestoneId}')"><div class="dep-cell">${depsHtml}</div></td>`:''}
                ${CAP.colZone?`<td onclick="openView('${m.milestoneId}')"><span class="zone-dot"><span style="width:7px;height:7px;border-radius:50%;background:${zcl};flex-shrink:0"></span>${esc(m.zone.split('–')[0].trim()||m.zone)}</span></td>`:''}
                <td onclick="openView('${m.milestoneId}')">${badge(m.status)}</td>
                <td onclick="event.stopPropagation()">
                    <div class="act-cell">
                        <button class="act-btn" onclick="toggleActMenu(event,'${m.milestoneId}')"><i class="bx bx-dots-vertical-rounded"></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    const s=(page-1)*PAGE_SIZE+1, e=Math.min(page*PAGE_SIZE,total);
    let btns='';
    for(let i=1;i<=pages;i++){
        if(i===1||i===pages||(i>=page-2&&i<=page+2)) btns+=`<button class="pgb ${i===page?'active':''}" onclick="goPage(${i})">${i}</button>`;
        else if(i===page-3||i===page+3) btns+=`<button class="pgb" disabled>…</button>`;
    }
    document.getElementById('pager').innerHTML=`
        <span>${total===0?'No results':`Showing ${s}–${e} of ${total}`}</span>
        <div class="pg-btns">
            <button class="pgb" onclick="goPage(${page-1})" ${page<=1?'disabled':''}><i class="bx bx-chevron-left"></i></button>
            ${btns}
            <button class="pgb" onclick="goPage(${page+1})" ${page>=pages?'disabled':''}><i class="bx bx-chevron-right"></i></button>
        </div>`;
}
window.goPage=p=>{page=p;renderList();};

// ── ACTION MENU ───────────────────────────────────────────────────────────────
let _activeMenu=null;
function closeAllMenus(){if(_activeMenu){_activeMenu.remove();_activeMenu=null;}}
function toggleActMenu(e, msId){
    e.stopPropagation(); closeAllMenus();
    const m=MS.find(x=>x.milestoneId===msId); if(!m) return;
    const isDone=['Completed','Force Completed'].includes(m.status);
    const isSk=m.status==='Skipped', isInPr=m.status==='In Progress', isPend=m.status==='Pending';
    const menu=document.createElement('div'); menu.className='act-menu';

    let items=`<button class="act-item" onclick="closeAllMenus();openView('${msId}')"><i class="bx bx-show"></i> View Details</button>`;

    // Edit — Admin + Super Admin only
    if(CAP.canEdit && !isDone && !isSk)
        items+=`<button class="act-item" onclick="closeAllMenus();openEdit('${msId}')"><i class="bx bx-edit"></i> Edit Milestone</button>`;

    items+=`<div class="act-sep"></div>`;

    // Update progress — Manager + above (not for staff; staff uses dedicated button)
    if(CAP.canUpdateProgress && RANK >= 2 && !isDone && !isSk)
        items+=`<button class="act-item" style="color:#1D4ED8" onclick="closeAllMenus();openProgress('${msId}')"><i class="bx bx-trending-up" style="color:#2563EB"></i> Update Progress</button>`;

    // Mark complete — Admin + above
    if(CAP.canComplete && (isInPr||isPend))
        items+=`<button class="act-item success" onclick="closeAllMenus();promptAct('${msId}','complete')"><i class="bx bx-check"></i> Mark Complete</button>`;

    // Flag delayed — Manager + above
    if(CAP.canFlag && !isDone && !isSk)
        items+=`<button class="act-item warning" onclick="closeAllMenus();promptAct('${msId}','flag')"><i class="bx bx-flag"></i> Flag Delayed</button>`;

    // Escalate blocker — Manager only
    if(CAP.canEscalate && !isDone)
        items+=`<button class="act-item" style="color:#991B1B" onclick="closeAllMenus();openEscalate('${msId}')"><i class="bx bx-error-circle" style="color:#DC2626"></i> Escalate Blocker</button>`;

    // Staff-only actions
    if(CAP.canSubmitEvidence && !isDone)
        items+=`<button class="act-item success" onclick="closeAllMenus();openEvidence('${msId}')"><i class="bx bx-upload"></i> Submit Evidence</button>`;
    if(CAP.canUpdateProgress && RANK === 1 && !isDone && !isSk)
        items+=`<button class="act-item" style="color:#1D4ED8" onclick="closeAllMenus();openProgress('${msId}')"><i class="bx bx-trending-up" style="color:#2563EB"></i> Update My Progress</button>`;

    // Super Admin only
    if(CAP.canOverrideDeps && !isDone)
        items+=`<button class="act-item" style="color:#6D28D9" onclick="closeAllMenus();promptAct('${msId}','override')"><i class="bx bx-git-branch" style="color:#8B5CF6"></i> Override Deps</button>`;
    if(CAP.canSkip && !isDone && !isSk)
        items+=`<button class="act-item" onclick="closeAllMenus();promptAct('${msId}','skip')"><i class="bx bx-skip-next"></i> Skip Milestone</button>`;
    if(CAP.canForceComplete && !isDone)
        items+=`<button class="act-item danger" onclick="closeAllMenus();promptAct('${msId}','force-complete')"><i class="bx bx-check-shield"></i> Force Complete</button>`;

    menu.innerHTML=items;
    document.body.appendChild(menu); _activeMenu=menu;
    const btn=e.currentTarget; const r=btn.getBoundingClientRect(); const mw=210;
    let left=r.right-mw, top=r.bottom+4;
    const menuH=menu.querySelectorAll('.act-item').length*36+16;
    if(top+menuH>window.innerHeight-16) top=r.top-menuH-4;
    if(left<8) left=8;
    menu.style.left=left+'px'; menu.style.top=top+'px'; menu.style.width=mw+'px';
    requestAnimationFrame(()=>menu.classList.add('open'));
}
document.addEventListener('click',closeAllMenus);
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeAllMenus();});

document.querySelectorAll('#tbl thead th[data-col]').forEach(th=>{
    th.addEventListener('click',()=>{
        const c=th.dataset.col; sortDir=sortCol===c?(sortDir==='asc'?'desc':'asc'):'asc'; sortCol=c; page=1; renderList();
    });
});
['srch','fStatus'].forEach(id=>{
    const el=document.getElementById(id); if(el) el.addEventListener('input',()=>{page=1;renderList();if(currentView==='gantt')renderGantt();});
});
['fProject','fZone'].forEach(id=>{
    const el=document.getElementById(id); if(el) el.addEventListener('input',()=>{page=1;renderList();if(currentView==='gantt')renderGantt();});
});

// ── GANTT ─────────────────────────────────────────────────────────────────────
function dateToStr(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
function strToDate(s){return new Date(s+'T00:00:00');}
function getGanttRange(){
    const t=new Date(); t.setHours(0,0,0,0);
    let start=new Date(t); let days=30;
    if(ganttZoom==='week'){start.setDate(t.getDate()-3+ganttOffset*7);days=14;}
    else if(ganttZoom==='month'){start.setDate(1);start.setMonth(t.getMonth()+ganttOffset);days=42;}
    else{start.setDate(1);start.setMonth(Math.floor(t.getMonth()/3)*3+ganttOffset*3);days=90;}
    return{start,days};
}
function renderGantt(){
    if(!CAP.canViewGantt||currentView!=='gantt') return;
    const filtered=getFiltered();
    const{start,days}=getGanttRange();
    const colW=ganttZoom==='week'?50:ganttZoom==='quarter'?20:32;
    const todayStr=today(), todayD=strToDate(todayStr), todayOffset=Math.round((todayD-start)/(1000*60*60*24));
    const totalW=days*colW;
    const endD=new Date(start); endD.setDate(endD.getDate()+days-1);
    document.getElementById('gnPeriod').textContent=start.toLocaleDateString('en-PH',{month:'short',year:'numeric'})+(ganttZoom!=='month'?' – '+endD.toLocaleDateString('en-PH',{month:'short',year:'numeric'}):'');
    let dateCols='';
    for(let i=0;i<days;i++){
        const d=new Date(start); d.setDate(d.getDate()+i);
        const ds=dateToStr(d), isToday=ds===todayStr;
        const show=ganttZoom==='week'||(ganttZoom==='month'&&[1,8,15,22].includes(d.getDate()))||(ganttZoom==='quarter'&&d.getDate()===1);
        dateCols+=`<div class="gantt-date-col ${isToday?'gdc-today':''}" style="width:${colW}px;min-width:${colW}px">${show?`<span class="gdc-month">${d.toLocaleDateString('en-US',{month:'short'})}</span><span class="gdc-day">${d.getDate()}</span>`:`<span class="gdc-day" style="font-size:9px;color:${isToday?'var(--grn)':'#D1D5DB'}">${d.getDate()}</span>`}</div>`;
    }
    let labelRows='', barRows='';
    const statusCls={'Pending':'gb-pending','In Progress':'gb-inprogress','Completed':'gb-completed','Overdue':'gb-overdue','Skipped':'gb-skipped','Force Completed':'gb-forcecompleted'};
    filtered.forEach(m=>{
        const clr=pc(m.project);
        labelRows+=`<div class="gl-row" onclick="openView('${m.milestoneId}')"><div class="gl-row-proj" style="background:${clr}"></div><div class="gl-row-info"><div class="gl-row-name">${esc(m.name)}</div><div class="gl-row-sub">${esc(m.milestoneId)} · ${esc(m.project.split(' ').slice(0,3).join(' '))}</div></div></div>`;
        const mStart=strToDate(m.targetDate);
        let barStartD=new Date(mStart); barStartD.setDate(barStartD.getDate()-7);
        let barEndD=m.completionDate?strToDate(m.completionDate):mStart;
        const actualStart=barStartD<barEndD?barStartD:barEndD, actualEnd=barStartD<barEndD?barEndD:barStartD;
        let barLeft=Math.round((actualStart-start)/(1000*60*60*24))*colW, barRight=Math.round((actualEnd-start)/(1000*60*60*24))*colW+colW;
        let renderLeft=barLeft, renderW=Math.max(barRight-barLeft,colW);
        if(renderLeft<0){renderW+=renderLeft;renderLeft=0;}
        const gridCols=Array.from({length:days},(_,i)=>{const dt=new Date(start.getFullYear(),start.getMonth(),start.getDate()+i);return`<div class="gantt-grid-col ${dateToStr(dt)===todayStr?'gantt-grid-today':''}" style="width:${colW}px;min-width:${colW}px;height:100%;position:relative"></div>`;}).join('');
        const barHtml=(renderW>0&&barLeft<totalW&&barRight>0)?`<div class="gantt-bar ${statusCls[m.status]||'gb-pending'}" style="left:${renderLeft}px;width:${Math.min(renderW,totalW-renderLeft)}px" onclick="openView('${m.milestoneId}')" title="${esc(m.name)}">${esc(m.name)}</div>`:'';
        barRows+=`<div class="gantt-row" style="width:${totalW}px"><div style="position:absolute;inset:0;display:flex">${gridCols}</div>${barHtml}</div>`;
    });
    if(!filtered.length){labelRows=`<div style="padding:48px 16px;text-align:center;color:var(--t3);font-size:13px">No milestones match filters.</div>`;barRows=`<div style="height:96px"></div>`;}
    const todayLineX=todayOffset>=0&&todayOffset<days?todayOffset*colW+colW/2:-999;
    document.getElementById('ganttBody').innerHTML=`<div class="gantt-labels"><div class="gl-hdr">Milestone</div>${labelRows}</div><div class="gantt-timeline" id="ganttTl"><div class="gantt-dates" style="width:${totalW}px">${dateCols}</div><div class="gantt-rows" style="width:${totalW}px;position:relative">${todayLineX>0?`<div class="today-line" style="left:${todayLineX}px"></div><div class="today-label" style="left:${todayLineX}px">Today</div>`:''} ${barRows}</div></div>`;
}
if(CAP.canViewGantt){
    document.getElementById('gnPrev').addEventListener('click',()=>{ganttOffset--;renderGantt();});
    document.getElementById('gnNext').addEventListener('click',()=>{ganttOffset++;renderGantt();});
    document.getElementById('gnToday').addEventListener('click',()=>{ganttOffset=0;renderGantt();});
    document.querySelectorAll('.gz-btn').forEach(b=>b.addEventListener('click',()=>{
        ganttZoom=b.dataset.zoom; ganttOffset=0;
        document.querySelectorAll('.gz-btn').forEach(x=>x.classList.remove('active')); b.classList.add('active'); renderGantt();
    }));
    document.getElementById('btnList').addEventListener('click',()=>{currentView='list';document.getElementById('listView').classList.add('active');document.getElementById('ganttView').classList.remove('active');document.getElementById('btnList').classList.add('active');document.getElementById('btnGantt').classList.remove('active');renderList();});
    document.getElementById('btnGantt').addEventListener('click',()=>{currentView='gantt';document.getElementById('ganttView').classList.add('active');document.getElementById('listView').classList.remove('active');document.getElementById('btnGantt').classList.add('active');document.getElementById('btnList').classList.remove('active');renderStats();buildDropdowns();renderGantt();});
}

// ── VIEW MODAL ────────────────────────────────────────────────────────────────
function openView(msId){const m=MS.find(x=>x.milestoneId===msId);if(!m)return;renderDetail(m);setVmTab('ov');document.getElementById('viewModal').classList.add('on');}
function closeView(){document.getElementById('viewModal').classList.remove('on');}
document.getElementById('vmClose').addEventListener('click',closeView);
document.getElementById('viewModal').addEventListener('click',function(e){if(e.target===this)closeView();});
document.querySelectorAll('.vm-tab').forEach(t=>t.addEventListener('click',()=>setVmTab(t.dataset.t)));
function setVmTab(name){
    document.querySelectorAll('.vm-tab').forEach(t=>t.classList.toggle('active',t.dataset.t===name));
    document.querySelectorAll('.vm-tp').forEach(p=>p.classList.toggle('active',p.id==='vt-'+name));
    if(name==='au') loadAuditTrail(MS.find(x=>x.milestoneId===currentViewId)?.id);
}
let currentViewId=null;

function renderDetail(m){
    currentViewId=m.milestoneId;
    const clr=pc(m.project);
    const isDone=['Completed','Force Completed'].includes(m.status);
    const isSk=m.status==='Skipped', isInPr=m.status==='In Progress', isPend=m.status==='Pending';
    const pClr=progColor(m.progress,m.status);
    const deps=m.deps||[];

    document.getElementById('vmAvatar').textContent=m.milestoneId.replace(/MS-\d{4}-/,'#');
    document.getElementById('vmAvatar').style.background=clr;
    document.getElementById('vmAvatar').style.fontSize='13px';
    document.getElementById('vmName').innerHTML=esc(m.name);
    document.getElementById('vmMid').innerHTML=`<span style="font-family:'DM Mono',monospace">${esc(m.milestoneId)}</span>&nbsp;·&nbsp;${esc(m.project)}&nbsp;${badge(m.status)}`;
    document.getElementById('vmChips').innerHTML=`
        <div class="vm-mc"><i class="bx bx-calendar-alt"></i>Target ${fD(m.targetDate)}</div>
        ${m.completionDate?`<div class="vm-mc"><i class="bx bx-check-circle"></i>Completed ${fD(m.completionDate)}</div>`:''}
        ${CAP.colZone?`<div class="vm-mc"><i class="bx bx-map-pin"></i>${esc(m.zone)}</div>`:''}
        ${CAP.canViewCrossDeps?`<div class="vm-mc"><i class="bx bx-git-branch"></i>${deps.length} Dependenc${deps.length===1?'y':'ies'}</div>`:''}`;

    // Build footer buttons based on role
    let footBtns='';
    if(CAP.canUpdateProgress && RANK >= 2 && !isDone && !isSk)
        footBtns+=`<button class="btn btn-prog btn-sm" onclick="closeView();openProgress('${m.milestoneId}')"><i class="bx bx-trending-up"></i> Update Progress</button>`;
    if(CAP.canSubmitEvidence && !isDone)
        footBtns+=`<button class="btn btn-evid btn-sm" onclick="closeView();openEvidence('${m.milestoneId}')"><i class="bx bx-upload"></i> Submit Evidence</button>`;
    if(CAP.canUpdateProgress && RANK === 1 && !isDone && !isSk)
        footBtns+=`<button class="btn btn-prog btn-sm" onclick="closeView();openProgress('${m.milestoneId}')"><i class="bx bx-trending-up"></i> Update My Progress</button>`;
    if(CAP.canComplete && !isDone && !isSk)
        footBtns+=`<button class="btn btn-complete btn-sm" onclick="closeView();promptAct('${m.milestoneId}','complete')"><i class="bx bx-check"></i> Mark Complete</button>`;
    if(CAP.canFlag && !isDone && !isSk)
        footBtns+=`<button class="btn btn-flag btn-sm" onclick="closeView();promptAct('${m.milestoneId}','flag')"><i class="bx bx-flag"></i> Flag Delayed</button>`;
    if(CAP.canEscalate && !isDone)
        footBtns+=`<button class="btn btn-esc btn-sm" onclick="closeView();openEscalate('${m.milestoneId}')"><i class="bx bx-error-circle"></i> Escalate</button>`;
    if(CAP.canOverrideDeps && !isDone)
        footBtns+=`<button class="btn btn-override btn-sm" onclick="closeView();promptAct('${m.milestoneId}','override')"><i class="bx bx-git-branch"></i> Override Deps</button>`;
    if(CAP.canForceComplete && !isDone)
        footBtns+=`<button class="btn btn-fc btn-sm" onclick="closeView();promptAct('${m.milestoneId}','force-complete')"><i class="bx bx-check-shield"></i> Force Complete</button>`;
    if(CAP.canEdit && !isDone && !isSk)
        footBtns+=`<button class="btn btn-ghost btn-sm" onclick="closeView();openEdit('${m.milestoneId}')"><i class="bx bx-edit"></i> Edit</button>`;
    footBtns+=`<button class="btn btn-ghost btn-sm" onclick="closeView()">Close</button>`;
    document.getElementById('vmFoot').innerHTML=footBtns;

    const rkc={Completed:'vm-rmk-a','Force Completed':'vm-rmk-t',Overdue:'vm-rmk-r',Skipped:'vm-rmk-n','In Progress':'vm-rmk-n',Pending:'vm-rmk-n'}[m.status]||'vm-rmk-n';
    document.getElementById('vt-ov').innerHTML=`
        <div class="vm-sbs">
            <div class="vm-sb"><div class="sbv">${m.progress}%</div><div class="sbl">Progress</div></div>
            ${CAP.canViewCrossDeps?`<div class="vm-sb"><div class="sbv">${deps.length}</div><div class="sbl">Dependencies</div></div>`:''}
            <div class="vm-sb"><div class="sbv" style="font-size:13px">${fD(m.targetDate)}</div><div class="sbl">Target Date</div></div>
            ${CAP.colCompletionDate?`<div class="vm-sb"><div class="sbv" style="font-size:13px">${m.completionDate?fD(m.completionDate):'—'}</div><div class="sbl">Completion</div></div>`:''}
        </div>
        <div style="background:#F3F4F6;border-radius:8px;height:10px;overflow:hidden">
            <div style="width:${m.progress}%;height:100%;background:${pClr};border-radius:8px;transition:width .5s"></div>
        </div>
        <div class="vm-ig" style="margin-top:4px">
            ${CAP.colProject?`<div class="vm-ii"><label>Project</label><div class="v" style="color:${clr};font-weight:700">${esc(m.project)}</div></div>`:''}
            ${CAP.colZone?`<div class="vm-ii"><label>Zone</label><div class="v">${esc(m.zone)}</div></div>`:''}
            <div class="vm-ii"><label>Status</label><div class="v">${badge(m.status)}</div></div>
            <div class="vm-ii"><label>Progress</label><div class="v">${m.progress}%</div></div>
            ${m.notes?`<div class="vm-ii vm-full"><label>Notes</label><div class="vm-rmk ${rkc}"><div class="rml">Notes</div>${esc(m.notes)}</div></div>`:''}
        </div>`;

    if(CAP.canViewCrossDeps){
        const dpEl=document.getElementById('vt-dp');
        if(dpEl) dpEl.innerHTML=`
            <div class="vm-sa-note"><i class="bx bx-info-circle"></i><span>Cross-project dependencies tracked site-wide. Blocked dependencies flag this milestone.</span></div>
            ${deps.length===0?`<div style="padding:32px 0;text-align:center;color:var(--t3)"><i class="bx bx-git-branch" style="font-size:40px;display:block;margin-bottom:10px;color:#C8E6C9"></i>No dependencies</div>`
            :`<div class="dep-list">${deps.map(d=>{const dm=MS.find(x=>x.milestoneId===d);const met=dm&&['Completed','Force Completed'].includes(dm.status);return`<div class="dep-item"><i class="bx bx-arrow-back dep-arrow-ic" style="transform:rotate(180deg)"></i><span class="dep-item-id">${esc(d)}</span><span class="dep-item-name">${esc(dm?dm.name:'Unknown Milestone')}</span><span class="dep-item-stat">${badge(dm?dm.status:'Unknown')}</span><span style="font-size:11px;font-weight:700;${met?'color:#166534':'color:#DC2626'}">${met?'✓ Met':'✗ Blocked'}</span></div>`;}).join('')}</div>`}`;
    }
    if(RANK >= 4){
        const auEl=document.getElementById('vt-au');
        if(auEl) auEl.innerHTML=`<div class="vm-sa-note"><i class="bx bx-shield-quarter"></i><span>Full audit trail — Super Admin view only. Read-only.</span></div><div id="auditContent"><div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">Loading…</div></div>`;
    }
}

async function loadAuditTrail(dbId){
    if(!dbId||RANK<4) return;
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

// ── ACTION MODAL ──────────────────────────────────────────────────────────────
function promptAct(msId,type){
    const m=MS.find(x=>x.milestoneId===msId); if(!m) return;
    actionTarget=msId; actionKey=type;
    const cfg={
        complete:        {icon:'✅',title:'Mark as Completed',      sa:false,saText:'',btn:'btn-complete',label:'<i class="bx bx-check"></i> Mark Complete'},
        flag:            {icon:'🚩',title:'Flag as Delayed',        sa:false,saText:'',btn:'btn-flag',    label:'<i class="bx bx-flag"></i> Flag Delayed'},
        override:        {icon:'🔓',title:'Override Dependencies',  sa:true, saText:'Bypasses all dependency locks for this milestone.',btn:'btn-override',label:'<i class="bx bx-git-branch"></i> Override & Proceed'},
        skip:            {icon:'⏭️',title:'Skip Milestone',         sa:true, saText:'Marks milestone non-applicable and unblocks dependents.',btn:'btn-skip',label:'<i class="bx bx-skip-next"></i> Skip'},
        'force-complete':{icon:'⚡',title:'Force Complete',          sa:true, saText:'Super Admin override — marks complete regardless of progress or dependencies.',btn:'btn-fc',label:'<i class="bx bx-check-shield"></i> Force Complete'},
    };
    const c=cfg[type]; if(!c) return;
    document.getElementById('amIcon').textContent=c.icon;
    document.getElementById('amTitle').textContent=c.title;
    document.getElementById('amBody').innerHTML=`Milestone <strong>${esc(m.milestoneId)}</strong> — <strong>${esc(m.name)}</strong>&nbsp;·&nbsp;${esc(m.project)}`;
    const san=document.getElementById('amSaNote');
    if(c.sa){san.style.display='flex';document.getElementById('amSaText').textContent=c.saText;}
    else san.style.display='none';
    document.getElementById('amExtra').innerHTML=''; document.getElementById('amRemarks').value='';
    const cb=document.getElementById('amConfirm'); cb.className=`btn btn-sm ${c.btn}`; cb.innerHTML=c.label;
    actionCb=null; document.getElementById('actionModal').classList.add('on');
}
document.getElementById('amConfirm').addEventListener('click',async()=>{
    if(actionCb){await actionCb();return;}
    const rmk=document.getElementById('amRemarks').value.trim();
    const m=MS.find(x=>x.milestoneId===actionTarget); if(!m) return;
    try{
        const updated=await apiPost(API+'?api=action',{id:m.id,type:actionKey,remarks:rmk});
        const idx=MS.findIndex(x=>x.id===updated.id); if(idx>-1) MS[idx]=updated;
        const msgs={complete:`${m.milestoneId} marked as Completed.`,flag:`${m.milestoneId} flagged as Overdue.`,override:`${m.milestoneId} — dependencies cleared.`,skip:`${m.milestoneId} skipped.`,'force-complete':`${m.milestoneId} force-completed.`};
        const types={complete:'s',flag:'w',override:'s',skip:'w','force-complete':'s'};
        toast(msgs[actionKey]||'Action applied.',types[actionKey]||'s');
        document.getElementById('actionModal').classList.remove('on');
        if(currentView==='list') renderList(); else{renderStats();buildDropdowns();renderGantt();}
    }catch(e){toast(e.message,'d');}
});
document.getElementById('amCancel').addEventListener('click',()=>{document.getElementById('actionModal').classList.remove('on');actionCb=null;});
document.getElementById('actionModal').addEventListener('click',function(e){if(e.target===this){this.classList.remove('on');actionCb=null;}});

// ── PROGRESS MODAL ────────────────────────────────────────────────────────────
function openProgress(msId){
    if(!CAP.canUpdateProgress) return;
    const m=MS.find(x=>x.milestoneId===msId); if(!m) return;
    progressTarget=msId;
    document.getElementById('pmBody').innerHTML=`<strong>${esc(m.milestoneId)}</strong> — ${esc(m.name)}`;
    document.getElementById('pmRange').value=m.progress;
    document.getElementById('pmVal').textContent=m.progress+'%';
    document.getElementById('pmNotes').value='';
    document.getElementById('progressModal').classList.add('on');
}
if(CAP.canUpdateProgress){
    document.getElementById('pmConfirm').addEventListener('click',async()=>{
        const m=MS.find(x=>x.milestoneId===progressTarget); if(!m) return;
        const progress=parseInt(document.getElementById('pmRange').value)||0;
        const notes=document.getElementById('pmNotes').value.trim();
        try{
            const updated=await apiPost(API+'?api=progress',{id:m.id,progress,notes});
            const idx=MS.findIndex(x=>x.id===updated.id); if(idx>-1) MS[idx]=updated;
            toast(`Progress updated to ${progress}%.`,'s');
            document.getElementById('progressModal').classList.remove('on');
            if(currentView==='list') renderList(); else{renderStats();buildDropdowns();renderGantt();}
        }catch(e){toast(e.message,'d');}
    });
    document.getElementById('pmCancel').addEventListener('click',()=>document.getElementById('progressModal').classList.remove('on'));
    document.getElementById('progressModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('on');});
}

// ── EVIDENCE MODAL ────────────────────────────────────────────────────────────
function openEvidence(msId){
    if(!CAP.canSubmitEvidence) return;
    const m=MS.find(x=>x.milestoneId===msId); if(!m) return;
    evidenceTarget=msId;
    document.getElementById('emBody').innerHTML=`<strong>${esc(m.milestoneId)}</strong> — ${esc(m.name)}`;
    document.getElementById('emNote').value=''; document.getElementById('emLink').value='';
    document.getElementById('evidenceModal').classList.add('on');
}
if(CAP.canSubmitEvidence){
    document.getElementById('emConfirm').addEventListener('click',async()=>{
        const m=MS.find(x=>x.milestoneId===evidenceTarget); if(!m) return;
        const note=document.getElementById('emNote').value.trim();
        const link=document.getElementById('emLink').value.trim();
        if(!note){shk('emNote');toast('Evidence description is required.','w');return;}
        try{
            await apiPost(API+'?api=evidence',{id:m.id,note,link});
            toast('Evidence submitted successfully.','s');
            document.getElementById('evidenceModal').classList.remove('on');
        }catch(e){toast(e.message,'d');}
    });
    document.getElementById('emCancel').addEventListener('click',()=>document.getElementById('evidenceModal').classList.remove('on'));
    document.getElementById('evidenceModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('on');});
}

// ── ESCALATE MODAL ────────────────────────────────────────────────────────────
function openEscalate(msId){
    if(!CAP.canEscalate) return;
    const m=MS.find(x=>x.milestoneId===msId); if(!m) return;
    escalateTarget=msId;
    document.getElementById('escBody').innerHTML=`<strong>${esc(m.milestoneId)}</strong> — ${esc(m.name)}`;
    document.getElementById('escNote').value='';
    document.getElementById('escalateModal').classList.add('on');
}
if(CAP.canEscalate){
    document.getElementById('escConfirm').addEventListener('click',async()=>{
        const m=MS.find(x=>x.milestoneId===escalateTarget); if(!m) return;
        const note=document.getElementById('escNote').value.trim();
        if(!note){shk('escNote');toast('Escalation reason is required.','w');return;}
        try{
            await apiPost(API+'?api=escalate',{id:m.id,note});
            toast('Blocker escalated to Admin.','s');
            document.getElementById('escalateModal').classList.remove('on');
        }catch(e){toast(e.message,'d');}
    });
    document.getElementById('escCancel').addEventListener('click',()=>document.getElementById('escalateModal').classList.remove('on'));
    document.getElementById('escalateModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('on');});
}

// ── SLIDER ────────────────────────────────────────────────────────────────────
function populateProjDropdown(selectedVal=''){
    const csInput=document.getElementById('csProjSearch'), csHidden=document.getElementById('fProj');
    if(!csInput) return;
    csHidden.value=selectedVal; csInput.value=selectedVal; wireProjSearch();
}
function wireProjSearch(){
    const csInput=document.getElementById('csProjSearch'), csHidden=document.getElementById('fProj'), csDrop=document.getElementById('csProjDrop');
    if(!csInput||csInput.dataset.wired) return; csInput.dataset.wired='1'; let csHl=-1;
    function csRender(q){
        const lq=(q||'').toLowerCase(), liveNames=new Set(PROJECTS.map(p=>p.name));
        const msNames=[...new Set(MS.map(m=>m.project))].filter(n=>n&&!liveNames.has(n));
        const filtered=[...PROJECTS.map(p=>p.name),...msNames].filter(n=>n.toLowerCase().includes(lq));
        if(!filtered.length){csDrop.innerHTML='<div class="cs-opt cs-none">No projects found</div>';}
        else{csDrop.innerHTML=filtered.map(n=>`<div class="cs-opt" data-name="${esc(n)}"><span class="cs-name">${esc(n)}</span></div>`).join('');csDrop.querySelectorAll('.cs-opt:not(.cs-none)').forEach(opt=>{opt.addEventListener('mousedown',e=>{e.preventDefault();csHidden.value=opt.dataset.name;csInput.value=opt.dataset.name;csDrop.classList.remove('open');});});}
        csHl=-1;
    }
    csInput.addEventListener('focus',()=>{csRender(csInput.value);csDrop.classList.add('open');});
    csInput.addEventListener('input',()=>{csHidden.value='';csRender(csInput.value);csDrop.classList.add('open');});
    csInput.addEventListener('blur',()=>setTimeout(()=>csDrop.classList.remove('open'),150));
    csInput.addEventListener('keydown',e=>{const opts=[...csDrop.querySelectorAll('.cs-opt:not(.cs-none)')];if(e.key==='ArrowDown'){e.preventDefault();csHl=Math.min(csHl+1,opts.length-1);}else if(e.key==='ArrowUp'){e.preventDefault();csHl=Math.max(csHl-1,0);}else if(e.key==='Enter'&&csHl>=0){e.preventDefault();const o=opts[csHl];if(o){csHidden.value=o.dataset.name;csInput.value=o.dataset.name;csDrop.classList.remove('open');}}else if(e.key==='Escape')csDrop.classList.remove('open');opts.forEach((o,i)=>o.classList.toggle('hl',i===csHl));if(csHl>=0&&opts[csHl])opts[csHl].scrollIntoView({block:'nearest'});});
}
function populateZoneDropdown(selectedVal=''){
    const sel=document.getElementById('fZoneSl'); if(!sel) return;
    sel.innerHTML='<option value="">Select zone…</option>'+ZONES.map(z=>`<option value="${esc(z.name)}" ${z.name===selectedVal||z.id===selectedVal?'selected':''}>${esc(z.name)}</option>`).join('');
}
function renderDepSelList(){
    const wrap=document.getElementById('depSelList'); if(!wrap) return;
    if(!selectedDeps.length){wrap.innerHTML='<div style="font-size:12px;color:var(--t3);padding:6px 2px">No dependencies added yet.</div>';return;}
    wrap.innerHTML=selectedDeps.map(id=>{const m=MS.find(x=>x.milestoneId===id);const isDone=m&&['Completed','Force Completed'].includes(m.status);return`<div class="dep-sel-item"><span class="dep-tag ${isDone?'met':''}" style="font-size:11px">${esc(id)}</span><span class="ds-nm">${m?esc(m.name.slice(0,40)):'Unknown milestone'}</span><button class="dep-rm" onclick="removeDep('${id}')"><i class="bx bx-x"></i></button></div>`;}).join('');
}
function removeDep(id){selectedDeps=selectedDeps.filter(d=>d!==id);renderDepSelList();}
function wireDepSearch(){
    const inp=document.getElementById('depSearch'), drop=document.getElementById('depDrop');
    if(!inp||inp.dataset.wired) return; inp.dataset.wired='1'; let hl=-1;
    function render(q){
        const lq=(q||'').toLowerCase();
        const pool=MS.filter(m=>{if(editId&&m.milestoneId===editId)return false;if(selectedDeps.includes(m.milestoneId))return false;return!lq||m.milestoneId.toLowerCase().includes(lq)||m.name.toLowerCase().includes(lq)||m.project.toLowerCase().includes(lq);});
        if(!pool.length){drop.innerHTML=`<div class="cs-opt cs-none">${lq?'No milestones match':'All available milestones already added'}</div>`;}
        else{drop.innerHTML=pool.slice(0,20).map(m=>`<div class="cs-opt" data-id="${esc(m.milestoneId)}"><span class="cs-name" style="display:flex;align-items:center;gap:6px"><span style="font-family:'DM Mono',monospace;font-size:10.5px;font-weight:700;color:var(--grn)">${esc(m.milestoneId)}</span><span style="font-size:12px;color:var(--t1);font-weight:500">${esc(m.name.slice(0,45))}</span></span><span class="cs-sub">${esc(m.project)} · ${esc(m.status)}</span></div>`).join('');drop.querySelectorAll('.cs-opt:not(.cs-none)').forEach(opt=>{opt.addEventListener('mousedown',e=>{e.preventDefault();if(!selectedDeps.includes(opt.dataset.id)){selectedDeps.push(opt.dataset.id);renderDepSelList();}inp.value='';drop.classList.remove('open');render('');});});}
        hl=-1;
    }
    inp.addEventListener('focus',()=>{render(inp.value);drop.classList.add('open');});
    inp.addEventListener('input',()=>{render(inp.value);drop.classList.add('open');});
    inp.addEventListener('blur',()=>setTimeout(()=>drop.classList.remove('open'),160));
    inp.addEventListener('keydown',e=>{const opts=[...drop.querySelectorAll('.cs-opt:not(.cs-none)')];if(e.key==='ArrowDown'){e.preventDefault();hl=Math.min(hl+1,opts.length-1);}else if(e.key==='ArrowUp'){e.preventDefault();hl=Math.max(hl-1,0);}else if(e.key==='Enter'&&hl>=0){e.preventDefault();const o=opts[hl];if(o){if(!selectedDeps.includes(o.dataset.id)){selectedDeps.push(o.dataset.id);renderDepSelList();}inp.value='';drop.classList.remove('open');render('');}}else if(e.key==='Escape')drop.classList.remove('open');opts.forEach((o,i)=>o.classList.toggle('hl',i===hl));if(hl>=0&&opts[hl])opts[hl].scrollIntoView({block:'nearest'});});
}
function openSlider(mode='create',m=null){
    if(!CAP.canAdd&&!CAP.canEdit) return;
    editId=mode==='edit'?m.milestoneId:null;
    selectedDeps=mode==='edit'?(m.deps||[]).slice():[];
    document.getElementById('slTitle').textContent=mode==='edit'?`Edit — ${m.milestoneId}`:'Add Milestone';
    document.getElementById('slSub').textContent=mode==='edit'?'Update fields below':'Fill in all required fields below';
    populateProjDropdown(mode==='edit'&&m?m.project:''); populateZoneDropdown(mode==='edit'&&m?m.zone:'');
    if(CAP.canViewCrossDeps){const ds=document.getElementById('depSearch');if(ds){ds.value='';delete ds.dataset.wired;}renderDepSelList();}
    if(mode==='edit'&&m){
        document.getElementById('fName').value=m.name;
        document.getElementById('fProj').value=m.project;
        document.getElementById('fZoneSl').value=m.zone;
        document.getElementById('fTarget').value=m.targetDate;
        document.getElementById('fCompletion').value=m.completionDate||'';
        document.getElementById('fProgress').value=m.progress;
        document.getElementById('fProgVal').textContent=m.progress+'%';
        document.getElementById('fStatusSl').value=['Overdue','Force Completed'].includes(m.status)?'In Progress':m.status;
        document.getElementById('fNotes').value=m.notes||'';
    } else {
        ['fName','fNotes'].forEach(id=>document.getElementById(id).value='');
        const cpi=document.getElementById('csProjSearch');if(cpi){cpi.value='';delete cpi.dataset.wired;}
        document.getElementById('fProj').value='';
        document.getElementById('fTarget').value=''; document.getElementById('fCompletion').value='';
        document.getElementById('fProgress').value=0; document.getElementById('fProgVal').textContent='0%';
        document.getElementById('fStatusSl').value='Pending';
    }
    const _cpi=document.getElementById('csProjSearch');if(_cpi) delete _cpi.dataset.wired;
    document.getElementById('msSlider').classList.add('on');
    document.getElementById('slOverlay').classList.add('on');
    setTimeout(()=>{wireProjSearch();if(CAP.canViewCrossDeps)wireDepSearch();document.getElementById('fName').focus();},100);
}
function openEdit(msId){if(!CAP.canEdit)return;const m=MS.find(x=>x.milestoneId===msId);if(m)openSlider('edit',m);}
function closeSlider(){document.getElementById('msSlider').classList.remove('on');document.getElementById('slOverlay').classList.remove('on');editId=null;}

if(CAP.canAdd||CAP.canEdit){
    document.getElementById('slOverlay').addEventListener('click',function(e){if(e.target===this)closeSlider();});
    document.getElementById('slClose').addEventListener('click',closeSlider);
    document.getElementById('slCancel').addEventListener('click',closeSlider);
}
if(CAP.canAdd){
    document.getElementById('createBtn').addEventListener('click',()=>openSlider('create'));
}
if(CAP.canAdd||CAP.canEdit){
    document.getElementById('slSubmit').addEventListener('click',async()=>{
        const btn=document.getElementById('slSubmit'); btn.disabled=true;
        try{
            const name=document.getElementById('fName').value.trim();
            const project=document.getElementById('fProj').value;
            const zone=document.getElementById('fZoneSl').value;
            const targetDate=document.getElementById('fTarget').value;
            const compDate=document.getElementById('fCompletion').value||null;
            const progress=parseInt(document.getElementById('fProgress').value)||0;
            const status=document.getElementById('fStatusSl').value;
            const notes=document.getElementById('fNotes').value.trim();
            if(!name){shk('fName');toast('Milestone name is required','w');return;}
            if(!project){shk('csProjSearch');toast('Please select a project','w');return;}
            if(!zone){shk('fZoneSl');toast('Please select a zone','w');return;}
            if(!targetDate){shk('fTarget');toast('Target date is required','w');return;}
            const m=editId?MS.find(x=>x.milestoneId===editId):null;
            const payload={name,project,zone,targetDate,completionDate:compDate,progress,status,notes,deps:selectedDeps.slice()};
            if(m) payload.id=m.id;
            const saved=await apiPost(API+'?api=save',payload);
            const idx=MS.findIndex(x=>x.id===saved.id);
            if(idx>-1) MS[idx]=saved; else{MS.unshift(saved);page=1;}
            toast(`${saved.milestoneId} ${m?'updated':'created'}.`,'s');
            closeSlider();
            if(currentView==='list') renderList(); else{renderStats();buildDropdowns();renderGantt();}
        }catch(e){toast(e.message,'d');}
        finally{btn.disabled=false;}
    });
}

// ── EXPORT ────────────────────────────────────────────────────────────────────
if(CAP.canExport){
    document.getElementById('exportBtn').addEventListener('click',()=>{
        const cols=RANK===1?['milestoneId','name','targetDate','status','progress']:['milestoneId','project','name','targetDate','completionDate','progress','status','zone'];
        const hdrs=RANK===1?['Milestone ID','Milestone Name','Target Date','Status','Progress %']:['Milestone ID','Project','Milestone Name','Target Date','Completion Date','Progress %','Status','Zone'];
        const rows=[hdrs.join(','),...getFiltered().map(m=>cols.map(c=>`"${String(m[c]??'').replace(/"/g,'""')}"`).join(','))];
        const a=document.createElement('a');
        a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
        a.download='milestones.csv'; a.click(); toast('CSV exported.','s');
    });
}

// ── UTILS ─────────────────────────────────────────────────────────────────────
function shk(id){const el=document.getElementById(id);if(!el)return;el.style.borderColor='var(--red)';el.style.animation='none';el.offsetHeight;el.style.animation='SHK .3s ease';setTimeout(()=>{el.style.borderColor='';el.style.animation='';},600);}
function toast(msg,type='s'){const ic={s:'bx-check-circle',w:'bx-error',d:'bx-error-circle'};const el=document.createElement('div');el.className=`toast t${type}`;el.innerHTML=`<i class="bx ${ic[type]}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;document.getElementById('toastWrap').appendChild(el);setTimeout(()=>{el.classList.add('out');setTimeout(()=>el.remove(),320);},3500);}

// ── INIT ──────────────────────────────────────────────────────────────────────
loadAll();
</script>
</body>
</html>