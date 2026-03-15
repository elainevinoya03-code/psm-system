<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE RESOLUTION (mirrors superadmin_sidebar.php) ─────────────────────────
function dr_resolve_role(): string {
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

$roleName = dr_resolve_role();
$roleRank = match($roleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,   // Staff / User
};

$userZone   = $_SESSION['zone']    ?? '';
$userId     = $_SESSION['user_id'] ?? null;
$userFullName = $_SESSION['full_name'] ?? ($userId ?? 'User');

// ── ROLE CAPABILITY FLAGS ─────────────────────────────────────────────────────
$cap = [
    // ── Visibility ────────────────────────────────────────────────────────────
    'canViewAllZones'        => $roleRank >= 4,   // SA: see everything
    'canViewZone'            => $roleRank >= 3,   // Admin: own zone only
    'canViewTeam'            => $roleRank >= 2,   // Manager: team docs
    'canViewOwn'             => $roleRank >= 1,   // Staff: own tasks only

    // ── Tabs ──────────────────────────────────────────────────────────────────
    'tabOverride'            => $roleRank >= 4,   // SA only
    'tabTransit'             => $roleRank >= 3,   // Admin + SA
    'tabTeam'                => $roleRank === 2,  // Manager only
    'tabMyTasks'             => $roleRank === 1,  // Staff only

    // ── Route statuses visible ────────────────────────────────────────────────
    'statusAll'              => $roleRank >= 4,   // SA: all
    'statusCompleted'        => $roleRank >= 3,   // Admin + SA see Completed
    'statusReturned'         => $roleRank >= 2,   // Manager + above see Returned

    // ── Routing form / creation ───────────────────────────────────────────────
    'canCreate'              => $roleRank >= 3,   // Admin + SA
    'canCreateOwn'           => $roleRank === 1,  // Staff: create own-doc routes (limited)
    'canEdit'                => $roleRank >= 3,   // Admin + SA

    // ── Cross-scope routing ───────────────────────────────────────────────────
    'canCrossZone'           => $roleRank >= 4,   // SA only
    'canCrossTeam'           => $roleRank >= 3,   // Admin + SA (zone-to-zone)
    'canCrossModule'         => $roleRank >= 3,   // Admin: PSM, SWS, ALMS, PLT cross-module

    // ── Actions ───────────────────────────────────────────────────────────────
    'canOverride'            => $roleRank >= 4,   // SA: force reroute
    'canOverrideCompleted'   => $roleRank >= 4,   // SA only
    'canUpdateStatus'        => $roleRank >= 2,   // Manager + above
    'canReceive'             => $roleRank >= 1,   // Staff: receive doc
    'canForward'             => $roleRank === 1,  // Staff: forward within team
    'canReturn'              => $roleRank >= 1,   // Staff: return doc
    'canReassign'            => $roleRank === 2,  // Manager: reassign within team
    'canFlagDelay'           => $roleRank === 2,  // Manager: flag delays
    'canScanQR'              => $roleRank === 1,  // Staff: QR scan action
    'canEmergencyEscalate'   => $roleRank >= 4,   // SA only (spec: "Emergency escalation" = SA only)

    // ── History / Audit ───────────────────────────────────────────────────────
    'canViewFullHistory'     => $roleRank >= 4,   // SA: full history
    'canViewRouteHistory'    => $roleRank >= 3,   // Admin: route-level history
    'canViewAuditAll'        => $roleRank >= 4,   // SA: system-wide audit log
    'canViewAuditRoute'      => $roleRank >= 3,   // Admin: single-route audit

    // ── Export ────────────────────────────────────────────────────────────────
    'canExport'              => $roleRank >= 3,   // Admin + SA
];

// ── HELPERS ──────────────────────────────────────────────────────────────────
function dr_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function dr_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function dr_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function dr_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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
function dr_fetch(string $url): array {
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
function dr_build(array $row): array {
    return [
        'id'             => (int)$row['id'],
        'routeId'        => $row['route_id']       ?? '',
        'docId'          => $row['doc_id']          ?? '',
        'docDbId'        => $row['doc_db_id']       ?? null,
        'docName'        => $row['doc_name']        ?? '',
        'docType'        => $row['doc_type']        ?? '',
        'from'           => $row['from_dept']       ?? '',
        'to'             => $row['to_dept']         ?? '',
        'assignee'       => $row['assignee']        ?? '',
        'assigneeId'     => $row['assignee_id']     ?? null,
        'routeType'      => $row['route_type']      ?? 'For Review',
        'priority'       => $row['priority']        ?? 'Normal',
        'dueDate'        => $row['due_date']        ?? null,
        'dateRouted'     => $row['date_routed']     ?? '',
        'status'         => $row['status']          ?? 'In Transit',
        'module'         => $row['module']          ?? '',
        'notes'          => $row['notes']           ?? '',
        'zone'           => $row['zone']            ?? '',
        'teamId'         => $row['team_id']         ?? null,
        'isOverridden'   => (bool)($row['is_overridden']  ?? false),
        'overrideReason' => $row['override_reason'] ?? null,
        'overriddenBy'   => $row['overridden_by']   ?? null,
        'overriddenAt'   => $row['overridden_at']   ?? null,
        'createdBy'      => $row['created_by']      ?? '',
        'createdAt'      => $row['created_at']      ?? '',
        'updatedAt'      => $row['updated_at']      ?? '',
    ];
}
function dr_next_id(): string {
    $year = date('Y');
    $rows = dr_sb('dtrs_routes', 'GET', [
        'select'   => 'route_id',
        'route_id' => 'like.RT-' . $year . '-%',
        'order'    => 'id.desc',
        'limit'    => '1',
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/RT-\d{4}-(\d+)/', $rows[0]['route_id'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return 'RT-' . $year . '-' . sprintf('%04d', $next);
}

// ── SCOPE HELPER — builds row-level filters per role ─────────────────────────
function dr_scope_query(array &$query): void {
    global $roleRank, $userZone, $userId;
    if ($roleRank >= 4) return; // SA: no scope restriction
    if ($roleRank === 3 && $userZone) {
        // Admin: own zone only
        $query['zone'] = 'eq.' . $userZone;
    } elseif ($roleRank === 2) {
        // Manager: team documents only — filter by assignee team OR zone
        if ($userZone) $query['zone'] = 'eq.' . $userZone;
    } elseif ($roleRank === 1 && $userId) {
        // Staff: only rows where assignee_id = userId
        $query['assignee_id'] = 'eq.' . $userId;
    }
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    global $roleRank, $cap, $userZone, $userId, $userFullName, $roleName;
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $userFullName;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
    $isSa   = $roleRank >= 4;

    try {

        // ── GET stats ─────────────────────────────────────────────────────────
        if ($api === 'stats' && $method === 'GET') {
            $query = ['select' => 'id,status'];
            dr_scope_query($query);
            $rows = dr_sb('dtrs_routes', 'GET', $query);
            $counts = ['total' => count($rows), 'transit' => 0, 'received' => 0, 'returned' => 0, 'completed' => 0];
            foreach ($rows as $r) {
                switch ($r['status']) {
                    case 'In Transit': $counts['transit']++;   break;
                    case 'Received':   $counts['received']++;  break;
                    case 'Returned':   $counts['returned']++;  break;
                    case 'Completed':  $counts['completed']++; break;
                }
            }
            // Staff: expose only own count, no Completed/Returned totals
            if ($roleRank === 1) {
                unset($counts['returned'], $counts['completed']);
            }
            dr_ok($counts);
        }

        // ── GET list ──────────────────────────────────────────────────────────
        if ($api === 'list' && $method === 'GET') {
            $page    = max(1, (int)($_GET['page']   ?? 1));
            $perPage = max(1, min(50, (int)($_GET['per'] ?? 8)));
            $search  = trim($_GET['q']      ?? '');
            $status  = trim($_GET['status'] ?? '');
            $rtype   = trim($_GET['rtype']  ?? '');
            $dept    = trim($_GET['dept']   ?? '');

            // RBAC: Staff cannot filter by Completed/Returned — strip it
            if ($roleRank === 1 && in_array($status, ['Completed', 'Returned'], true)) {
                $status = '';
            }

            $parts = ['select=*', 'order=created_at.desc'];

            // Scope by role
            if ($roleRank === 3 && $userZone) $parts[] = 'zone=eq.' . urlencode($userZone);
            if ($roleRank === 2 && $userZone) $parts[] = 'zone=eq.' . urlencode($userZone);
            if ($roleRank === 1 && $userId)   $parts[] = 'assignee_id=eq.' . urlencode($userId);

            if ($status) $parts[] = 'status=eq.'     . urlencode($status);
            if ($rtype)  $parts[] = 'route_type=eq.' . urlencode($rtype);
            // Dept filter: Admin/SA only (staff has no dept filter)
            if ($dept && $roleRank >= 3) $parts[] = 'or=' . urlencode("(from_dept.eq.{$dept},to_dept.eq.{$dept})");
            if ($search) $parts[] = 'or=' . urlencode("(doc_id.ilike.*{$search}*,doc_name.ilike.*{$search}*,from_dept.ilike.*{$search}*,to_dept.ilike.*{$search}*,assignee.ilike.*{$search}*)");

            $url   = SUPABASE_URL . '/rest/v1/dtrs_routes?' . implode('&', $parts);
            $rows  = dr_fetch($url);

            // Staff: strip full history info from DTO — just show own fields
            if ($roleRank === 1) {
                $rows = array_filter($rows, fn($r) => ($r['assignee_id'] ?? null) == $userId);
            }

            $total  = count($rows);
            $offset = ($page - 1) * $perPage;
            $slice  = array_slice(array_values($rows), $offset, $perPage);

            dr_ok([
                'items'   => array_values(array_map('dr_build', $slice)),
                'total'   => $total,
                'page'    => $page,
                'perPage' => $perPage,
                'pages'   => max(1, (int)ceil($total / $perPage)),
            ]);
        }

        // ── GET transit ───────────────────────────────────────────────────────
        if ($api === 'transit' && $method === 'GET') {
            if ($roleRank < 3) dr_err('Insufficient permissions', 403);
            $query = ['select' => '*', 'status' => 'eq.In Transit', 'order' => 'created_at.desc'];
            if ($roleRank === 3 && $userZone) $query['zone'] = 'eq.' . $userZone;
            $rows = dr_sb('dtrs_routes', 'GET', $query);
            dr_ok(array_values(array_map('dr_build', $rows)));
        }

        // ── GET team-routes (Manager) ─────────────────────────────────────────
        if ($api === 'team-routes' && $method === 'GET') {
            if ($roleRank !== 2) dr_err('Insufficient permissions', 403);
            $query = ['select' => '*', 'order' => 'created_at.desc'];
            if ($userZone) $query['zone'] = 'eq.' . $userZone;
            $rows = dr_sb('dtrs_routes', 'GET', $query);
            dr_ok(array_values(array_map('dr_build', $rows)));
        }

        // ── GET my-tasks (Staff) ──────────────────────────────────────────────
        if ($api === 'my-tasks' && $method === 'GET') {
            if ($roleRank !== 1) dr_err('Insufficient permissions', 403);
            if (!$userId) dr_err('Not authenticated', 401);
            $rows = dr_sb('dtrs_routes', 'GET', [
                'select'      => '*',
                'assignee_id' => 'eq.' . $userId,
                'status'      => 'neq.Completed',
                'order'       => 'created_at.desc',
            ]);
            dr_ok(array_values(array_map('dr_build', $rows)));
        }

        // ── GET override-queue (SA) ───────────────────────────────────────────
        if ($api === 'override-queue' && $method === 'GET') {
            if (!$cap['canOverride']) dr_err('Insufficient permissions', 403);
            $url  = SUPABASE_URL . '/rest/v1/dtrs_routes?select=*';
            // SA can override completed too; others cannot
            if (!$cap['canOverrideCompleted']) $url .= '&status=neq.Completed';
            $url .= '&order=created_at.desc';
            $rows = dr_fetch($url);
            dr_ok(array_values(array_map('dr_build', $rows)));
        }

        // ── GET history ───────────────────────────────────────────────────────
        if ($api === 'history' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) dr_err('Missing id', 400);
            // Staff: only their own routes
            if ($roleRank === 1) {
                $check = dr_sb('dtrs_routes', 'GET', ['select' => 'assignee_id', 'id' => 'eq.' . $id, 'limit' => '1']);
                if (empty($check) || ($check[0]['assignee_id'] ?? null) != $userId) dr_err('Access denied', 403);
            }
            $rows = dr_sb('dtrs_route_history', 'GET', [
                'select'   => '*',
                'route_id' => 'eq.' . $id,
                'order'    => 'occurred_at.asc',
            ]);
            // Staff: only show own history steps (no SA/admin internal entries)
            if ($roleRank === 1) {
                $rows = array_filter($rows, fn($r) => !str_contains($r['role_label'] ?? '', 'SA Override'));
            }
            dr_ok(array_values($rows));
        }

        // ── GET audit (single route) ──────────────────────────────────────────
        if ($api === 'audit' && $method === 'GET') {
            if (!$cap['canViewAuditRoute']) dr_err('Insufficient permissions', 403);
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) dr_err('Missing id', 400);
            $rows = dr_sb('dtrs_route_audit', 'GET', [
                'select'   => '*',
                'route_id' => 'eq.' . $id,
                'order'    => 'occurred_at.desc',
            ]);
            dr_ok($rows);
        }

        // ── GET audit-all ─────────────────────────────────────────────────────
        if ($api === 'audit-all' && $method === 'GET') {
            if (!$cap['canViewAuditAll']) dr_err('Insufficient permissions', 403);
            $rows = dr_sb('dtrs_route_audit', 'GET', [
                'select' => 'id,action_label,actor_name,actor_role,dot_class,is_super_admin,ip_address,occurred_at,route_id',
                'order'  => 'occurred_at.desc',
                'limit'  => '200',
            ]);
            dr_ok($rows);
        }

        // ── GET staff ─────────────────────────────────────────────────────────
        if ($api === 'staff' && $method === 'GET') {
            $query = ['select' => 'user_id,first_name,last_name,zone', 'status' => 'eq.Active', 'order' => 'first_name.asc'];
            // Manager/Staff: only own zone staff
            if ($roleRank < 4 && $userZone) $query['zone'] = 'eq.' . $userZone;
            $rows = dr_sb('users', 'GET', $query);
            $staff = array_map(fn($r) => [
                'id'   => $r['user_id'],
                'name' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            ], $rows);
            dr_ok(array_values(array_filter($staff, fn($s) => $s['name'] !== '')));
        }

        // ── GET docs-search ───────────────────────────────────────────────────
        if ($api === 'docs-search' && $method === 'GET') {
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 1) { dr_ok([]); exit; }
            $url = SUPABASE_URL . '/rest/v1/dtrs_documents?select=id,doc_id,title,doc_type,department,direction,sender,recipient,status,zone&or='
                . urlencode("(doc_id.ilike.*{$q}*,title.ilike.*{$q}*)")
                . '&status=neq.Archived&order=created_at.desc&limit=20';
            // Admin: scope to own zone
            if ($roleRank === 3 && $userZone) {
                $url .= '&zone=eq.' . urlencode($userZone);
            }
            // Staff: scope to own docs
            if ($roleRank === 1 && $userId) {
                $url .= '&created_by_id=eq.' . urlencode($userId);
            }
            $rows = dr_fetch($url);
            $results = array_map(fn($r) => [
                'id'         => (int)$r['id'],
                'docId'      => $r['doc_id']     ?? '',
                'title'      => $r['title']      ?? '',
                'docType'    => $r['doc_type']   ?? '',
                'department' => $r['department'] ?? '',
                'direction'  => $r['direction']  ?? '',
                'sender'     => $r['sender']     ?? '',
                'recipient'  => $r['recipient']  ?? '',
                'status'     => $r['status']     ?? '',
                'zone'       => $r['zone']       ?? '',
            ], $rows);
            dr_ok(array_values($results));
        }

        // ── POST create ───────────────────────────────────────────────────────
        if ($api === 'create' && $method === 'POST') {
            if (!$cap['canCreate'] && !$cap['canCreateOwn']) dr_err('Insufficient permissions', 403);
            $b = dr_body();

            $docName   = trim($b['docName']   ?? '');
            $docId     = trim($b['docId']     ?? '');
            $docType   = trim($b['docType']   ?? '');
            $fromDept  = trim($b['from']      ?? '');
            $toDept    = trim($b['to']        ?? '');
            $assignee  = trim($b['assignee']  ?? '');
            $routeType = trim($b['routeType'] ?? '');
            $priority  = trim($b['priority']  ?? 'Normal');
            $dueDate   = trim($b['dueDate']   ?? '') ?: null;
            $notes     = trim($b['notes']     ?? '');

            if (!$docName)   dr_err('Document name is required', 400);
            if (!$fromDept)  dr_err('Originating department is required', 400);
            if (!$toDept)    dr_err('Destination department is required', 400);
            if (!$assignee)  dr_err('Assignee is required', 400);
            if (!$routeType) dr_err('Route type is required', 400);

            // Staff: only Action/Review/Signature, no Filing
            $allowedTypes = $roleRank === 1
                ? ['For Action', 'For Review', 'For Signature']
                : ['For Action', 'For Review', 'For Signature', 'For Filing'];
            if (!in_array($routeType, $allowedTypes, true)) dr_err('Invalid or unauthorized route type', 400);

            // Cross-zone guard: Admin cannot route outside own zone
            if ($roleRank === 3 && $userZone) {
                $routeZone = $b['zone'] ?? $userZone;
                if ($routeZone !== $userZone) dr_err('Cross-zone routing is not permitted for your role', 403);
            }

            $routeId = dr_next_id();
            $now     = date('Y-m-d H:i:s');

            $docDbId = null;
            if ($docId) {
                $linked = dr_sb('dtrs_documents', 'GET', ['select' => 'id', 'doc_id' => 'eq.' . $docId, 'limit' => '1']);
                if (!empty($linked)) $docDbId = (int)$linked[0]['id'];
            }
            if (!$docId) $docId = $routeId;

            $payload = [
                'route_id'        => $routeId,
                'doc_id'          => $docId,
                'doc_db_id'       => $docDbId,
                'doc_name'        => $docName,
                'doc_type'        => $docType,
                'from_dept'       => $fromDept,
                'to_dept'         => $toDept,
                'assignee'        => $assignee,
                'route_type'      => $routeType,
                'priority'        => $priority,
                'due_date'        => $dueDate,
                'date_routed'     => date('Y-m-d'),
                'status'          => 'In Transit',
                'module'          => $fromDept,
                'notes'           => $notes,
                'zone'            => $b['zone'] ?? $userZone,
                'created_by'      => $actor,
                'created_user_id' => $userId,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];

            $inserted = dr_sb('dtrs_routes', 'POST', [], [$payload]);
            if (empty($inserted)) dr_err('Failed to create route', 500);
            $newId = (int)$inserted[0]['id'];

            dr_sb('dtrs_route_history', 'POST', [], [
                ['route_id' => $newId, 'role_label' => "Originated — {$fromDept}", 'actor_name' => $actor,
                 'step_type' => 'rtd-done', 'icon' => 'bx-check',
                 'note' => "Document routed for {$routeType}.", 'occurred_at' => $now],
                ['route_id' => $newId, 'role_label' => "In Transit → {$toDept}", 'actor_name' => 'System',
                 'step_type' => 'rtd-current', 'icon' => 'bx-time',
                 'note' => "Awaiting {$assignee}.", 'occurred_at' => date('Y-m-d H:i:s', strtotime('+1 second'))],
            ]);

            dr_sb('dtrs_route_audit', 'POST', [], [[
                'route_id'      => $newId,
                'action_label'  => "Document routed from {$fromDept} to {$toDept} — {$routeType}",
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'dot_class'     => 'dot-b',
                'is_super_admin'=> $isSa,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            $rows = dr_sb('dtrs_routes', 'GET', ['id' => 'eq.' . $newId, 'select' => '*', 'limit' => '1']);
            dr_ok(dr_build($rows[0]));
        }

        // ── POST update ───────────────────────────────────────────────────────
        if ($api === 'update' && $method === 'POST') {
            if (!$cap['canEdit']) dr_err('Insufficient permissions', 403);
            $b  = dr_body();
            $id = (int)($b['id'] ?? 0);
            if (!$id) dr_err('Missing id', 400);

            // Admin: verify route belongs to their zone
            if ($roleRank === 3 && $userZone) {
                $chk = dr_sb('dtrs_routes', 'GET', ['select' => 'zone', 'id' => 'eq.' . $id, 'limit' => '1']);
                if (!empty($chk) && ($chk[0]['zone'] ?? '') !== $userZone) dr_err('Access denied — cross-zone', 403);
            }

            $now = date('Y-m-d H:i:s');
            dr_sb('dtrs_routes', 'PATCH', ['id' => 'eq.' . $id], [
                'doc_name'   => trim($b['docName']   ?? ''),
                'doc_type'   => trim($b['docType']   ?? ''),
                'from_dept'  => trim($b['from']      ?? ''),
                'to_dept'    => trim($b['to']        ?? ''),
                'assignee'   => trim($b['assignee']  ?? ''),
                'route_type' => trim($b['routeType'] ?? ''),
                'priority'   => trim($b['priority']  ?? 'Normal'),
                'due_date'   => trim($b['dueDate']   ?? '') ?: null,
                'notes'      => trim($b['notes']     ?? ''),
                'updated_at' => $now,
            ]);
            dr_sb('dtrs_route_audit', 'POST', [], [[
                'route_id'      => $id,
                'action_label'  => 'Route details updated',
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'dot_class'     => 'dot-b',
                'is_super_admin'=> $isSa,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);
            dr_ok(['updated' => true]);
        }

        // ── POST update-status ────────────────────────────────────────────────
        if ($api === 'update-status' && $method === 'POST') {
            if (!$cap['canUpdateStatus'] && !$cap['canReceive']) dr_err('Insufficient permissions', 403);
            $b       = dr_body();
            $id      = (int)($b['id']      ?? 0);
            $status  = trim($b['status']   ?? '');
            $remarks = trim($b['remarks']  ?? '');
            if (!$id)     dr_err('Missing id', 400);
            if (!$status) dr_err('Status is required', 400);

            // Staff: can only Receive, Forward (→ In Transit), Return
            $allowedStatuses = $roleRank === 1
                ? ['In Transit', 'Received', 'Returned']
                : ['In Transit', 'Received', 'Returned', 'Completed'];
            if (!in_array($status, $allowedStatuses, true)) dr_err('Status not permitted for your role', 403);

            // Staff: verify this is their assigned route
            if ($roleRank === 1) {
                $check = dr_sb('dtrs_routes', 'GET', ['select' => 'assignee_id', 'id' => 'eq.' . $id, 'limit' => '1']);
                if (empty($check) || ($check[0]['assignee_id'] ?? null) != $userId) dr_err('Access denied', 403);
            }
            // Manager: verify zone scope
            if ($roleRank === 2 && $userZone) {
                $check = dr_sb('dtrs_routes', 'GET', ['select' => 'zone', 'id' => 'eq.' . $id, 'limit' => '1']);
                if (!empty($check) && ($check[0]['zone'] ?? '') !== $userZone) dr_err('Access denied — cross-zone', 403);
            }

            $routes = dr_sb('dtrs_routes', 'GET', ['id' => 'eq.' . $id, 'select' => 'to_dept,from_dept,assignee', 'limit' => '1']);
            if (empty($routes)) dr_err('Route not found', 404);
            $route = $routes[0];

            $now = date('Y-m-d H:i:s');
            dr_sb('dtrs_routes', 'PATCH', ['id' => 'eq.' . $id], ['status' => $status, 'updated_at' => $now]);

            $stepMap = [
                'In Transit' => ['rtd-current', 'bx-time',         "In Transit → {$route['to_dept']}"],
                'Received'   => ['rtd-done',    'bx-check',        "Received — {$route['to_dept']}"],
                'Returned'   => ['rtd-return',  'bx-undo',         "Returned to {$route['from_dept']}"],
                'Completed'  => ['rtd-done',    'bx-check-double', 'Route Completed'],
            ];
            [$stepType, $icon, $roleLabel] = $stepMap[$status];
            $dotMap = ['In Transit' => 'dot-b', 'Received' => 'dot-g', 'Returned' => 'dot-o', 'Completed' => 'dot-g'];

            dr_sb('dtrs_route_history', 'POST', [], [[
                'route_id'    => $id,
                'role_label'  => $roleLabel,
                'actor_name'  => $actor,
                'step_type'   => $stepType,
                'icon'        => $icon,
                'note'        => $remarks,
                'occurred_at' => $now,
            ]]);
            dr_sb('dtrs_route_audit', 'POST', [], [[
                'route_id'      => $id,
                'action_label'  => "Status updated to {$status}" . ($remarks ? " — {$remarks}" : ''),
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'dot_class'     => $dotMap[$status],
                'is_super_admin'=> $isSa,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);
            dr_ok(['updated' => true, 'status' => $status]);
        }

        // ── POST reassign (Manager) ───────────────────────────────────────────
        if ($api === 'reassign' && $method === 'POST') {
            if (!$cap['canReassign']) dr_err('Insufficient permissions', 403);
            $b         = dr_body();
            $id        = (int)($b['id']       ?? 0);
            $assignee  = trim($b['assignee']  ?? '');
            $remarks   = trim($b['remarks']   ?? '');
            if (!$id || !$assignee) dr_err('Missing id or assignee', 400);

            // Scope: must be within manager's zone
            if ($userZone) {
                $chk = dr_sb('dtrs_routes', 'GET', ['select' => 'zone', 'id' => 'eq.' . $id, 'limit' => '1']);
                if (!empty($chk) && ($chk[0]['zone'] ?? '') !== $userZone) dr_err('Access denied — cross-zone', 403);
            }

            $now = date('Y-m-d H:i:s');
            dr_sb('dtrs_routes', 'PATCH', ['id' => 'eq.' . $id], ['assignee' => $assignee, 'updated_at' => $now]);
            dr_sb('dtrs_route_audit', 'POST', [], [[
                'route_id'      => $id,
                'action_label'  => "Reassigned to {$assignee} by Manager" . ($remarks ? ": {$remarks}" : ''),
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'dot_class'     => 'dot-b',
                'is_super_admin'=> false,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);
            dr_ok(['reassigned' => true]);
        }

        // ── POST flag-delay (Manager) ─────────────────────────────────────────
        if ($api === 'flag-delay' && $method === 'POST') {
            if (!$cap['canFlagDelay']) dr_err('Insufficient permissions', 403);
            $b      = dr_body();
            $id     = (int)($b['id']   ?? 0);
            $reason = trim($b['reason'] ?? '');
            if (!$id)     dr_err('Missing id', 400);
            if (!$reason) dr_err('Reason required', 400);

            // Scope: must be within manager's zone
            if ($userZone) {
                $chk = dr_sb('dtrs_routes', 'GET', ['select' => 'zone', 'id' => 'eq.' . $id, 'limit' => '1']);
                if (!empty($chk) && ($chk[0]['zone'] ?? '') !== $userZone) dr_err('Access denied — cross-zone', 403);
            }

            $now = date('Y-m-d H:i:s');
            dr_sb('dtrs_route_audit', 'POST', [], [[
                'route_id'      => $id,
                'action_label'  => "Routing delay flagged by Manager: {$reason}",
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'dot_class'     => 'dot-o',
                'is_super_admin'=> false,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);
            dr_ok(['flagged' => true]);
        }

        // ── POST forward (Staff — forward to next person within team) ─────────
        if ($api === 'forward' && $method === 'POST') {
            if (!$cap['canForward']) dr_err('Insufficient permissions', 403);
            $b        = dr_body();
            $id       = (int)($b['id']       ?? 0);
            $assignee = trim($b['assignee']  ?? '');
            $toDept   = trim($b['toDept']    ?? '');
            $remarks  = trim($b['remarks']   ?? '');
            if (!$id || !$assignee) dr_err('Missing id or assignee', 400);

            // Staff: can only forward within own zone/team
            $chk = dr_sb('dtrs_routes', 'GET', ['select' => 'assignee_id,zone', 'id' => 'eq.' . $id, 'limit' => '1']);
            if (empty($chk) || ($chk[0]['assignee_id'] ?? null) != $userId) dr_err('Access denied', 403);
            if ($userZone && ($chk[0]['zone'] ?? '') !== $userZone) dr_err('Cross-team forwarding not permitted', 403);

            $now = date('Y-m-d H:i:s');
            $patch = ['assignee' => $assignee, 'status' => 'In Transit', 'updated_at' => $now];
            if ($toDept) $patch['to_dept'] = $toDept;
            dr_sb('dtrs_routes', 'PATCH', ['id' => 'eq.' . $id], $patch);

            dr_sb('dtrs_route_history', 'POST', [], [[
                'route_id'    => $id,
                'role_label'  => "Forwarded to {$assignee}" . ($toDept ? " — {$toDept}" : ''),
                'actor_name'  => $actor,
                'step_type'   => 'rtd-current',
                'icon'        => 'bx-right-arrow-alt',
                'note'        => $remarks,
                'occurred_at' => $now,
            ]]);
            dr_sb('dtrs_route_audit', 'POST', [], [[
                'route_id'      => $id,
                'action_label'  => "Forwarded to {$assignee} by Staff",
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'dot_class'     => 'dot-b',
                'is_super_admin'=> false,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);
            dr_ok(['forwarded' => true]);
        }

        // ── POST override (SA) ────────────────────────────────────────────────
        if ($api === 'override' && $method === 'POST') {
            if (!$cap['canOverride']) dr_err('Insufficient permissions', 403);
            $b      = dr_body();
            $id     = (int)($b['id']     ?? 0);
            $dept   = trim($b['dept']    ?? '');
            $person = trim($b['person']  ?? '');
            $reason = trim($b['reason']  ?? '');
            if (!$id)     dr_err('Missing id', 400);
            if (!$dept)   dr_err('Destination department is required', 400);
            if (!$reason) dr_err('Override reason is required', 400);

            $routes = dr_sb('dtrs_routes', 'GET', ['id' => 'eq.' . $id, 'select' => 'to_dept,assignee,route_id,status', 'limit' => '1']);
            if (empty($routes)) dr_err('Route not found', 404);
            $route = $routes[0];
            // SA cannot override Completed unless they have the special flag
            if ($route['status'] === 'Completed' && !$cap['canOverrideCompleted'])
                dr_err('Cannot override a completed route', 403);

            $oldTo = $route['to_dept'];
            $now   = date('Y-m-d H:i:s');
            $patch = ['to_dept' => $dept, 'status' => 'In Transit', 'is_overridden' => true,
                      'override_reason' => $reason, 'overridden_by' => $actor, 'overridden_at' => $now, 'updated_at' => $now];
            if ($person) $patch['assignee'] = $person;

            dr_sb('dtrs_routes', 'PATCH', ['id' => 'eq.' . $id], $patch);
            dr_sb('dtrs_route_history', 'POST', [], [[
                'route_id'    => $id,
                'role_label'  => "SA Override → {$dept}",
                'actor_name'  => $actor,
                'step_type'   => 'rtd-current',
                'icon'        => 'bx-shield-quarter',
                'note'        => $reason,
                'occurred_at' => $now,
            ]]);
            dr_sb('dtrs_route_audit', 'POST', [], [[
                'route_id'      => $id,
                'action_label'  => "SA Override: Rerouted from {$oldTo} to {$dept}. Reason: {$reason}",
                'actor_name'    => $actor,
                'actor_role'    => 'Super Admin',
                'dot_class'     => 'dot-o',
                'is_super_admin'=> true,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);
            dr_ok(['overridden' => true]);
        }

        // ── GET export ────────────────────────────────────────────────────────
        if ($api === 'export' && $method === 'GET') {
            if (!$cap['canExport']) dr_err('Insufficient permissions', 403);
            $query = ['select' => '*', 'order' => 'created_at.desc'];
            if ($roleRank === 3 && $userZone) $query['zone'] = 'eq.' . $userZone;
            $rows = dr_sb('dtrs_routes', 'GET', $query);
            dr_ok(array_values(array_map('dr_build', $rows)));
        }

        dr_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        dr_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── HTML PAGE RENDER ──────────────────────────────────────────────────────────
include $_SERVER['DOCUMENT_ROOT'] . '/includes/superadmin_sidebar.php';
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

$capJson      = json_encode($cap);
$roleNameEsc  = htmlspecialchars($roleName, ENT_QUOTES);
$roleRankJs   = (int)$roleRank;
$userZoneEsc  = htmlspecialchars($userZone, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Document Routing — DTRS</title>
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --primary:#2E7D32;--primary-dark:#1B5E20;--primary-light:#388E3C;
  --surface:#FFFFFF;--bg:#F5F7F5;
  --border:rgba(46,125,50,.14);--border-mid:rgba(46,125,50,.22);
  --text-1:#1A2B1C;--text-2:#5D6F62;--text-3:#9EB0A2;
  --hover-s:rgba(46,125,50,.05);
  --shadow-sm:0 1px 4px rgba(46,125,50,.08);
  --shadow-md:0 4px 16px rgba(46,125,50,.12);
  --shadow-xl:0 20px 60px rgba(0,0,0,.22);
  --danger:#DC2626;--warning:#D97706;--info:#2563EB;
  --gold:#B45309;
  --radius:12px;--tr:all .18s ease;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text-1);}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-thumb{background:rgba(46,125,50,.22);border-radius:4px}

.page{max-width:1600px;margin:0 auto;padding:0 0 3rem}
.po-ph{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:20px;animation:fadeUp .4s both;flex-wrap:wrap;}
.po-ph .eyebrow{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--primary);margin-bottom:2px;}
.po-ph h1{font-size:26px;font-weight:800;color:var(--text-1);line-height:1.15;}
.po-acts{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}

/* ROLE PILL */
.role-pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:3px 10px;border-radius:20px;vertical-align:middle;margin-left:10px;}
.rp-sa{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;}
.rp-ad{background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;}
.rp-mg{background:#F0FDF4;color:#166534;border:1px solid #BBF7D0;}
.rp-st{background:#F3F4F6;color:#374151;border:1px solid #D1D5DB;}

/* BANNERS */
.rb-banner{display:flex;align-items:center;gap:10px;border-radius:12px;padding:10px 16px;font-size:12.5px;margin-bottom:16px;animation:fadeUp .4s both;}
.rb-info{background:#EFF6FF;border:1px solid #BFDBFE;color:#1D4ED8;}
.rb-warn{background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;}
.rb-user{background:#F0FDF4;border:1px solid #BBF7D0;color:#166534;}
.rb-banner i{font-size:18px;flex-shrink:0;}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-p{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3);}
.btn-p:hover{background:var(--primary-dark);transform:translateY(-1px);}
.btn-g{background:var(--surface);color:var(--text-2);border:1px solid var(--border-mid);}
.btn-g:hover{background:var(--hover-s);color:var(--text-1);}
.btn-s{font-size:12px;padding:7px 14px;}
.btn-danger{background:var(--danger);color:#fff;}
.btn-danger:hover{background:#B91C1C;transform:translateY(-1px);}
.btn-warn{background:var(--warning);color:#fff;}
.btn-warn:hover{background:#B45309;transform:translateY(-1px);}
.btn-info{background:var(--info);color:#fff;}
.btn-info:hover{background:#1D4ED8;transform:translateY(-1px);}
.btn-gold{background:var(--gold);color:#fff;}
.btn-gold:hover{background:#92400E;transform:translateY(-1px);}
.btn-grn{background:#D1FAE5;color:#065F46;border:1px solid #A7F3D0;}
.btn-grn:hover{background:#A7F3D0;}
.btn-blue{background:#DBEAFE;color:#1D4ED8;border:1px solid #BFDBFE;}
.btn-blue:hover{background:#BFDBFE;}
.btn:disabled{opacity:.45;pointer-events:none;}

/* STATS */
.dr-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:20px;}
.po-stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:11px 12px;box-shadow:var(--shadow-sm);display:flex;align-items:center;gap:8px;animation:fadeUp .4s both;min-width:0;}
.stat-ic{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.stat-body{min-width:0;flex:1;}
.stat-v{font-size:18px;font-weight:800;line-height:1;}
.stat-l{font-size:10px;color:var(--text-2);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ic-g{background:#E8F5E9;color:var(--primary);}.ic-o{background:#FEF3C7;color:var(--warning);}
.ic-r{background:#FEE2E2;color:var(--danger);}.ic-b{background:#EFF6FF;color:var(--info);}
.ic-gy{background:#F3F4F6;color:#6B7280;}

/* TABS */
.nav-bar{display:flex;align-items:center;gap:3px;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:5px;margin-bottom:10px;width:fit-content;animation:fadeUp .4s .05s both;}
.tab-btn{font-family:'Inter',sans-serif;font-size:12px;font-weight:600;padding:7px 14px;border-radius:9px;border:none;cursor:pointer;transition:var(--tr);color:var(--text-2);background:transparent;display:flex;align-items:center;gap:6px;white-space:nowrap;}
.tab-btn.active{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3);}
.tab-btn:not(.active):hover{background:var(--hover-s);color:var(--text-1);}
.tab-panel{display:none;}.tab-panel.active{display:block;}

/* FILTER BAR */
.filter-bar{display:flex;align-items:center;gap:6px;margin-bottom:14px;animation:fadeUp .4s .12s both;flex-wrap:wrap;row-gap:6px;}
.sw{position:relative;flex:0 0 220px;}
.sw i{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--text-3);pointer-events:none;}
.sinput{width:100%;padding:7px 10px 7px 30px;font-family:'Inter',sans-serif;font-size:12px;border:1px solid var(--border-mid);border-radius:9px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);height:34px;}
.sinput:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12);}
.sinput::placeholder{color:var(--text-3);}
.fsel{font-family:'Inter',sans-serif;font-size:12px;padding:0 26px 0 10px;border:1px solid var(--border-mid);border-radius:9px;background:var(--surface);color:var(--text-1);cursor:pointer;outline:none;transition:var(--tr);appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;flex-shrink:0;height:34px;}
.fsel:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12);}
.clear-filters{font-size:11px;font-weight:600;color:var(--text-3);background:none;border:1px solid var(--border-mid);cursor:pointer;padding:0 12px;border-radius:9px;transition:var(--tr);white-space:nowrap;display:flex;align-items:center;gap:4px;flex-shrink:0;height:34px;}
.clear-filters:hover{color:var(--danger);background:#FEE2E2;border-color:#FECACA;}

/* TABLE CARD */
.po-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-md);animation:fadeUp .4s .15s both;}
.po-table-wrap{overflow-x:auto;}
.po-table{width:100%;min-width:780px;border-collapse:collapse;font-size:12px;}
.po-table thead th{font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-2);padding:10px 12px;text-align:left;background:var(--bg);border-bottom:1px solid var(--border);white-space:nowrap;}
.po-table thead th:first-child{padding-left:16px;}
.po-table tbody tr{border-bottom:1px solid var(--border);transition:background .15s;cursor:pointer;}
.po-table tbody tr:last-child{border-bottom:none;}.po-table tbody tr:hover{background:var(--hover-s);}
.po-table tbody td{padding:11px 12px;vertical-align:middle;}
.po-table tbody td:first-child{padding-left:16px;}
.po-table tbody td:last-child{white-space:nowrap;}
.po-card-ft{padding:12px 20px;border-top:1px solid var(--border);background:linear-gradient(135deg,rgba(46,125,50,.03),var(--bg));display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.ft-info{font-size:12px;color:var(--text-2);}
.pbtns{display:flex;gap:5px;}
.pb{width:30px;height:30px;border-radius:7px;border:1px solid var(--border-mid);background:var(--surface);font-family:'Inter',sans-serif;font-size:12px;font-weight:500;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--text-1);}
.pb:hover{background:var(--hover-s);border-color:var(--primary);color:var(--primary);}
.pb.active{background:var(--primary);border-color:var(--primary);color:#fff;}
.pb:disabled{opacity:.4;pointer-events:none;}

/* CELLS */
.doc-id{font-family:'DM Mono',monospace;font-size:12px;font-weight:600;color:var(--primary);}
.doc-sub{font-size:10px;color:var(--text-3);margin-top:2px;}
.doc-name{font-size:12px;font-weight:600;color:var(--text-1);}
.dept-av{width:26px;height:26px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:9px;color:#fff;flex-shrink:0;margin-right:6px;vertical-align:middle;}
.dept-name{font-size:12px;font-weight:600;color:var(--text-1);vertical-align:middle;}
.date-val{font-size:12px;color:var(--text-2);}
.chip{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;}
.chip::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0;}
.chip-transit  {background:#EFF6FF;color:var(--info);}
.chip-received {background:#D1FAE5;color:#065F46;}
.chip-returned {background:#FEF3C7;color:var(--gold);}
.chip-completed{background:#DCFCE7;color:#166534;}
.rtype{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;white-space:nowrap;}
.rt-action{background:#FEE2E2;color:var(--danger);}.rt-review{background:#EFF6FF;color:var(--info);}
.rt-signature{background:#F3E8FF;color:#7C3AED;}.rt-filing{background:#F3F4F6;color:#6B7280;}

/* ROW ACTIONS */
.row-acts{display:flex;align-items:center;gap:3px;}
.icon-btn{width:26px;height:26px;border-radius:7px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:13px;color:var(--text-2);transition:var(--tr);}
.icon-btn:hover{background:var(--hover-s);border-color:var(--primary);color:var(--primary);}
.icon-btn.gold:hover{background:#FEF3C7;border-color:#FDE68A;color:var(--gold);}
.icon-btn.green:hover{background:#D1FAE5;border-color:#6EE7B7;color:#065F46;}
.icon-btn.blue:hover{background:#DBEAFE;border-color:#93C5FD;color:var(--info);}

/* EMPTY */
.po-empty{padding:60px 20px;text-align:center;color:var(--text-3);}
.po-empty i{font-size:48px;display:block;margin-bottom:10px;color:#C8E6C9;}
.po-empty p{font-size:14px;}

/* SKELETON */
.skeleton{background:linear-gradient(90deg,var(--bg) 25%,rgba(46,125,50,.07) 50%,var(--bg) 75%);background-size:400% 100%;animation:shimmer 1.4s infinite;border-radius:8px;}
@keyframes shimmer{0%{background-position:100% 50%}100%{background-position:0% 50%}}

/* MY TASKS (Staff) */
.task-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px 20px;margin-bottom:12px;box-shadow:var(--shadow-sm);display:flex;align-items:flex-start;justify-content:space-between;gap:16px;animation:fadeUp .4s both;}
.task-card:hover{border-color:var(--primary);background:var(--hover-s);}
.task-card-id{font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--primary);}
.task-card-name{font-size:14px;font-weight:700;color:var(--text-1);margin:3px 0;}
.task-card-meta{font-size:11px;color:var(--text-3);display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:4px;}
.task-acts{display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end;}
.qr-btn{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;padding:6px 12px;border-radius:8px;border:1px solid var(--border-mid);background:#F3F4F6;color:var(--text-2);cursor:pointer;transition:var(--tr);}
.qr-btn:hover{background:#DBEAFE;border-color:#93C5FD;color:var(--info);}
.qr-icon{font-size:15px;}

/* TEAM VIEW (Manager) */
.team-row-acts{display:flex;gap:6px;flex-wrap:wrap;}

/* ROUTE TIMELINE */
.route-timeline{display:flex;flex-direction:column;gap:0;padding:4px 0;}
.rt-step{display:flex;gap:14px;position:relative;}
.rt-step:not(:last-child)::before{content:'';position:absolute;left:15px;top:34px;bottom:0;width:2px;background:var(--border);}
.rt-dot{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;z-index:1;}
.rtd-done{background:#E8F5E9;color:var(--primary);border:2px solid var(--primary);}
.rtd-current{background:var(--primary);color:#fff;border:2px solid var(--primary);}
.rtd-pending{background:var(--bg);color:var(--text-3);border:2px solid var(--border-mid);}
.rtd-return{background:#FEF3C7;color:var(--gold);border:2px solid var(--gold);}
.rt-info{padding:4px 0 20px;}
.rt-role{font-size:13px;font-weight:600;color:var(--text-1);}
.rt-by{font-size:12px;color:var(--text-2);margin-top:2px;}
.rt-ts{font-size:11px;color:var(--text-3);font-family:'DM Mono',monospace;margin-top:2px;}
.rt-note{font-size:11px;color:var(--text-2);margin-top:4px;background:var(--bg);border-radius:6px;padding:4px 8px;display:inline-block;}

/* AUDIT LOG */
.audit-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--border);}
.audit-item:last-child{border-bottom:none;}
.audit-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px;}
.dot-g{background:var(--primary)}.dot-b{background:var(--info)}.dot-o{background:var(--warning)}.dot-r{background:var(--danger)}.dot-gy{background:#9CA3AF;}
.audit-act{font-size:13px;font-weight:600;color:var(--text-1);}
.audit-by{font-size:12px;color:var(--text-2);margin-top:2px;}
.audit-ts{font-size:11px;color:var(--text-3);font-family:'DM Mono',monospace;margin-top:1px;}

/* OVERRIDE QUEUE */
.override-item{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:10px;box-shadow:var(--shadow-sm);}
.override-hd{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px;}
.override-id{font-family:'DM Mono',monospace;font-size:12px;font-weight:600;color:var(--danger);}
.override-name{font-size:13px;font-weight:700;color:var(--text-1);margin-top:2px;}
.override-meta{font-size:11px;color:var(--text-3);margin-top:2px;}

/* INFO BOXES */
.sa-banner{background:linear-gradient(135deg,rgba(27,94,32,.04),rgba(46,125,50,.07));border:1px solid rgba(46,125,50,.2);border-radius:12px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;}
.sa-banner i{color:var(--primary);font-size:18px;flex-shrink:0;}
.sa-banner span{font-size:12px;font-weight:600;color:var(--primary);}
.warn-box{background:#FEF3C7;border:1px solid rgba(180,83,9,.25);border-radius:12px;padding:12px 16px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start;}
.warn-box i{color:var(--gold);font-size:17px;flex-shrink:0;}
.warn-box p{font-size:13px;color:var(--gold);font-weight:500;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;}
.info-item label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);display:block;margin-bottom:3px;}
.info-item .v{font-size:13px;font-weight:500;color:var(--text-1);}
.info-item .v.mono{font-family:'DM Mono',monospace;}

/* OVERLAY */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1100;opacity:0;pointer-events:none;transition:opacity .25s;}
.overlay.show{opacity:1;pointer-events:all;}

/* SIDE PANEL */
.side-panel{position:fixed;top:0;right:0;bottom:0;width:560px;max-width:100%;background:var(--surface);z-index:1400;display:flex;flex-direction:column;box-shadow:-8px 0 40px rgba(0,0,0,.18);transform:translateX(100%);transition:transform .32s cubic-bezier(.4,0,.2,1);}
.side-panel.show{transform:translateX(0);}
.sp-hd{padding:22px 28px 18px;background:linear-gradient(135deg,rgba(46,125,50,.06),rgba(46,125,50,.02));border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-shrink:0;}
.sp-title{font-size:20px;font-weight:800;color:var(--text-1);}
.sp-sub{font-size:12px;color:var(--text-2);margin-top:4px;}
.sp-cl{width:34px;height:34px;border-radius:9px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--text-2);transition:var(--tr);flex-shrink:0;}
.sp-cl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA;}
.sp-body{flex:1;overflow-y:auto;padding:24px 28px;}
.sp-body::-webkit-scrollbar{width:4px;}
.sp-body::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px;}
.sp-ft{padding:16px 28px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;}

/* MODALS */
.modal-base{position:fixed;inset:0;z-index:1200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;}
.modal-base.show{opacity:1;pointer-events:all;}
.mbox-sm{background:var(--surface);border-radius:20px;width:520px;max-width:100%;box-shadow:var(--shadow-xl);transform:scale(.96);transition:transform .25s;overflow:hidden;}
.mbox-lg{background:var(--surface);border-radius:20px;width:820px;max-width:100%;max-height:92vh;display:flex;flex-direction:column;box-shadow:var(--shadow-xl);transform:scale(.96);transition:transform .25s;overflow:hidden;}
.modal-base.show .mbox-sm,.modal-base.show .mbox-lg{transform:scale(1);}
.m-hd{padding:20px 26px 16px;border-bottom:1px solid var(--border);background:var(--bg);display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-shrink:0;}
.m-hd-ic{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.m-hd-title{font-size:16px;font-weight:700;color:var(--text-1);}
.m-hd-sub{font-size:12px;color:var(--text-2);margin-top:3px;}
.m-cl{width:32px;height:32px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:18px;color:var(--text-2);transition:var(--tr);flex-shrink:0;}
.m-cl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA;}
.m-body{flex:1;overflow-y:auto;padding:22px 26px;}
.m-body::-webkit-scrollbar{width:4px;}
.m-body::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px;}
.m-ft{padding:14px 26px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;}
.m-body-p{padding:20px 26px;}

/* FORM */
.sec-hd{font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--primary);margin:0 0 14px;}
.divider{height:1px;background:var(--border);margin:20px 0 18px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-grid .full{grid-column:1/-1;}
.fg{display:flex;flex-direction:column;gap:6px;}
.fl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-2);display:flex;align-items:center;gap:3px;}
.fl .req{color:var(--danger);}
.fi,.fs,.fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 13px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);width:100%;}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12);}
.fi::placeholder{color:var(--text-3);}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:32px;}
.fta{resize:vertical;min-height:72px;line-height:1.6;}

/* ROUTE TYPE SELECTOR */
.rtype-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.rtype-opt{border:1.5px solid var(--border-mid);border-radius:10px;padding:10px 14px;cursor:pointer;transition:var(--tr);display:flex;align-items:center;gap:10px;}
.rtype-opt:hover{background:var(--hover-s);}
.rtype-opt.selected{border-color:var(--primary);background:rgba(46,125,50,.05);}
.rtype-opt input[type=radio]{display:none;}
.rtype-ic{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.rtype-label{font-size:12px;font-weight:700;color:var(--text-1);}
.rtype-desc{font-size:10px;color:var(--text-3);margin-top:1px;}

/* DOC PICKER */
.dp-item{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border);transition:background .12s;}
.dp-item:last-child{border-bottom:none;}.dp-item:hover{background:var(--hover-s);}
.dp-item-ic{width:30px;height:30px;border-radius:8px;background:#E8F5E9;color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;margin-top:1px;}
.dp-item-id{font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--primary);}
.dp-item-title{font-size:12px;font-weight:600;color:var(--text-1);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:340px;}
.dp-item-meta{font-size:10px;color:var(--text-3);margin-top:2px;display:flex;gap:6px;flex-wrap:wrap;}
.dp-empty{padding:16px;text-align:center;color:var(--text-3);font-size:12px;}
.dp-loading{padding:12px 14px;display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text-3);}

/* TOAST */
#tw{position:fixed;bottom:28px;right:28px;display:flex;flex-direction:column;gap:10px;z-index:9999;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:11px 16px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow-xl);pointer-events:all;min-width:220px;animation:toastIn .3s ease;}
.toast.success{background:var(--primary);}.toast.warning{background:var(--warning);}.toast.danger{background:var(--danger);}.toast.info{background:var(--info);}
.toast.out{animation:toastOut .3s ease forwards;}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastOut{from{opacity:1}to{opacity:0;transform:translateY(12px)}}
@keyframes shake{0%,100%{transform:translateX(0)}25%,75%{transform:translateX(-5px)}50%{transform:translateX(5px)}}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:1200px){.dr-stats{grid-template-columns:repeat(3,minmax(0,1fr));}}
@media(max-width:768px){.dr-stats{grid-template-columns:repeat(2,minmax(0,1fr));}.form-grid{grid-template-columns:1fr;}.info-grid{grid-template-columns:1fr;}.rtype-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="page">

  <!-- PAGE HEADER -->
  <div class="po-ph">
    <div>
      <p class="eyebrow">DTRS · Document Tracking &amp; Logistics Records</p>
      <h1>Document Routing
        <span class="role-pill <?= match($roleName){
            'Super Admin'=>'rp-sa','Admin'=>'rp-ad','Manager'=>'rp-mg',default=>'rp-st'
        } ?>">
          <i class="bx <?= match($roleName){
              'Super Admin'=>'bx-shield-quarter','Admin'=>'bx-cog','Manager'=>'bx-user-voice',default=>'bx-user'
          } ?>" style="font-size:12px"></i>
          <?= $roleNameEsc ?>
        </span>
        <?php if ($userZone): ?>
        <span style="font-size:12px;font-weight:500;color:var(--text-2);margin-left:8px;vertical-align:middle">
          <i class="bx bx-map-pin" style="font-size:13px;color:var(--primary)"></i>
          <?= htmlspecialchars($userZone, ENT_QUOTES) ?>
        </span>
        <?php endif; ?>
      </h1>
    </div>
    <div class="po-acts">
      <?php if ($cap['canViewAuditAll']): ?>
      <button class="btn btn-g" id="auditBtn"><i class='bx bx-history'></i> Audit Trail</button>
      <?php endif; ?>
      <?php if ($cap['canExport']): ?>
      <button class="btn btn-g" id="exportBtn"><i class='bx bx-export'></i> Export</button>
      <?php endif; ?>
      <?php if ($cap['canCreate']): ?>
      <button class="btn btn-p" id="routeBtn"><i class='bx bx-git-branch'></i> New Route</button>
      <?php elseif ($cap['canCreateOwn']): ?>
      <button class="btn btn-p" id="routeBtn"><i class='bx bx-git-branch'></i> Route My Document</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ROLE CONTEXT BANNERS -->
  <?php if ($roleRank === 3): ?>
  <div class="rb-banner rb-info">
    <i class="bx bx-info-circle"></i>
    <span>You are viewing <strong>zone-specific routes</strong> for <strong><?= htmlspecialchars($userZone) ?></strong>. Cross-zone routing is not permitted. You can create routes, update statuses, and edit within your zone.</span>
  </div>
  <?php elseif ($roleRank === 2): ?>
  <div class="rb-banner rb-warn">
    <i class="bx bx-group"></i>
    <span>Manager view — showing <strong>team documents</strong> for <?= htmlspecialchars($userZone) ?>. You can monitor routing progress, reassign within team, and flag delays.</span>
  </div>
  <?php elseif ($roleRank === 1): ?>
  <div class="rb-banner rb-user">
    <i class="bx bx-user"></i>
    <span>Showing only <strong>your assigned routing tasks</strong>. You can receive documents, forward within your team, and return documents.</span>
  </div>
  <?php endif; ?>

  <!-- STATS -->
  <div class="dr-stats" id="statsRow">
    <?php for ($i = 0; $i < ($roleRank === 1 ? 3 : 5); $i++): ?>
    <div class="po-stat"><div class="stat-ic skeleton" style="width:32px;height:32px"></div><div class="stat-body"><div class="skeleton" style="height:14px;width:36px;margin-bottom:4px"></div><div class="skeleton" style="height:10px;width:65px"></div></div></div>
    <?php endfor; ?>
  </div>

  <!-- TABS — rendered per role -->
  <div class="nav-bar">
    <?php if ($roleRank === 1): ?>
      <button class="tab-btn active" data-tab="mytasks"><i class='bx bx-task'></i> My Tasks</button>
      <?php /* Staff: if they're allowed to create own routes, show a "Route Document" tab */ ?>
    <?php elseif ($roleRank === 2): ?>
      <button class="tab-btn active" data-tab="team"><i class='bx bx-group'></i> Team Documents</button>
    <?php else: ?>
      <button class="tab-btn active" data-tab="routing"><i class='bx bx-transfer'></i> Routing Queue</button>
      <?php if ($cap['tabTransit']): ?>
      <button class="tab-btn" data-tab="transit"><i class='bx bx-trip'></i> In Transit</button>
      <?php endif; ?>
      <?php if ($cap['tabOverride']): ?>
      <button class="tab-btn" data-tab="override"><i class='bx bx-shield-quarter'></i> SA Override</button>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- FILTER BAR — shown for Admin/SA routing queue only -->
  <?php if ($roleRank >= 3): ?>
  <div class="filter-bar" id="filterBar">
    <div class="sw"><i class='bx bx-search'></i><input type="text" class="sinput" id="srch" placeholder="Search by Doc ID, name, department…"></div>
    <select class="fsel" id="fStatus">
      <option value="">All Statuses</option>
      <option value="In Transit">In Transit</option>
      <option value="Received">Received</option>
      <option value="Returned">Returned</option>
      <?php if ($cap['statusCompleted']): ?><option value="Completed">Completed</option><?php endif; ?>
    </select>
    <select class="fsel" id="fRouteType">
      <option value="">All Route Types</option>
      <option value="For Action">For Action</option>
      <option value="For Review">For Review</option>
      <option value="For Signature">For Signature</option>
      <option value="For Filing">For Filing</option>
    </select>
    <?php if ($cap['canViewAllZones'] || $cap['canViewZone']): ?>
    <select class="fsel" id="fDept">
      <option value="">All Departments</option>
      <option>HR</option><option>Finance</option><option>Admin</option>
      <option>Legal</option><option>PSM</option><option>Operations</option>
      <option>IT Department</option><option>Executive Office</option>
    </select>
    <?php endif; ?>
    <button class="clear-filters" id="clearFilters"><i class='bx bx-x'></i> Clear</button>
  </div>
  <?php else: ?>
  <!-- Manager/Staff: simple search only -->
  <div class="filter-bar" id="filterBar">
    <div class="sw"><i class='bx bx-search'></i><input type="text" class="sinput" id="srch" placeholder="Search by ID, name…"></div>
    <select class="fsel" id="fStatus">
      <option value="">All Statuses</option>
      <option value="In Transit">In Transit</option>
      <option value="Received">Received</option>
      <?php if ($cap['statusReturned']): ?><option value="Returned">Returned</option><?php endif; ?>
    </select>
    <button class="clear-filters" id="clearFilters"><i class='bx bx-x'></i> Clear</button>
  </div>
  <?php endif; ?>

  <!-- ── TAB: MY TASKS (Staff) ── -->
  <?php if ($roleRank === 1): ?>
  <div class="tab-panel active" id="tab-mytasks">
    <div id="myTasksList">
      <div class="skeleton" style="height:80px;border-radius:14px;margin-bottom:12px"></div>
      <div class="skeleton" style="height:80px;border-radius:14px"></div>
    </div>
    <div id="myTasksEmpty" class="po-empty" style="display:none">
      <i class='bx bx-task'></i><p>No routing tasks assigned to you.</p>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── TAB: TEAM DOCUMENTS (Manager) ── -->
  <?php if ($roleRank === 2): ?>
  <div class="tab-panel active" id="tab-team">
    <div class="po-card">
      <div class="po-table-wrap">
        <table class="po-table">
          <thead><tr>
            <th>Document ID</th><th>Document Name</th><th>Route Type</th>
            <th>From → To</th><th>Assigned To</th><th>Date Routed</th>
            <th>Status</th><th>Actions</th>
          </tr></thead>
          <tbody id="teamBody">
            <tr><td colspan="8" style="padding:28px;text-align:center"><div class="skeleton" style="height:14px;width:60%;margin:0 auto"></div></td></tr>
          </tbody>
        </table>
        <div id="teamEmpty" class="po-empty" style="display:none"><i class='bx bx-group'></i><p>No team documents found.</p></div>
      </div>
      <div class="po-card-ft"><div class="ft-info" id="teamFtInfo"></div></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── TAB: ROUTING QUEUE (Admin + SA) ── -->
  <?php if ($roleRank >= 3): ?>
  <div class="tab-panel active" id="tab-routing">
    <div class="po-card">
      <div class="po-table-wrap">
        <table class="po-table">
          <thead><tr>
            <th>Document ID</th><th>Document Name</th><th>Route Type</th>
            <th>From</th><th>To</th><th>Assigned To</th>
            <th>Date Routed</th><th>Status</th><th>Actions</th>
          </tr></thead>
          <tbody id="routingBody">
            <tr><td colspan="9" style="padding:28px;text-align:center"><div class="skeleton" style="height:14px;width:60%;margin:0 auto"></div></td></tr>
          </tbody>
        </table>
        <div id="routingEmpty" class="po-empty" style="display:none"><i class='bx bx-git-branch'></i><p>No routing records found.</p></div>
      </div>
      <div class="po-card-ft">
        <div class="ft-info" id="ftInfo"></div>
        <div class="pbtns" id="pagBtns"></div>
      </div>
    </div>
  </div>

  <!-- ── TAB: IN TRANSIT (Admin + SA) ── -->
  <?php if ($cap['tabTransit']): ?>
  <div class="tab-panel" id="tab-transit">
    <div class="po-card" style="animation:fadeUp .4s both">
      <div style="padding:14px 20px;border-bottom:1px solid var(--border);background:var(--bg);display:flex;align-items:center;gap:8px">
        <i class='bx bx-trip' style="color:var(--info);font-size:17px"></i>
        <span style="font-size:13px;font-weight:700">Documents Currently In Transit</span>
        <?php if ($roleRank === 3): ?>
        <span style="font-size:11px;color:var(--text-3)">— <?= htmlspecialchars($userZone) ?> zone only</span>
        <?php else: ?>
        <span style="font-size:11px;color:var(--text-3)">— System-wide</span>
        <?php endif; ?>
      </div>
      <div class="po-table-wrap">
        <table class="po-table">
          <thead><tr>
            <th>Document ID</th><th>Document Name</th><th>Route Type</th>
            <th>From → To</th><th>Assigned To</th><th>Date Routed</th>
            <th>Module</th><th>Actions</th>
          </tr></thead>
          <tbody id="transitBody"></tbody>
        </table>
        <div id="transitEmpty" class="po-empty" style="display:none"><i class='bx bx-trip'></i><p>No documents currently in transit.</p></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── TAB: SA OVERRIDE ── -->
  <?php if ($cap['tabOverride']): ?>
  <div class="tab-panel" id="tab-override">
    <div class="sa-banner" style="animation:fadeUp .4s both">
      <i class='bx bx-shield-quarter'></i>
      <span>Super Admin Override — Forcibly reroute or override any document routing regardless of department, zone, or current status.</span>
    </div>
    <div id="overrideList" style="animation:fadeUp .4s .1s both">
      <div class="skeleton" style="height:90px;border-radius:12px;margin-bottom:10px"></div>
      <div class="skeleton" style="height:90px;border-radius:12px"></div>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div><!-- .page -->
</main>

<div class="overlay" id="ov"></div>

<!-- ── NEW / EDIT ROUTE SIDE PANEL (Admin + SA + Staff own) ── -->
<?php if ($cap['canCreate'] || $cap['canCreateOwn']): ?>
<div class="side-panel" id="routePanel">
  <div class="sp-hd">
    <div>
      <div class="sp-title" id="routePanelTitle">
        <?= $roleRank === 1 ? 'Route My Document' : 'New Document Route' ?>
      </div>
      <div class="sp-sub" id="routePanelSub">Fill in routing details below</div>
    </div>
    <button class="sp-cl" id="routePanelCl"><i class='bx bx-x'></i></button>
  </div>
  <div class="sp-body">
    <div class="sec-hd">Document Details</div>
    <!-- DOC PICKER -->
    <div class="fg full" style="margin-bottom:14px">
      <label class="fl">Link to Registered Document <span class="req">*</span></label>
      <div style="position:relative">
        <i class='bx bx-search' style="position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--text-3);pointer-events:none;z-index:1"></i>
        <input type="text" class="fi" id="docPickerInput" placeholder="Type Document ID or title to search…" autocomplete="off" style="padding-left:34px;padding-right:36px">
        <button id="docPickerClear" onclick="clearDocPicker()" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-3);font-size:17px;display:none;padding:0;line-height:1"><i class='bx bx-x'></i></button>
        <div id="docPickerDropdown" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--surface);border:1px solid var(--border-mid);border-radius:10px;box-shadow:var(--shadow-md);z-index:500;max-height:260px;overflow-y:auto"></div>
      </div>
      <div id="docPickerSelected" style="display:none;margin-top:8px;padding:10px 12px;background:linear-gradient(135deg,rgba(46,125,50,.05),rgba(46,125,50,.02));border:1px solid rgba(46,125,50,.25);border-radius:10px;gap:10px;align-items:flex-start">
        <i class='bx bx-file-blank' style="color:var(--primary);font-size:18px;flex-shrink:0;margin-top:1px"></i>
        <div style="min-width:0;flex:1">
          <div style="font-size:12px;font-weight:700;color:var(--text-1)" id="pickerBadgeTitle">—</div>
          <div style="font-size:11px;color:var(--text-3);margin-top:2px;display:flex;gap:8px;flex-wrap:wrap">
            <span id="pickerBadgeId" style="font-family:'DM Mono',monospace;color:var(--primary);font-weight:600"></span>
            <span id="pickerBadgeType"></span><span id="pickerBadgeDept"></span>
          </div>
        </div>
        <button onclick="clearDocPicker()" style="background:none;border:none;cursor:pointer;color:var(--text-3);font-size:15px;flex-shrink:0;padding:0;transition:var(--tr)" onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--text-3)'"><i class='bx bx-x'></i></button>
      </div>
      <input type="hidden" id="rDocId"><input type="hidden" id="rDocDbId">
    </div>
    <div class="form-grid">
      <div class="fg full"><label class="fl">Document Name <span class="req">*</span></label><input type="text" class="fi" id="rDocName" placeholder="Auto-filled or enter manually"></div>
      <div class="fg"><label class="fl">Document Type</label>
        <select class="fs" id="rDocType"><option value="">Select...</option><option>Contract</option><option>Memo</option><option>Report</option><option>Invoice</option><option>Policy</option><option>Legal Document</option><option>HR Document</option></select>
      </div>
      <div class="fg"><label class="fl">Department</label><input type="text" class="fi" id="rDocDept" placeholder="Auto-filled" readonly style="background:var(--bg);color:var(--text-2);cursor:default"></div>
    </div>
    <div class="divider"></div>
    <div class="sec-hd">Routing Details</div>
    <div class="form-grid">
      <div class="fg"><label class="fl">Originating Department <span class="req">*</span></label>
        <select class="fs" id="rFrom">
          <option value="">Select...</option>
          <?php if ($roleRank === 3 && $userZone): ?>
          <option selected><?= htmlspecialchars($userZone) ?></option>
          <?php else: ?>
          <option>HR</option><option>Finance</option><option>Admin</option>
          <option>Legal</option><option>PSM</option><option>IT Department</option>
          <option>Operations</option><option>Executive Office</option>
          <?php endif; ?>
        </select>
      </div>
      <div class="fg"><label class="fl">Destination Department <span class="req">*</span></label>
        <select class="fs" id="rTo">
          <option value="">Select...</option>
          <?php if ($roleRank === 3 && $userZone): ?>
          <!-- Admin: same zone destinations only — populated via JS from zone staff -->
          <option>HR</option><option>Finance</option><option>Admin</option>
          <option>Legal</option><option>PSM</option><option>IT Department</option>
          <option>Operations</option><option>Executive Office</option>
          <?php elseif ($roleRank === 1): ?>
          <!-- Staff: team/person only — populated from zone staff -->
          <?php else: ?>
          <option>HR</option><option>Finance</option><option>Admin</option>
          <option>Legal</option><option>PSM</option><option>IT Department</option>
          <option>Operations</option><option>Executive Office</option>
          <?php endif; ?>
        </select>
      </div>
      <div class="fg full"><label class="fl">Assigned To <span class="req">*</span></label>
        <select class="fs" id="rAssignee"><option value="">Loading staff…</option></select>
      </div>
    </div>
    <div class="divider"></div>
    <div class="sec-hd">Route Type <span style="font-size:11px;font-weight:700;color:var(--danger);margin-left:4px">*</span></div>
    <div class="rtype-grid">
      <label class="rtype-opt" id="rt-action"><input type="radio" name="routeType" value="For Action">
        <div class="rtype-ic" style="background:#FEE2E2;color:var(--danger)"><i class='bx bx-check-square'></i></div>
        <div><div class="rtype-label">For Action</div><div class="rtype-desc">Requires direct action</div></div>
      </label>
      <label class="rtype-opt" id="rt-review"><input type="radio" name="routeType" value="For Review">
        <div class="rtype-ic" style="background:#EFF6FF;color:var(--info)"><i class='bx bx-search-alt'></i></div>
        <div><div class="rtype-label">For Review</div><div class="rtype-desc">Review and feedback</div></div>
      </label>
      <label class="rtype-opt" id="rt-signature"><input type="radio" name="routeType" value="For Signature">
        <div class="rtype-ic" style="background:#F3E8FF;color:#7C3AED"><i class='bx bx-pencil'></i></div>
        <div><div class="rtype-label">For Signature</div><div class="rtype-desc">Requires signature</div></div>
      </label>
      <?php if ($cap['canCreate']): /* Admin + SA only see For Filing */ ?>
      <label class="rtype-opt" id="rt-filing"><input type="radio" name="routeType" value="For Filing">
        <div class="rtype-ic" style="background:#F3F4F6;color:#6B7280"><i class='bx bx-archive'></i></div>
        <div><div class="rtype-label">For Filing</div><div class="rtype-desc">Archive document</div></div>
      </label>
      <?php endif; ?>
    </div>
    <div class="divider"></div>
    <div class="form-grid">
      <div class="fg"><label class="fl">Priority</label>
        <select class="fs" id="rPriority"><option value="Normal">Normal</option><option value="Urgent">Urgent</option><option value="Rush">Rush</option></select>
      </div>
      <div class="fg"><label class="fl">Due Date</label><input type="date" class="fi" id="rDueDate"></div>
      <div class="fg full"><label class="fl">Instructions / Notes</label><textarea class="fta" id="rNotes" placeholder="Routing instructions or special notes…"></textarea></div>
    </div>
  </div>
  <div class="sp-ft">
    <button class="btn btn-g btn-s" id="routePanelCancel">Cancel</button>
    <button class="btn btn-p btn-s" id="routeSave"><i class='bx bx-git-branch'></i> <span id="routeSaveLabel">Submit Route</span></button>
  </div>
</div>
<?php endif; ?>

<!-- ── VIEW MODAL ── -->
<div class="modal-base" id="viewModal">
  <div class="mbox-lg">
    <div class="m-hd">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="m-hd-ic" style="background:#E8F5E9;color:var(--primary)"><i class='bx bx-file'></i></div>
        <div><div class="m-hd-title" id="viewTitle">Routing Details</div><div class="m-hd-sub" id="viewSub"></div></div>
      </div>
      <button class="m-cl" onclick="closeModal('viewModal')"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body" id="viewBody"></div>
    <div class="m-ft" id="viewFt"></div>
  </div>
</div>

<!-- ── STATUS MODAL ── -->
<div class="modal-base" id="statusModal">
  <div class="mbox-sm">
    <div class="m-hd">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="m-hd-ic" style="background:#E8F5E9;color:var(--primary)"><i class='bx bx-refresh'></i></div>
        <div><div class="m-hd-title" id="statusTitle">Update Routing Status</div><div class="m-hd-sub" id="statusSub"></div></div>
      </div>
      <button class="m-cl" onclick="closeModal('statusModal')"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body-p">
      <div class="info-grid" id="statusInfo"></div>
      <div class="fg" style="margin-bottom:14px">
        <label class="fl">New Status <span class="req">*</span></label>
        <select class="fs" id="newStatus">
          <option value="">— Select Status —</option>
          <option value="In Transit">In Transit</option>
          <option value="Received">Received</option>
          <option value="Returned">Returned</option>
          <?php if ($cap['statusCompleted']): ?>
          <option value="Completed">Completed</option>
          <?php endif; ?>
        </select>
      </div>
      <div class="fg"><label class="fl">Remarks</label><textarea class="fta" id="statusRemarks" placeholder="Add a note…" style="min-height:60px"></textarea></div>
    </div>
    <div class="m-ft">
      <button class="btn btn-g btn-s" onclick="closeModal('statusModal')">Cancel</button>
      <button class="btn btn-p btn-s" id="statusSave"><i class='bx bx-check'></i> Update Status</button>
    </div>
  </div>
</div>

<!-- ── FORWARD MODAL (Staff) ── -->
<?php if ($cap['canForward']): ?>
<div class="modal-base" id="forwardModal">
  <div class="mbox-sm">
    <div class="m-hd">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="m-hd-ic" style="background:#DBEAFE;color:var(--info)"><i class='bx bx-right-arrow-alt'></i></div>
        <div><div class="m-hd-title" id="fwdTitle">Forward Document</div><div class="m-hd-sub">Forward within your team only</div></div>
      </div>
      <button class="m-cl" onclick="closeModal('forwardModal')"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body-p">
      <div class="info-grid" id="fwdInfo"></div>
      <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#1D4ED8;display:flex;gap:8px">
        <i class='bx bx-info-circle' style="font-size:15px;flex-shrink:0;margin-top:1px"></i>
        You can only forward to members within your team/zone.
      </div>
      <div class="fg" style="margin-bottom:12px">
        <label class="fl">Forward To <span class="req">*</span></label>
        <select class="fs" id="fwdAssignee"><option value="">Select person…</option></select>
      </div>
      <div class="fg"><label class="fl">Remarks</label><textarea class="fta" id="fwdRemarks" placeholder="Reason for forwarding…" style="min-height:55px"></textarea></div>
    </div>
    <div class="m-ft">
      <button class="btn btn-g btn-s" onclick="closeModal('forwardModal')">Cancel</button>
      <button class="btn btn-info btn-s" id="fwdSave"><i class='bx bx-right-arrow-alt'></i> Forward</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── REASSIGN MODAL (Manager) ── -->
<?php if ($cap['canReassign']): ?>
<div class="modal-base" id="reassignModal">
  <div class="mbox-sm">
    <div class="m-hd">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="m-hd-ic" style="background:#D1FAE5;color:#065F46"><i class='bx bx-transfer-alt'></i></div>
        <div><div class="m-hd-title" id="reassignTitle">Reassign Route</div><div class="m-hd-sub">Reassign to another team member</div></div>
      </div>
      <button class="m-cl" onclick="closeModal('reassignModal')"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body-p">
      <div class="info-grid" id="reassignInfo"></div>
      <div class="fg" style="margin-bottom:12px">
        <label class="fl">Reassign To <span class="req">*</span></label>
        <select class="fs" id="reassignTo"><option value="">Select person…</option></select>
      </div>
      <div class="fg"><label class="fl">Remarks</label><textarea class="fta" id="reassignRemarks" placeholder="Reason for reassignment…" style="min-height:55px"></textarea></div>
    </div>
    <div class="m-ft">
      <button class="btn btn-g btn-s" onclick="closeModal('reassignModal')">Cancel</button>
      <button class="btn btn-p btn-s" id="reassignSave"><i class='bx bx-transfer-alt'></i> Reassign</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── FLAG DELAY MODAL (Manager) ── -->
<?php if ($cap['canFlagDelay']): ?>
<div class="modal-base" id="flagModal">
  <div class="mbox-sm">
    <div class="m-hd">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="m-hd-ic" style="background:#FEF3C7;color:var(--gold)"><i class='bx bx-flag'></i></div>
        <div><div class="m-hd-title" id="flagTitle">Flag Routing Delay</div><div class="m-hd-sub" id="flagSub"></div></div>
      </div>
      <button class="m-cl" onclick="closeModal('flagModal')"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body-p">
      <div class="fg"><label class="fl">Reason for Flagging <span class="req">*</span></label><textarea class="fta" id="flagReason" placeholder="Describe the delay or issue…" style="min-height:72px"></textarea></div>
    </div>
    <div class="m-ft">
      <button class="btn btn-g btn-s" onclick="closeModal('flagModal')">Cancel</button>
      <button class="btn btn-warn btn-s" id="flagSave"><i class='bx bx-flag'></i> Flag Delay</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── OVERRIDE MODAL (SA) ── -->
<?php if ($cap['canOverride']): ?>
<div class="modal-base" id="overrideModal">
  <div class="mbox-sm">
    <div class="m-hd">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="m-hd-ic" style="background:#FEF3C7;color:var(--gold)"><i class='bx bx-shield-quarter'></i></div>
        <div><div class="m-hd-title" id="overrideTitle">Force Reroute</div><div class="m-hd-sub">Super Admin — Override routing</div></div>
      </div>
      <button class="m-cl" onclick="closeModal('overrideModal')"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body-p">
      <div class="sa-banner"><i class='bx bx-shield-quarter'></i><span>This override is permanently logged in the audit trail.</span></div>
      <div class="warn-box"><i class='bx bx-error-circle'></i><p>Forcibly rerouting overrides the current routing chain. The original assignee will be notified.</p></div>
      <div class="info-grid" id="overrideInfo"></div>
      <div class="form-grid">
        <div class="fg"><label class="fl">Reroute To Department <span class="req">*</span></label>
          <select class="fs" id="overrideDept"><option value="">Select...</option><option>HR</option><option>Finance</option><option>Admin</option><option>Legal</option><option>PSM</option><option>IT Department</option><option>Operations</option><option>Executive Office</option></select>
        </div>
        <div class="fg"><label class="fl">Reassign To Person</label>
          <select class="fs" id="overridePerson"><option value="">Select officer...</option></select>
        </div>
        <div class="fg full"><label class="fl">Override Reason <span class="req">*</span></label><textarea class="fta" id="overrideReason" placeholder="State the reason for this forced reroute…" style="min-height:60px"></textarea></div>
      </div>
    </div>
    <div class="m-ft">
      <button class="btn btn-g btn-s" onclick="closeModal('overrideModal')">Cancel</button>
      <button class="btn btn-gold btn-s" id="overrideSave"><i class='bx bx-check-shield'></i> Apply Override</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── AUDIT TRAIL MODAL (SA) ── -->
<?php if ($cap['canViewAuditAll']): ?>
<div class="modal-base" id="auditModal">
  <div class="mbox-lg">
    <div class="m-hd">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="m-hd-ic" style="background:#E8F5E9;color:var(--primary)"><i class='bx bx-history'></i></div>
        <div><div class="m-hd-title">Routing Audit Trail</div><div class="m-hd-sub" id="auditSub"></div></div>
      </div>
      <button class="m-cl" onclick="closeModal('auditModal')"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body">
      <div class="sa-banner" style="margin-bottom:16px"><i class='bx bx-shield-quarter'></i><span>Super Admin View — Complete routing audit trail across all departments.</span></div>
      <div id="auditList"></div>
    </div>
    <div class="m-ft"><button class="btn btn-g btn-s" onclick="closeModal('auditModal')">Close</button></div>
  </div>
</div>
<?php endif; ?>

<div id="tw"></div>

<script>
// ── ROLE CAPS (PHP → JS) ──────────────────────────────────────────────────────
const CAP  = <?= $capJson ?>;
const RANK = <?= $roleRankJs ?>;
const ROLE = <?= json_encode($roleName) ?>;
const ZONE = <?= json_encode($userZone) ?>;
const API  = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';

// ── API ───────────────────────────────────────────────────────────────────────
async function apiFetch(path, opts = {}) {
    const r = await fetch(path, { headers: { 'Content-Type': 'application/json' }, ...opts });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p     => apiFetch(p);
const apiPost = (p,b) => apiFetch(p, { method: 'POST', body: JSON.stringify(b) });

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc     = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'}) : '—';
const fmtTs   = d => d ? new Date(d).toLocaleString('en-PH',{year:'numeric',month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit'}) : '—';
const COLS = ['#2E7D32','#1B5E20','#0D9488','#2563EB','#7C3AED','#D97706','#DC2626','#0891B2','#059669','#B45309'];
const gc  = n => { let h=0; for(const c of String(n)) h=(h*31+c.charCodeAt(0))%COLS.length; return COLS[h]; };
const ini = n => String(n).split(' ').map(w=>w[0]).join('').substring(0,2).toUpperCase();
const chipClass  = s => ({'In Transit':'chip-transit','Received':'chip-received','Returned':'chip-returned','Completed':'chip-completed'}[s]||'chip-transit');
const rtypeClass = t => ({'For Action':'rt-action','For Review':'rt-review','For Signature':'rt-signature','For Filing':'rt-filing'}[t]||'rt-review');

// ── STATE ─────────────────────────────────────────────────────────────────────
let currentPage = 1;
const PER_PAGE  = 8;
let activeStatusId   = null;
let activeOverrideId = null;
let activeReassignId = null;
let activeFlagId     = null;
let activeFwdId      = null;
let editRouteId      = null;
let staffList        = [];

// ── INIT ──────────────────────────────────────────────────────────────────────
loadStaff();
loadStats();
// Load the correct initial tab data
if (RANK === 1)      loadMyTasks();
else if (RANK === 2) loadTeam();
else                 loadTable();

// ── STAFF ─────────────────────────────────────────────────────────────────────
async function loadStaff() {
    try {
        staffList = await apiGet(API + '?api=staff');
        const opts = staffList.map(s => `<option value="${esc(s.name)}">${esc(s.name)}</option>`).join('');
        const base = '<option value="">Select officer…</option>';
        ['rAssignee','overridePerson','fwdAssignee','reassignTo'].forEach(id => {
            const el = document.getElementById(id); if (el) el.innerHTML = base + opts;
        });
    } catch(e) {
        ['rAssignee'].forEach(id => { const el = document.getElementById(id); if (el) el.innerHTML = '<option value="">Unable to load staff</option>'; });
    }
}

// ── STATS ─────────────────────────────────────────────────────────────────────
async function loadStats() {
    try {
        const d = await apiGet(API + '?api=stats');
        const row = document.getElementById('statsRow');
        if (RANK === 1) {
            row.innerHTML = `
              <div class="po-stat"><div class="stat-ic ic-b"><i class='bx bx-task'></i></div><div class="stat-body"><div class="stat-v">${d.total}</div><div class="stat-l">My Tasks</div></div></div>
              <div class="po-stat"><div class="stat-ic ic-o"><i class='bx bx-trip'></i></div><div class="stat-body"><div class="stat-v">${d.transit}</div><div class="stat-l">In Transit</div></div></div>
              <div class="po-stat"><div class="stat-ic ic-g"><i class='bx bx-check-circle'></i></div><div class="stat-body"><div class="stat-v">${d.received}</div><div class="stat-l">Received</div></div></div>`;
        } else {
            row.innerHTML = `
              <div class="po-stat" style="animation-delay:.05s"><div class="stat-ic ic-g"><i class='bx bx-git-branch'></i></div><div class="stat-body"><div class="stat-v">${d.total}</div><div class="stat-l">Total Routes</div></div></div>
              <div class="po-stat" style="animation-delay:.08s"><div class="stat-ic ic-b"><i class='bx bx-trip'></i></div><div class="stat-body"><div class="stat-v">${d.transit}</div><div class="stat-l">In Transit</div></div></div>
              <div class="po-stat" style="animation-delay:.11s"><div class="stat-ic ic-g"><i class='bx bx-check-circle'></i></div><div class="stat-body"><div class="stat-v">${d.received}</div><div class="stat-l">Received</div></div></div>
              <div class="po-stat" style="animation-delay:.14s"><div class="stat-ic ic-o"><i class='bx bx-undo'></i></div><div class="stat-body"><div class="stat-v">${d.returned}</div><div class="stat-l">Returned</div></div></div>
              <div class="po-stat" style="animation-delay:.17s"><div class="stat-ic ic-gy"><i class='bx bx-check-double'></i></div><div class="stat-body"><div class="stat-v">${d.completed}</div><div class="stat-l">Completed</div></div></div>`;
        }
    } catch(e) { toast('Failed to load stats: ' + e.message, 'danger'); }
}

// ── MY TASKS (Staff) ──────────────────────────────────────────────────────────
async function loadMyTasks() {
    try {
        const rows = await apiGet(API + '?api=my-tasks');
        const list  = document.getElementById('myTasksList');
        const empty = document.getElementById('myTasksEmpty');
        if (!rows.length) { list.innerHTML = ''; empty.style.display = 'block'; return; }
        empty.style.display = 'none';
        list.innerHTML = rows.map(r => `
          <div class="task-card">
            <div>
              <div class="task-card-id">${esc(r.routeId)}</div>
              <div class="task-card-name">${esc(r.docName)}</div>
              <div class="task-card-meta">
                <span class="rtype ${rtypeClass(r.routeType)}">${esc(r.routeType)}</span>
                <span class="chip ${chipClass(r.status)}">${esc(r.status)}</span>
                <span><i class='bx bx-calendar' style="font-size:11px"></i> ${fmtDate(r.dueDate||r.dateRouted)}</span>
                <span>${esc(r.from)} → ${esc(r.to)}</span>
              </div>
            </div>
            <div class="task-acts">
              ${CAP.canScanQR?`<button class="qr-btn" onclick="toast('QR scan feature — open camera','info')"><i class='bx bx-qr-scan qr-icon'></i> Scan QR</button>`:''}
              ${r.status==='In Transit'?`<button class="btn btn-grn btn-s" onclick="quickStatus(${r.id},'Received','Document received')"><i class='bx bx-check'></i> Mark Received</button>`:''}
              ${CAP.canForward&&r.status!=='Completed'?`<button class="btn btn-blue btn-s" onclick="openForward(${r.id})"><i class='bx bx-right-arrow-alt'></i> Forward</button>`:''}
              ${r.status!=='Completed'?`<button class="btn btn-g btn-s" onclick="quickStatus(${r.id},'Returned','Returning document')"><i class='bx bx-undo'></i> Return</button>`:''}
              <button class="btn btn-g btn-s" onclick="openView(${r.id})"><i class='bx bx-show'></i> View</button>
            </div>
          </div>`).join('');
    } catch(e) { toast('Failed to load tasks: ' + e.message, 'danger'); }
}

// Quick status update (Staff: Received / Return)
async function quickStatus(id, status, remarks) {
    try {
        await apiPost(API + '?api=update-status', { id, status, remarks });
        toast(`Marked as ${status}`, 'success');
        loadMyTasks(); loadStats();
    } catch(e) { toast(e.message, 'danger'); }
}

// ── TEAM DOCUMENTS (Manager) ──────────────────────────────────────────────────
async function loadTeam() {
    try {
        const rows = await apiGet(API + '?api=team-routes');
        const tbody = document.getElementById('teamBody');
        const empty = document.getElementById('teamEmpty');
        if (!tbody) return;
        if (!rows.length) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
        empty.style.display = 'none';
        tbody.innerHTML = rows.map(r => `
          <tr onclick="openView(${r.id})">
            <td><div class="doc-id">${esc(r.docId)}</div><div class="doc-sub">${esc(r.docType||'Doc')}</div></td>
            <td><div class="doc-name" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(r.docName)}</div></td>
            <td><span class="rtype ${rtypeClass(r.routeType)}">${esc(r.routeType)}</span></td>
            <td>
              <div style="display:flex;align-items:center;gap:4px">
                <div class="dept-av" style="background:${gc(r.from)}">${ini(r.from)}</div>
                <span class="dept-name">${esc(r.from)}</span>
                <i class='bx bx-right-arrow-alt' style="font-size:13px;color:var(--text-3)"></i>
                <div class="dept-av" style="background:${gc(r.to)}">${ini(r.to)}</div>
                <span class="dept-name">${esc(r.to)}</span>
              </div>
            </td>
            <td><span style="font-size:12px;font-weight:500">${esc(r.assignee)}</span></td>
            <td><div class="date-val">${fmtDate(r.dateRouted)}</div></td>
            <td><span class="chip ${chipClass(r.status)}">${esc(r.status)}</span></td>
            <td onclick="event.stopPropagation()">
              <div class="team-row-acts">
                <button class="icon-btn" onclick="openView(${r.id})" title="View"><i class='bx bx-show'></i></button>
                ${CAP.canUpdateStatus?`<button class="icon-btn" onclick="openStatus(${r.id})" title="Update Status"><i class='bx bx-refresh'></i></button>`:''}
                ${CAP.canReassign?`<button class="icon-btn green" onclick="openReassign(${r.id})" title="Reassign"><i class='bx bx-transfer-alt'></i></button>`:''}
                ${CAP.canFlagDelay?`<button class="icon-btn" style="border:1px solid #FDE68A" onmouseover="this.style.background='#FEF3C7'" onmouseout="this.style.background=''" onclick="openFlag(${r.id})" title="Flag Delay"><i class='bx bx-flag' style="color:var(--gold)"></i></button>`:''}
              </div>
            </td>
          </tr>`).join('');
        document.getElementById('teamFtInfo').textContent = `${rows.length} team document${rows.length===1?'':'s'}`;
    } catch(e) { toast('Failed to load team docs: ' + e.message, 'danger'); }
}

// ── ROUTING TABLE (Admin + SA) ────────────────────────────────────────────────
async function loadTable() {
    const fDept = document.getElementById('fDept');
    const params = new URLSearchParams({
        api: 'list', page: currentPage, per: PER_PAGE,
        ...(document.getElementById('srch').value.trim()    && { q:      document.getElementById('srch').value.trim() }),
        ...(document.getElementById('fStatus').value        && { status: document.getElementById('fStatus').value }),
        ...(document.getElementById('fRouteType')?.value    && { rtype:  document.getElementById('fRouteType').value }),
        ...(fDept?.value                                     && { dept:   fDept.value }),
    });
    try {
        const d = await apiGet(API + '?' + params);
        renderTable(d);
    } catch(e) {
        toast('Failed to load routes: ' + e.message, 'danger');
        document.getElementById('routingBody').innerHTML =
            `<tr><td colspan="9" style="padding:24px;text-align:center;color:var(--danger);font-size:12px">Error loading data.</td></tr>`;
    }
}

function renderTable(d) {
    const tbody = document.getElementById('routingBody');
    const empty = document.getElementById('routingEmpty');
    if (!d.items.length) {
        tbody.innerHTML = ''; empty.style.display = 'block';
        document.getElementById('ftInfo').textContent = '';
        document.getElementById('pagBtns').innerHTML = ''; return;
    }
    empty.style.display = 'none';
    tbody.innerHTML = d.items.map(r => `
      <tr onclick="openView(${r.id})">
        <td><div class="doc-id">${esc(r.docId)}</div><div class="doc-sub">${esc(r.docType||'Document')}</div></td>
        <td><div class="doc-name" style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(r.docName)}</div></td>
        <td><span class="rtype ${rtypeClass(r.routeType)}">${esc(r.routeType)}</span></td>
        <td><div style="display:flex;align-items:center"><div class="dept-av" style="background:${gc(r.from)}">${ini(r.from)}</div><span class="dept-name">${esc(r.from)}</span></div></td>
        <td><div style="display:flex;align-items:center"><div class="dept-av" style="background:${gc(r.to)}">${ini(r.to)}</div><span class="dept-name">${esc(r.to)}</span></div></td>
        <td><span style="font-size:12px;font-weight:500;color:var(--text-1)">${esc(r.assignee)}</span></td>
        <td><div class="date-val">${fmtDate(r.dateRouted)}</div></td>
        <td><span class="chip ${chipClass(r.status)}">${esc(r.status)}</span></td>
        <td onclick="event.stopPropagation()">
          <div class="row-acts">
            <button class="icon-btn" onclick="openView(${r.id})" title="View History"><i class='bx bx-history'></i></button>
            ${CAP.canUpdateStatus?`<button class="icon-btn" onclick="openStatus(${r.id})" title="Update Status"><i class='bx bx-refresh'></i></button>`:''}
            ${CAP.canEdit?`<button class="icon-btn blue" onclick="openEdit(${r.id})" title="Edit"><i class='bx bx-edit'></i></button>`:''}
            ${CAP.canOverride?`<button class="icon-btn gold" onclick="openOverride(${r.id})" title="Force Override (SA)"><i class='bx bx-shield-quarter'></i></button>`:''}
          </div>
        </td>
      </tr>`).join('');
    const s = (d.page - 1) * d.perPage + 1;
    const e = Math.min(d.page * d.perPage, d.total);
    document.getElementById('ftInfo').textContent = `Showing ${s}–${e} of ${d.total} routes`;
    renderPag(d.pages, d.page);
}
function renderPag(pages, cur) {
    let b = '';
    for (let i = 1; i <= pages; i++) {
        if (i === 1 || i === pages || (i >= cur-1 && i <= cur+1)) b += `<button class="pb${i===cur?' active':''}" onclick="goPg(${i})">${i}</button>`;
        else if (i === cur-2 || i === cur+2) b += `<button class="pb" disabled>…</button>`;
    }
    document.getElementById('pagBtns').innerHTML =
        `<button class="pb" onclick="goPg(${cur-1})" ${cur<=1?'disabled':''}><i class='bx bx-chevron-left'></i></button>${b}<button class="pb" onclick="goPg(${cur+1})" ${cur>=pages?'disabled':''}><i class='bx bx-chevron-right'></i></button>`;
}
window.goPg = p => { currentPage = p; loadTable(); };

// ── FILTER EVENTS ─────────────────────────────────────────────────────────────
['srch','fStatus','fRouteType','fDept'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', () => { currentPage = 1; if (RANK >= 3) loadTable(); else if (RANK === 2) loadTeam(); else loadMyTasks(); });
});
document.getElementById('clearFilters').addEventListener('click', () => {
    document.getElementById('srch').value = '';
    ['fStatus','fRouteType','fDept'].forEach(id => { const el = document.getElementById(id); if (el) el.selectedIndex = 0; });
    currentPage = 1;
    if (RANK >= 3) loadTable(); else if (RANK === 2) loadTeam(); else loadMyTasks();
});

// ── IN TRANSIT ────────────────────────────────────────────────────────────────
async function loadTransit() {
    if (!CAP.tabTransit) return;
    try {
        const rows = await apiGet(API + '?api=transit');
        const tbody = document.getElementById('transitBody');
        const empty = document.getElementById('transitEmpty');
        if (!rows.length) { tbody.innerHTML = ''; empty.style.display = 'block'; return; }
        empty.style.display = 'none';
        tbody.innerHTML = rows.map(r => `
          <tr onclick="openView(${r.id})">
            <td><div class="doc-id">${esc(r.docId)}</div></td>
            <td><div class="doc-name" style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(r.docName)}</div></td>
            <td><span class="rtype ${rtypeClass(r.routeType)}">${esc(r.routeType)}</span></td>
            <td>
              <div style="display:flex;align-items:center;gap:4px">
                <div class="dept-av" style="background:${gc(r.from)}">${ini(r.from)}</div><span class="dept-name">${esc(r.from)}</span>
                <i class='bx bx-right-arrow-alt' style="font-size:14px;color:var(--text-3)"></i>
                <div class="dept-av" style="background:${gc(r.to)}">${ini(r.to)}</div><span class="dept-name">${esc(r.to)}</span>
              </div>
            </td>
            <td><span style="font-size:12px;font-weight:500">${esc(r.assignee)}</span></td>
            <td><div class="date-val">${fmtDate(r.dateRouted)}</div></td>
            <td><span style="font-size:11px;font-weight:700;background:var(--bg);border:1px solid var(--border);color:var(--text-2);padding:2px 8px;border-radius:6px">${esc(r.module)}</span></td>
            <td onclick="event.stopPropagation()">
              <div class="row-acts">
                <button class="icon-btn" onclick="openView(${r.id})"><i class='bx bx-show'></i></button>
                ${CAP.canUpdateStatus?`<button class="icon-btn" onclick="openStatus(${r.id})"><i class='bx bx-refresh'></i></button>`:''}
                ${CAP.canOverride?`<button class="icon-btn gold" onclick="openOverride(${r.id})"><i class='bx bx-shield-quarter'></i></button>`:''}
              </div>
            </td>
          </tr>`).join('');
    } catch(e) { toast('Failed to load transit: ' + e.message, 'danger'); }
}

// ── OVERRIDE QUEUE ────────────────────────────────────────────────────────────
async function loadOverride() {
    if (!CAP.tabOverride) return;
    try {
        const rows = await apiGet(API + '?api=override-queue');
        const el = document.getElementById('overrideList');
        if (!rows.length) { el.innerHTML = '<div class="po-empty"><i class=\'bx bx-check-circle\'></i><p>No active routes available for override.</p></div>'; return; }
        el.innerHTML = rows.map(r => `
          <div class="override-item">
            <div class="override-hd">
              <div>
                <div class="override-id">${esc(r.routeId)} · <span class="chip ${chipClass(r.status)}">${esc(r.status)}</span></div>
                <div class="override-name">${esc(r.docName)}</div>
                <div class="override-meta">${esc(r.from)} → ${esc(r.to)} · Assignee: ${esc(r.assignee)} · ${esc(r.routeType)} ${r.zone?'· '+esc(r.zone):''}</div>
              </div>
              <div style="display:flex;gap:8px;flex-shrink:0">
                <button class="btn btn-g btn-s" onclick="openView(${r.id})"><i class='bx bx-history'></i> History</button>
                <button class="btn btn-gold btn-s" onclick="openOverride(${r.id})"><i class='bx bx-shield-quarter'></i> Force Reroute</button>
              </div>
            </div>
          </div>`).join('');
    } catch(e) { toast('Failed to load override queue: ' + e.message, 'danger'); }
}

// ── VIEW MODAL ────────────────────────────────────────────────────────────────
window.openView = async id => {
    showModal('viewModal');
    document.getElementById('viewBody').innerHTML =
        '<div style="padding:20px"><div class="skeleton" style="height:12px;width:60%;margin-bottom:10px"></div><div class="skeleton" style="height:12px;width:80%;margin-bottom:10px"></div><div class="skeleton" style="height:100px;margin-top:16px;border-radius:10px"></div></div>';
    document.getElementById('viewFt').innerHTML = '';
    try {
        const fetchList = apiGet(API + `?api=list&page=1&per=500`);
        const fetchHist = apiGet(API + `?api=history&id=${id}`);
        const fetchAudit = CAP.canViewAuditRoute ? apiGet(API + `?api=audit&id=${id}`) : Promise.resolve([]);
        const [routes, history, audit] = await Promise.all([fetchList, fetchHist, fetchAudit]);
        const r = routes.items.find(x => x.id === id);
        if (!r) { toast('Route not found', 'danger'); closeModal('viewModal'); return; }

        document.getElementById('viewTitle').textContent = r.routeId;
        document.getElementById('viewSub').textContent   = `${r.docName} · ${r.routeType}`;

        const steps = history.map(h => `
          <div class="rt-step">
            <div class="rt-dot ${h.step_type}"><i class='bx ${h.icon || "bx-time"}'></i></div>
            <div class="rt-info">
              <div class="rt-role">${esc(h.role_label)}</div>
              ${h.actor_name ? `<div class="rt-by">${esc(h.actor_name)}</div>` : '<div class="rt-by" style="color:var(--text-3);font-style:italic">Awaiting</div>'}
              <div class="rt-ts">${fmtTs(h.occurred_at)}</div>
              ${h.note ? `<div class="rt-note">${esc(h.note)}</div>` : ''}
            </div>
          </div>`).join('') || '<div style="color:var(--text-3);font-size:12px;padding:10px 0">No history steps yet.</div>';

        const auditHtml = audit.length
            ? audit.map(a => `<div class="audit-item"><div class="audit-dot ${a.dot_class||'dot-b'}"></div><div><div class="audit-act">${esc(a.action_label)}</div><div class="audit-by">By ${esc(a.actor_name)} ${a.is_super_admin?'<span style="font-size:9px;background:#FEF3C7;color:var(--gold);padding:1px 5px;border-radius:4px;font-weight:700;margin-left:4px">SA</span>':''}</div><div class="audit-ts">${fmtTs(a.occurred_at)}</div></div></div>`).join('')
            : '';

        document.getElementById('viewBody').innerHTML = `
          <div class="info-grid">
            <div class="info-item"><label>Route ID</label><div class="v mono">${esc(r.routeId)}</div></div>
            <div class="info-item"><label>Status</label><div class="v"><span class="chip ${chipClass(r.status)}">${esc(r.status)}</span></div></div>
            <div class="info-item"><label>From</label><div class="v">${esc(r.from)}</div></div>
            <div class="info-item"><label>To</label><div class="v">${esc(r.to)}</div></div>
            <div class="info-item"><label>Assigned To</label><div class="v">${esc(r.assignee)}</div></div>
            <div class="info-item"><label>Route Type</label><div class="v"><span class="rtype ${rtypeClass(r.routeType)}">${esc(r.routeType)}</span></div></div>
            <div class="info-item"><label>Date Routed</label><div class="v">${fmtDate(r.dateRouted)}</div></div>
            <div class="info-item"><label>Due Date</label><div class="v">${fmtDate(r.dueDate)}</div></div>
            <div class="info-item"><label>Priority</label><div class="v">${esc(r.priority)}</div></div>
            ${r.zone?`<div class="info-item"><label>Zone</label><div class="v">${esc(r.zone)}</div></div>`:''}
          </div>
          ${r.notes ? `<div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 14px;font-size:12px;color:var(--text-2);margin-bottom:16px"><strong>Notes:</strong> ${esc(r.notes)}</div>` : ''}
          ${r.isOverridden ? `<div style="background:#FEF3C7;border:1px solid rgba(180,83,9,.2);border-radius:10px;padding:10px 14px;font-size:12px;color:var(--gold);margin-bottom:16px"><strong>SA Override:</strong> ${esc(r.overrideReason)} — by ${esc(r.overriddenBy)} at ${fmtTs(r.overriddenAt)}</div>` : ''}
          <div style="font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--primary);margin-bottom:12px">Routing History</div>
          <div class="route-timeline">${steps}</div>
          ${auditHtml ? `<div style="font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--primary);margin:16px 0 12px">Activity Log</div><div>${auditHtml}</div>` : ''}`;

        // Footer buttons by role
        let ftBtns = `<button class="btn btn-g btn-s" onclick="closeModal('viewModal')">Close</button>`;
        if (RANK === 1) {
            if (r.status === 'In Transit') ftBtns = `<button class="btn btn-grn btn-s" onclick="closeModal('viewModal');quickStatus(${r.id},'Received','Document received')"><i class='bx bx-check'></i> Mark Received</button>` + ftBtns;
            if (CAP.canForward && r.status !== 'Completed') ftBtns = `<button class="btn btn-blue btn-s" onclick="closeModal('viewModal');openForward(${r.id})"><i class='bx bx-right-arrow-alt'></i> Forward</button>` + ftBtns;
            if (r.status !== 'Completed') ftBtns = `<button class="btn btn-g btn-s" onclick="closeModal('viewModal');quickStatus(${r.id},'Returned','')"><i class='bx bx-undo'></i> Return</button>` + ftBtns;
        } else if (RANK === 2) {
            if (CAP.canUpdateStatus) ftBtns = `<button class="btn btn-info btn-s" onclick="closeModal('viewModal');openStatus(${r.id})"><i class='bx bx-refresh'></i> Update Status</button>` + ftBtns;
            if (CAP.canReassign)     ftBtns = `<button class="btn btn-p btn-s" onclick="closeModal('viewModal');openReassign(${r.id})"><i class='bx bx-transfer-alt'></i> Reassign</button>` + ftBtns;
            if (CAP.canFlagDelay)    ftBtns = `<button class="btn btn-warn btn-s" onclick="closeModal('viewModal');openFlag(${r.id})"><i class='bx bx-flag'></i> Flag Delay</button>` + ftBtns;
        } else {
            if (CAP.canUpdateStatus) ftBtns = `<button class="btn btn-info btn-s" onclick="closeModal('viewModal');openStatus(${r.id})"><i class='bx bx-refresh'></i> Update Status</button>` + ftBtns;
            if (CAP.canOverride)     ftBtns = `<button class="btn btn-gold btn-s" onclick="closeModal('viewModal');openOverride(${r.id})"><i class='bx bx-shield-quarter'></i> Force Override</button>` + ftBtns;
        }
        document.getElementById('viewFt').innerHTML = ftBtns;
    } catch(e) { toast('Error loading view: ' + e.message, 'danger'); closeModal('viewModal'); }
};

// ── STATUS MODAL ──────────────────────────────────────────────────────────────
window.openStatus = async id => {
    if (!CAP.canUpdateStatus && !CAP.canReceive) return toast('Insufficient permissions', 'danger');
    try {
        const d = await apiGet(API + `?api=list&page=1&per=500`);
        const r = d.items.find(x => x.id === id); if (!r) return toast('Route not found', 'danger');
        activeStatusId = id;
        document.getElementById('statusTitle').textContent = `Update Status — ${r.routeId}`;
        document.getElementById('statusSub').textContent   = r.docName;
        document.getElementById('statusInfo').innerHTML = `
          <div class="info-item"><label>Current Status</label><div class="v"><span class="chip ${chipClass(r.status)}">${esc(r.status)}</span></div></div>
          <div class="info-item"><label>Assigned To</label><div class="v">${esc(r.assignee)}</div></div>`;
        document.getElementById('newStatus').value    = '';
        document.getElementById('statusRemarks').value = '';
        showModal('statusModal');
    } catch(e) { toast('Error: ' + e.message, 'danger'); }
};
document.getElementById('statusSave').addEventListener('click', async () => {
    const status  = document.getElementById('newStatus').value;
    const remarks = document.getElementById('statusRemarks').value.trim();
    if (!status) { shk('newStatus'); return toast('Select a new status', 'danger'); }
    const btn = document.getElementById('statusSave');
    btn.disabled = true; btn.innerHTML = `<i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i> Saving…`;
    try {
        await apiPost(API + '?api=update-status', { id: activeStatusId, status, remarks });
        toast(`Status updated to ${status}`, 'success');
        closeModal('statusModal'); activeStatusId = null; refresh();
    } catch(e) { toast('Update failed: ' + e.message, 'danger'); }
    finally { btn.disabled = false; btn.innerHTML = `<i class='bx bx-check'></i> Update Status`; }
});

// ── FORWARD MODAL (Staff) ─────────────────────────────────────────────────────
window.openForward = async id => {
    if (!CAP.canForward) return;
    try {
        const d = await apiGet(API + `?api=list&page=1&per=500`);
        const r = d.items.find(x => x.id === id); if (!r) return toast('Route not found', 'danger');
        activeFwdId = id;
        document.getElementById('fwdTitle').textContent = `Forward — ${r.routeId}`;
        document.getElementById('fwdInfo').innerHTML = `<div class="info-item"><label>Document</label><div class="v">${esc(r.docName)}</div></div><div class="info-item"><label>Current To</label><div class="v">${esc(r.to)}</div></div>`;
        document.getElementById('fwdRemarks').value = '';
        document.getElementById('fwdAssignee').value = '';
        showModal('forwardModal');
    } catch(e) { toast('Error: ' + e.message, 'danger'); }
};
const fwdSaveEl = document.getElementById('fwdSave');
if (fwdSaveEl) fwdSaveEl.addEventListener('click', async () => {
    const assignee = document.getElementById('fwdAssignee').value;
    const remarks  = document.getElementById('fwdRemarks').value.trim();
    if (!assignee) { shk('fwdAssignee'); return toast('Select a person to forward to', 'danger'); }
    const btn = fwdSaveEl; btn.disabled = true;
    try {
        await apiPost(API + '?api=forward', { id: activeFwdId, assignee, remarks });
        toast(`Forwarded to ${assignee}`, 'success');
        closeModal('forwardModal'); activeFwdId = null; loadMyTasks(); loadStats();
    } catch(e) { toast(e.message, 'danger'); }
    finally { btn.disabled = false; }
});

// ── REASSIGN MODAL (Manager) ──────────────────────────────────────────────────
window.openReassign = async id => {
    if (!CAP.canReassign) return;
    try {
        const d = await apiGet(API + `?api=list&page=1&per=500`);
        const r = d.items.find(x => x.id === id); if (!r) return toast('Route not found', 'danger');
        activeReassignId = id;
        document.getElementById('reassignTitle').textContent = `Reassign — ${r.routeId}`;
        document.getElementById('reassignInfo').innerHTML = `<div class="info-item"><label>Document</label><div class="v">${esc(r.docName)}</div></div><div class="info-item"><label>Current Assignee</label><div class="v">${esc(r.assignee)}</div></div>`;
        document.getElementById('reassignTo').value = '';
        document.getElementById('reassignRemarks').value = '';
        showModal('reassignModal');
    } catch(e) { toast('Error: ' + e.message, 'danger'); }
};
const reassignSaveEl = document.getElementById('reassignSave');
if (reassignSaveEl) reassignSaveEl.addEventListener('click', async () => {
    const assignee = document.getElementById('reassignTo').value;
    const remarks  = document.getElementById('reassignRemarks').value.trim();
    if (!assignee) { shk('reassignTo'); return toast('Select a person', 'danger'); }
    const btn = reassignSaveEl; btn.disabled = true;
    try {
        await apiPost(API + '?api=reassign', { id: activeReassignId, assignee, remarks });
        toast(`Reassigned to ${assignee}`, 'success');
        closeModal('reassignModal'); activeReassignId = null; loadTeam(); loadStats();
    } catch(e) { toast(e.message, 'danger'); }
    finally { btn.disabled = false; }
});

// ── FLAG DELAY MODAL (Manager) ────────────────────────────────────────────────
window.openFlag = async id => {
    if (!CAP.canFlagDelay) return;
    try {
        const d = await apiGet(API + `?api=list&page=1&per=500`);
        const r = d.items.find(x => x.id === id); if (!r) return toast('Route not found', 'danger');
        activeFlagId = id;
        document.getElementById('flagTitle').textContent = `Flag Delay — ${r.routeId}`;
        document.getElementById('flagSub').textContent   = r.docName;
        document.getElementById('flagReason').value = '';
        showModal('flagModal');
    } catch(e) { toast('Error: ' + e.message, 'danger'); }
};
const flagSaveEl = document.getElementById('flagSave');
if (flagSaveEl) flagSaveEl.addEventListener('click', async () => {
    const reason = document.getElementById('flagReason').value.trim();
    if (!reason) { shk('flagReason'); return toast('Reason required', 'danger'); }
    const btn = flagSaveEl; btn.disabled = true;
    try {
        await apiPost(API + '?api=flag-delay', { id: activeFlagId, reason });
        toast('Routing delay flagged', 'warning');
        closeModal('flagModal'); activeFlagId = null;
    } catch(e) { toast(e.message, 'danger'); }
    finally { btn.disabled = false; }
});

// ── OVERRIDE MODAL (SA) ───────────────────────────────────────────────────────
window.openOverride = async id => {
    if (!CAP.canOverride) return;
    try {
        const d = await apiGet(API + `?api=list&page=1&per=500`);
        const r = d.items.find(x => x.id === id); if (!r) return toast('Route not found', 'danger');
        activeOverrideId = id;
        document.getElementById('overrideTitle').textContent = `Force Reroute — ${r.routeId}`;
        document.getElementById('overrideInfo').innerHTML = `
          <div class="info-item"><label>Document</label><div class="v">${esc(r.docName)}</div></div>
          <div class="info-item"><label>Current Route</label><div class="v">${esc(r.from)} → ${esc(r.to)}</div></div>
          <div class="info-item"><label>Assignee</label><div class="v">${esc(r.assignee)}</div></div>
          <div class="info-item"><label>Status</label><div class="v"><span class="chip ${chipClass(r.status)}">${esc(r.status)}</span></div></div>`;
        document.getElementById('overrideDept').value   = '';
        document.getElementById('overridePerson').value = '';
        document.getElementById('overrideReason').value = '';
        showModal('overrideModal');
    } catch(e) { toast('Error: ' + e.message, 'danger'); }
};
const overrideSaveEl = document.getElementById('overrideSave');
if (overrideSaveEl) overrideSaveEl.addEventListener('click', async () => {
    const dept   = document.getElementById('overrideDept').value;
    const person = document.getElementById('overridePerson').value;
    const reason = document.getElementById('overrideReason').value.trim();
    if (!dept)   { shk('overrideDept');   return toast('Select destination department', 'danger'); }
    if (!reason) { shk('overrideReason'); return toast('Override reason required', 'danger'); }
    const btn = overrideSaveEl; btn.disabled = true; btn.innerHTML = `<i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i> Applying…`;
    try {
        await apiPost(API + '?api=override', { id: activeOverrideId, dept, person, reason });
        toast(`Route forcibly rerouted to ${dept}`, 'warning');
        closeModal('overrideModal'); activeOverrideId = null; refresh();
    } catch(e) { toast('Override failed: ' + e.message, 'danger'); }
    finally { btn.disabled = false; btn.innerHTML = `<i class='bx bx-check-shield'></i> Apply Override`; }
});

// ── ROUTE PANEL (Create / Edit) ───────────────────────────────────────────────
function openRoutePanel(id = null) {
    if (!CAP.canCreate && !CAP.canCreateOwn) return;
    editRouteId = id;
    const isEdit = !!id;
    document.getElementById('routePanelTitle').textContent = isEdit ? 'Edit Route' : (RANK === 1 ? 'Route My Document' : 'New Document Route');
    document.getElementById('routeSaveLabel').textContent  = isEdit ? 'Save Changes' : 'Submit Route';
    clearDocPicker();
    ['rDocName','rNotes','rDocDept'].forEach(x => { const el = document.getElementById(x); if (el) el.value = ''; });
    ['rDocType','rFrom','rTo','rPriority'].forEach(x => { const el = document.getElementById(x); if (el) el.value = ''; });
    document.getElementById('rDueDate').value = '';
    document.getElementById('rAssignee').selectedIndex = 0;
    document.querySelectorAll('.rtype-opt').forEach(o => o.classList.remove('selected'));
    document.querySelectorAll('input[name="routeType"]').forEach(r => r.checked = false);
    document.getElementById('routePanel').classList.add('show');
    document.getElementById('ov').classList.add('show');
}
function closeRoutePanel() {
    document.getElementById('routePanel').classList.remove('show');
    document.getElementById('ov').classList.remove('show');
    editRouteId = null;
}
async function openEdit(id) {
    if (!CAP.canEdit) return;
    openRoutePanel(id);
}

const routeBtnEl = document.getElementById('routeBtn');
if (routeBtnEl) routeBtnEl.addEventListener('click', () => openRoutePanel());
const rpClEl = document.getElementById('routePanelCl');
if (rpClEl) rpClEl.addEventListener('click', closeRoutePanel);
const rpCancelEl = document.getElementById('routePanelCancel');
if (rpCancelEl) rpCancelEl.addEventListener('click', closeRoutePanel);

document.querySelectorAll('.rtype-opt').forEach(opt => {
    opt.addEventListener('click', () => {
        document.querySelectorAll('.rtype-opt').forEach(o => o.classList.remove('selected'));
        opt.classList.add('selected');
    });
});

const routeSaveEl = document.getElementById('routeSave');
if (routeSaveEl) routeSaveEl.addEventListener('click', async () => {
    const docName   = document.getElementById('rDocName').value.trim();
    const fromDept  = document.getElementById('rFrom').value;
    const toDept    = document.getElementById('rTo').value;
    const assignee  = document.getElementById('rAssignee').value;
    const rtEl      = document.querySelector('input[name="routeType"]:checked');
    if (!docName)  { shk('rDocName'); return toast('Document name is required', 'danger'); }
    if (!fromDept) { shk('rFrom');    return toast('Originating department is required', 'danger'); }
    if (!toDept)   { shk('rTo');      return toast('Destination department is required', 'danger'); }
    if (!assignee) { shk('rAssignee');return toast('Assignee is required', 'danger'); }
    if (!rtEl)     return toast('Select a route type', 'danger');

    const btn = routeSaveEl; btn.disabled = true;
    btn.innerHTML = `<i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i> Saving…`;
    try {
        const payload = {
            docName, docId: document.getElementById('rDocId').value.trim(),
            docType: document.getElementById('rDocType').value,
            from: fromDept, to: toDept, assignee,
            routeType: rtEl.value,
            priority: document.getElementById('rPriority').value || 'Normal',
            dueDate: document.getElementById('rDueDate').value || null,
            notes: document.getElementById('rNotes').value.trim(),
            zone: ZONE || '',
        };
        if (editRouteId) {
            payload.id = editRouteId;
            await apiPost(API + '?api=update', payload);
            toast('Route updated successfully', 'success');
        } else {
            const saved = await apiPost(API + '?api=create', payload);
            toast(`Route ${saved.routeId} created`, 'success');
        }
        closeRoutePanel(); refresh();
    } catch(e) { toast('Save failed: ' + e.message, 'danger'); }
    finally { btn.disabled = false; btn.innerHTML = `<i class='bx bx-git-branch'></i> <span id="routeSaveLabel">${editRouteId?'Save Changes':'Submit Route'}</span>`; }
});

// ── AUDIT BTN (SA) ────────────────────────────────────────────────────────────
const auditBtnEl = document.getElementById('auditBtn');
if (auditBtnEl) auditBtnEl.addEventListener('click', async () => {
    if (!CAP.canViewAuditAll) return;
    document.getElementById('auditList').innerHTML =
        '<div class="skeleton" style="height:60px;border-radius:8px;margin-bottom:10px"></div><div class="skeleton" style="height:60px;border-radius:8px"></div>';
    showModal('auditModal');
    try {
        const rows = await apiGet(API + '?api=audit-all');
        document.getElementById('auditSub').textContent = `${rows.length} entries system-wide`;
        document.getElementById('auditList').innerHTML = rows.length
            ? rows.map(a => `<div class="audit-item"><div class="audit-dot ${a.dot_class||'dot-b'}"></div><div><div class="audit-act">${esc(a.action_label)}</div><div class="audit-by">By ${esc(a.actor_name)} ${a.is_super_admin?'<span style="font-size:9px;background:#FEF3C7;color:var(--gold);padding:1px 5px;border-radius:4px;font-weight:700;margin-left:4px">SA</span>':''}</div><div class="audit-ts">${fmtTs(a.occurred_at)}</div></div></div>`).join('')
            : '<div style="padding:20px;text-align:center;color:var(--text-3);font-size:12px">No audit entries yet.</div>';
    } catch(e) { toast('Audit load error: ' + e.message, 'danger'); }
});

// ── EXPORT ────────────────────────────────────────────────────────────────────
const exportBtnEl = document.getElementById('exportBtn');
if (exportBtnEl) exportBtnEl.addEventListener('click', async () => {
    if (!CAP.canExport) return;
    try {
        const rows = await apiGet(API + '?api=export');
        const cols = ['routeId','docId','docName','docType','from','to','assignee','routeType','priority','dateRouted','dueDate','status','module','zone'];
        const lines = [cols.join(',')];
        rows.forEach(r => lines.push(cols.map(c => `"${String(r[c]??'').replace(/"/g,'""')}"`).join(',')));
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
        a.download = `dtrs_routing_${new Date().toISOString().split('T')[0]}.csv`;
        a.click(); toast('Exported successfully', 'success');
    } catch(e) { toast('Export failed: ' + e.message, 'danger'); }
});

// ── TABS ──────────────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(b => b.addEventListener('click', () => {
    const name = b.dataset.tab;
    document.querySelectorAll('.tab-btn').forEach(x => x.classList.toggle('active', x === b));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + name));
    document.getElementById('filterBar').style.display = (name === 'routing' || name === 'team' || name === 'mytasks') ? 'flex' : 'none';
    if (name === 'transit')  loadTransit();
    if (name === 'override') loadOverride();
    if (name === 'team')     loadTeam();
    if (name === 'mytasks')  loadMyTasks();
    if (name === 'routing')  loadTable();
}));

// ── DOCUMENT PICKER ───────────────────────────────────────────────────────────
let dpTimeout = null;
let dpSelected = null;
const dpInputEl    = () => document.getElementById('docPickerInput');
const dpDropEl     = () => document.getElementById('docPickerDropdown');
const dpBadgeEl    = () => document.getElementById('docPickerSelected');
const dpClearBtnEl = () => document.getElementById('docPickerClear');

const dpInputElem = document.getElementById('docPickerInput');
if (dpInputElem) {
    dpInputElem.addEventListener('input', function() {
        clearTimeout(dpTimeout);
        const q = this.value.trim();
        dpClearBtnEl().style.display = q ? 'block' : 'none';
        if (!q) { dpDropEl().style.display = 'none'; return; }
        dpDropEl().style.display = 'block';
        dpDropEl().innerHTML = '<div class="dp-loading"><i class=\'bx bx-loader-alt\' style="animation:spin .8s linear infinite;font-size:14px"></i> Searching…</div>';
        dpTimeout = setTimeout(() => searchDocs(q), 250);
    });
    document.addEventListener('click', e => {
        if (!e.target.closest('#docPickerInput') && !e.target.closest('#docPickerDropdown'))
            dpDropEl().style.display = 'none';
    });
}
async function searchDocs(q) {
    try {
        const results = await apiGet(API + '?api=docs-search&q=' + encodeURIComponent(q));
        const dd = dpDropEl();
        if (!results.length) { dd.innerHTML = `<div class="dp-empty"><i class='bx bx-search' style="font-size:20px;display:block;margin-bottom:4px;color:#C8E6C9"></i>No documents found</div>`; return; }
        dd.innerHTML = results.map(r => `
          <div class="dp-item" onclick="selectDoc(${JSON.stringify(r).replace(/"/g,'&quot;')})">
            <div class="dp-item-ic"><i class='bx bx-file-blank'></i></div>
            <div style="min-width:0;flex:1">
              <div class="dp-item-id">${esc(r.docId)}</div>
              <div class="dp-item-title">${esc(r.title)}</div>
              <div class="dp-item-meta">${r.docType?`<span>${esc(r.docType)}</span>`:''} ${r.department?`<span>${esc(r.department)}</span>`:''}</div>
            </div>
          </div>`).join('');
    } catch(e) { dpDropEl().innerHTML = `<div class="dp-empty" style="color:var(--danger)">Search error: ${esc(e.message)}</div>`; }
}
const DEPT_MAP = {'procurement':'PSM','psm':'PSM','supply chain':'PSM','logistics':'Operations','hr':'HR','human resources':'HR','finance':'Finance','accounting':'Finance','legal':'Legal','compliance':'Legal','admin':'Admin','administration':'Admin','it':'IT Department','it department':'IT Department','information technology':'IT Department','operations':'Operations','executive':'Executive Office','executive office':'Executive Office','management':'Executive Office'};
function resolveDept(dept, selectId) {
    if (!dept) return '';
    const sel = document.getElementById(selectId); if (!sel) return '';
    const opts = [...sel.options].filter(o => o.value);
    const d = dept.trim().toLowerCase();
    const exact = opts.find(o => o.value.toLowerCase() === d);
    if (exact) return exact.value;
    if (DEPT_MAP[d]) return DEPT_MAP[d];
    const partial = opts.find(o => o.value.toLowerCase().includes(d) || d.includes(o.value.toLowerCase()));
    return partial ? partial.value : '';
}
function flashFill(el) {
    if (!el) return;
    el.style.transition = 'border-color .15s,background .15s';
    el.style.borderColor = 'var(--primary)';
    el.style.background  = '#F0FDF4';
    setTimeout(() => { el.style.borderColor = ''; el.style.background = ''; }, 900);
}
window.selectDoc = function(r) {
    dpSelected = r;
    dpDropEl().style.display = 'none';
    dpInputEl().value = r.docId + ' — ' + r.title;
    dpClearBtnEl().style.display = 'block';
    const badge = dpBadgeEl(); badge.style.display = 'flex';
    document.getElementById('pickerBadgeTitle').textContent = r.title;
    document.getElementById('pickerBadgeId').textContent    = r.docId;
    document.getElementById('pickerBadgeType').textContent  = r.docType || '';
    document.getElementById('pickerBadgeDept').textContent  = r.department || '';
    document.getElementById('rDocId').value   = r.docId;
    document.getElementById('rDocDbId').value = r.id;
    const nameEl = document.getElementById('rDocName'); nameEl.value = r.title; flashFill(nameEl);
    if (r.docType) {
        const TYPE_MAP = {'memo':'Memo','memorandum':'Memo','contract':'Contract','agreement':'Contract','invoice':'Invoice','billing':'Invoice','report':'Report','policy':'Policy','legal document':'Legal Document','legal':'Legal Document','hr document':'HR Document','hr':'HR Document'};
        const typeSel = document.getElementById('rDocType');
        const dL = r.docType.trim().toLowerCase();
        const opts = [...typeSel.options].filter(o => o.value);
        let resolved = opts.find(o => o.value.toLowerCase() === dL)?.value || TYPE_MAP[dL] || opts.find(o => dL.includes(o.value.toLowerCase()) || o.value.toLowerCase().includes(dL))?.value;
        if (resolved) { typeSel.value = resolved; flashFill(typeSel); }
    }
    if (r.department) {
        const deptEl = document.getElementById('rDocDept'); deptEl.value = r.department; flashFill(deptEl);
        const resolved = resolveDept(r.department, 'rFrom');
        if (resolved) { const fromSel = document.getElementById('rFrom'); fromSel.value = resolved; flashFill(fromSel); }
    }
};
window.clearDocPicker = function() {
    dpSelected = null;
    if (dpInputEl()) dpInputEl().value = '';
    if (dpClearBtnEl()) dpClearBtnEl().style.display = 'none';
    if (dpDropEl())  dpDropEl().style.display = 'none';
    if (dpBadgeEl()) dpBadgeEl().style.display = 'none';
    ['rDocId','rDocDbId','rDocName','rDocType','rDocDept','rFrom'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
};

// ── MODAL HELPERS ─────────────────────────────────────────────────────────────
function showModal(id)  { document.getElementById(id).classList.add('show'); document.getElementById('ov').classList.add('show'); }
function closeModal(id) {
    document.getElementById(id).classList.remove('show');
    const anyOpen = ['viewModal','statusModal','overrideModal','auditModal','forwardModal','reassignModal','flagModal'].some(mid => document.getElementById(mid)?.classList.contains('show'));
    if (!anyOpen && !document.getElementById('routePanel').classList.contains('show'))
        document.getElementById('ov').classList.remove('show');
}
document.getElementById('ov').addEventListener('click', () => {
    ['viewModal','statusModal','overrideModal','auditModal','forwardModal','reassignModal','flagModal'].forEach(id => { const el = document.getElementById(id); if (el) el.classList.remove('show'); });
    closeRoutePanel();
    document.getElementById('ov').classList.remove('show');
});

// ── REFRESH ───────────────────────────────────────────────────────────────────
function refresh() {
    loadStats();
    if (RANK === 1)      loadMyTasks();
    else if (RANK === 2) loadTeam();
    else                 loadTable();
}

// ── TOAST ─────────────────────────────────────────────────────────────────────
function toast(msg, type = 'success') {
    const icons = { success:'bx-check-circle', danger:'bx-error-circle', warning:'bx-error', info:'bx-info-circle' };
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<i class='bx ${icons[type]}' style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('tw').appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 300); }, 3200);
}
function shk(id) {
    const el = document.getElementById(id); if (!el) return;
    el.style.borderColor = '#DC2626';
    el.style.animation   = 'shake .3s ease';
    setTimeout(() => { el.style.borderColor = ''; el.style.animation = ''; }, 600);
}
</script>
</body>
</html>