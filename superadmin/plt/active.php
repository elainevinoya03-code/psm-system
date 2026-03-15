<?php
declare(strict_types=1);

ob_start();

$root = dirname(__DIR__, 2);

ini_set('display_errors', '0');
ini_set('log_errors',     '1');

require_once $root . '/config/config.php';

if (!defined('PG_DSN'))         define('PG_DSN',         'pgsql:host=aws-1-ap-northeast-1.pooler.supabase.com;port=5432;dbname=postgres;sslmode=require');
if (!defined('PG_DB_USER'))     define('PG_DB_USER',     'postgres.fnpxtquhvlflyjibuwlx');
if (!defined('PG_DB_PASSWORD')) define('PG_DB_PASSWORD', '0ltvCJjD0CkZoBpX');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── ROLE RESOLUTION (mirrors superadmin_sidebar.php) ─────────────────────────
function _plt_resolve_role(): string {
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

$roleName = _plt_resolve_role();
$roleRank = match($roleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1, // Staff / User
};

// ── PERMISSION GATES ──────────────────────────────────────────────────────────
$CAN_VIEW_ALL_ZONES     = $roleRank >= 4; // SA: all zones
$CAN_VIEW_ZONE          = $roleRank >= 3; // Admin: own zone
$CAN_CREATE_EDIT        = $roleRank >= 4; // SA only
$CAN_BUDGET_OVERRIDE    = $roleRank >= 4; // SA only
$CAN_FORCE_CLOSE        = $roleRank >= 4; // SA only
$CAN_FORCE_COMPLETE     = $roleRank >= 4; // SA only
$CAN_CROSS_REASSIGN     = $roleRank >= 4; // SA only
$CAN_BATCH_CLOSE        = $roleRank >= 4; // SA only
$CAN_AUDIT_GLOBAL       = $roleRank >= 4; // SA only
$CAN_EXPORT             = $roleRank >= 3; // Admin+
$CAN_UPDATE_PROGRESS    = $roleRank >= 2; // Manager+ (flag delays, escalate)
$CAN_FLAG_DELAY         = $roleRank >= 2; // Manager+
$CAN_SEE_BUDGET         = $roleRank >= 2; // Manager+ see budget
$CAN_SEE_SYSTEM_BUDGET  = $roleRank >= 4; // SA only: system-wide budget consolidation

// Allowed status filters per role
$ALLOWED_STATUSES = match(true) {
    $roleRank >= 4 => ['Planning','Active','On Hold','Delayed','Completed','Terminated'],
    $roleRank >= 3 => ['Planning','Active','On Hold','Delayed','Completed'],
    $roleRank >= 2 => ['Planning','Active','On Hold','Delayed'],
    default        => ['Active'],
};

$currentUser = [
    'user_id'   => $_SESSION['user_id']   ?? null,
    'full_name' => $_SESSION['full_name'] ?? ($_SESSION['name'] ?? 'Super Admin'),
    'email'     => $_SESSION['email']     ?? '',
    'roles'     => [$roleName],
    'zone'      => $_SESSION['zone']      ?? '',
];

// ── JS ROLE CAPABILITIES ──────────────────────────────────────────────────────
$jsRole = json_encode([
    'name'               => $roleName,
    'rank'               => $roleRank,
    'canCreateEdit'      => $CAN_CREATE_EDIT,
    'canBudgetOverride'  => $CAN_BUDGET_OVERRIDE,
    'canForceClose'      => $CAN_FORCE_CLOSE,
    'canForceComplete'   => $CAN_FORCE_COMPLETE,
    'canCrossReassign'   => $CAN_CROSS_REASSIGN,
    'canBatchClose'      => $CAN_BATCH_CLOSE,
    'canAuditGlobal'     => $CAN_AUDIT_GLOBAL,
    'canExport'          => $CAN_EXPORT,
    'canUpdateProgress'  => $CAN_UPDATE_PROGRESS,
    'canFlagDelay'       => $CAN_FLAG_DELAY,
    'canSeeBudget'       => $CAN_SEE_BUDGET,
    'canSeeSystemBudget' => $CAN_SEE_SYSTEM_BUDGET,
    'canViewAllZones'    => $CAN_VIEW_ALL_ZONES,
    'userZone'           => $currentUser['zone'],
    'allowedStatuses'    => $ALLOWED_STATUSES,
]);

// ═══════════════════════════════════════════════════════════════════════════
// API
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {

    ob_clean();
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');

    function getPDO(): PDO {
        static $pdo = null;
        if ($pdo) return $pdo;
        $dsn = PG_DSN;
        if (strpos($dsn, 'options=') === false) {
            $dsn .= ";options='--search_path%3Dpublic'";
        }
        $pdo = new PDO($dsn, PG_DB_USER, PG_DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        try { $pdo->exec('SET search_path TO public'); } catch (\Throwable $e) {}
        return $pdo;
    }

    function ok(mixed $data = null, string $message = 'OK'): void {
        echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
        exit;
    }
    function fail(string $message, int $code = 400, mixed $errors = null): void {
        http_response_code($code);
        echo json_encode(['success' => false, 'message' => $message, 'errors' => $errors]);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $action = trim($_GET['action']);
    $body   = [];
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
    }

    // ── LIST ───────────────────────────────────────────────────────────────
    function actionList(): void {
        global $roleRank, $currentUser, $ALLOWED_STATUSES;

        $pdo      = getPDO();
        $search   = trim($_GET['search']    ?? '');
        $status   = trim($_GET['status']    ?? '');
        $zone     = trim($_GET['zone']      ?? '');
        $priority = trim($_GET['priority']  ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to']   ?? '');
        $page     = max(1, (int)($_GET['page']     ?? 1));
        $perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 15)));

        $where = ['1=1']; $params = [];

        // Status scoping by role
        if ($status !== '' && in_array($status, $ALLOWED_STATUSES, true)) {
            $where[] = 'p.status = :status';
            $params[':status'] = $status;
        } elseif ($status === '' && $roleRank <= 1) {
            // Staff: active only
            $where[] = "p.status = 'Active'";
        } elseif ($status !== '') {
            // Requested a status not allowed for this role — ignore it
        }

        // Zone scoping
        if ($roleRank >= 4) {
            // SA: all zones; may filter by zone param
            if ($zone !== '') { $where[] = 'p.zone = :zone'; $params[':zone'] = $zone; }
        } elseif ($roleRank === 3) {
            // Admin: scoped to own zone (unless no zone set)
            $userZone = $currentUser['zone'] ?? '';
            if ($userZone !== '') { $where[] = 'p.zone = :zone'; $params[':zone'] = $userZone; }
        } elseif ($roleRank === 2) {
            // Manager: scoped to own zone
            $userZone = $currentUser['zone'] ?? '';
            if ($userZone !== '') { $where[] = 'p.zone = :zone'; $params[':zone'] = $userZone; }
        } else {
            // Staff/User: only projects where they are the manager (assigned)
            $userName = $currentUser['full_name'] ?? '';
            if ($userName !== '') {
                $where[] = '(p.manager = :uname OR p.created_by = :uname2)';
                $params[':uname']  = $userName;
                $params[':uname2'] = $userName;
            } else {
                $where[] = '1=0'; // no user identity, show nothing
            }
        }

        if ($search !== '') {
            $where[] = "(p.project_id ILIKE :search OR p.name ILIKE :search
                         OR p.manager ILIKE :search OR p.ref ILIKE :search)";
            $params[':search'] = "%$search%";
        }
        if ($priority !== '') { $where[] = 'p.priority = :priority'; $params[':priority'] = $priority; }
        if ($dateFrom !== '') { $where[] = 'p.start_date >= :date_from'; $params[':date_from'] = $dateFrom; }
        if ($dateTo   !== '') { $where[] = 'p.start_date <= :date_to';   $params[':date_to']   = $dateTo;   }

        $whereSQL = implode(' AND ', $where);

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM plt_projects p WHERE $whereSQL");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("
            SELECT p.id, p.project_id, p.name, p.zone, p.manager, p.priority,
                   p.start_date, p.end_date, p.ref, p.budget, p.spend,
                   p.progress, p.status, p.description, p.conflict,
                   p.conflict_note, p.created_at, p.updated_at
            FROM plt_projects p
            WHERE $whereSQL
            ORDER BY p.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['budget']   = (float)$r['budget'];
            $r['spend']    = (float)$r['spend'];
            $r['progress'] = (int)$r['progress'];
            $r['conflict'] = (bool)$r['conflict'];
        }

        ok([
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int)ceil($total / $perPage),
            'stats'     => getStats($pdo),
            'filters'   => [
                'zones'      => getDistinct($pdo, 'zone'),
                'priorities' => getDistinct($pdo, 'priority'),
            ],
        ]);
    }

    function getStats(PDO $pdo): array {
        global $roleRank, $currentUser;

        // Build WHERE clause for zone scoping
        $where = ''; $params = [];
        if ($roleRank <= 3) {
            $userZone = $currentUser['zone'] ?? '';
            if ($userZone !== '') {
                $where = "WHERE zone = :zone";
                $params[':zone'] = $userZone;
            }
        } elseif ($roleRank <= 1) {
            $userName = $currentUser['full_name'] ?? '';
            $where = "WHERE manager = :uname";
            $params[':uname'] = $userName;
        }

        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)                                                    AS total,
                COUNT(*) FILTER (WHERE status = 'Planning')                 AS planning,
                COUNT(*) FILTER (WHERE status = 'Active')                   AS active,
                COUNT(*) FILTER (WHERE status = 'On Hold')                  AS on_hold,
                COUNT(*) FILTER (WHERE status = 'Delayed')                  AS delayed,
                COUNT(*) FILTER (WHERE status = 'Completed')                AS completed,
                COUNT(*) FILTER (WHERE status = 'Terminated')               AS terminated,
                COUNT(*) FILTER (WHERE conflict = TRUE)                     AS conflicts,
                COALESCE(SUM(budget) FILTER (WHERE status NOT IN ('Terminated')), 0) AS total_budget,
                COALESCE(SUM(spend)  FILTER (WHERE status NOT IN ('Terminated')), 0) AS total_spend
            FROM plt_projects $where
        ");
        $stmt->execute($params);
        $row = $stmt->fetch();
        return array_map(fn($v) => is_numeric($v) ? (float)$v : $v, $row);
    }

    function getDistinct(PDO $pdo, string $col): array {
        global $roleRank, $currentUser;

        if ($col === 'zone') {
            // SA sees all zones; others see only their zone
            if ($roleRank >= 4) {
                return $pdo->query("SELECT name AS val FROM sws_zones ORDER BY name")
                           ->fetchAll(PDO::FETCH_COLUMN);
            }
            $userZone = $currentUser['zone'] ?? '';
            return $userZone ? [$userZone] : [];
        }
        if ($col === 'priority') {
            return $pdo->query("SELECT DISTINCT priority AS val FROM plt_projects
                                WHERE priority IS NOT NULL ORDER BY priority")
                       ->fetchAll(PDO::FETCH_COLUMN);
        }
        return [];
    }

    // ── GET ────────────────────────────────────────────────────────────────
    function actionGet(): void {
        global $roleRank, $currentUser;
        $pdo = getPDO();
        $id  = (int)($_GET['id'] ?? 0);
        if (!$id) fail('Missing id');

        $s = $pdo->prepare("SELECT * FROM plt_projects WHERE id = :id");
        $s->execute([':id' => $id]);
        $proj = $s->fetch();
        if (!$proj) fail('Project not found', 404);

        // Zone scoping for non-SA
        if ($roleRank <= 3) {
            $userZone = $currentUser['zone'] ?? '';
            if ($userZone && $proj['zone'] !== $userZone) fail('Access denied to this project', 403);
        }
        // Staff: only own projects
        if ($roleRank <= 1) {
            $userName = $currentUser['full_name'] ?? '';
            if ($proj['manager'] !== $userName && $proj['created_by'] !== $userName) {
                fail('Access denied', 403);
            }
        }

        $proj['budget']   = (float)$proj['budget'];
        $proj['spend']    = (float)$proj['spend'];
        $proj['progress'] = (int)$proj['progress'];
        $proj['conflict'] = (bool)$proj['conflict'];

        $s = $pdo->prepare("SELECT * FROM plt_milestones WHERE project_id = :id ORDER BY sort_order ASC");
        $s->execute([':id' => $id]);
        $proj['milestones'] = $s->fetchAll();

        // Audit log only for SA
        if ($roleRank >= 4) {
            $s = $pdo->prepare("SELECT * FROM plt_audit_log WHERE project_id = :id ORDER BY occurred_at DESC");
            $s->execute([':id' => $id]);
            $proj['audit'] = $s->fetchAll();
        } else {
            $proj['audit'] = [];
        }

        ok($proj);
    }

    // ── CREATE ─────────────────────────────────────────────────────────────
    function actionCreate(): void {
        global $currentUser, $body;
        requireRole(4);
        $pdo = getPDO();

        $v = validatePayload($body);
        if ($v !== true) fail('Validation failed', 422, $v);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO plt_projects
                    (project_id, name, zone, manager, priority,
                     start_date, end_date, ref, budget, spend,
                     progress, status, description, conflict, conflict_note,
                     created_user_id, created_by)
                VALUES
                    (:project_id, :name, :zone, :manager, :priority,
                     :start_date, :end_date, :ref, :budget, 0,
                     :progress, :status, :description, :conflict, :conflict_note,
                     :created_user_id, :created_by)
                RETURNING id, project_id
            ");
            $conflict = !empty(trim($body['conflict_note'] ?? ''));
            $stmt->execute([
                ':project_id'      => strtoupper(trim($body['project_id'])),
                ':name'            => trim($body['name']),
                ':zone'            => trim($body['zone']),
                ':manager'         => trim($body['manager']),
                ':priority'        => trim($body['priority']),
                ':start_date'      => $body['start_date'],
                ':end_date'        => $body['end_date'],
                ':ref'             => trim($body['ref'] ?? ''),
                ':budget'          => (float)($body['budget'] ?? 0),
                ':progress'        => min(100, max(0, (int)($body['progress'] ?? 0))),
                ':status'          => $body['status'] ?? 'Planning',
                ':description'     => trim($body['description'] ?? ''),
                ':conflict'        => $conflict ? 'true' : 'false',
                ':conflict_note'   => trim($body['conflict_note'] ?? ''),
                ':created_user_id' => $currentUser['user_id'] ?? null,
                ':created_by'      => $currentUser['full_name'] ?? 'Super Admin',
            ]);
            $proj   = $stmt->fetch();
            $projId = (int)$proj['id'];

            insertAudit($pdo, $projId, 'Project Created',
                $currentUser['full_name'] ?? 'Super Admin', 'Super Admin',
                "Priority: {$body['priority']}. Manager: {$body['manager']}.", 'dot-g', true);

            $pdo->commit();
            ok(['id' => $projId, 'project_id' => $proj['project_id']],
               "Project {$proj['project_id']} created");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if (str_contains($e->getMessage(), '23505')) fail('Project ID already exists', 409);
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── UPDATE ─────────────────────────────────────────────────────────────
    function actionUpdate(): void {
        global $currentUser, $body;
        requireRole(4); // SA only
        $pdo = getPDO();

        $id = (int)($body['id'] ?? 0);
        if (!$id) fail('Missing project id');

        $proj = fetchProject($pdo, $id);
        if (!$proj) fail('Project not found', 404);
        if (in_array($proj['status'], ['Completed', 'Terminated'], true))
            fail('Cannot edit a Completed or Terminated project', 403);

        $v = validatePayload($body);
        if ($v !== true) fail('Validation failed', 422, $v);

        $pdo->beginTransaction();
        try {
            $conflict = !empty(trim($body['conflict_note'] ?? ''));
            $pdo->prepare("
                UPDATE plt_projects SET
                    name           = :name,
                    zone           = :zone,
                    manager        = :manager,
                    priority       = :priority,
                    start_date     = :start_date,
                    end_date       = :end_date,
                    ref            = :ref,
                    budget         = :budget,
                    progress       = :progress,
                    status         = :status,
                    description    = :description,
                    conflict       = :conflict,
                    conflict_note  = :conflict_note,
                    updated_at     = NOW()
                WHERE id = :id
            ")->execute([
                ':name'          => trim($body['name']),
                ':zone'          => trim($body['zone']),
                ':manager'       => trim($body['manager']),
                ':priority'      => trim($body['priority']),
                ':start_date'    => $body['start_date'],
                ':end_date'      => $body['end_date'],
                ':ref'           => trim($body['ref'] ?? ''),
                ':budget'        => (float)($body['budget'] ?? 0),
                ':progress'      => min(100, max(0, (int)($body['progress'] ?? 0))),
                ':status'        => $body['status'],
                ':description'   => trim($body['description'] ?? ''),
                ':conflict'      => $conflict ? 'true' : 'false',
                ':conflict_note' => trim($body['conflict_note'] ?? ''),
                ':id'            => $id,
            ]);
            insertAudit($pdo, $id, "Project Updated — status: {$body['status']}",
                $currentUser['full_name'] ?? 'Super Admin', 'Super Admin', '', 'dot-b', true);
            $pdo->commit();
            ok(['id' => $id], 'Project updated');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── FORCE COMPLETE ─────────────────────────────────────────────────────
    function actionComplete(): void {
        global $currentUser, $body;
        requireRole(4);
        $pdo    = getPDO();
        $id     = (int)($body['id']    ?? 0);
        $reason = trim($body['reason'] ?? '');
        if (!$id) fail('Missing project id');
        $proj = fetchProject($pdo, $id);
        if (!$proj) fail('Project not found', 404);
        if (!in_array($proj['status'], ['Active', 'Delayed', 'On Hold'], true))
            fail("Cannot force-complete a project with status: {$proj['status']}", 422);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE plt_projects SET status='Completed', progress=100, updated_at=NOW() WHERE id=:id")
                ->execute([':id' => $id]);
            insertAudit($pdo, $id, 'Project Force Completed — SA Override',
                $currentUser['full_name'] ?? 'Super Admin', 'Super Admin', $reason, 'dot-g', true);
            $pdo->commit();
            ok(null, "Project {$proj['project_id']} force-completed");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── FORCE CLOSE ────────────────────────────────────────────────────────
    function actionClose(): void {
        global $currentUser, $body;
        requireRole(4);
        $pdo    = getPDO();
        $id     = (int)($body['id']    ?? 0);
        $reason = trim($body['reason'] ?? '');
        if (!$id)     fail('Missing project id');
        if (!$reason) fail('Reason is required for force-close', 422);
        $proj = fetchProject($pdo, $id);
        if (!$proj) fail('Project not found', 404);
        if (in_array($proj['status'], ['Completed', 'Terminated'], true))
            fail("Cannot close a project with status: {$proj['status']}", 422);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE plt_projects SET status='Terminated', updated_at=NOW() WHERE id=:id")
                ->execute([':id' => $id]);
            insertAudit($pdo, $id, "Project Force Closed (SA) — $reason",
                $currentUser['full_name'] ?? 'Super Admin', 'Super Admin', $reason, 'dot-r', true);
            $pdo->commit();
            ok(null, "Project {$proj['project_id']} force-closed");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── REASSIGN MANAGER ───────────────────────────────────────────────────
    function actionReassign(): void {
        global $currentUser, $body;
        requireRole(4);
        $pdo    = getPDO();
        $id     = (int)($body['id']    ?? 0);
        $toMgr  = trim($body['to']     ?? '');
        $reason = trim($body['reason'] ?? '');
        if (!$id)    fail('Missing project id');
        if (!$toMgr) fail('Target manager name is required', 422);
        $proj = fetchProject($pdo, $id);
        if (!$proj) fail('Project not found', 404);
        if (in_array($proj['status'], ['Completed', 'Terminated'], true))
            fail("Cannot reassign a project with status: {$proj['status']}", 422);
        $from = $proj['manager'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE plt_projects SET manager=:mgr, updated_at=NOW() WHERE id=:id")
                ->execute([':mgr' => $toMgr, ':id' => $id]);
            insertAudit($pdo, $id, "Manager Reassigned (SA): $from → $toMgr" . ($reason ? " | $reason" : ''),
                $currentUser['full_name'] ?? 'Super Admin', 'Super Admin', $reason, 'dot-b', true);
            $pdo->commit();
            ok(null, "Project reassigned to $toMgr");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── BUDGET OVERRIDE ────────────────────────────────────────────────────
    function actionBudget(): void {
        global $currentUser, $body;
        requireRole(4);
        $pdo       = getPDO();
        $id        = (int)($body['id']       ?? 0);
        $newBudget = (float)($body['budget'] ?? 0);
        $reason    = trim($body['reason']    ?? '');
        if (!$id)            fail('Missing project id');
        if ($newBudget <= 0) fail('New budget amount must be greater than zero', 422);
        if (!$reason)        fail('Reason is required for budget override', 422);
        $proj = fetchProject($pdo, $id);
        if (!$proj) fail('Project not found', 404);
        $oldBudget = (float)$proj['budget'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE plt_projects SET budget=:budget, updated_at=NOW() WHERE id=:id")
                ->execute([':budget' => $newBudget, ':id' => $id]);
            insertAudit($pdo, $id,
                "Budget Override (SA): ₱" . number_format($oldBudget, 2) . " → ₱" . number_format($newBudget, 2) . " | $reason",
                $currentUser['full_name'] ?? 'Super Admin', 'Super Admin', $reason, 'dot-o', true);
            $pdo->commit();
            ok(null, "Budget updated to ₱" . number_format($newBudget, 2));
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── UPDATE PROGRESS — Manager+ ─────────────────────────────────────────
    function actionProgress(): void {
        global $currentUser, $body, $roleRank;
        if ($roleRank < 2) fail('Insufficient permissions', 403);

        $pdo  = getPDO();
        $id   = (int)($body['id']  ?? 0);
        $pct  = min(100, max(0, (int)($body['pct'] ?? 0)));
        $note = trim($body['note'] ?? '');
        if (!$id) fail('Missing project id');
        $proj = fetchProject($pdo, $id);
        if (!$proj) fail('Project not found', 404);
        if (in_array($proj['status'], ['Completed', 'Terminated'], true))
            fail("Cannot update progress for a {$proj['status']} project", 422);

        // Zone scoping
        if ($roleRank <= 3) {
            $userZone = $currentUser['zone'] ?? '';
            if ($userZone && $proj['zone'] !== $userZone) fail('Access denied', 403);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE plt_projects SET progress=:pct, updated_at=NOW() WHERE id=:id")
                ->execute([':pct' => $pct, ':id' => $id]);
            insertAudit($pdo, $id, "Progress Updated — {$pct}%",
                $currentUser['full_name'] ?? 'Super Admin',
                $roleRank >= 4 ? 'Super Admin' : ($roleRank >= 3 ? 'Admin' : 'Manager'),
                $note, 'dot-b', $roleRank >= 4);
            $pdo->commit();
            ok(['progress' => $pct], "Progress updated to {$pct}%");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── FLAG DELAY — Manager+ ──────────────────────────────────────────────
    function actionFlagDelay(): void {
        global $currentUser, $body, $roleRank;
        if ($roleRank < 2) fail('Insufficient permissions', 403);

        $pdo    = getPDO();
        $id     = (int)($body['id']    ?? 0);
        $note   = trim($body['note']   ?? '');
        $escalate = (bool)($body['escalate'] ?? false);
        if (!$id) fail('Missing project id');
        $proj = fetchProject($pdo, $id);
        if (!$proj) fail('Project not found', 404);

        // Zone scoping
        if ($roleRank <= 3) {
            $userZone = $currentUser['zone'] ?? '';
            if ($userZone && $proj['zone'] !== $userZone) fail('Access denied', 403);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE plt_projects SET status='Delayed', updated_at=NOW() WHERE id=:id AND status='Active'")
                ->execute([':id' => $id]);
            $label = $escalate
                ? "Delay escalated to Admin" . ($note ? " — $note" : '')
                : "Delay flagged by Manager" . ($note ? " — $note" : '');
            insertAudit($pdo, $id, $label,
                $currentUser['full_name'] ?? 'Manager',
                $roleRank >= 3 ? 'Admin' : 'Manager',
                $note, 'dot-o', false);
            $pdo->commit();
            ok(null, 'Delay flagged');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── BATCH CLOSE — SA only ──────────────────────────────────────────────
    function actionBatchClose(): void {
        global $currentUser, $body;
        requireRole(4);
        $pdo    = getPDO();
        $ids    = array_filter(array_map('intval', $body['ids'] ?? []), fn($v) => $v > 0);
        $reason = trim($body['reason'] ?? '');
        if (empty($ids)) fail('No project IDs provided', 422);
        if (!$reason)    fail('Reason is required for batch close', 422);

        $pdo->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE plt_projects SET status='Terminated', updated_at=NOW()
                           WHERE id IN ($placeholders) AND status NOT IN ('Completed','Terminated')")
                ->execute($ids);
            foreach ($ids as $id) {
                insertAudit($pdo, $id, "Batch Force Close (SA) — $reason",
                    $currentUser['full_name'] ?? 'Super Admin', 'Super Admin', $reason, 'dot-r', true);
            }
            $pdo->commit();
            ok(null, count($ids) . ' project(s) force-closed');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── AUDIT GLOBAL — SA only ─────────────────────────────────────────────
    function actionAuditGlobal(): void {
        global $roleRank;
        requireRole(4);
        $pdo    = getPDO();
        $limit  = min(200, max(1, (int)($_GET['limit']  ?? 50)));
        $offset = max(0,            (int)($_GET['offset'] ?? 0));
        $stmt   = $pdo->prepare("
            SELECT al.id, al.project_id AS proj_row_id, p.project_id, p.name AS project_name,
                   al.action_label, al.actor_name, al.actor_role,
                   al.dot_class, al.is_super_admin, al.ip_address, al.occurred_at
            FROM plt_audit_log al
            JOIN plt_projects p ON p.id = al.project_id
            ORDER BY al.occurred_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $total = (int)$pdo->query("SELECT COUNT(*) FROM plt_audit_log")->fetchColumn();
        ok(['rows' => $stmt->fetchAll(), 'total' => $total]);
    }

    // ── EXPORT CSV — Admin+ ────────────────────────────────────────────────
    function actionExport(): void {
        global $roleRank, $currentUser;
        if ($roleRank < 3) fail('Insufficient permissions', 403);

        $pdo = getPDO();

        $where = '1=1'; $params = [];
        if ($roleRank <= 3) {
            $userZone = $currentUser['zone'] ?? '';
            if ($userZone) { $where .= ' AND zone = :zone'; $params[':zone'] = $userZone; }
        }

        $stmt = $pdo->prepare("
            SELECT project_id, name, zone, manager, priority,
                   start_date, end_date, ref, budget, spend,
                   progress, status, conflict, conflict_note, description, created_at
            FROM plt_projects WHERE $where
            ORDER BY created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="active_projects_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');

        // Budget columns only for Admin+
        $headers = ['Project ID','Name','Zone','Manager','Priority','Start Date','End Date','PO/PR Ref','Progress %','Status'];
        if ($roleRank >= 3) $headers = array_merge($headers, ['Budget','Actual Spend','Zone Conflict','Conflict Note']);
        $headers[] = 'Description';

        fputcsv($out, $headers);
        foreach ($rows as $r) {
            $row = [
                $r['project_id'], $r['name'], $r['zone'], $r['manager'], $r['priority'],
                $r['start_date'], $r['end_date'], $r['ref'], $r['progress'], $r['status'],
            ];
            if ($roleRank >= 3) {
                $row[] = $r['budget'];
                $row[] = $r['spend'];
                $row[] = $r['conflict'] ? 'Yes' : 'No';
                $row[] = $r['conflict_note'];
            }
            $row[] = $r['description'];
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    // ── NEXT PROJECT ID — SA only ──────────────────────────────────────────
    function actionNextId(): void {
        requireRole(4);
        $pdo = getPDO();
        $y   = date('Y');
        $row = $pdo->query("SELECT project_id FROM plt_projects ORDER BY id DESC LIMIT 1")->fetch();
        $next = 1001;
        if ($row) {
            $parts = explode('-', $row['project_id']);
            $last  = (int)end($parts);
            $next  = $last + 1;
        }
        ok(['project_id' => "PLT-{$y}-" . str_pad((string)$next, 4, '0', STR_PAD_LEFT)]);
    }

    // ── LOOKUP REF — SA only ───────────────────────────────────────────────
    function actionLookupRef(): void {
        requireRole(4);
        $pdo = getPDO();
        $q   = trim($_GET['q'] ?? '');
        $results = [];

        $stmt = $pdo->prepare("
            SELECT po.id, po.po_number AS ref_no, po.supplier_name AS party, po.total_amount,
                   po.status, po.date_issued AS date_filed, 'PO' AS ref_type
            FROM psm_purchase_orders po
            WHERE po.status NOT IN ('Cancelled','Voided')
              AND (po.po_number ILIKE :q OR po.supplier_name ILIKE :q OR po.pr_reference ILIKE :q)
            ORDER BY po.date_issued DESC LIMIT 8
        ");
        $stmt->execute([':q' => '%' . $q . '%']);
        $pos = $stmt->fetchAll();
        foreach ($pos as &$r) { $r['total_amount'] = (float)$r['total_amount']; }
        $results = array_merge($results, $pos);

        $stmt = $pdo->prepare("
            SELECT pr.id, pr.pr_number AS ref_no, pr.requestor_name AS party, pr.total_amount,
                   pr.status, pr.date_filed, 'PR' AS ref_type
            FROM psm_purchase_requests pr
            WHERE pr.status IN ('Approved','Pending Approval','Pending')
              AND (pr.pr_number ILIKE :q OR pr.requestor_name ILIKE :q OR pr.department ILIKE :q)
            ORDER BY pr.date_filed DESC LIMIT 8
        ");
        $stmt->execute([':q' => '%' . $q . '%']);
        $prs = $stmt->fetchAll();
        foreach ($prs as &$r) { $r['total_amount'] = (float)$r['total_amount']; }
        $results = array_merge($results, $prs);

        ok($results);
    }

    // ── SHARED HELPERS ─────────────────────────────────────────────────────
    /** @return array|false */
    function fetchProject(PDO $pdo, int $id) {
        $s = $pdo->prepare("SELECT * FROM plt_projects WHERE id = :id");
        $s->execute([':id' => $id]);
        return $s->fetch();
    }
    function insertAudit(PDO $pdo, int $projectId, string $label, string $actor,
                         string $role, string $note, string $dotClass, bool $isSa): void {
        $pdo->prepare("
            INSERT INTO plt_audit_log
                (project_id, action_label, actor_name, actor_role, note, dot_class, is_super_admin, ip_address)
            VALUES (:proj_id, :label, :actor, :role, :note, :dot, :is_sa, :ip)
        ")->execute([
            ':proj_id' => $projectId,
            ':label'   => $label,
            ':actor'   => $actor,
            ':role'    => $role,
            ':note'    => $note,
            ':dot'     => $dotClass,
            ':is_sa'   => $isSa ? 'true' : 'false',
            ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
    /** @return true|array */
    function validatePayload(array $b) {
        $errors = [];
        if (empty(trim($b['name']     ?? ''))) $errors['name']       = 'Required';
        if (empty(trim($b['zone']     ?? ''))) $errors['zone']       = 'Required';
        if (empty(trim($b['manager']  ?? ''))) $errors['manager']    = 'Required';
        if (empty(trim($b['priority'] ?? ''))) $errors['priority']   = 'Required';
        if (empty($b['start_date']))           $errors['start_date'] = 'Required';
        if (empty($b['end_date']))             $errors['end_date']   = 'Required';
        if (empty($b['budget']) || (float)$b['budget'] <= 0) $errors['budget'] = 'Must be greater than zero';
        return $errors ?: true;
    }
    function requireRole(int $min): void {
        global $roleRank;
        if ($roleRank < $min) fail('Insufficient permissions', 403);
    }

    // ── Router ─────────────────────────────────────────────────────────────
    try {
        match ($action) {
            'list'         => actionList(),
            'get'          => actionGet(),
            'create'       => actionCreate(),
            'update'       => actionUpdate(),
            'complete'     => actionComplete(),
            'close'        => actionClose(),
            'reassign'     => actionReassign(),
            'budget'       => actionBudget(),
            'progress'     => actionProgress(),
            'flag_delay'   => actionFlagDelay(),
            'batch_close'  => actionBatchClose(),
            'audit_global' => actionAuditGlobal(),
            'export'       => actionExport(),
            'next_id'      => actionNextId(),
            'lookup_ref'   => actionLookupRef(),
            default        => fail("Unknown action: $action", 404),
        };
    } catch (\Throwable $e) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'trace' => $e->getFile() . ':' . $e->getLine()]);
        exit;
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// PAGE
// ═══════════════════════════════════════════════════════════════════════════
$API_URL = '?';

include $root . '/includes/superadmin_sidebar.php';
include $root . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Active Projects — PLT</title>
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/base.css">
  <link rel="stylesheet" href="/css/sidebar.css">
  <link rel="stylesheet" href="/css/header.css">
  <style>
/* ── TOKENS ─────────────────────────────────────────────── */
#mainContent, #projSlider, #slOverlay, #actionModal, #viewModal, .ap-toasts {
  --s:#fff; --bd:rgba(46,125,50,.13); --bdm:rgba(46,125,50,.26);
  --t1:var(--text-primary); --t2:var(--text-secondary); --t3:#9EB0A2;
  --hbg:var(--hover-bg-light); --bg:var(--bg-color);
  --grn:var(--primary-color); --gdk:var(--primary-dark);
  --red:#DC2626; --amb:#D97706; --blu:#2563EB; --tel:#0D9488;
  --pur:#7C3AED;
  --shmd:0 4px 20px rgba(46,125,50,.12); --shlg:0 24px 60px rgba(0,0,0,.22);
  --rad:12px; --tr:var(--transition);
}
#mainContent *, #projSlider *, #slOverlay *, #actionModal *, #viewModal *, .ap-toasts * { box-sizing:border-box; }

/* ── ACCESS BANNER ──────────────────────────────────────── */
.access-banner{display:flex;align-items:flex-start;gap:10px;padding:10px 16px;border-radius:10px;font-size:12px;margin-bottom:16px;animation:UP .4s both}
.ab-info{background:#EFF6FF;border:1px solid #BFDBFE;color:var(--blu)}
.ab-warn{background:#FEF3C7;border:1px solid #FDE68A;color:var(--amb)}
.access-banner i{font-size:16px;flex-shrink:0;margin-top:1px}

/* ── PAGE ──────────────────────────────────────────────── */
.ap-wrap { max-width:1600px; margin:0 auto; padding:0 0 4rem; }
.ap-ph { display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:26px; animation:UP .4s both; }
.ap-ph .ey { font-size:11px; font-weight:600; letter-spacing:.14em; text-transform:uppercase; color:var(--grn); margin-bottom:4px; }
.ap-ph h1  { font-size:26px; font-weight:800; color:var(--t1); line-height:1.15; }
.ap-ph-r   { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

/* ── BUTTONS ─────────────────────────────────────────────── */
.btn { display:inline-flex; align-items:center; gap:7px; font-family:'Inter',sans-serif; font-size:13px; font-weight:600; padding:9px 18px; border-radius:10px; border:none; cursor:pointer; transition:var(--tr); white-space:nowrap; }
.btn-primary { background:var(--grn); color:#fff; box-shadow:0 2px 8px rgba(46,125,50,.32); }
.btn-primary:hover { background:var(--gdk); transform:translateY(-1px); }
.btn-ghost   { background:var(--s); color:var(--t2); border:1px solid var(--bdm); }
.btn-ghost:hover { background:var(--hbg); color:var(--t1); }
.btn-approve { background:#DCFCE7; color:#166534; border:1px solid #BBF7D0; }
.btn-approve:hover { background:#BBF7D0; }
.btn-reject  { background:#FEE2E2; color:var(--red); border:1px solid #FECACA; }
.btn-reject:hover { background:#FCA5A5; }
.btn-override { background:#EFF6FF; color:var(--blu); border:1px solid #BFDBFE; }
.btn-override:hover { background:#DBEAFE; }
.btn-warn    { background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; }
.btn-warn:hover { background:#FDE68A; }
.btn-gold    { background:#B45309; color:#fff; }
.btn-gold:hover { background:#92400E; transform:translateY(-1px); }
.btn-purple  { background:var(--pur); color:#fff; }
.btn-purple:hover { background:#6D28D9; transform:translateY(-1px); }
.btn-orange  { background:var(--amb); color:#fff; }
.btn-orange:hover { background:#B45309; transform:translateY(-1px); }
.btn-sm  { font-size:12px; padding:7px 14px; }
.btn-xs  { font-size:11px; padding:4px 9px; border-radius:7px; }
.btn.ionly { width:26px; height:26px; padding:0; justify-content:center; font-size:13px; flex-shrink:0; border-radius:6px; }
.btn:disabled { opacity:.45; pointer-events:none; }

/* ── STATS ─────────────────────────────────────────────── */
.ap-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(155px,1fr)); gap:12px; margin-bottom:22px; animation:UP .4s .05s both; }
.sc { background:var(--s); border:1px solid var(--bd); border-radius:var(--rad); padding:14px 16px; box-shadow:0 1px 4px rgba(46,125,50,.07); display:flex; align-items:center; gap:12px; }
.sc-ic { width:38px; height:38px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:18px; }
.ic-b{background:#EFF6FF;color:var(--blu)} .ic-a{background:#FEF3C7;color:var(--amb)}
.ic-g{background:#DCFCE7;color:#166534}    .ic-r{background:#FEE2E2;color:var(--red)}
.ic-t{background:#CCFBF1;color:var(--tel)} .ic-gy{background:#F3F4F6;color:#6B7280}
.ic-p{background:#F5F3FF;color:#6D28D9}    .ic-o{background:#FFF7ED;color:#C2410C}
.sc-v { font-size:22px; font-weight:800; color:var(--t1); line-height:1; }
.sc-l { font-size:11px; color:var(--t2); margin-top:2px; }

/* ── TOOLBAR ─────────────────────────────────────────────── */
.ap-tb { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:18px; animation:UP .4s .1s both; }
.sw { position:relative; flex:1; min-width:220px; }
.sw i { position:absolute; left:11px; top:50%; transform:translateY(-50%); font-size:17px; color:var(--t3); pointer-events:none; }
.si { width:100%; padding:9px 11px 9px 36px; font-family:'Inter',sans-serif; font-size:13px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; transition:var(--tr); }
.si:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.10); }
.si::placeholder { color:var(--t3); }
.sel { font-family:'Inter',sans-serif; font-size:13px; padding:9px 28px 9px 11px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); cursor:pointer; outline:none; appearance:none; transition:var(--tr); background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; }
.sel:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.10); }
.date-range-wrap { display:flex; align-items:center; gap:6px; }
.date-range-wrap span { font-size:12px; color:var(--t3); font-weight:500; }
.fi-date { font-family:'Inter',sans-serif; font-size:13px; padding:9px 11px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; transition:var(--tr); }
.fi-date:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.10); }
.clear-btn { font-size:12px; font-weight:600; color:var(--t3); background:none; border:1px solid var(--bdm); cursor:pointer; padding:7px 11px; border-radius:9px; transition:var(--tr); white-space:nowrap; display:flex; align-items:center; gap:4px; flex-shrink:0; }
.clear-btn:hover { color:var(--red); background:#FEE2E2; border-color:#FECACA; }

/* ── BULK BAR ─────────────────────────────────────────── */
.bulk-bar { display:none; align-items:center; gap:10px; padding:10px 16px; background:linear-gradient(135deg,#F0FDF4,#DCFCE7); border:1px solid rgba(46,125,50,.22); border-radius:12px; margin-bottom:14px; flex-wrap:wrap; animation:UP .25s both; }
.bulk-bar.on { display:flex; }
.bulk-count { font-size:13px; font-weight:700; color:#166534; }
.bulk-sep { width:1px; height:22px; background:rgba(46,125,50,.25); }
.sa-exclusive { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; background:linear-gradient(135deg,#FEF3C7,#FDE68A); color:#92400E; border:1px solid #FCD34D; border-radius:6px; padding:2px 7px; }

/* ── TABLE ─────────────────────────────────────────────── */
.ap-card { background:var(--s); border:1px solid var(--bd); border-radius:16px; overflow:hidden; box-shadow:var(--shmd); animation:UP .4s .13s both; }
.ap-tbl { width:100%; border-collapse:collapse; font-size:12.5px; table-layout:fixed; }
.ap-tbl col.col-cb    { width:36px; }
.ap-tbl col.col-id    { width:120px; }
.ap-tbl col.col-name  { width:160px; }
.ap-tbl col.col-zone  { width:110px; }
.ap-tbl col.col-mgr   { width:120px; }
.ap-tbl col.col-dates { width:140px; }
.ap-tbl col.col-ref   { width:105px; }
.ap-tbl col.col-budget{ width:115px; }
.ap-tbl col.col-prog  { width:95px; }
.ap-tbl col.col-stat  { width:100px; }
.ap-tbl col.col-act   { width:155px; }
.ap-tbl thead th { font-size:10.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--t2); padding:10px 10px; text-align:left; background:var(--bg); border-bottom:1px solid var(--bd); white-space:nowrap; cursor:pointer; user-select:none; overflow:hidden; }
.ap-tbl thead th.no-sort { cursor:default; }
.ap-tbl thead th:hover:not(.no-sort) { color:var(--grn); }
.ap-tbl thead th.sorted { color:var(--grn); }
.ap-tbl thead th .sic { margin-left:3px; opacity:.4; font-size:12px; vertical-align:middle; }
.ap-tbl thead th.sorted .sic { opacity:1; }
.ap-tbl thead th:first-child,
.ap-tbl tbody td:first-child { padding-left:12px; padding-right:4px; }
.ap-tbl tbody tr { border-bottom:1px solid var(--bd); transition:background .13s; }
.ap-tbl tbody tr:last-child { border-bottom:none; }
.ap-tbl tbody tr:hover { background:var(--hbg); }
.ap-tbl tbody tr.row-selected { background:#F0FDF4; }
.ap-tbl tbody td { padding:12px 10px; vertical-align:middle; cursor:pointer; max-width:0; overflow:hidden; text-overflow:ellipsis; }
.ap-tbl tbody td:first-child { cursor:default; }
.ap-tbl tbody td:last-child { white-space:nowrap; cursor:default; overflow:visible; padding:10px 8px; max-width:none; }
.cb-wrap { display:flex; align-items:center; justify-content:center; }
input[type=checkbox].cb { width:15px; height:15px; accent-color:var(--grn); cursor:pointer; }
.proj-id   { font-family:'DM Mono',monospace; font-size:11.5px; font-weight:600; color:var(--grn); white-space:nowrap; }
.proj-name-cell { display:flex; flex-direction:column; gap:2px; }
.proj-nm   { font-weight:700; font-size:12.5px; color:var(--t1); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.proj-ref  { font-family:'DM Mono',monospace; font-size:10.5px; color:var(--t3); }
.proj-date { font-size:11.5px; color:var(--t2); white-space:nowrap; line-height:1.6; }
.proj-budget { font-family:'DM Mono',monospace; font-size:12px; font-weight:700; color:var(--t1); white-space:nowrap; }
.proj-spend  { font-family:'DM Mono',monospace; font-size:10.5px; color:var(--t3); white-space:nowrap; }
.mgr-cell { display:flex; align-items:center; gap:7px; min-width:0; }
.mgr-av   { width:26px; height:26px; border-radius:50%; font-size:9px; font-weight:700; color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.mgr-name { font-weight:600; color:var(--t1); font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.zone-dot { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.act-cell { display:flex; gap:4px; align-items:center; flex-wrap:nowrap; }
.prog-wrap { display:flex; flex-direction:column; gap:4px; }
.prog-pct  { font-size:11px; font-weight:700; color:var(--t1); }
.prog-bar-bg   { height:5px; background:#E5E7EB; border-radius:10px; overflow:hidden; }
.prog-bar-fill { height:100%; border-radius:10px; transition:width .5s ease; }
.conflict-badge { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; border-radius:6px; padding:2px 7px; }
.conflict-badge i { font-size:11px; }
.my-role-chip { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; background:#EDE9FE; color:#6D28D9; border-radius:5px; padding:2px 7px; }

/* ── BADGES ─────────────────────────────────────────────── */
.badge { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; padding:3px 8px; border-radius:20px; white-space:nowrap; }
.badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }
.b-planning   { background:#EFF6FF; color:#1D4ED8; }
.b-active     { background:#DCFCE7; color:#166534; }
.b-onhold     { background:#FEF3C7; color:#92400E; }
.b-delayed    { background:#FEE2E2; color:#991B1B; }
.b-completed  { background:#F0FDF4; color:#15803D; }
.b-terminated { background:#F3F4F6; color:#374151; }
.pri-chip { display:inline-flex; align-items:center; gap:3px; font-size:9px; font-weight:700; padding:2px 6px; border-radius:5px; text-transform:uppercase; letter-spacing:.04em; }
.pr-crit { background:#FEE2E2; color:#991B1B; }
.pr-high { background:#FEF3C7; color:#92400E; }
.pr-med  { background:#DBEAFE; color:#1E40AF; }
.pr-low  { background:#F3F4F6; color:#374151; }

/* ── PAGINATION ─────────────────────────────────────────── */
.ap-pager { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:14px 20px; border-top:1px solid var(--bd); background:var(--bg); font-size:13px; color:var(--t2); }
.pg-btns  { display:flex; gap:5px; }
.pgb { width:32px; height:32px; border-radius:8px; border:1px solid var(--bdm); background:var(--s); font-family:'Inter',sans-serif; font-size:13px; cursor:pointer; display:grid; place-content:center; transition:var(--tr); color:var(--t1); }
.pgb:hover   { background:var(--hbg); border-color:var(--grn); color:var(--grn); }
.pgb.active  { background:var(--grn); border-color:var(--grn); color:#fff; }
.pgb:disabled { opacity:.4; pointer-events:none; }
.empty { padding:72px 20px; text-align:center; color:var(--t3); }
.empty i { font-size:54px; display:block; margin-bottom:14px; color:#C8E6C9; }

/* ── VIEW MODAL ─────────────────────────────────────────── */
#viewModal { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9050; display:flex; align-items:center; justify-content:center; padding:20px; opacity:0; pointer-events:none; transition:opacity .25s; }
#viewModal.on { opacity:1; pointer-events:all; }
.vm-box { background:#fff; border-radius:20px; width:820px; max-width:100%; max-height:92vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.22); overflow:hidden; }
.vm-hd  { padding:24px 28px 0; border-bottom:1px solid rgba(46,125,50,.14); background:var(--bg-color); flex-shrink:0; }
.vm-top { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:16px; }
.vm-si  { display:flex; align-items:center; gap:16px; }
.vm-av  { width:56px; height:56px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:18px; color:#fff; flex-shrink:0; }
.vm-nm  { font-size:19px; font-weight:800; color:var(--text-primary); display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.vm-id  { font-size:12px; color:var(--text-secondary); margin-top:4px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-family:'DM Mono',monospace; }
.vm-cl  { width:36px; height:36px; border-radius:8px; border:1px solid rgba(46,125,50,.22); background:#fff; cursor:pointer; display:grid; place-content:center; font-size:20px; color:var(--text-secondary); transition:all .15s; flex-shrink:0; }
.vm-cl:hover { background:#FEE2E2; color:#DC2626; border-color:#FECACA; }
.vm-chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
.vm-chip { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--text-secondary); background:#fff; border:1px solid rgba(46,125,50,.14); border-radius:8px; padding:5px 10px; }
.vm-chip i { font-size:14px; color:var(--primary-color); }
.vm-tabs { display:flex; gap:4px; }
.vm-tab { font-family:'Inter',sans-serif; font-size:13px; font-weight:600; padding:8px 16px; border-radius:8px 8px 0 0; cursor:pointer; transition:all .15s; color:var(--text-secondary); border:none; background:transparent; display:flex; align-items:center; gap:6px; white-space:nowrap; }
.vm-tab:hover { background:var(--hover-bg-light); color:var(--text-primary); }
.vm-tab.active { background:var(--primary-color); color:#fff; }
.vm-bd { flex:1; overflow-y:auto; padding:24px 28px; background:#fff; }
.vm-bd::-webkit-scrollbar { width:4px; }
.vm-bd::-webkit-scrollbar-thumb { background:rgba(46,125,50,.22); border-radius:4px; }
.vm-tp { display:none; flex-direction:column; gap:18px; }
.vm-tp.active { display:flex; }
.vm-sbs { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
.vm-sb  { background:var(--bg-color); border:1px solid rgba(46,125,50,.14); border-radius:10px; padding:14px 16px; }
.vm-sb .sbv { font-size:18px; font-weight:800; color:var(--text-primary); line-height:1; }
.vm-sb .sbv.mono { font-family:'DM Mono',monospace; font-size:13px; color:var(--primary-color); }
.vm-sb .sbl { font-size:11px; color:var(--text-secondary); margin-top:3px; }
.vm-ig  { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.vm-ii label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9EB0A2; display:block; margin-bottom:4px; }
.vm-ii .v { font-size:13px; font-weight:500; color:var(--text-primary); line-height:1.5; }
.vm-ii .v.muted { font-weight:400; color:#4B5563; }
.vm-full { grid-column:1/-1; }
.sa-note { display:flex; align-items:flex-start; gap:8px; background:#FFFBEB; border:1px solid #FCD34D; border-radius:10px; padding:10px 14px; font-size:12px; color:#92400E; }
.sa-note i { font-size:15px; flex-shrink:0; margin-top:1px; }
.conflict-note-block { display:flex; align-items:flex-start; gap:8px; background:#FEF2F2; border:1px solid #FECACA; border-radius:10px; padding:10px 14px; font-size:12px; color:#991B1B; }
.conflict-note-block i { font-size:15px; flex-shrink:0; margin-top:1px; }
.vm-txnt { width:100%; border-collapse:collapse; font-size:13px; }
.vm-txnt thead th { font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--text-secondary); padding:10px 12px; text-align:left; background:var(--bg-color); border-bottom:1px solid rgba(46,125,50,.14); white-space:nowrap; }
.vm-txnt tbody tr { border-bottom:1px solid rgba(46,125,50,.14); transition:background .12s; }
.vm-txnt tbody tr:last-child { border-bottom:none; }
.vm-txnt tbody tr:hover { background:var(--hover-bg-light); }
.vm-txnt tbody td { padding:10px 12px; vertical-align:middle; }
.vm-audit-item { display:flex; gap:12px; padding:12px 0; border-bottom:1px solid rgba(46,125,50,.14); }
.vm-audit-item:last-child { border-bottom:none; padding-bottom:0; }
.vm-audit-dot { width:28px; height:28px; border-radius:7px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:13px; }
.dot-g-ic{background:#DCFCE7;color:#166534} .dot-b-ic{background:#EFF6FF;color:var(--blu)}
.dot-o-ic{background:#FEF3C7;color:var(--amb)} .dot-r-ic{background:#FEE2E2;color:var(--red)}
.dot-gy-ic{background:#F3F4F6;color:#6B7280}
.vm-audit-body { flex:1; min-width:0; }
.vm-audit-body .au { font-size:13px; font-weight:500; color:var(--text-primary); }
.vm-audit-body .at { font-size:11px; color:#9EB0A2; margin-top:3px; font-family:'DM Mono',monospace; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.vm-audit-note { font-size:11.5px; color:#6B7280; margin-top:3px; font-style:italic; }
.vm-audit-ip { font-family:'DM Mono',monospace; font-size:10px; color:#9CA3AF; background:#F3F4F6; border-radius:4px; padding:1px 6px; }
.vm-audit-ts { font-family:'DM Mono',monospace; font-size:10px; color:#9EB0A2; flex-shrink:0; margin-left:auto; padding-left:8px; white-space:nowrap; }
.sa-tag { font-size:10px; font-weight:700; background:#FEF3C7; color:#92400E; border-radius:4px; padding:1px 5px; border:1px solid #FCD34D; }
.vm-ft { padding:16px 28px; border-top:1px solid rgba(46,125,50,.14); background:var(--bg-color); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; flex-wrap:wrap; }
.budget-bar-row { display:flex; flex-direction:column; gap:6px; margin-top:8px; }
.budget-labels { display:flex; justify-content:space-between; font-size:11px; font-weight:600; }
.budget-bar-bg { height:10px; background:#E5E7EB; border-radius:10px; overflow:hidden; position:relative; }
.budget-bar-fill { height:100%; border-radius:10px; transition:width .6s ease; }

/* ── SLIDE-OVER ─────────────────────────────────────────── */
#slOverlay { position:fixed; inset:0; background:rgba(0,0,0,.52); z-index:9000; opacity:0; pointer-events:none; transition:opacity .25s; }
#slOverlay.on { opacity:1; pointer-events:all; }
#projSlider { position:fixed; top:0; right:-600px; bottom:0; width:560px; max-width:100vw; background:var(--s); z-index:9001; transition:right .3s cubic-bezier(.4,0,.2,1); display:flex; flex-direction:column; overflow:hidden; box-shadow:-4px 0 40px rgba(0,0,0,.18); }
#projSlider.on { right:0; }
.sl-hdr { display:flex; align-items:flex-start; justify-content:space-between; padding:20px 24px 18px; border-bottom:1px solid var(--bd); background:var(--bg); flex-shrink:0; }
.sl-title    { font-size:17px; font-weight:700; color:var(--t1); }
.sl-subtitle { font-size:12px; color:var(--t2); margin-top:2px; }
.sl-close { width:36px; height:36px; border-radius:8px; border:1px solid var(--bdm); background:var(--s); cursor:pointer; display:grid; place-content:center; font-size:20px; color:var(--t2); transition:var(--tr); flex-shrink:0; }
.sl-close:hover { background:#FEE2E2; color:var(--red); border-color:#FECACA; }
.sl-body { flex:1; overflow-y:auto; padding:24px; display:flex; flex-direction:column; gap:18px; }
.sl-body::-webkit-scrollbar { width:4px; }
.sl-body::-webkit-scrollbar-thumb { background:var(--bdm); border-radius:4px; }
.sl-foot { padding:16px 24px; border-top:1px solid var(--bd); background:var(--bg); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; }

/* ── FORM ────────────────────────────────────────────────── */
.fg { display:flex; flex-direction:column; gap:6px; }
.fr { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.fl { font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--t2); }
.fl span { color:var(--red); margin-left:2px; }
.fi, .fs, .fta { font-family:'Inter',sans-serif; font-size:13px; padding:10px 12px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; transition:var(--tr); width:100%; }
.fi:focus, .fs:focus, .fta:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.11); }
.fs { appearance:none; cursor:pointer; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; padding-right:30px; }
.fta { resize:vertical; min-height:70px; }
.fd { font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--t3); display:flex; align-items:center; gap:10px; }
.fd::after { content:''; flex:1; height:1px; background:var(--bd); }
.proj-id-wrap { position:relative; }
.proj-id-wrap .auto-hint { position:absolute; right:10px; top:50%; transform:translateY(-50%); font-size:11px; font-weight:600; color:var(--grn); background:#E8F5E9; border:1px solid rgba(46,125,50,.2); border-radius:5px; padding:2px 7px; cursor:pointer; transition:var(--tr); }
.proj-id-wrap .auto-hint:hover { background:#C8E6C9; }

/* ── ACTION MODAL ─────────────────────────────────────────── */
#actionModal { position:fixed; inset:0; background:rgba(0,0,0,.52); z-index:9100; display:grid; place-content:center; opacity:0; pointer-events:none; transition:opacity .2s; padding:20px; }
#actionModal.on { opacity:1; pointer-events:all; }
.am-box   { background:var(--s); border-radius:16px; padding:28px 28px 24px; width:440px; max-width:100%; box-shadow:var(--shlg); }
.am-icon  { font-size:44px; margin-bottom:10px; line-height:1; }
.am-title { font-size:18px; font-weight:700; color:var(--t1); margin-bottom:6px; }
.am-body  { font-size:13px; color:var(--t2); line-height:1.6; margin-bottom:16px; }
.am-fg    { display:flex; flex-direction:column; gap:5px; margin-bottom:18px; }
.am-fg label { font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--t2); }
.am-fg textarea, .am-fg input { font-family:'Inter',sans-serif; font-size:13px; padding:10px 12px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; resize:vertical; width:100%; transition:var(--tr); }
.am-fg textarea { min-height:72px; }
.am-fg textarea:focus, .am-fg input:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.11); }
.am-acts  { display:flex; gap:10px; justify-content:flex-end; }
.am-sa-note { display:flex; align-items:flex-start; gap:8px; background:#FFFBEB; border:1px solid #FCD34D; border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:12px; color:#92400E; }
.am-sa-note i { font-size:15px; flex-shrink:0; margin-top:1px; }
.progress-slider-wrap { display:flex; flex-direction:column; gap:10px; margin-bottom:14px; }
.progress-slider-wrap input[type=range] { width:100%; accent-color:var(--grn); }
.progress-slider-val { font-family:'DM Mono',monospace; font-size:28px; font-weight:800; color:var(--grn); text-align:center; }

/* ── TOAST ─────────────────────────────────────────────── */
.ap-toasts { position:fixed; bottom:28px; right:28px; z-index:9999; display:flex; flex-direction:column; gap:10px; pointer-events:none; }
.toast { background:#0A1F0D; color:#fff; padding:12px 18px; border-radius:10px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:10px; box-shadow:var(--shlg); pointer-events:all; min-width:220px; animation:TIN .3s ease; }
.toast.ts{background:var(--grn);} .toast.tw{background:var(--amb);} .toast.td{background:var(--red);} .toast.ti{background:var(--blu);}
.toast.out { animation:TOUT .3s ease forwards; }

@keyframes UP   { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
@keyframes TIN  { from{opacity:0;transform:translateY(8px)}  to{opacity:1;transform:translateY(0)} }
@keyframes TOUT { from{opacity:1;transform:translateY(0)}    to{opacity:0;transform:translateY(8px)} }
@keyframes SHK  { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-5px)} 40%,80%{transform:translateX(5px)} }

@media(max-width:768px) {
  #projSlider { width:100vw; }
  .fr { grid-template-columns:1fr; }
  .ap-stats { grid-template-columns:repeat(2,1fr); }
  .vm-sbs { grid-template-columns:repeat(2,1fr); }
  .vm-ig  { grid-template-columns:1fr; }
}
  </style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="ap-wrap">

  <!-- PAGE HEADER -->
  <div class="ap-ph">
    <div>
      <p class="ey">PLT · Project Logistics Tracker</p>
      <h1>Active Projects</h1>
    </div>
    <div class="ap-ph-r">
      <?php if ($CAN_AUDIT_GLOBAL): ?>
      <button class="btn btn-ghost" id="auditBtn"><i class='bx bx-history'></i> Audit Trail</button>
      <?php endif; ?>
      <?php if ($CAN_EXPORT): ?>
      <button class="btn btn-ghost" id="expBtn"><i class='bx bx-export'></i> Export CSV</button>
      <?php endif; ?>
      <?php if ($CAN_CREATE_EDIT): ?>
      <button class="btn btn-primary" id="createBtn"><i class='bx bx-plus'></i> New Project</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ACCESS BANNERS -->
  <?php if ($roleName === 'Admin'): ?>
  <div class="access-banner ab-info"><i class='bx bx-info-circle'></i><div>You have <strong>Admin access</strong> — showing projects in your zone only. You can monitor progress, flag delays, and export zone reports. Budget override, cross-zone reassignment, and force-close require Super Admin.</div></div>
  <?php elseif ($roleName === 'Manager'): ?>
  <div class="access-banner ab-warn"><i class='bx bx-lock-open-alt'></i><div>You have <strong>Manager access</strong> — showing your zone's active projects. You can update progress and flag delays. Editing, budget controls, and closing projects require Admin or Super Admin.</div></div>
  <?php elseif ($roleRank <= 1): ?>
  <div class="access-banner ab-warn"><i class='bx bx-lock-open-alt'></i><div>You have <strong>Staff access</strong> — showing only projects assigned to you, read-only view.</div></div>
  <?php endif; ?>

  <!-- STATS -->
  <div class="ap-stats" id="statsBar"></div>

  <!-- TOOLBAR -->
  <div class="ap-tb">
    <div class="sw">
      <i class='bx bx-search'></i>
      <input type="text" class="si" id="srch" placeholder="Search by project name, ID, manager or reference…">
    </div>
    <select class="sel" id="fStatus">
      <option value="">All Statuses</option>
      <?php foreach ($ALLOWED_STATUSES as $st): ?>
      <option><?= htmlspecialchars($st) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($CAN_VIEW_ALL_ZONES): // Only SA can filter by zone ?>
    <select class="sel" id="fZoneF"><option value="">All Zones</option></select>
    <?php endif; ?>
    <select class="sel" id="fPriorityF">
      <option value="">All Priorities</option>
      <option>Critical</option><option>High</option><option>Medium</option><option>Low</option>
    </select>
    <?php if ($roleRank >= 3): // Admin+ get date range ?>
    <div class="date-range-wrap">
      <input type="date" class="fi-date" id="fDateFrom" title="Start Date From">
      <span>–</span>
      <input type="date" class="fi-date" id="fDateTo" title="Start Date To">
    </div>
    <?php endif; ?>
    <button class="clear-btn" id="clearFilters"><i class='bx bx-x'></i> Clear</button>
  </div>

  <!-- BULK BAR — SA only -->
  <?php if ($CAN_BATCH_CLOSE): ?>
  <div class="bulk-bar" id="bulkBar">
    <span class="bulk-count" id="bulkCount">0 selected</span>
    <div class="bulk-sep"></div>
    <button class="btn btn-reject btn-sm" id="batchCloseBtn"><i class='bx bx-x-circle'></i> Force Close Selected</button>
    <button class="btn btn-ghost btn-sm" id="clearSelBtn"><i class='bx bx-x-circle'></i> Clear</button>
    <span class="sa-exclusive" style="margin-left:auto"><i class='bx bx-shield-quarter'></i> Super Admin Exclusive</span>
  </div>
  <?php endif; ?>

  <!-- TABLE -->
  <div class="ap-card">
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
      <table class="ap-tbl" id="tbl">
        <colgroup>
          <?php if ($CAN_BATCH_CLOSE): ?><col class="col-cb"><?php endif; ?>
          <col class="col-id">
          <col class="col-name">
          <?php if ($roleRank >= 2): ?><col class="col-zone"><?php endif; ?>
          <?php if ($roleRank >= 3): ?><col class="col-mgr"><?php endif; ?>
          <col class="col-dates">
          <?php if ($roleRank >= 2): ?><col class="col-ref"><?php endif; ?>
          <?php if ($CAN_SEE_BUDGET): ?><col class="col-budget"><?php endif; ?>
          <col class="col-prog">
          <col class="col-stat">
          <col class="col-act">
        </colgroup>
        <thead>
          <tr>
            <?php if ($CAN_BATCH_CLOSE): ?>
            <th class="no-sort"><div class="cb-wrap"><input type="checkbox" class="cb" id="checkAll" title="Select all"></div></th>
            <?php endif; ?>
            <th data-col="project_id">Project ID <i class='bx bx-sort sic'></i></th>
            <th data-col="name">Project Name <i class='bx bx-sort sic'></i></th>
            <?php if ($roleRank >= 2): ?>
            <th data-col="zone">Zone <i class='bx bx-sort sic'></i></th>
            <?php endif; ?>
            <?php if ($roleRank >= 3): ?>
            <th data-col="manager">Manager <i class='bx bx-sort sic'></i></th>
            <?php endif; ?>
            <th data-col="start_date">Start / End <i class='bx bx-sort sic'></i></th>
            <?php if ($roleRank >= 2): ?>
            <th data-col="ref">PO/PR Ref <i class='bx bx-sort sic'></i></th>
            <?php endif; ?>
            <?php if ($CAN_SEE_BUDGET): ?>
            <th data-col="budget">Budget <i class='bx bx-sort sic'></i></th>
            <?php endif; ?>
            <th data-col="progress">Progress <i class='bx bx-sort sic'></i></th>
            <th data-col="status">Status <i class='bx bx-sort sic'></i></th>
            <th class="no-sort">Actions</th>
          </tr>
        </thead>
        <tbody id="tbody"></tbody>
      </table>
    </div>
    <div class="ap-pager" id="pager"></div>
  </div>

</div>
</main>

<!-- TOAST CONTAINER -->
<div class="ap-toasts" id="toastWrap"></div>

<!-- CREATE / EDIT SLIDE-OVER — SA only -->
<?php if ($CAN_CREATE_EDIT): ?>
<div id="slOverlay">
<div id="projSlider">
  <div class="sl-hdr">
    <div>
      <div class="sl-title" id="slTitle">New Project</div>
      <div class="sl-subtitle" id="slSub">Fill in all required fields below</div>
    </div>
    <button class="sl-close" id="slClose"><i class='bx bx-x'></i></button>
  </div>
  <div class="sl-body">
    <div class="fr">
      <div class="fg">
        <label class="fl">Project ID <span>*</span></label>
        <div class="proj-id-wrap">
          <input type="text" class="fi" id="fProjId" placeholder="e.g. PLT-2025-0001" style="padding-right:70px">
          <span class="auto-hint" id="autoGenHint" title="Auto-generate Project ID">Auto</span>
        </div>
      </div>
      <div class="fg">
        <label class="fl">Project Name <span>*</span></label>
        <input type="text" class="fi" id="fName" placeholder="e.g. Road Widening Phase 3">
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Zone <span>*</span></label>
        <select class="fs" id="fZoneSl"><option value="">Select…</option></select>
      </div>
      <div class="fg">
        <label class="fl">Project Manager <span>*</span></label>
        <input type="text" class="fi" id="fManager" placeholder="e.g. Carlo Mendoza">
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Priority <span>*</span></label>
        <select class="fs" id="fPrioritySl">
          <option value="">Select…</option>
          <option>Critical</option><option>High</option><option>Medium</option><option>Low</option>
        </select>
      </div>
      <div class="fg">
        <label class="fl">Status</label>
        <select class="fs" id="fStatusSl">
          <option>Planning</option><option>Active</option><option>On Hold</option>
          <option>Delayed</option><option>Completed</option><option>Terminated</option>
        </select>
      </div>
    </div>
    <div class="fr">
      <div class="fg"><label class="fl">Start Date <span>*</span></label><input type="date" class="fi" id="fStart"></div>
      <div class="fg"><label class="fl">Target End Date <span>*</span></label><input type="date" class="fi" id="fEnd"></div>
    </div>
    <div class="fr">
      <div class="fg" style="position:relative">
        <label class="fl">PO / PR Reference</label>
        <div style="position:relative">
          <i class='bx bx-search' style="position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:16px;color:var(--t3);pointer-events:none;z-index:1"></i>
          <input type="text" class="fi" id="fRef" placeholder="Search PO or PR number…" autocomplete="off" spellcheck="false" style="padding-left:34px">
        </div>
        <input type="hidden" id="fRefAmt">
        <div id="refSuggest" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:9999;background:#fff;border:1px solid rgba(46,125,50,.26);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.14);overflow:hidden;max-height:320px;overflow-y:auto;margin-top:2px;"></div>
      </div>
      <div class="fg">
        <label class="fl">Allocated Budget (₱) <span>*</span></label>
        <input type="number" class="fi" id="fBudget" placeholder="0.00" min="0" step="0.01">
      </div>
    </div>
    <div class="fr">
      <div class="fg"><label class="fl">Progress % (0–100)</label><input type="number" class="fi" id="fProgress" placeholder="0" min="0" max="100" value="0"></div>
    </div>
    <div class="fg">
      <label class="fl">Project Description / Scope</label>
      <textarea class="fta" id="fDesc" placeholder="Briefly describe project scope and objectives…"></textarea>
    </div>
    <div class="fg">
      <label class="fl">Zone Conflict Notes</label>
      <textarea class="fta" id="fConflict" placeholder="Note any cross-zone resource conflicts…" style="min-height:54px"></textarea>
    </div>
  </div>
  <div class="sl-foot">
    <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="slSubmit"><i class='bx bx-save'></i> Save Project</button>
  </div>
</div>
</div>
<?php endif; ?>

<!-- VIEW MODAL -->
<div id="viewModal">
  <div class="vm-box">
    <div class="vm-hd">
      <div class="vm-top">
        <div class="vm-si">
          <div class="vm-av" id="vmAvatar"></div>
          <div><div class="vm-nm" id="vmName">—</div><div class="vm-id" id="vmId">—</div></div>
        </div>
        <button class="vm-cl" id="vmClose"><i class='bx bx-x'></i></button>
      </div>
      <div class="vm-chips" id="vmChips"></div>
      <div class="vm-tabs">
        <button class="vm-tab active" data-t="ov"><i class='bx bx-grid-alt'></i> Overview</button>
        <button class="vm-tab" data-t="ml"><i class='bx bx-list-check'></i> Milestones</button>
        <?php if ($CAN_SEE_BUDGET): ?>
        <button class="vm-tab" data-t="bd"><i class='bx bx-bar-chart-alt-2'></i> Budget</button>
        <?php endif; ?>
        <?php if ($CAN_AUDIT_GLOBAL): ?>
        <button class="vm-tab" data-t="au"><i class='bx bx-shield-quarter'></i> Audit Trail</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="vm-bd" id="vmBody">
      <div class="vm-tp active" id="vt-ov"></div>
      <div class="vm-tp"        id="vt-ml"></div>
      <?php if ($CAN_SEE_BUDGET): ?>
      <div class="vm-tp"        id="vt-bd"></div>
      <?php endif; ?>
      <?php if ($CAN_AUDIT_GLOBAL): ?>
      <div class="vm-tp"        id="vt-au"></div>
      <?php endif; ?>
    </div>
    <div class="vm-ft" id="vmFoot"></div>
  </div>
</div>

<!-- ACTION CONFIRM MODAL -->
<div id="actionModal">
  <div class="am-box">
    <div class="am-icon" id="amIcon">⚠️</div>
    <div class="am-title" id="amTitle">Confirm Action</div>
    <div class="am-body"  id="amBody"></div>
    <div class="am-sa-note" id="amSaNote" style="display:none">
      <i class='bx bx-shield-quarter'></i>
      <span id="amSaText"></span>
    </div>
    <div id="amFields"></div>
    <div class="am-fg">
      <label id="amRmkLabel">Remarks / Notes</label>
      <textarea id="amRemarks" placeholder="Add remarks for this action…"></textarea>
    </div>
    <div class="am-acts">
      <button class="btn btn-ghost btn-sm" id="amCancel">Cancel</button>
      <button class="btn btn-sm" id="amConfirm">Confirm</button>
    </div>
  </div>
</div>

<script>
// ── ROLE from PHP ─────────────────────────────────────────────────────────────
const ROLE = <?= $jsRole ?>;

const API_URL = '<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>';

/* ── Helpers ──────────────────────────────────────────────────── */
const ZC = {
  'North Zone':'#2E7D32','South Zone':'#0D9488','East Zone':'#2563EB',
  'West Zone':'#D97706','Central Zone':'#7C3AED','Head Office':'#DC2626'
};
const COLS = ['#2E7D32','#1B5E20','#388E3C','#0D9488','#2563EB','#7C3AED','#D97706','#DC2626'];
const gc  = n => ZC[n] || COLS[String(n||'').split('').reduce((h,c)=>h*31+c.charCodeAt(0),0) % COLS.length];
const ini = n => String(n||'').split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
const esc = s => String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fD  = d => { if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'}); };
const fM  = v => v!=null ? '₱'+Number(v).toLocaleString('en-PH',{minimumFractionDigits:2}) : '—';
const today = () => new Date().toISOString().split('T')[0];

/* ── Badge helpers ─────────────────────────────────────────────── */
function badge(s) {
  const m = {Planning:'b-planning',Active:'b-active','On Hold':'b-onhold',Delayed:'b-delayed',Completed:'b-completed',Terminated:'b-terminated'};
  return `<span class="badge ${m[s]||'b-planning'}">${esc(s)}</span>`;
}
function priChip(p) {
  const m = {Critical:'pr-crit',High:'pr-high',Medium:'pr-med',Low:'pr-low'};
  return `<span class="pri-chip ${m[p]||'pr-med'}">${esc(p)}</span>`;
}
function progBar(pct, status) {
  let clr = '#2E7D32';
  if (status === 'Delayed' || pct < 30) clr = '#DC2626';
  else if (status === 'On Hold' || pct < 60) clr = '#D97706';
  else if (status === 'Completed') clr = '#2563EB';
  return `<div class="prog-wrap">
    <div class="prog-pct">${pct}%</div>
    <div class="prog-bar-bg"><div class="prog-bar-fill" style="width:${pct}%;background:${clr}"></div></div>
  </div>`;
}
function dotIconClass(dc) {
  const m = {'dot-g':'dot-g-ic','dot-b':'dot-b-ic','dot-o':'dot-o-ic','dot-r':'dot-r-ic','dot-gy':'dot-gy-ic'};
  return m[dc] || 'dot-gy-ic';
}
function dotIcon(dc) {
  const m = {'dot-g':'bx-check-circle','dot-b':'bx-edit','dot-o':'bx-shield-quarter','dot-r':'bx-x-circle','dot-gy':'bx-time'};
  return m[dc] || 'bx-time';
}

/* ── API wrapper ─────────────────────────────────────────────── */
async function api(action, params = {}, body = null) {
  const url = new URL(API_URL, location.origin);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params))
    if (v !== '' && v !== null && v !== undefined) url.searchParams.set(k, v);
  const opts = { headers: { 'Content-Type': 'application/json' } };
  if (body) { opts.method = 'POST'; opts.body = JSON.stringify(body); }
  const res  = await fetch(url, opts);
  const json = await res.json();
  if (!json.success) throw new Error(json.message ?? 'API error');
  return json.data;
}

/* ── State ───────────────────────────────────────────────────── */
let rows = [], totalRows = 0, lastPage = 1;
let pg = 1, PP = 15;
let sortCol = 'created_at', sortDir = 'desc';
let zoneOptions = [];
let selectedIds = new Set();
let editId = null;
let actionTarget = null, actionKey = null;

/* ── Action predicates (gated by ROLE) ──────────────────────── */
const isActive    = p => ['Active','Delayed','On Hold'].includes(p.status);
const canEdit     = p => ROLE.canCreateEdit && !['Completed','Terminated'].includes(p.status);
const canClose    = p => ROLE.canForceClose && !['Completed','Terminated'].includes(p.status);
const canComplete = p => ROLE.canForceComplete && isActive(p);
const canReassign = p => ROLE.canCrossReassign && isActive(p);
const canBudget   = p => ROLE.canBudgetOverride && isActive(p);
const canProgress = p => ROLE.canUpdateProgress && isActive(p);
const canFlag     = p => ROLE.canFlagDelay && isActive(p);

/* ── Filters ─────────────────────────────────────────────────── */
function getFilters() {
  return {
    search:   document.getElementById('srch').value.trim(),
    status:   document.getElementById('fStatus').value,
    zone:     document.getElementById('fZoneF')?.value || '',
    priority: document.getElementById('fPriorityF').value,
    date_from: document.getElementById('fDateFrom')?.value || '',
    date_to:   document.getElementById('fDateTo')?.value   || '',
    page: pg, per_page: PP,
  };
}

/* ── Main render ─────────────────────────────────────────────── */
async function render() {
  try {
    const data = await api('list', getFilters());
    rows      = data.rows     || [];
    totalRows = data.total    || 0;
    lastPage  = data.last_page|| 1;
    rStats(data.stats);
    rDropdowns(data.filters);
    rTable();
  } catch(e) { toast(e.message, 'd'); }
}

function rStats(s) {
  // Stats vary by role
  if (ROLE.rank <= 1) {
    // Staff: my projects stats only
    document.getElementById('statsBar').innerHTML = `
      <div class="sc"><div class="sc-ic ic-g"><i class='bx bx-briefcase'></i></div><div><div class="sc-v">${s.active|0}</div><div class="sc-l">My Active Projects</div></div></div>
      <div class="sc"><div class="sc-ic ic-a"><i class='bx bx-pause-circle'></i></div><div><div class="sc-v">${s.on_hold|0}</div><div class="sc-l">On Hold</div></div></div>`;
    return;
  }
  if (ROLE.rank === 2) {
    // Manager: zone stats, no budget consolidation, no terminated
    document.getElementById('statsBar').innerHTML = `
      <div class="sc"><div class="sc-ic ic-b"><i class='bx bx-briefcase'></i></div><div><div class="sc-v">${s.total|0}</div><div class="sc-l">Zone Projects</div></div></div>
      <div class="sc"><div class="sc-ic ic-g"><i class='bx bx-play-circle'></i></div><div><div class="sc-v">${s.active|0}</div><div class="sc-l">Active</div></div></div>
      <div class="sc"><div class="sc-ic ic-a"><i class='bx bx-pause-circle'></i></div><div><div class="sc-v">${s.on_hold|0}</div><div class="sc-l">On Hold</div></div></div>
      <div class="sc"><div class="sc-ic ic-r"><i class='bx bx-alarm-exclamation'></i></div><div><div class="sc-v">${s.delayed|0}</div><div class="sc-l">Delayed</div></div></div>`;
    return;
  }
  if (ROLE.rank === 3) {
    // Admin: zone stats + zone budget (no system-wide)
    document.getElementById('statsBar').innerHTML = `
      <div class="sc"><div class="sc-ic ic-b"><i class='bx bx-briefcase'></i></div><div><div class="sc-v">${s.total|0}</div><div class="sc-l">Total Projects</div></div></div>
      <div class="sc"><div class="sc-ic ic-g"><i class='bx bx-play-circle'></i></div><div><div class="sc-v">${s.active|0}</div><div class="sc-l">Active</div></div></div>
      <div class="sc"><div class="sc-ic ic-a"><i class='bx bx-pause-circle'></i></div><div><div class="sc-v">${s.on_hold|0}</div><div class="sc-l">On Hold</div></div></div>
      <div class="sc"><div class="sc-ic ic-r"><i class='bx bx-alarm-exclamation'></i></div><div><div class="sc-v">${s.delayed|0}</div><div class="sc-l">Delayed</div></div></div>
      <div class="sc"><div class="sc-ic ic-g"><i class='bx bx-check-double'></i></div><div><div class="sc-v">${s.completed|0}</div><div class="sc-l">Completed</div></div></div>
      <div class="sc"><div class="sc-ic ic-t"><i class='bx bx-money-withdraw'></i></div><div><div class="sc-v" style="font-size:12px;font-weight:800">${fM(s.total_budget)}</div><div class="sc-l">Zone Budget</div></div></div>`;
    return;
  }
  // SA: full system-wide stats
  document.getElementById('statsBar').innerHTML = `
    <div class="sc"><div class="sc-ic ic-b"><i class='bx bx-briefcase'></i></div><div><div class="sc-v">${s.total|0}</div><div class="sc-l">Total Projects</div></div></div>
    <div class="sc"><div class="sc-ic ic-g"><i class='bx bx-play-circle'></i></div><div><div class="sc-v">${s.active|0}</div><div class="sc-l">Active</div></div></div>
    <div class="sc"><div class="sc-ic ic-a"><i class='bx bx-pause-circle'></i></div><div><div class="sc-v">${s.on_hold|0}</div><div class="sc-l">On Hold</div></div></div>
    <div class="sc"><div class="sc-ic ic-r"><i class='bx bx-alarm-exclamation'></i></div><div><div class="sc-v">${s.delayed|0}</div><div class="sc-l">Delayed</div></div></div>
    <div class="sc"><div class="sc-ic ic-g"><i class='bx bx-check-double'></i></div><div><div class="sc-v">${s.completed|0}</div><div class="sc-l">Completed</div></div></div>
    <div class="sc"><div class="sc-ic ic-gy"><i class='bx bx-x-circle'></i></div><div><div class="sc-v">${s.terminated|0}</div><div class="sc-l">Terminated</div></div></div>
    <div class="sc"><div class="sc-ic ic-o"><i class='bx bx-transfer-alt'></i></div><div><div class="sc-v">${s.conflicts|0}</div><div class="sc-l">Zone Conflicts</div></div></div>
    <div class="sc"><div class="sc-ic ic-t"><i class='bx bx-money-withdraw'></i></div><div><div class="sc-v" style="font-size:12px;font-weight:800">${fM(s.total_budget)}</div><div class="sc-l">System Budget</div></div></div>`;
}

function rDropdowns({ zones = [], priorities = [] }) {
  zoneOptions = zones || [];

  // Zone filter — SA only
  const zEl = document.getElementById('fZoneF');
  if (zEl) {
    const zv = zEl.value;
    zEl.innerHTML = '<option value="">All Zones</option>' +
      zoneOptions.map(z => `<option ${z === zv ? 'selected' : ''}>${esc(z)}</option>`).join('');
  }

  // Zone selector in form — SA only
  const zSel = document.getElementById('fZoneSl');
  if (zSel) {
    const cur = zSel.value;
    zSel.innerHTML = '<option value="">Select…</option>' +
      zoneOptions.map(z => `<option ${z === cur ? 'selected' : ''}>${esc(z)}</option>`).join('');
  }
}

function rTable() {
  document.querySelectorAll('#tbl thead th[data-col]').forEach(th => {
    const c = th.dataset.col;
    th.classList.toggle('sorted', c === sortCol);
    const ic = th.querySelector('.sic');
    if (ic) ic.className = `bx ${c === sortCol ? (sortDir === 'asc' ? 'bx-sort-up' : 'bx-sort-down') : 'bx-sort'} sic`;
  });

  const tb = document.getElementById('tbody');
  const colCount = document.querySelectorAll('#tbl thead th').length;

  if (!rows.length) {
    tb.innerHTML = `<tr><td colspan="${colCount}"><div class="empty"><i class='bx bx-briefcase'></i><p>No projects match your filters.</p></div></td></tr>`;
    document.getElementById('pager').innerHTML = '';
    return;
  }

  tb.innerHTML = rows.map(p => {
    const clr = gc(p.zone);
    const chk = selectedIds.has(String(p.id));
    const overBudget = p.spend > p.budget;

    // Checkbox — SA only
    const cbCell = ROLE.canBatchClose
      ? `<td onclick="event.stopPropagation()"><div class="cb-wrap"><input type="checkbox" class="cb row-cb" data-id="${p.id}" ${chk ? 'checked' : ''}></div></td>`
      : '';

    // Zone cell — Manager+
    const zoneCell = ROLE.rank >= 2
      ? `<td onclick="openView(${p.id})"><div class="zone-dot"><span style="width:7px;height:7px;border-radius:50%;background:${clr};flex-shrink:0;display:inline-block"></span>${esc(p.zone)}</div></td>`
      : '';

    // Manager cell — Admin+
    const mgrCell = ROLE.rank >= 3
      ? `<td onclick="openView(${p.id})"><div class="mgr-cell"><div class="mgr-av" style="background:${clr}">${ini(p.manager)}</div><span class="mgr-name">${esc(p.manager)}</span></div></td>`
      : '';

    // PO/PR ref — Manager+
    const refCell = ROLE.rank >= 2
      ? `<td onclick="openView(${p.id})"><span class="proj-ref" style="color:var(--t2)">${esc(p.ref)}</span></td>`
      : '';

    // Budget — Manager+
    let budgetCell = '';
    if (ROLE.canSeeBudget) {
      budgetCell = `<td onclick="openView(${p.id})">
        <div class="proj-budget">${fM(p.budget)}</div>
        <div class="proj-spend" style="${overBudget ? 'color:var(--red);font-weight:700' : ''}">Spent: ${fM(p.spend)}</div>
      </td>`;
    }

    // Status column — Staff sees simpler version
    let statusCell = '';
    if (ROLE.rank <= 1) {
      statusCell = `<td onclick="openView(${p.id})">${badge(p.status)}</td>`;
    } else {
      statusCell = `<td onclick="openView(${p.id})">
        <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-start">
          ${badge(p.status)}
          <div style="display:flex;gap:3px;flex-wrap:wrap">
            ${priChip(p.priority)}
            ${p.conflict && ROLE.rank >= 3 ? `<span class="conflict-badge"><i class='bx bx-transfer-alt'></i> Conflict</span>` : ''}
          </div>
        </div>
      </td>`;
    }

    // Action buttons — gated by role
    let actions = `<button class="btn btn-ghost ionly" onclick="openView(${p.id})" title="View"><i class='bx bx-show'></i></button>`;
    if (canEdit(p))     actions += ` <button class="btn btn-ghost ionly" onclick="openEdit(${p.id})" title="Edit"><i class='bx bx-edit'></i></button>`;
    if (canProgress(p)) actions += ` <button class="btn btn-ghost ionly" title="Update Progress" onclick="promptAct(${p.id},'progress')"><i class='bx bx-trending-up'></i></button>`;
    if (canFlag(p))     actions += ` <button class="btn btn-ghost ionly" title="Flag Delay" onclick="promptAct(${p.id},'flag_delay')" style="color:var(--amb)"><i class='bx bx-flag'></i></button>`;
    if (canReassign(p)) actions += ` <button class="btn btn-ghost ionly" title="Reassign Manager" onclick="promptAct(${p.id},'reassign')"><i class='bx bx-user-x'></i></button>`;
    if (canBudget(p))   actions += ` <button class="btn btn-ghost ionly" title="Budget Override" onclick="promptAct(${p.id},'budget')" style="color:var(--amb)"><i class='bx bx-dollar'></i></button>`;
    if (canComplete(p)) actions += ` <button class="btn btn-ghost ionly" title="Force Complete" onclick="promptAct(${p.id},'complete')" style="color:var(--grn)"><i class='bx bx-check-double'></i></button>`;
    if (canClose(p))    actions += ` <button class="btn btn-ghost ionly" title="Force Close (SA)" onclick="promptAct(${p.id},'close')" style="color:var(--red)"><i class='bx bx-x-circle'></i></button>`;

    return `<tr class="${chk ? 'row-selected' : ''}">
      ${cbCell}
      <td onclick="openView(${p.id})"><span class="proj-id">${esc(p.project_id)}</span></td>
      <td onclick="openView(${p.id})">
        <div class="proj-name-cell">
          <span class="proj-nm">${esc(p.name)}</span>
          ${ROLE.rank >= 2 ? `<span class="proj-ref">${esc(p.ref)}</span>` : ''}
        </div>
      </td>
      ${zoneCell}${mgrCell}
      <td onclick="openView(${p.id})">
        <div class="proj-date">${fD(p.start_date)}<br><span style="font-size:10.5px;color:var(--t3)">→ ${fD(p.end_date)}</span></div>
      </td>
      ${refCell}${budgetCell}
      <td onclick="openView(${p.id})">${progBar(p.progress, p.status)}</td>
      ${statusCell}
      <td onclick="event.stopPropagation()"><div class="act-cell">${actions}</div></td>
    </tr>`;
  }).join('');

  if (ROLE.canBatchClose) {
    document.querySelectorAll('.row-cb').forEach(cb => {
      cb.addEventListener('change', function () {
        const id = this.dataset.id;
        if (this.checked) selectedIds.add(id); else selectedIds.delete(id);
        this.closest('tr').classList.toggle('row-selected', this.checked);
        updateBulkBar(); syncCheckAll();
      });
    });
    syncCheckAll();
  }
  rPager();
}

function syncCheckAll() {
  const ca = document.getElementById('checkAll');
  if (!ca) return;
  const pageIds = rows.map(p => String(p.id));
  const allChecked = pageIds.length > 0 && pageIds.every(id => selectedIds.has(id));
  const someChecked = pageIds.some(id => selectedIds.has(id));
  ca.checked = allChecked;
  ca.indeterminate = !allChecked && someChecked;
}

function rPager() {
  const s = (pg - 1) * PP + 1, e = Math.min(pg * PP, totalRows);
  let btns = '';
  for (let i = 1; i <= lastPage; i++) {
    if (i === 1 || i === lastPage || (i >= pg - 2 && i <= pg + 2)) btns += `<button class="pgb ${i === pg ? 'active' : ''}" onclick="goPage(${i})">${i}</button>`;
    else if (i === pg - 3 || i === pg + 3) btns += `<button class="pgb" disabled>…</button>`;
  }
  document.getElementById('pager').innerHTML = `
    <span>${totalRows === 0 ? 'No results' : `Showing ${s}–${e} of ${totalRows} projects`}</span>
    <div class="pg-btns">
      <button class="pgb" onclick="goPage(${pg - 1})" ${pg <= 1 ? 'disabled' : ''}><i class='bx bx-chevron-left'></i></button>
      ${btns}
      <button class="pgb" onclick="goPage(${pg + 1})" ${pg >= lastPage ? 'disabled' : ''}><i class='bx bx-chevron-right'></i></button>
    </div>`;
}
window.goPage = p => { pg = p; render(); };

/* ── Sort headers ─────────────────────────────────────────────── */
document.querySelectorAll('#tbl thead th[data-col]').forEach(th => {
  th.addEventListener('click', () => {
    const c = th.dataset.col;
    sortDir = sortCol === c ? (sortDir === 'asc' ? 'desc' : 'asc') : 'desc';
    sortCol = c; pg = 1; render();
  });
});

/* ── Filter events ─────────────────────────────────────────────── */
['srch','fStatus','fZoneF','fPriorityF','fDateFrom','fDateTo'].forEach(id => {
  document.getElementById(id)?.addEventListener('input', () => { pg = 1; render(); });
});
document.getElementById('clearFilters').addEventListener('click', () => {
  ['srch','fDateFrom','fDateTo'].forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
  ['fStatus','fZoneF','fPriorityF'].forEach(id => { const el = document.getElementById(id); if(el) el.selectedIndex = 0; });
  pg = 1; render();
});

/* ── Check All — SA only ─────────────────────────────────────────── */
document.getElementById('checkAll')?.addEventListener('change', function () {
  rows.forEach(p => { if (this.checked) selectedIds.add(String(p.id)); else selectedIds.delete(String(p.id)); });
  rTable(); updateBulkBar();
});

/* ── Bulk Bar — SA only ──────────────────────────────────────────── */
function updateBulkBar() {
  const bulkBar = document.getElementById('bulkBar');
  if (!bulkBar) return;
  const n = selectedIds.size;
  bulkBar.classList.toggle('on', n > 0);
  document.getElementById('bulkCount').textContent = n === 1 ? '1 selected' : `${n} selected`;
}
document.getElementById('clearSelBtn')?.addEventListener('click', () => { selectedIds.clear(); rTable(); updateBulkBar(); });

document.getElementById('batchCloseBtn')?.addEventListener('click', () => {
  const closeable = [...selectedIds].filter(id => {
    const p = rows.find(r => String(r.id) === id);
    return p && canClose(p);
  });
  if (!closeable.length) return toast('No closeable projects in selection', 'w');
  window._batchIds = closeable.map(Number);
  actionKey = 'batch-close'; actionTarget = null;
  showActionModal('⛔', `Force Close ${closeable.length} Project(s)`,
    `Force-close <strong>${closeable.length}</strong> project(s). Status will be set to Terminated.`,
    true, 'Super Admin force-close authority across all zones.',
    'btn-reject', '<i class="bx bx-x-circle"></i> Force Close',
    [{ id: 'amRemarks', label: 'Reason (required)', type: 'textarea', required: true }]);
});

/* ── VIEW MODAL ─────────────────────────────────────────────────── */
window.openView = async id => {
  try {
    const p = await api('get', { id });
    renderDetail(p);
    setVmTab('ov');
    document.getElementById('viewModal').classList.add('on');
  } catch(e) { toast(e.message, 'd'); }
};

function closeView() { document.getElementById('viewModal').classList.remove('on'); }
document.getElementById('vmClose').addEventListener('click', closeView);
document.getElementById('viewModal').addEventListener('click', function (e) { if (e.target === this) closeView(); });
document.querySelectorAll('.vm-tab').forEach(t => t.addEventListener('click', () => setVmTab(t.dataset.t)));
function setVmTab(name) {
  document.querySelectorAll('.vm-tab').forEach(t => t.classList.toggle('active', t.dataset.t === name));
  document.querySelectorAll('.vm-tp').forEach(p => p.classList.toggle('active', p.id === 'vt-' + name));
}

function renderDetail(p) {
  const clr = gc(p.zone);
  const overBudget = p.spend > p.budget;
  const budgetPct  = p.budget > 0 ? Math.min(100, Math.round((p.spend / p.budget) * 100)) : 0;
  const barColor   = overBudget ? '#DC2626' : budgetPct > 80 ? '#D97706' : '#2E7D32';

  document.getElementById('vmAvatar').innerHTML = ini(p.name);
  document.getElementById('vmAvatar').style.background = clr;
  document.getElementById('vmName').innerHTML = esc(p.name);
  document.getElementById('vmId').innerHTML   =
    `<span>${esc(p.project_id)}</span> &nbsp;·&nbsp; ${esc(p.zone)} &nbsp;${badge(p.status)} ${p.conflict && ROLE.rank >= 3 ? `<span class="conflict-badge"><i class='bx bx-transfer-alt'></i> Conflict</span>` : ''}`;

  // Chips — vary by role
  let chips = `<div class="vm-chip"><i class='bx bx-calendar'></i>${fD(p.start_date)} → ${fD(p.end_date)}</div>`;
  chips += `<div class="vm-chip"><i class='bx bx-trending-up'></i>${p.progress}% Complete</div>`;
  if (ROLE.rank >= 3) chips = `<div class="vm-chip"><i class='bx bx-user'></i>${esc(p.manager)}</div>` + chips;
  if (ROLE.canSeeBudget) chips += `<div class="vm-chip"><i class='bx bx-money-withdraw'></i>${fM(p.budget)}</div>`;
  chips += priChip(p.priority);
  document.getElementById('vmChips').innerHTML = chips;

  // Footer buttons — gated by role
  const ft = document.getElementById('vmFoot');
  const btns = [`<button class="btn btn-ghost btn-sm" onclick="closeView()">Close</button>`];
  if (canEdit(p))     btns.push(`<button class="btn btn-ghost btn-sm" onclick="closeView();openEdit(${p.id})"><i class='bx bx-edit'></i> Edit</button>`);
  if (canProgress(p)) btns.push(`<button class="btn btn-ghost btn-sm" onclick="closeView();promptAct(${p.id},'progress')"><i class='bx bx-trending-up'></i> Progress</button>`);
  if (canFlag(p))     btns.push(`<button class="btn btn-orange btn-sm" onclick="closeView();promptAct(${p.id},'flag_delay')"><i class='bx bx-flag'></i> Flag Delay</button>`);
  if (canReassign(p)) btns.push(`<button class="btn btn-override btn-sm" onclick="closeView();promptAct(${p.id},'reassign')"><i class='bx bx-user-x'></i> Reassign</button>`);
  if (canBudget(p))   btns.push(`<button class="btn btn-warn btn-sm" onclick="closeView();promptAct(${p.id},'budget')"><i class='bx bx-dollar'></i> Budget Override</button>`);
  if (canComplete(p)) btns.push(`<button class="btn btn-approve btn-sm" onclick="closeView();promptAct(${p.id},'complete')"><i class='bx bx-check-double'></i> Force Complete</button>`);
  if (canClose(p))    btns.push(`<button class="btn btn-reject btn-sm" onclick="closeView();promptAct(${p.id},'close')"><i class='bx bx-x-circle'></i> Force Close</button>`);
  ft.innerHTML = btns.join('');

  // Overview tab — adjusted per role
  const scoreCards = ROLE.canSeeBudget
    ? `<div class="vm-sbs">
        <div class="vm-sb"><div class="sbv">${(p.milestones||[]).filter(m=>m.status==='Completed').length}/${(p.milestones||[]).length}</div><div class="sbl">Milestones Done</div></div>
        <div class="vm-sb"><div class="sbv mono">${p.progress}%</div><div class="sbl">Overall Progress</div></div>
        <div class="vm-sb"><div class="sbv mono" style="${overBudget ? 'color:var(--red)' : ''}">${fM(p.spend)}</div><div class="sbl">Actual Spend</div></div>
        <div class="vm-sb"><div class="sbv mono" style="${overBudget ? 'color:var(--red)' : 'color:#166534'}">${overBudget ? '-' : '+'}${fM(Math.abs(p.budget - p.spend))}</div><div class="sbl">${overBudget ? 'Over Budget' : 'Remaining'}</div></div>
      </div>`
    : `<div class="vm-sbs" style="grid-template-columns:repeat(2,1fr)">
        <div class="vm-sb"><div class="sbv">${(p.milestones||[]).filter(m=>m.status==='Completed').length}/${(p.milestones||[]).length}</div><div class="sbl">Milestones Done</div></div>
        <div class="vm-sb"><div class="sbv mono">${p.progress}%</div><div class="sbl">Overall Progress</div></div>
      </div>`;

  // Info fields — vary by role
  let infoFields = '';
  infoFields += `<div class="vm-ii"><label>Project ID</label><div class="v" style="font-family:'DM Mono',monospace">${esc(p.project_id)}</div></div>`;
  infoFields += `<div class="vm-ii"><label>Zone</label><div class="v" style="color:${clr}">${esc(p.zone)}</div></div>`;
  if (ROLE.rank >= 3) infoFields += `<div class="vm-ii"><label>Project Manager</label><div class="v">${esc(p.manager)}</div></div>`;
  infoFields += `<div class="vm-ii"><label>Priority</label><div class="v">${priChip(p.priority)}</div></div>`;
  infoFields += `<div class="vm-ii"><label>Start Date</label><div class="v muted">${fD(p.start_date)}</div></div>`;
  infoFields += `<div class="vm-ii"><label>Target End Date</label><div class="v muted">${fD(p.end_date)}</div></div>`;
  if (ROLE.rank >= 2) infoFields += `<div class="vm-ii"><label>PO / PR Reference</label><div class="v muted" style="font-family:'DM Mono',monospace;font-size:12px">${esc(p.ref)}</div></div>`;
  infoFields += `<div class="vm-ii"><label>Status</label><div class="v">${badge(p.status)}</div></div>`;
  if (p.description) infoFields += `<div class="vm-ii vm-full"><label>Project Description</label><div class="v muted">${esc(p.description)}</div></div>`;
  if (p.conflict && p.conflict_note && ROLE.rank >= 3)
    infoFields += `<div class="vm-ii vm-full"><div class="conflict-note-block"><i class='bx bx-transfer-alt'></i><span><strong>Cross-Zone Resource Conflict:</strong> ${esc(p.conflict_note)}</span></div></div>`;

  const saNoteHtml = ROLE.rank >= 4
    ? `<div class="sa-note"><i class='bx bx-shield-quarter'></i><span>Super Admin view — you can Force Complete, Reassign Manager, Override Budget, or Force Close this project from the actions below.</span></div>`
    : '';

  document.getElementById('vt-ov').innerHTML = `${scoreCards}<div class="vm-ig">${infoFields}</div>${saNoteHtml}`;

  // Milestones tab
  const milestones = p.milestones || [];
  const msBadge = s => {
    if (s === 'Completed') return `<span class="badge b-completed">Completed</span>`;
    if (s === 'In Progress') return `<span class="badge b-active">In Progress</span>`;
    return `<span class="badge b-planning">Pending</span>`;
  };
  document.getElementById('vt-ml').innerHTML = milestones.length
    ? `<table class="vm-txnt">
        <thead><tr><th style="width:28px">#</th><th>Milestone</th><th>Target Date</th><th>Status</th></tr></thead>
        <tbody>${milestones.map((m, i) => `<tr>
          <td style="color:#9CA3AF;font-size:11px;font-weight:600">${i + 1}</td>
          <td style="font-weight:600;color:var(--text-primary)">${esc(m.name)}</td>
          <td style="font-family:'DM Mono',monospace;font-size:11.5px;color:#6B7280">${esc(m.target_date || m.target || '—')}</td>
          <td>${msBadge(m.status)}</td>
        </tr>`).join('')}</tbody>
      </table>`
    : `<div class="empty"><i class='bx bx-list-check'></i><p>No milestones recorded for this project.</p></div>`;

  // Budget tab — Admin+ only (element only exists in DOM for them)
  const vtBd = document.getElementById('vt-bd');
  if (vtBd) {
    vtBd.innerHTML = `
      <div class="vm-sbs">
        <div class="vm-sb"><div class="sbv mono">${fM(p.budget)}</div><div class="sbl">Allocated Budget</div></div>
        <div class="vm-sb"><div class="sbv mono" style="${overBudget ? 'color:#DC2626' : ''}">${fM(p.spend)}</div><div class="sbl">Actual Spend</div></div>
        <div class="vm-sb"><div class="sbv mono" style="${overBudget ? 'color:#DC2626' : 'color:#166534'}">${overBudget ? '-' : '+'}${fM(Math.abs(p.budget - p.spend))}</div><div class="sbl">${overBudget ? 'Overrun' : 'Remaining'}</div></div>
        <div class="vm-sb"><div class="sbv mono">${budgetPct}%</div><div class="sbl">Budget Consumed</div></div>
      </div>
      <div class="budget-bar-row">
        <div class="budget-labels">
          <span style="color:#6B7280">Budget Utilization</span>
          <span style="color:${barColor};font-weight:700">${budgetPct}%</span>
        </div>
        <div class="budget-bar-bg"><div class="budget-bar-fill" style="width:${Math.min(100,budgetPct)}%;background:${barColor}"></div></div>
        ${overBudget ? `<div style="font-size:11px;color:#DC2626;font-weight:700;margin-top:4px"><i class='bx bx-error-circle'></i> Budget overrun — Super Admin override required to continue.</div>` : ''}
      </div>`;
  }

  // Audit trail — SA only (element only exists in DOM for them)
  const vtAu = document.getElementById('vt-au');
  if (vtAu) {
    const audit = p.audit || [];
    vtAu.innerHTML = `
      <div class="sa-note"><i class='bx bx-shield-quarter'></i><span>Immutable audit trail — Super Admin visible only. Timestamps and IPs are read-only.</span></div>
      <div>${audit.map(a => `
        <div class="vm-audit-item">
          <div class="vm-audit-dot ${dotIconClass(a.dot_class)}"><i class='bx ${dotIcon(a.dot_class)}'></i></div>
          <div class="vm-audit-body">
            <div class="au">${esc(a.action_label)} ${a.is_super_admin ? '<span class="sa-tag">Super Admin</span>' : ''}</div>
            <div class="at">
              <i class='bx bx-user' style="font-size:11px"></i>${esc(a.actor_name)} · ${esc(a.actor_role)}
              ${a.ip_address ? `<span class="vm-audit-ip"><i class='bx bx-desktop' style="font-size:10px;margin-right:2px"></i>${esc(a.ip_address)}</span>` : ''}
            </div>
            ${a.note ? `<div class="vm-audit-note">"${esc(a.note)}"</div>` : ''}
          </div>
          <div class="vm-audit-ts">${esc(new Date(a.occurred_at).toLocaleString('en-PH'))}</div>
        </div>`).join('')}
      </div>`;
  }
}

/* ── ACTION MODAL ─────────────────────────────────────────────── */
function showActionModal(icon, title, body, sa, saText, btnClass, btnLabel, extraFields = []) {
  document.getElementById('amIcon').textContent  = icon;
  document.getElementById('amTitle').textContent = title;
  document.getElementById('amBody').innerHTML    = body;
  const saNote = document.getElementById('amSaNote');
  if (sa) { saNote.style.display = 'flex'; document.getElementById('amSaText').textContent = saText; }
  else    { saNote.style.display = 'none'; }
  const container = document.getElementById('amFields');
  container.innerHTML = extraFields.filter(f => f.id !== 'amRemarks').map(f => `
    <div class="am-fg">
      <label>${f.label}</label>
      ${f.type === 'textarea'
        ? `<textarea id="${f.id}" placeholder="${f.placeholder || ''}"></textarea>`
        : `<input type="${f.type || 'text'}" id="${f.id}" placeholder="${f.placeholder || ''}">`
      }
    </div>`).join('');
  document.getElementById('amRemarks').value = '';
  document.getElementById('amRmkLabel').textContent = 'Remarks / Notes';
  const cb = document.getElementById('amConfirm');
  cb.className = `btn btn-sm ${btnClass}`;
  cb.innerHTML = btnLabel;
  document.getElementById('actionModal').classList.add('on');
}

function promptAct(id, type) {
  if (!ROLE.canUpdateProgress && (type === 'progress' || type === 'flag_delay')) {
    return toast('Insufficient permissions', 'w');
  }
  const p = rows.find(r => r.id === id); if (!p) return;
  actionTarget = id; actionKey = type;

  const projLabel = `<strong>${esc(p.project_id)}</strong> — ${esc(p.name)}<br><span style="font-size:12px;color:#9EB0A2">${esc(p.zone)} · ${ROLE.rank >= 3 ? esc(p.manager) + ' · ' : ''}${ROLE.canSeeBudget ? fM(p.budget) : ''}</span>`;

  const cfg = {
    complete: {
      icon: '✅', title: `Force Complete — ${p.project_id}`, body: projLabel,
      sa: true, saText: 'Super Admin override — marks project as Completed regardless of milestone status.',
      btn: 'btn-approve', label: '<i class="bx bx-check-double"></i> Force Complete', fields: []
    },
    close: {
      icon: '⛔', title: `Force Close — ${p.project_id}`, body: projLabel,
      sa: true, saText: 'Super Admin authority required to force-close an active project.',
      btn: 'btn-reject', label: '<i class="bx bx-x-circle"></i> Force Close', fields: []
    },
    reassign: {
      icon: '👤', title: `Reassign Manager — ${p.project_id}`,
      body: `Current manager: <strong>${esc(p.manager)}</strong>`,
      sa: true, saText: 'Super Admin can reassign the project manager cross-zone.',
      btn: 'btn-override', label: '<i class="bx bx-user-check"></i> Reassign',
      fields: [{ id: 'amReassignTo', label: 'Reassign To *', type: 'text', placeholder: 'Target manager name' }]
    },
    budget: {
      icon: '💰', title: `Budget Override — ${p.project_id}`,
      body: `Current budget: <strong>${fM(p.budget)}</strong>`,
      sa: true, saText: 'Super Admin budget override — adjusts the allocated budget ceiling.',
      btn: 'btn-warn', label: '<i class="bx bx-dollar-circle"></i> Override Budget',
      fields: [{ id: 'amNewBudget', label: 'New Budget Amount (₱) *', type: 'number', placeholder: '0.00' }]
    },
    progress: {
      icon: '📈', title: `Update Progress — ${p.project_id}`, body: projLabel,
      sa: false, saText: '',
      btn: 'btn-primary', label: '<i class="bx bx-trending-up"></i> Update Progress',
      fields: []
    },
    flag_delay: {
      icon: '🚩', title: `Flag Delay — ${p.project_id}`, body: projLabel,
      sa: false, saText: '',
      btn: 'btn-orange', label: '<i class="bx bx-flag"></i> Submit Flag',
      fields: [{ id: 'amEscalate', label: 'Escalate to Admin', type: 'text', placeholder: 'Leave blank to flag only; type YES to escalate' }]
    },
  };

  const c = cfg[type];
  if (!c) return toast('Unknown action', 'w');
  showActionModal(c.icon, c.title, c.body, c.sa, c.saText, c.btn, c.label, c.fields || []);

  if (type === 'progress') {
    document.getElementById('amFields').innerHTML = `
      <div class="progress-slider-wrap">
        <div class="progress-slider-val" id="progVal">${p.progress}%</div>
        <input type="range" min="0" max="100" step="5" value="${p.progress}" id="progRange"
               oninput="document.getElementById('progVal').textContent=this.value+'%'">
      </div>`;
    document.getElementById('amRmkLabel').textContent = 'Notes';
  }
}

window.promptAct = promptAct;

document.getElementById('amConfirm').addEventListener('click', async () => {
  const rmk = document.getElementById('amRemarks').value.trim();
  const btn = document.getElementById('amConfirm');
  const orig = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Please wait…';

  try {
    if (actionKey === 'batch-close') {
      if (!rmk) { toast('Reason is required', 'w'); btn.disabled = false; btn.innerHTML = orig; return; }
      await api('batch_close', {}, { ids: window._batchIds, reason: rmk });
      selectedIds.clear();
      document.getElementById('actionModal').classList.remove('on');
      toast(`${window._batchIds.length} project(s) force-closed`, 'w');
      await render(); updateBulkBar();
      return;
    }

    if (actionKey === 'complete') {
      await api('complete', {}, { id: actionTarget, reason: rmk });
      toast('Project force-completed', 's');
    } else if (actionKey === 'close') {
      if (!rmk) { toast('Reason is required', 'w'); btn.disabled = false; btn.innerHTML = orig; return; }
      await api('close', {}, { id: actionTarget, reason: rmk });
      toast('Project force-closed', 'w');
    } else if (actionKey === 'reassign') {
      const to = document.getElementById('amReassignTo')?.value.trim() || '';
      if (!to) { toast('Target manager name is required', 'w'); btn.disabled = false; btn.innerHTML = orig; return; }
      await api('reassign', {}, { id: actionTarget, to, reason: rmk });
      toast(`Project reassigned to ${to}`, 's');
    } else if (actionKey === 'budget') {
      const nb = parseFloat(document.getElementById('amNewBudget')?.value || 0);
      if (!nb || nb <= 0) { toast('New budget amount is required', 'w'); btn.disabled = false; btn.innerHTML = orig; return; }
      if (!rmk) { toast('Reason is required for budget override', 'w'); btn.disabled = false; btn.innerHTML = orig; return; }
      await api('budget', {}, { id: actionTarget, budget: nb, reason: rmk });
      toast('Budget updated', 's');
    } else if (actionKey === 'progress') {
      const pct = +(document.getElementById('progRange')?.value || 0);
      await api('progress', {}, { id: actionTarget, pct, note: rmk });
      toast(`Progress updated to ${pct}%`, 's');
    } else if (actionKey === 'flag_delay') {
      const escalateEl = document.getElementById('amEscalate');
      const escalate = escalateEl && escalateEl.value.trim().toUpperCase() === 'YES';
      await api('flag_delay', {}, { id: actionTarget, note: rmk, escalate });
      toast(escalate ? 'Delay escalated to Admin' : 'Delay flagged', 'w');
    }

    document.getElementById('actionModal').classList.remove('on');
    await render();
  } catch(e) {
    toast(e.message, 'd');
  } finally {
    btn.disabled = false; btn.innerHTML = orig;
  }
});

document.getElementById('amCancel').addEventListener('click', () => document.getElementById('actionModal').classList.remove('on'));
document.getElementById('actionModal').addEventListener('click', function (e) { if (e.target === this) this.classList.remove('on'); });

/* ── SLIDE-OVER — SA only ─────────────────────────────────────────── */
<?php if ($CAN_CREATE_EDIT): ?>
document.getElementById('createBtn').addEventListener('click', async () => {
  editId = null;
  document.getElementById('slTitle').textContent = 'New Project';
  document.getElementById('slSub').textContent   = 'Fill in all required fields below';
  ['fName','fManager','fRef','fDesc','fConflict'].forEach(id => document.getElementById(id).value = '');
  ['fZoneSl','fPrioritySl'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('fStatusSl').value   = 'Planning';
  document.getElementById('fBudget').value     = '';
  document.getElementById('fProgress').value   = 0;
  document.getElementById('fStart').value      = today();
  document.getElementById('fEnd').value        = '';
  document.getElementById('fProjId').value     = '';
  document.getElementById('fProjId').readOnly  = false;
  document.getElementById('autoGenHint').style.display = '';
  openSlider();
});

window.openEdit = async id => {
  try {
    const p = await api('get', { id });
    editId = id;
    document.getElementById('slTitle').textContent = `Edit — ${p.project_id}`;
    document.getElementById('slSub').textContent   = p.name;
    document.getElementById('fProjId').value       = p.project_id;
    document.getElementById('fProjId').readOnly    = true;
    document.getElementById('autoGenHint').style.display = 'none';
    document.getElementById('fName').value         = p.name;
    document.getElementById('fZoneSl').value       = p.zone;
    document.getElementById('fManager').value      = p.manager;
    document.getElementById('fPrioritySl').value   = p.priority;
    document.getElementById('fStatusSl').value     = p.status;
    document.getElementById('fStart').value        = p.start_date;
    document.getElementById('fEnd').value          = p.end_date;
    document.getElementById('fRef').value          = p.ref || '';
    document.getElementById('fBudget').value       = p.budget;
    document.getElementById('fProgress').value     = p.progress;
    document.getElementById('fDesc').value         = p.description || '';
    document.getElementById('fConflict').value     = p.conflict_note || '';
    openSlider();
  } catch(e) { toast(e.message, 'd'); }
};

document.getElementById('autoGenHint').addEventListener('click', async () => {
  try {
    const data = await api('next_id');
    document.getElementById('fProjId').value = data.project_id;
  } catch {
    const y = new Date().getFullYear();
    document.getElementById('fProjId').value = `PLT-${y}-${String(Math.floor(Math.random()*9000)+1000)}`;
  }
});

function openSlider() {
  document.getElementById('projSlider').classList.add('on');
  document.getElementById('slOverlay').classList.add('on');
}
function closeSlider() {
  document.getElementById('projSlider').classList.remove('on');
  document.getElementById('slOverlay').classList.remove('on');
  editId = null;
}
document.getElementById('slOverlay').addEventListener('click', function (e) { if (e.target === this) closeSlider(); });
document.getElementById('slClose').addEventListener('click', closeSlider);
document.getElementById('slCancel').addEventListener('click', closeSlider);

document.getElementById('slSubmit').addEventListener('click', async () => {
  const project_id = document.getElementById('fProjId').value.trim();
  const name       = document.getElementById('fName').value.trim();
  const zone       = document.getElementById('fZoneSl').value;
  const manager    = document.getElementById('fManager').value.trim();
  const priority   = document.getElementById('fPrioritySl').value;
  const start_date = document.getElementById('fStart').value;
  const end_date   = document.getElementById('fEnd').value;
  const budget     = parseFloat(document.getElementById('fBudget').value) || 0;

  if (!project_id) { shk('fProjId');    return toast('Project ID is required', 'w'); }
  if (!name)       { shk('fName');      return toast('Project name is required', 'w'); }
  if (!zone)       { shk('fZoneSl');    return toast('Please select a zone', 'w'); }
  if (!manager)    { shk('fManager');   return toast('Project manager is required', 'w'); }
  if (!priority)   { shk('fPrioritySl'); return toast('Please select a priority', 'w'); }
  if (!start_date) { shk('fStart');     return toast('Start date is required', 'w'); }
  if (!end_date)   { shk('fEnd');       return toast('Target end date is required', 'w'); }
  if (!budget)     { shk('fBudget');    return toast('Budget amount is required', 'w'); }

  const payload = {
    id: editId, project_id, name, zone, manager, priority,
    start_date, end_date,
    ref:          document.getElementById('fRef').value.trim(),
    budget,
    progress:     parseInt(document.getElementById('fProgress').value) || 0,
    status:       document.getElementById('fStatusSl').value,
    description:  document.getElementById('fDesc').value.trim(),
    conflict_note:document.getElementById('fConflict').value.trim(),
  };

  const btn = document.getElementById('slSubmit');
  const orig = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Saving…';

  try {
    await api(editId ? 'update' : 'create', {}, payload);
    toast(editId ? `${name} updated` : `${project_id} created`, 's');
    closeSlider();
    await render();
  } catch(e) {
    toast(e.message, 'd');
  } finally {
    btn.disabled = false; btn.innerHTML = orig;
  }
});

/* ── REF AUTOCOMPLETE — SA only ─────────────────────────────────── */
(function () {
  const fRef    = document.getElementById('fRef');
  const suggest = document.getElementById('refSuggest');
  let _debounce = null;
  const TYPE_COLOR = { PO: '#2563EB', PR: '#D97706' };
  const TYPE_BG    = { PO: '#EFF6FF', PR: '#FEF3C7' };

  function hideSuggest() { suggest.style.display = 'none'; suggest.innerHTML = ''; }

  function showSuggestions(rows) {
    if (!rows.length) {
      suggest.innerHTML = '<div style="padding:12px 14px;font-size:12px;color:var(--t3)">No matching POs or PRs found</div>';
      suggest.style.display = 'block'; return;
    }
    suggest.innerHTML = rows.map(r => `
      <div class="ref-sug-item" data-ref='${JSON.stringify(r).replace(/'/g,"&#39;")}' style="padding:10px 14px;cursor:pointer;border-bottom:1px solid rgba(46,125,50,.1);transition:background .12s"
           onmouseenter="this.style.background='#F0FDF4'" onmouseleave="this.style.background=''">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
          <span style="font-family:'DM Mono',monospace;font-size:12px;font-weight:700;color:var(--grn)">${r.ref_no}</span>
          <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;background:${TYPE_BG[r.ref_type]};color:${TYPE_COLOR[r.ref_type]};white-space:nowrap">${r.ref_type}</span>
        </div>
        <div style="font-size:12px;font-weight:600;color:var(--t1);margin-top:2px">${r.party}</div>
        <div style="display:flex;align-items:center;gap:10px;margin-top:2px;flex-wrap:wrap">
          <span style="font-size:11px;color:var(--t2);font-family:'DM Mono',monospace">₱${Number(r.total_amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</span>
          <span style="font-size:11px;padding:2px 7px;border-radius:20px;background:${r.status==='Approved'||r.status==='Confirmed'||r.status==='Fulfilled'?'#DCFCE7':'#FEF3C7'};color:${r.status==='Approved'||r.status==='Confirmed'||r.status==='Fulfilled'?'#166534':'#92400E'}">${r.status}</span>
        </div>
      </div>`).join('');
    suggest.querySelectorAll('.ref-sug-item').forEach(item => {
      item.addEventListener('mousedown', e => {
        e.preventDefault();
        const r = JSON.parse(item.dataset.ref);
        fRef.value = r.ref_no;
        const budgetEl = document.getElementById('fBudget');
        if (budgetEl && (!budgetEl.value || +budgetEl.value === 0) && r.total_amount > 0)
          budgetEl.value = r.total_amount;
        hideSuggest(); fRef.focus();
        toast(`${r.ref_type} ${r.ref_no} linked — ${r.party}`, 's');
      });
    });
    suggest.style.display = 'block';
  }

  async function fetchRefs(q) {
    try { const data = await api('lookup_ref', { q }); showSuggestions(Array.isArray(data) ? data : []); }
    catch(e) { hideSuggest(); }
  }

  fRef.addEventListener('focus', () => { if (fRef.value.trim().length === 0) fetchRefs(''); });
  fRef.addEventListener('input', () => { clearTimeout(_debounce); _debounce = setTimeout(() => fetchRefs(fRef.value.trim()), 220); });
  fRef.addEventListener('blur',   () => { setTimeout(hideSuggest, 180); });
  fRef.addEventListener('keydown', e => { if (e.key === 'Escape') hideSuggest(); });
})();
<?php else: ?>
// Non-SA: openEdit is a no-op
window.openEdit = id => toast('Insufficient permissions to edit projects', 'w');
<?php endif; ?>

/* ── GLOBAL AUDIT TRAIL — SA only ──────────────────────────────── */
<?php if ($CAN_AUDIT_GLOBAL): ?>
document.getElementById('auditBtn').addEventListener('click', async () => {
  try {
    const data = await api('audit_global', { limit: 50 });
    document.getElementById('amIcon').textContent  = '📋';
    document.getElementById('amTitle').textContent = 'Global Project Audit Trail';
    document.getElementById('amBody').innerHTML    = `<span style="font-size:12px;color:var(--t3)">${data.total} entries system-wide</span>`;
    document.getElementById('amSaNote').style.display = 'flex';
    document.getElementById('amSaText').textContent = 'Super Admin View — complete project activity log.';
    document.getElementById('amFields').innerHTML  = `
      <div style="max-height:340px;overflow-y:auto;border:1px solid var(--bd);border-radius:10px;padding:4px 0">
        ${(data.rows || []).map(a => `
          <div style="display:flex;gap:10px;padding:10px 14px;border-bottom:1px solid var(--bd)">
            <div class="vm-audit-dot ${dotIconClass(a.dot_class)}" style="flex-shrink:0"><i class='bx ${dotIcon(a.dot_class)}'></i></div>
            <div style="flex:1;min-width:0">
              <div style="font-size:13px;font-weight:600;color:var(--t1)">${esc(a.action_label)}</div>
              <div style="font-size:11px;color:var(--t3);font-family:'DM Mono',monospace">${esc(a.project_id)} · ${esc(a.actor_name)} · ${new Date(a.occurred_at).toLocaleString('en-PH')}</div>
            </div>
          </div>`).join('')}
      </div>`;
    document.getElementById('amRemarks').style.display  = 'none';
    document.getElementById('amRmkLabel').style.display = 'none';
    const cb = document.getElementById('amConfirm');
    cb.className = 'btn btn-ghost btn-sm'; cb.innerHTML = 'Close';
    cb.onclick = () => { document.getElementById('actionModal').classList.remove('on'); cb.onclick = null; restoreAmRemarks(); };
    document.getElementById('amCancel').style.display = 'none';
    document.getElementById('actionModal').classList.add('on');
  } catch(e) { toast(e.message, 'd'); }
});
function restoreAmRemarks() {
  document.getElementById('amRemarks').style.display  = '';
  document.getElementById('amRmkLabel').style.display = '';
  document.getElementById('amCancel').style.display   = '';
  document.getElementById('amConfirm').onclick        = null;
}
<?php endif; ?>

/* ── EXPORT — Admin+ ──────────────────────────────────────────── */
<?php if ($CAN_EXPORT): ?>
document.getElementById('expBtn').addEventListener('click', () => {
  const url = new URL(API_URL, location.origin);
  url.searchParams.set('action', 'export');
  window.open(url.toString(), '_blank');
  toast('Export started — CSV downloading…', 's');
});
<?php endif; ?>

/* ── Utilities ───────────────────────────────────────────────────── */
function shk(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.style.borderColor = 'var(--red)'; el.style.animation = 'none';
  el.offsetHeight; el.style.animation = 'SHK .3s ease';
  setTimeout(() => { el.style.borderColor = ''; el.style.animation = ''; }, 600);
}
function toast(msg, type = 's') {
  const ic = { s: 'bx-check-circle', w: 'bx-error', d: 'bx-error-circle', i: 'bx-info-circle' };
  const el = document.createElement('div');
  el.className = `toast t${type}`;
  el.innerHTML = `<i class='bx ${ic[type] || "bx-check-circle"}' style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 320); }, 3200);
}

/* ── Init ─────────────────────────────────────────────────────────── */
render();
</script>
</body>
</html>