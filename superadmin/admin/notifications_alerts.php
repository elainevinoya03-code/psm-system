<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE GUARD (mirror of sidebar role logic) ─────────────────────────────────
function na_resolve_role(): string {
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

$naRoleName = na_resolve_role();
$naRoleRank = match($naRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,
};

// Only Super Admin may access Notifications & Alerts admin page and APIs
if ($naRoleRank < 4) {
    $isApi = isset($_GET['api'])
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

    if ($isApi) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Forbidden — Super Admin only']);
        exit;
    }

    $dashboardUrl = match($naRoleName) {
        'Super Admin' => '/superadmin_dashboard.php',
        'Admin'       => '/admin_dashboard.php',
        'Manager'     => '/manager_dashboard.php',
        default       => '/user_dashboard.php',
    };
    header('Location: ' . $dashboardUrl);
    exit;
}

// ── HELPERS ──────────────────────────────────────────────────────────────────
function na_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function na_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function na_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** Supabase REST — same pattern as all other modules */
function na_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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

function na_fetch(string $url): array {
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

// Auto-incrementing notif_id
function na_next_id(): string {
    $rows = na_sb('notifications', 'GET', [
        'select' => 'notif_id',
        'order'  => 'id.desc',
        'limit'  => '1',
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/ALT-(\d+)/', $rows[0]['notif_id'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return 'ALT-' . sprintf('%04d', $next);
}

/** Build a clean DTO from a notifications row */
function na_build(array $row): array {
    return [
        'id'               => (int)$row['id'],
        'notifId'          => $row['notif_id']          ?? '',
        'category'         => $row['category']          ?? '',
        'module'           => $row['module']            ?? '',
        'severity'         => $row['severity']          ?? 'Medium',
        'title'            => $row['title']             ?? '',
        'description'      => $row['description']       ?? '',
        'zone'             => $row['zone']              ?? '',
        'status'           => $row['status']            ?? 'unread',
        'sourceTable'      => $row['source_table']      ?? '',
        'sourceId'         => $row['source_id']         ?? null,
        'escalatedBy'      => $row['escalated_by']      ?? null,
        'escalatedAt'      => $row['escalated_at']      ?? null,
        'escalatePriority' => $row['escalate_priority'] ?? null,
        'escalateRemarks'  => $row['escalate_remarks']  ?? null,
        'createdAt'        => $row['created_at']        ?? '',
        'updatedAt'        => $row['updated_at']        ?? '',
    ];
}

/**
 * Seed missing notifications from live module data.
 * Only inserts if no existing notification references the same source_table+source_id.
 */
function na_seed_from_modules(): int {
    $seeded = 0;
    $now    = date('Y-m-d H:i:s');

    // ── 1. SWS Low Stock ────────────────────────────────────────────────────
    try {
        $items = na_fetch(SUPABASE_URL . '/rest/v1/sws_inventory?select=id,code,name,stock,min_level,zone,active&active=eq.true&order=stock.asc&limit=100');
        foreach ($items as $item) {
            if ((int)$item['stock'] > (int)$item['min_level']) continue;
            // Check if already notified
            $exists = na_sb('notifications', 'GET', [
                'select'       => 'id',
                'source_table' => 'eq.sws_inventory',
                'source_id'    => 'eq.' . $item['id'],
                'status'       => 'neq.dismissed',
                'limit'        => '1',
            ]);
            if (!empty($exists)) continue;
            $isOut = (int)$item['stock'] === 0;
            na_sb('notifications', 'POST', [], [[
                'notif_id'     => na_next_id(),
                'category'     => 'Low Stock',
                'module'       => 'SWS',
                'severity'     => $isOut ? 'Critical' : ((int)$item['stock'] <= (int)$item['min_level'] / 2 ? 'High' : 'Medium'),
                'title'        => ($isOut ? 'Out of Stock: ' : 'Low Stock Alert: ') . ($item['name'] ?? $item['code']),
                'description'  => 'Item ' . ($item['code'] ?? '') . ' — ' . ($item['name'] ?? '') . ' stock is ' . $item['stock'] . ' unit(s), below minimum level of ' . $item['min_level'] . '. Zone: ' . ($item['zone'] ?? 'Unknown'),
                'zone'         => $item['zone'] ?? '',
                'status'       => 'unread',
                'source_table' => 'sws_inventory',
                'source_id'    => (int)$item['id'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]]);
            $seeded++;
        }
    } catch (Throwable $e) {}

    // ── 2. PSM Purchase Requests — date_needed approaching/past ─────────────
    try {
        $prs = na_fetch(SUPABASE_URL . '/rest/v1/psm_purchase_requests?select=id,pr_number,requestor_name,department,date_needed,status&status=neq.Approved&status=neq.Completed&status=neq.Cancelled&order=date_needed.asc&limit=50');
        foreach ($prs as $pr) {
            if (empty($pr['date_needed'])) continue;
            $daysLeft = (int)((strtotime($pr['date_needed']) - time()) / 86400);
            if ($daysLeft > 3) continue;
            $exists = na_sb('notifications', 'GET', [
                'select'       => 'id',
                'source_table' => 'eq.psm_purchase_requests',
                'source_id'    => 'eq.' . $pr['id'],
                'status'       => 'neq.dismissed',
                'limit'        => '1',
            ]);
            if (!empty($exists)) continue;
            na_sb('notifications', 'POST', [], [[
                'notif_id'     => na_next_id(),
                'category'     => 'PO Pending',
                'module'       => 'PSM',
                'severity'     => $daysLeft <= 0 ? 'Critical' : ($daysLeft <= 1 ? 'High' : 'Medium'),
                'title'        => 'Purchase Request Overdue: ' . ($pr['pr_number'] ?? ''),
                'description'  => ($pr['pr_number'] ?? '') . ' needed by ' . $pr['date_needed'] . '. Requestor: ' . ($pr['requestor_name'] ?? '') . ' (' . ($pr['department'] ?? '') . '). Status: ' . ($pr['status'] ?? ''),
                'zone'         => $pr['department'] ?? '',
                'status'       => 'unread',
                'source_table' => 'psm_purchase_requests',
                'source_id'    => (int)$pr['id'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]]);
            $seeded++;
        }
    } catch (Throwable $e) {}

    // ── 3. PSM Contracts — expiring within 30 days ───────────────────────────
    try {
        $cutoff = date('Y-m-d', strtotime('+30 days'));
        $contracts = na_fetch(SUPABASE_URL . '/rest/v1/psm_contracts?select=id,contract_no,supplier,branch,expiry_date,status&status=eq.Active&expiry_date=lte.' . urlencode($cutoff) . '&order=expiry_date.asc&limit=30');
        foreach ($contracts as $c) {
            $daysLeft = (int)((strtotime($c['expiry_date']) - time()) / 86400);
            $exists = na_sb('notifications', 'GET', [
                'select'       => 'id',
                'source_table' => 'eq.psm_contracts',
                'source_id'    => 'eq.' . $c['id'],
                'status'       => 'neq.dismissed',
                'limit'        => '1',
            ]);
            if (!empty($exists)) continue;
            na_sb('notifications', 'POST', [], [[
                'notif_id'     => na_next_id(),
                'category'     => 'Document Issues',
                'module'       => 'PSM',
                'severity'     => $daysLeft <= 0 ? 'Critical' : ($daysLeft <= 7 ? 'High' : 'Medium'),
                'title'        => ($daysLeft <= 0 ? 'Contract Expired: ' : 'Contract Expiring Soon: ') . ($c['contract_no'] ?? ''),
                'description'  => 'Contract ' . ($c['contract_no'] ?? '') . ' with supplier ' . ($c['supplier'] ?? '') . ($daysLeft <= 0 ? ' has expired.' : ' expires in ' . $daysLeft . ' day(s) on ' . $c['expiry_date'] . '.') . ' Branch: ' . ($c['branch'] ?? ''),
                'zone'         => $c['branch'] ?? '',
                'status'       => 'unread',
                'source_table' => 'psm_contracts',
                'source_id'    => (int)$c['id'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]]);
            $seeded++;
        }
    } catch (Throwable $e) {}

    // ── 4. PSM Receipts — overdue / disputed ────────────────────────────────
    try {
        $receipts = na_fetch(SUPABASE_URL . '/rest/v1/psm_receipts?select=id,receipt_no,supplier,branch,delivery_date,status&status=in.(Pending,Disputed)&order=delivery_date.asc&limit=30');
        foreach ($receipts as $r) {
            if (empty($r['delivery_date'])) continue;
            $daysOld = (int)((time() - strtotime($r['delivery_date'])) / 86400);
            if ($daysOld < 2) continue;
            $exists = na_sb('notifications', 'GET', [
                'select'       => 'id',
                'source_table' => 'eq.psm_receipts',
                'source_id'    => 'eq.' . $r['id'],
                'status'       => 'neq.dismissed',
                'limit'        => '1',
            ]);
            if (!empty($exists)) continue;
            na_sb('notifications', 'POST', [], [[
                'notif_id'     => na_next_id(),
                'category'     => 'Delivery Delay',
                'module'       => 'PSM',
                'severity'     => $r['status'] === 'Disputed' ? 'High' : ($daysOld >= 5 ? 'Critical' : 'Medium'),
                'title'        => ($r['status'] === 'Disputed' ? 'Disputed Receipt: ' : 'Overdue Receipt: ') . ($r['receipt_no'] ?? ''),
                'description'  => 'Receipt ' . ($r['receipt_no'] ?? '') . ' from supplier ' . ($r['supplier'] ?? '') . '. Delivery date was ' . $r['delivery_date'] . ' (' . $daysOld . ' day(s) ago). Status: ' . ($r['status'] ?? '') . '. Branch: ' . ($r['branch'] ?? ''),
                'zone'         => $r['branch'] ?? '',
                'status'       => 'unread',
                'source_table' => 'psm_receipts',
                'source_id'    => (int)$r['id'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]]);
            $seeded++;
        }
    } catch (Throwable $e) {}

    // ── 5. ALMS Maintenance — overdue or due within 7 days ──────────────────
    try {
        $cutoff = date('Y-m-d', strtotime('+7 days'));
        $schedules = na_fetch(SUPABASE_URL . '/rest/v1/alms_maintenance_schedules?select=id,schedule_id,asset_name,zone,next_due,status,tech&status=in.(Scheduled,Overdue)&next_due=lte.' . urlencode($cutoff) . '&order=next_due.asc&limit=30');
        foreach ($schedules as $s) {
            $daysLeft = (int)((strtotime($s['next_due']) - time()) / 86400);
            $exists = na_sb('notifications', 'GET', [
                'select'       => 'id',
                'source_table' => 'eq.alms_maintenance_schedules',
                'source_id'    => 'eq.' . $s['id'],
                'status'       => 'neq.dismissed',
                'limit'        => '1',
            ]);
            if (!empty($exists)) continue;
            na_sb('notifications', 'POST', [], [[
                'notif_id'     => na_next_id(),
                'category'     => 'Maintenance Due',
                'module'       => 'ALMS',
                'severity'     => $daysLeft < 0 ? 'Critical' : ($daysLeft <= 2 ? 'High' : 'Medium'),
                'title'        => ($daysLeft < 0 ? 'Maintenance Overdue: ' : 'Maintenance Due Soon: ') . ($s['asset_name'] ?? $s['schedule_id']),
                'description'  => 'Schedule ' . ($s['schedule_id'] ?? '') . ' for asset "' . ($s['asset_name'] ?? '') . '" is ' . ($daysLeft < 0 ? abs($daysLeft) . ' day(s) overdue.' : 'due in ' . $daysLeft . ' day(s) on ' . $s['next_due'] . '.') . ' Technician: ' . ($s['tech'] ?? 'Unassigned') . '. Zone: ' . ($s['zone'] ?? ''),
                'zone'         => $s['zone'] ?? '',
                'status'       => 'unread',
                'source_table' => 'alms_maintenance_schedules',
                'source_id'    => (int)$s['id'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]]);
            $seeded++;
        }
    } catch (Throwable $e) {}

    // ── 6. PLT Projects — overdue ────────────────────────────────────────────
    try {
        $today = date('Y-m-d');
        $projects = na_fetch(SUPABASE_URL . '/rest/v1/plt_projects?select=id,project_id,name,zone,end_date,status,manager&end_date=lt.' . urlencode($today) . '&status=in.(Active,On%20Hold,Delayed)&order=end_date.asc&limit=20');
        foreach ($projects as $p) {
            $daysOld = (int)((time() - strtotime($p['end_date'])) / 86400);
            $exists = na_sb('notifications', 'GET', [
                'select'       => 'id',
                'source_table' => 'eq.plt_projects',
                'source_id'    => 'eq.' . $p['id'],
                'status'       => 'neq.dismissed',
                'limit'        => '1',
            ]);
            if (!empty($exists)) continue;
            na_sb('notifications', 'POST', [], [[
                'notif_id'     => na_next_id(),
                'category'     => 'Delivery Delay',
                'module'       => 'PLT',
                'severity'     => $daysOld >= 7 ? 'Critical' : ($daysOld >= 3 ? 'High' : 'Medium'),
                'title'        => 'Project Overdue: ' . ($p['name'] ?? $p['project_id']),
                'description'  => 'Project ' . ($p['project_id'] ?? '') . ' — "' . ($p['name'] ?? '') . '" ended ' . $daysOld . ' day(s) ago on ' . $p['end_date'] . '. Current status: ' . ($p['status'] ?? '') . '. Manager: ' . ($p['manager'] ?? 'Unassigned'),
                'zone'         => $p['zone'] ?? '',
                'status'       => 'unread',
                'source_table' => 'plt_projects',
                'source_id'    => (int)$p['id'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]]);
            $seeded++;
        }
    } catch (Throwable $e) {}

    // ── 7. PLT Assignments — overdue ─────────────────────────────────────────
    try {
        $today = date('Y-m-d');
        $assignments = na_fetch(SUPABASE_URL . '/rest/v1/plt_assignments?select=id,assignment_id,task,zone,due_date,status,assigned_to&due_date=lt.' . urlencode($today) . '&status=in.(Assigned,In%20Progress,Unassigned)&order=due_date.asc&limit=20');
        foreach ($assignments as $a) {
            $daysOld = (int)((time() - strtotime($a['due_date'])) / 86400);
            $exists = na_sb('notifications', 'GET', [
                'select'       => 'id',
                'source_table' => 'eq.plt_assignments',
                'source_id'    => 'eq.' . $a['id'],
                'status'       => 'neq.dismissed',
                'limit'        => '1',
            ]);
            if (!empty($exists)) continue;
            na_sb('notifications', 'POST', [], [[
                'notif_id'     => na_next_id(),
                'category'     => 'Delivery Delay',
                'module'       => 'PLT',
                'severity'     => $daysOld >= 5 ? 'High' : 'Medium',
                'title'        => 'Assignment Overdue: ' . ($a['assignment_id'] ?? ''),
                'description'  => 'Assignment ' . ($a['assignment_id'] ?? '') . ' — "' . mb_substr($a['task'] ?? '', 0, 100) . '" was due ' . $daysOld . ' day(s) ago. Assigned to: ' . ($a['assigned_to'] ?? 'Unassigned') . '. Zone: ' . ($a['zone'] ?? ''),
                'zone'         => $a['zone'] ?? '',
                'status'       => 'unread',
                'source_table' => 'plt_assignments',
                'source_id'    => (int)$a['id'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]]);
            $seeded++;
        }
    } catch (Throwable $e) {}

    // ── 8. DTRS Documents — needs_validation or stale ───────────────────────
    try {
        $docs = na_fetch(SUPABASE_URL . '/rest/v1/dtrs_documents?select=id,doc_id,title,department,assigned_to,status,needs_validation&needs_validation=eq.true&status=neq.Archived&status=neq.Completed&order=created_at.desc&limit=20');
        foreach ($docs as $d) {
            $exists = na_sb('notifications', 'GET', [
                'select'       => 'id',
                'source_table' => 'eq.dtrs_documents',
                'source_id'    => 'eq.' . $d['id'],
                'status'       => 'neq.dismissed',
                'limit'        => '1',
            ]);
            if (!empty($exists)) continue;
            na_sb('notifications', 'POST', [], [[
                'notif_id'     => na_next_id(),
                'category'     => 'Document Issues',
                'module'       => 'DTRS',
                'severity'     => 'Medium',
                'title'        => 'Document Needs Validation: ' . ($d['doc_id'] ?? ''),
                'description'  => 'Document "' . ($d['title'] ?? '') . '" (' . ($d['doc_id'] ?? '') . ') has low AI confidence and requires manual validation. Assigned to: ' . ($d['assigned_to'] ?? '—') . '. Department: ' . ($d['department'] ?? ''),
                'zone'         => $d['department'] ?? '',
                'status'       => 'unread',
                'source_table' => 'dtrs_documents',
                'source_id'    => (int)$d['id'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]]);
            $seeded++;
        }
    } catch (Throwable $e) {}

    return $seeded;
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Admin');

    try {

        // ── GET /api=seed ─────────────────────────────────────────────────────
        // Scans all modules and creates missing notifications. Safe to call repeatedly.
        if ($api === 'seed' && $method === 'GET') {
            $seeded = na_seed_from_modules();
            na_ok(['seeded' => $seeded]);
        }

        // ── GET /api=stats ────────────────────────────────────────────────────
        if ($api === 'stats' && $method === 'GET') {
            $rows = na_sb('notifications', 'GET', ['select' => 'id,status,severity']);
            $total    = count($rows);
            $unread   = count(array_filter($rows, fn($r) => $r['status'] === 'unread'));
            $critical = count(array_filter($rows, fn($r) => $r['severity'] === 'Critical' && $r['status'] !== 'dismissed'));
            $escalated= count(array_filter($rows, fn($r) => $r['status'] === 'escalated'));
            $dismissed= count(array_filter($rows, fn($r) => $r['status'] === 'dismissed'));
            na_ok(['total' => $total, 'unread' => $unread, 'critical' => $critical, 'escalated' => $escalated, 'dismissed' => $dismissed]);
        }

        // ── GET /api=list ─────────────────────────────────────────────────────
        if ($api === 'list' && $method === 'GET') {
            $page     = max(1, (int)($_GET['page']     ?? 1));
            $perPage  = max(1, min(50, (int)($_GET['per']  ?? 8)));
            $search   = trim($_GET['q']        ?? '');
            $zone     = trim($_GET['zone']     ?? '');
            $cat      = trim($_GET['cat']      ?? '');
            $mod      = trim($_GET['mod']      ?? '');
            $status   = trim($_GET['status']   ?? '');
            $date     = trim($_GET['date']     ?? '');
            $hideRead = $_GET['hideRead'] === '1';

            $parts = ['select=*', 'order=created_at.desc', 'limit=500'];
            if ($zone)   $parts[] = 'zone=eq.'     . urlencode($zone);
            if ($cat)    $parts[] = 'category=eq.' . urlencode($cat);
            if ($mod)    $parts[] = 'module=eq.'   . urlencode($mod);
            if ($status) {
                $map = ['Unread'=>'unread','Read'=>'read','Escalated'=>'escalated','Dismissed'=>'dismissed'];
                $sv  = $map[$status] ?? strtolower($status);
                $parts[] = 'status=eq.' . urlencode($sv);
            }
            if ($date)   $parts[] = 'created_at=gte.' . urlencode($date . 'T00:00:00') . '&created_at=lte.' . urlencode($date . 'T23:59:59');
            if ($search) $parts[] = 'or=' . urlencode("(title.ilike.*{$search}*,description.ilike.*{$search}*)");

            $url  = SUPABASE_URL . '/rest/v1/notifications?' . implode('&', $parts);
            $rows = na_fetch($url);

            if ($hideRead) $rows = array_values(array_filter($rows, fn($r) => $r['status'] !== 'read'));

            $total  = count($rows);
            $offset = ($page - 1) * $perPage;
            $slice  = array_slice($rows, $offset, $perPage);

            na_ok([
                'items'   => array_values(array_map('na_build', $slice)),
                'total'   => $total,
                'page'    => $page,
                'perPage' => $perPage,
                'pages'   => max(1, (int)ceil($total / $perPage)),
            ]);
        }

        // ── GET /api=sidebar ─────────────────────────────────────────────────
        // Data for critical list, category breakdown, and overdue team actions
        if ($api === 'sidebar' && $method === 'GET') {
            $rows = na_sb('notifications', 'GET', ['select' => 'id,notif_id,category,module,severity,title,zone,status', 'order' => 'created_at.desc', 'limit' => '500']);

            $critical = array_values(array_filter($rows, fn($r) => $r['severity'] === 'Critical' && $r['status'] !== 'dismissed'));
            $critical = array_slice($critical, 0, 5);

            $cats = ['Low Stock'=>0,'PO Pending'=>0,'Delivery Delay'=>0,'Maintenance Due'=>0,'Document Issues'=>0];
            foreach ($rows as $r) {
                if ($r['status'] === 'dismissed') continue;
                if (isset($cats[$r['category']])) $cats[$r['category']]++;
            }

            // Overdue from PLT assignments
            $overdue = [];
            try {
                $today = date('Y-m-d');
                $assignments = na_fetch(SUPABASE_URL . '/rest/v1/plt_assignments?select=assignment_id,task,assigned_to,due_date&due_date=lt.' . urlencode($today) . '&status=in.(Assigned,In%20Progress)&order=due_date.asc&limit=5');
                foreach ($assignments as $a) {
                    $days = max(1, (int)((time() - strtotime($a['due_date'])) / 86400));
                    $overdue[] = ['name' => $a['assigned_to'] ?? 'Unassigned', 'task' => mb_substr($a['task'] ?? '', 0, 40), 'days' => $days];
                }
            } catch (Throwable $e) {}

            na_ok(['critical' => $critical, 'catBreakdown' => $cats, 'overdue' => $overdue]);
        }

        // ── POST /api=mark-read ───────────────────────────────────────────────
        if ($api === 'mark-read' && $method === 'POST') {
            $b  = na_body();
            $id = (int)($b['id'] ?? 0);
            if (!$id) na_err('Missing id', 400);
            na_sb('notifications', 'PATCH', ['id' => 'eq.' . $id], ['status' => 'read', 'updated_at' => date('Y-m-d H:i:s')]);
            na_ok(['updated' => true]);
        }

        // ── POST /api=mark-all-read ───────────────────────────────────────────
        if ($api === 'mark-all-read' && $method === 'POST') {
            $url = SUPABASE_URL . '/rest/v1/notifications?status=eq.unread';
            $headers = [
                'Content-Type: application/json',
                'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
                'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
                'Prefer: return=representation',
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'PATCH',
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => json_encode(['status' => 'read', 'updated_at' => date('Y-m-d H:i:s')]),
            ]);
            curl_exec($ch); curl_close($ch);
            na_ok(['updated' => true]);
        }

        // ── POST /api=dismiss ─────────────────────────────────────────────────
        if ($api === 'dismiss' && $method === 'POST') {
            $b  = na_body();
            $id = (int)($b['id'] ?? 0);
            if (!$id) na_err('Missing id', 400);
            na_sb('notifications', 'PATCH', ['id' => 'eq.' . $id], [
                'status'       => 'dismissed',
                'dismissed_by' => $actor,
                'dismissed_at' => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
            na_ok(['dismissed' => true]);
        }

        // ── POST /api=escalate ────────────────────────────────────────────────
        if ($api === 'escalate' && $method === 'POST') {
            $b        = na_body();
            $id       = (int)($b['id']       ?? 0);
            $priority = trim($b['priority']  ?? 'Urgent');
            $remarks  = trim($b['remarks']   ?? '');
            if (!$id) na_err('Missing id', 400);
            na_sb('notifications', 'PATCH', ['id' => 'eq.' . $id], [
                'status'           => 'escalated',
                'escalated_by'     => $actor,
                'escalated_at'     => date('Y-m-d H:i:s'),
                'escalate_priority'=> $priority,
                'escalate_remarks' => $remarks,
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);
            na_ok(['escalated' => true]);
        }

        na_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        na_err('Server error: ' . $e->getMessage(), 500);
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
<title>Notifications &amp; Alerts</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
:root{
  --s:#fff;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);
  --t1:#1A2E1D;--t2:#5D6F62;--t3:#9EB0A2;
  --hbg:#EEF5EE;--bg:#F6FAF6;
  --grn:#2E7D32;--gdk:#1B5E20;
  --red:#DC2626;--amb:#D97706;--blu:#2563EB;--tel:#0D9488;
  --shmd:0 4px 20px rgba(46,125,50,.12);--shlg:0 24px 60px rgba(0,0,0,.22);
  --rad:12px;--tr:all .18s ease;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--t1);}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-thumb{background:rgba(46,125,50,.22);border-radius:4px}

.na-wrap{max-width:1440px;margin:0 auto;padding:0 0 4rem}

/* PAGE HEADER */
.na-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:26px;animation:UP .4s both}
.na-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px}
.na-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15}
.na-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.32)}
.btn-primary:hover{background:var(--gdk);transform:translateY(-1px)}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm)}
.btn-ghost:hover{background:var(--hbg);color:var(--t1)}
.btn-sm{font-size:12px;padding:7px 14px}
.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px}
.btn-read{background:#DCFCE7;color:#166534;border:1px solid #BBF7D0}
.btn-read:hover{background:#BBF7D0}
.btn-dismiss{background:#F3F4F6;color:#374151;border:1px solid #D1D5DB}
.btn-dismiss:hover{background:#E5E7EB}
.btn-escalate{background:#EFF6FF;color:var(--blu);border:1px solid #BFDBFE}
.btn-escalate:hover{background:#DBEAFE}
.btn:disabled{opacity:.45;pointer-events:none}

/* STATS */
.na-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:22px;animation:UP .4s .05s both}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:14px 16px;box-shadow:0 1px 4px rgba(46,125,50,.07);display:flex;align-items:center;gap:12px}
.sc-ic{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px}
.ic-r{background:#FEE2E2;color:var(--red)}.ic-a{background:#FEF3C7;color:var(--amb)}
.ic-g{background:#DCFCE7;color:#166534}.ic-b{background:#EFF6FF;color:var(--blu)}
.ic-t{background:#CCFBF1;color:var(--tel)}.ic-d{background:#F3F4F6;color:#374151}
.sc-v{font-size:22px;font-weight:800;color:var(--t1);line-height:1}
.sc-l{font-size:11px;color:var(--t2);margin-top:2px}

/* SKELETON */
.skeleton{background:linear-gradient(90deg,var(--bg) 25%,rgba(46,125,50,.07) 50%,var(--bg) 75%);background-size:400% 100%;animation:shimmer 1.4s infinite;border-radius:8px}
@keyframes shimmer{0%{background-position:100% 50%}100%{background-position:0% 50%}}

/* NOTICE */
.notice-banner{display:flex;align-items:flex-start;gap:12px;background:linear-gradient(135deg,#FFFBEB,#FEF3C7);border:1px solid #FCD34D;border-radius:12px;padding:14px 18px;margin-bottom:22px;animation:UP .4s .07s both}
.notice-banner i{font-size:20px;color:#D97706;flex-shrink:0;margin-top:1px}
.nb-t{font-size:13px;font-weight:700;color:#92400E;margin-bottom:2px}
.nb-s{font-size:12px;color:#B45309;line-height:1.6}

/* TOOLBAR */
.na-tb{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;animation:UP .4s .1s both}
.sw{position:relative;flex:1;min-width:200px}
.sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--t3);pointer-events:none}
.si{width:100%;padding:9px 11px 9px 36px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.si:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}
.si::placeholder{color:var(--t3)}
.sel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 28px 9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center}
.sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}
.fi-date{font-family:'Inter',sans-serif;font-size:13px;padding:9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr)}
.fi-date:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}

/* LAYOUT */
.na-body{display:grid;grid-template-columns:1fr 300px;gap:20px;animation:UP .4s .13s both}

/* INBOX CARD */
.inbox-card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd)}
.inbox-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--bd);background:var(--bg)}
.inbox-hd-t{font-size:14px;font-weight:700;color:var(--t1)}
.inbox-hd-r{display:flex;align-items:center;gap:8px}
.inbox-cnt{font-size:12px;font-weight:700;color:var(--grn);background:#E8F5E9;border-radius:20px;padding:2px 9px}

/* ALERT ITEMS */
.alert-list{display:flex;flex-direction:column}
.alert-item{display:flex;gap:14px;padding:16px 20px;border-bottom:1px solid var(--bd);transition:background .13s;position:relative;cursor:pointer}
.alert-item:last-child{border-bottom:none}
.alert-item:hover{background:var(--hbg)}
.alert-item.unread{background:#FAFFFE}
.alert-item.unread::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--grn);border-radius:0 2px 2px 0}
.alert-item.dismissed{opacity:.45}
.alert-item.dismissed .alert-actions{display:none}
.ai-icon{width:40px;height:40px;border-radius:11px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:19px;margin-top:1px}
.ai-body{flex:1;min-width:0}
.ai-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px}
.ai-title{font-size:13px;font-weight:700;color:var(--t1);line-height:1.35}
.ai-title.read{font-weight:500;color:var(--t2)}
.ai-ts{font-family:'DM Mono',monospace;font-size:10.5px;color:var(--t3);white-space:nowrap;flex-shrink:0;padding-top:2px}
.ai-desc{font-size:12px;color:var(--t2);line-height:1.55;margin-bottom:8px}
.ai-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:8px}
.alert-actions{display:flex;gap:5px;flex-wrap:wrap}
.unread-dot{width:8px;height:8px;border-radius:50%;background:var(--grn);flex-shrink:0;margin-top:5px}

/* CHIPS */
.chip{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:20px;white-space:nowrap}
.chip::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0}
.chip-red{background:#FEE2E2;color:#991B1B}
.chip-amb{background:#FEF3C7;color:#92400E}
.chip-blu{background:#EFF6FF;color:#1D4ED8}
.chip-grn{background:#DCFCE7;color:#166534}
.chip-tel{background:#CCFBF1;color:#0F766E}
.chip-gry{background:#F3F4F6;color:#374151}
.chip-pur{background:#F5F3FF;color:#5B21B6}
.mod-tag{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px;background:#F3F4F6;color:#6B7280;letter-spacing:.04em}

/* EMPTY */
.empty{padding:60px 20px;text-align:center;color:var(--t3)}
.empty i{font-size:50px;display:block;margin-bottom:12px;color:#C8E6C9}

/* PAGINATION */
.na-pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:12px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2)}
.pg-btns{display:flex;gap:5px}
.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1)}
.pgb:hover{background:var(--hbg);border-color:var(--grn);color:var(--grn)}
.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff}
.pgb:disabled{opacity:.4;pointer-events:none}

/* SIDE PANEL */
.side-panel{display:flex;flex-direction:column;gap:16px}
.sp-card{background:var(--s);border:1px solid var(--bd);border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(46,125,50,.07)}
.sp-hd{padding:13px 16px;border-bottom:1px solid var(--bd);background:var(--bg);display:flex;align-items:center;gap:8px}
.sp-hd i{font-size:16px;color:var(--grn)}
.sp-hd-t{font-size:12px;font-weight:700;color:var(--t1)}
.sp-body{padding:14px 16px}
.crit-list{display:flex;flex-direction:column;gap:8px}
.crit-item{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-radius:10px;border:1px solid var(--bd);background:var(--bg);cursor:pointer;transition:var(--tr)}
.crit-item:hover{border-color:var(--bdm);background:var(--hbg)}
.crit-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;margin-top:4px}
.crit-t{font-size:12px;font-weight:700;color:var(--t1);line-height:1.3}
.crit-s{font-size:11px;color:var(--t2);margin-top:2px}
.cat-rows{display:flex;flex-direction:column;gap:7px}
.cat-row{display:flex;align-items:center;gap:8px}
.cat-lbl{font-size:11px;font-weight:600;color:var(--t2);width:105px;flex-shrink:0}
.cat-bar-wrap{flex:1;height:6px;background:#E5E7EB;border-radius:3px;overflow:hidden}
.cat-bar{height:100%;border-radius:3px;transition:width .4s ease}
.cat-val{font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--t1);width:22px;text-align:right;flex-shrink:0}
.od-list{display:flex;flex-direction:column;gap:0}
.od-item{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--bd)}
.od-item:last-child{border-bottom:none;padding-bottom:0}
.od-item:first-child{padding-top:0}
.od-av{width:26px;height:26px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:9.5px;font-weight:700;color:#fff}
.od-nm{font-size:12px;font-weight:600;color:var(--t1);line-height:1.2}
.od-s{font-size:11px;color:var(--t2)}
.od-badge{margin-left:auto;flex-shrink:0}
.locked-row{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#FAFAFA;border:1px dashed #D1D5DB;border-radius:9px;font-size:12px;color:#9CA3AF;margin-top:8px}
.locked-row:first-child{margin-top:0}
.locked-row i{font-size:15px;color:#D1D5DB}

/* MODALS */
#escalateModal,#detailModal{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9100;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .22s}
#escalateModal.on,#detailModal.on{opacity:1;pointer-events:all}
.em-box{background:#fff;border-radius:16px;width:440px;max-width:100%;box-shadow:var(--shlg);overflow:hidden}
.dm-box{background:#fff;border-radius:16px;width:540px;max-width:100%;box-shadow:var(--shlg);overflow:hidden}
.em-hd{padding:20px 22px 16px;border-bottom:1px solid var(--bd);background:var(--bg);display:flex;align-items:flex-start;justify-content:space-between}
.em-hd-t{font-size:16px;font-weight:700;color:var(--t1)}
.em-hd-s{font-size:12px;color:var(--t2);margin-top:2px}
.em-cl{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:#fff;cursor:pointer;display:grid;place-content:center;font-size:18px;color:var(--t2);transition:var(--tr);flex-shrink:0}
.em-cl:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA}
.em-body{padding:20px 22px;display:flex;flex-direction:column;gap:14px}
.em-alert-preview{background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:12px 14px}
.em-alert-preview .apt{font-size:13px;font-weight:700;color:var(--t1);margin-bottom:4px}
.em-alert-preview .aps{font-size:12px;color:var(--t2);line-height:1.5}
.em-fg{display:flex;flex-direction:column;gap:5px}
.em-fg label{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2)}
.em-fg select,.em-fg textarea{font-family:'Inter',sans-serif;font-size:13px;padding:9px 12px;border:1px solid var(--bdm);border-radius:9px;background:#fff;color:var(--t1);outline:none;transition:var(--tr);width:100%}
.em-fg select{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;padding-right:28px}
.em-fg select:focus,.em-fg textarea:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10)}
.em-fg textarea{resize:vertical;min-height:72px}
.em-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:9px;padding:10px 12px;font-size:12px;color:#92400E;line-height:1.55}
.em-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px}
.em-ft{padding:14px 22px;border-top:1px solid var(--bd);background:var(--bg);display:flex;gap:8px;justify-content:flex-end}
.dm-tabs{display:flex;gap:3px;padding:12px 22px 0;border-bottom:1px solid var(--bd)}
.dm-tab{font-family:'Inter',sans-serif;font-size:12.5px;font-weight:600;padding:7px 14px;border-radius:8px 8px 0 0;cursor:pointer;border:none;background:transparent;color:var(--t2);transition:var(--tr)}
.dm-tab.active{background:var(--grn);color:#fff}
.dm-tab:hover:not(.active){background:var(--hbg);color:var(--t1)}
.dm-panel{display:none;padding:20px 22px;flex-direction:column;gap:14px}
.dm-panel.active{display:flex}
.dm-ig{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.dm-ii label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--t3);display:block;margin-bottom:3px}
.dm-ii .v{font-size:13px;font-weight:500;color:var(--t1)}
.dm-ii .v.muted{color:#4B5563;font-weight:400}
.dm-full{grid-column:1/-1}

/* TOAST */
.na-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:TIN .3s ease}
.toast.ts{background:var(--grn)}.toast.tw{background:var(--amb)}.toast.td{background:var(--red)}
.toast.out{animation:TOUT .3s ease forwards}

@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:1100px){.na-body{grid-template-columns:1fr}.side-panel{display:grid;grid-template-columns:1fr 1fr;gap:16px}}
@media(max-width:640px){.side-panel{grid-template-columns:1fr}.na-tb{flex-direction:column;align-items:stretch}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="na-wrap">

  <!-- PAGE HEADER -->
  <div class="na-ph">
    <div>
      <p class="ey">System Administration</p>
      <h1>Notifications &amp; Alerts</h1>
    </div>
    <div class="na-ph-r">
      <button class="btn btn-ghost btn-sm" id="refreshBtn"><i class="bx bx-refresh"></i> Refresh Alerts</button>
      <button class="btn btn-ghost btn-sm" id="exportBtn"><i class="bx bx-export"></i> Export</button>
      <button class="btn btn-primary btn-sm" id="markAllBtn"><i class="bx bx-check-double"></i> Mark All Read</button>
    </div>
  </div>

  <!-- STATS -->
  <div class="na-stats" id="statsBar">
    <?php for ($i = 0; $i < 5; $i++): ?>
    <div class="sc"><div class="sc-ic skeleton" style="width:38px;height:38px"></div><div><div class="skeleton" style="height:16px;width:36px;margin-bottom:4px"></div><div class="skeleton" style="height:10px;width:70px"></div></div></div>
    <?php endfor; ?>
  </div>

  <!-- PERMISSION NOTICE -->
  <div class="notice-banner">
    <i class="bx bx-lock-alt"></i>
    <div>
      <div class="nb-t">Zone Alert Inbox — View &amp; Act on Zone Alerts</div>
      <div class="nb-s">You can <strong>mark read, dismiss, and escalate</strong> zone alerts. <strong>Override alerts, bulk actions, and RA 9184 compliance alerts</strong> require Super Admin authority. Alerts are auto-generated from live module data.</div>
    </div>
  </div>

  <!-- TOOLBAR -->
  <div class="na-tb">
    <div class="sw"><i class="bx bx-search"></i><input type="text" class="si" id="srch" placeholder="Search alerts by title or description…"></div>
    <select class="sel" id="fZone"><option value="">All Zones</option></select>
    <select class="sel" id="fCat">
      <option value="">All Categories</option>
      <option>Low Stock</option><option>PO Pending</option><option>Delivery Delay</option>
      <option>Maintenance Due</option><option>Document Issues</option>
    </select>
    <select class="sel" id="fMod">
      <option value="">All Modules</option>
      <option>SWS</option><option>PSM</option><option>PLT</option><option>ALMS</option><option>DTRS</option>
    </select>
    <select class="sel" id="fStatus">
      <option value="">All Status</option>
      <option>Unread</option><option>Read</option><option>Escalated</option><option>Dismissed</option>
    </select>
    <input type="date" class="fi-date" id="fDate" title="Filter by date">
  </div>

  <!-- MAIN BODY -->
  <div class="na-body">
    <!-- INBOX -->
    <div class="inbox-card">
      <div class="inbox-hd">
        <div class="inbox-hd-t">Zone Alert Inbox</div>
        <div class="inbox-hd-r">
          <span class="inbox-cnt" id="inboxCount">Loading…</span>
          <button class="btn btn-ghost btn-xs" id="collapseReadBtn"><i class="bx bx-hide"></i> Hide Read</button>
        </div>
      </div>
      <div class="alert-list" id="alertList">
        <div style="padding:32px;text-align:center"><div class="skeleton" style="height:14px;width:60%;margin:0 auto"></div></div>
      </div>
      <div class="na-pager" id="pager"></div>
    </div>

    <!-- SIDE PANEL -->
    <div class="side-panel">
      <div class="sp-card">
        <div class="sp-hd"><i class="bx bx-error-circle"></i><div class="sp-hd-t">Zone Critical Alerts</div></div>
        <div class="sp-body"><div class="crit-list" id="critList"><div class="skeleton" style="height:60px;border-radius:10px"></div></div></div>
      </div>
      <div class="sp-card">
        <div class="sp-hd"><i class="bx bx-bar-chart-alt-2"></i><div class="sp-hd-t">Alert Breakdown</div></div>
        <div class="sp-body"><div class="cat-rows" id="catBreakdown"><div class="skeleton" style="height:8px;margin-bottom:8px"></div><div class="skeleton" style="height:8px;margin-bottom:8px"></div><div class="skeleton" style="height:8px"></div></div></div>
      </div>
      <div class="sp-card">
        <div class="sp-hd"><i class="bx bx-group"></i><div class="sp-hd-t">Team Overdue Actions</div></div>
        <div class="sp-body"><div class="od-list" id="overdueList"><div class="skeleton" style="height:44px;border-radius:8px"></div></div></div>
      </div>
      <div class="sp-card">
        <div class="sp-hd"><i class="bx bx-lock-alt"></i><div class="sp-hd-t">Restricted Actions</div></div>
        <div class="sp-body" style="display:flex;flex-direction:column;gap:0">
          <div class="locked-row"><i class="bx bx-lock-alt"></i>Override alerts — Super Admin only</div>
          <div class="locked-row"><i class="bx bx-lock-alt"></i>Bulk actions across all zones — Super Admin only</div>
          <div class="locked-row"><i class="bx bx-lock-alt"></i>RA 9184 compliance alerts — Super Admin only</div>
        </div>
      </div>
    </div>
  </div>

</div>
</main>

<div class="na-toasts" id="toastWrap"></div>

<!-- ESCALATE MODAL -->
<div id="escalateModal">
  <div class="em-box">
    <div class="em-hd">
      <div><div class="em-hd-t">📤 Escalate to Super Admin</div><div class="em-hd-s">This alert will be forwarded to the System Administrator</div></div>
      <button class="em-cl" onclick="closeModal('escalateModal')"><i class="bx bx-x"></i></button>
    </div>
    <div class="em-body">
      <div class="em-alert-preview"><div class="apt" id="escTitle"></div><div class="aps" id="escDesc"></div></div>
      <div class="em-sa-note"><i class="bx bx-shield-quarter"></i><span>Escalating flags this alert for immediate Super Admin review. You will be notified once actioned.</span></div>
      <div class="em-fg"><label>Priority Level</label>
        <select id="escPriority"><option>Normal</option><option>High</option><option selected>Urgent</option><option>Critical</option></select>
      </div>
      <div class="em-fg"><label>Remarks / Notes</label><textarea id="escRemarks" placeholder="Explain why this needs Super Admin attention…"></textarea></div>
    </div>
    <div class="em-ft">
      <button class="btn btn-ghost btn-sm" onclick="closeModal('escalateModal')">Cancel</button>
      <button class="btn btn-escalate btn-sm" id="escConfirm"><i class="bx bx-send"></i> Escalate Alert</button>
    </div>
  </div>
</div>

<!-- DETAIL MODAL -->
<div id="detailModal">
  <div class="dm-box">
    <div class="em-hd">
      <div><div class="em-hd-t" id="dmTitle"></div><div class="em-hd-s" id="dmSub"></div></div>
      <button class="em-cl" onclick="closeModal('detailModal')"><i class="bx bx-x"></i></button>
    </div>
    <div class="dm-tabs">
      <button class="dm-tab active" onclick="setDmTab('info',this)"><i class="bx bx-info-circle"></i> Details</button>
      <button class="dm-tab" onclick="setDmTab('act',this)"><i class="bx bx-list-check"></i> Actions</button>
    </div>
    <div class="dm-panel active" id="dmt-info"><div class="dm-ig" id="dmInfoGrid"></div></div>
    <div class="dm-panel" id="dmt-act"><div id="dmActBody"></div></div>
    <div class="em-ft" id="dmFoot"></div>
  </div>
</div>

<script>
const API = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';

// ── API ───────────────────────────────────────────────────────────────────────
async function apiFetch(path, opts = {}) {
    const r = await fetch(path, { headers: { 'Content-Type': 'application/json' }, ...opts });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p     => apiFetch(p);
const apiPost = (p,b) => apiFetch(p, { method:'POST', body:JSON.stringify(b) });

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc     = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmtTs   = d => { try{ return new Date(d).toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}); }catch{return d||'—';} };
const COLORS  = ['#2E7D32','#0D9488','#2563EB','#D97706','#7C3AED','#DC2626','#0891B2','#059669'];
const gc      = n => COLORS[Math.abs(String(n).split('').reduce((h,c)=>h*31+c.charCodeAt(0)|0,0))%COLORS.length];
const ini     = n => String(n||'').split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase()||'?';

const CATS = {
    'Low Stock':      {chip:'chip-red',ic:'ic-r',icon:'bx-package',      bar:'#EF4444'},
    'PO Pending':     {chip:'chip-amb',ic:'ic-a',icon:'bx-receipt',       bar:'#F59E0B'},
    'Delivery Delay': {chip:'chip-blu',ic:'ic-b',icon:'bx-time-five',     bar:'#3B82F6'},
    'Maintenance Due':{chip:'chip-tel',ic:'ic-t',icon:'bx-wrench',        bar:'#14B8A6'},
    'Document Issues':{chip:'chip-pur',ic:'ic-d',icon:'bx-file-blank',    bar:'#7C3AED'},
};
const SEV_CHIP = {Critical:'chip-red',High:'chip-amb',Medium:'chip-blu',Low:'chip-grn'};
const SEV_DOT  = {Critical:'#EF4444',High:'#F59E0B',Medium:'#3B82F6',Low:'#22C55E'};

function sevChip(s)  { return `<span class="chip ${SEV_CHIP[s]||'chip-gry'}">${s}</span>`; }
function statusChip(s){const m={unread:'chip-grn',read:'chip-gry',escalated:'chip-blu',dismissed:'chip-gry'};const l={unread:'Unread',read:'Read',escalated:'Escalated',dismissed:'Dismissed'};return `<span class="chip ${m[s]||'chip-gry'}">${l[s]||s}</span>`;}

// ── STATE ─────────────────────────────────────────────────────────────────────
let currentPage = 1;
const PER_PAGE  = 8;
let hideRead    = false;
let escTargetId = null;
let detailItem  = null;

// ── INIT ──────────────────────────────────────────────────────────────────────
seedAndLoad();

async function seedAndLoad() {
    try { await apiGet(API + '?api=seed'); } catch(e) {}
    loadStats();
    loadTable();
    loadSidebar();
    buildZoneFilter();
}

// ── ZONE FILTER ───────────────────────────────────────────────────────────────
async function buildZoneFilter() {
    try {
        // Get distinct zones from notifications
        const rows = await apiGet(API + '?api=list&page=1&per=500');
        const zones = [...new Set(rows.items.map(r => r.zone).filter(Boolean))].sort();
        const sel = document.getElementById('fZone');
        sel.innerHTML = '<option value="">All Zones</option>' + zones.map(z => `<option>${esc(z)}</option>`).join('');
    } catch(e) {}
}

// ── STATS ─────────────────────────────────────────────────────────────────────
async function loadStats() {
    try {
        const d = await apiGet(API + '?api=stats');
        document.getElementById('statsBar').innerHTML = `
          <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-bell"></i></div><div><div class="sc-v">${d.total}</div><div class="sc-l">Total Alerts</div></div></div>
          <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-bell-ring"></i></div><div><div class="sc-v">${d.unread}</div><div class="sc-l">Unread</div></div></div>
          <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-error-circle"></i></div><div><div class="sc-v">${d.critical}</div><div class="sc-l">Critical</div></div></div>
          <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-upload"></i></div><div><div class="sc-v">${d.escalated}</div><div class="sc-l">Escalated</div></div></div>
          <div class="sc"><div class="sc-ic ic-d"><i class="bx bx-check-double"></i></div><div><div class="sc-v">${d.dismissed}</div><div class="sc-l">Dismissed</div></div></div>`;
    } catch(e) { toast('Stats error: ' + e.message, 'w'); }
}

// ── TABLE ─────────────────────────────────────────────────────────────────────
async function loadTable() {
    const params = new URLSearchParams({
        api:      'list',
        page:     currentPage,
        per:      PER_PAGE,
        hideRead: hideRead ? '1' : '0',
        ...(document.getElementById('srch').value.trim()    && { q:      document.getElementById('srch').value.trim() }),
        ...(document.getElementById('fZone').value          && { zone:   document.getElementById('fZone').value }),
        ...(document.getElementById('fCat').value           && { cat:    document.getElementById('fCat').value }),
        ...(document.getElementById('fMod').value           && { mod:    document.getElementById('fMod').value }),
        ...(document.getElementById('fStatus').value        && { status: document.getElementById('fStatus').value }),
        ...(document.getElementById('fDate').value          && { date:   document.getElementById('fDate').value }),
    });
    try {
        const d = await apiGet(API + '?' + params);
        renderTable(d);
    } catch(e) {
        toast('Failed to load alerts: ' + e.message, 'w');
        document.getElementById('alertList').innerHTML = `<div style="padding:24px;text-align:center;color:var(--red);font-size:12px">Error loading alerts. Please refresh.</div>`;
    }
}

function renderTable(d) {
    document.getElementById('inboxCount').textContent = d.total === 1 ? '1 alert' : `${d.total} alerts`;
    const list = document.getElementById('alertList');

    if (!d.items.length) {
        list.innerHTML = `<div class="empty"><i class="bx bx-bell-off"></i><p>No alerts match your filters.</p></div>`;
        document.getElementById('pager').innerHTML = '';
        return;
    }

    list.innerHTML = d.items.map(a => {
        const c = CATS[a.category] || { chip:'chip-gry', ic:'ic-d', icon:'bx-bell' };
        const isDismissed = a.status === 'dismissed';
        const isEsc       = a.status === 'escalated';
        return `<div class="alert-item ${a.status==='unread'?'unread':''} ${isDismissed?'dismissed':''}" onclick="openDetail(${JSON.stringify(a).replace(/"/g,'&quot;')})">
          ${a.status==='unread' ? '<div class="unread-dot"></div>' : ''}
          <div class="ai-icon ${c.ic}"><i class="bx ${c.icon}"></i></div>
          <div class="ai-body">
            <div class="ai-top">
              <div class="ai-title ${a.status==='read'||isDismissed?'read':''}">${esc(a.title)}</div>
              <div class="ai-ts">${fmtTs(a.createdAt)}</div>
            </div>
            <div class="ai-desc">${esc(a.description)}</div>
            <div class="ai-meta">
              ${sevChip(a.severity)}
              <span class="chip ${c.chip}">${esc(a.category)}</span>
              <span class="mod-tag">${esc(a.module)}</span>
              <span style="font-size:11px;color:var(--t3)">${esc(a.zone)}</span>
              ${isEsc ? '<span class="chip chip-blu">Escalated</span>' : ''}
              ${isDismissed ? '<span class="chip chip-gry">Dismissed</span>' : ''}
            </div>
            ${!isDismissed ? `<div class="alert-actions" onclick="event.stopPropagation()">
              ${a.status!=='read'&&!isEsc ? `<button class="btn btn-read btn-xs" onclick="markRead(${a.id})"><i class="bx bx-check"></i> Mark Read</button>` : ''}
              ${!isEsc ? `<button class="btn btn-escalate btn-xs" onclick="openEscalate(${JSON.stringify(a).replace(/"/g,'&quot;')})"><i class="bx bx-upload"></i> Escalate</button>` : ''}
              <button class="btn btn-dismiss btn-xs" onclick="dismissAlert(${a.id})"><i class="bx bx-x"></i> Dismiss</button>
            </div>` : ''}
          </div>
        </div>`;
    }).join('');

    // Pagination
    const total = d.total, pages = d.pages, page = d.page;
    const s = (page-1)*PER_PAGE+1, e = Math.min(page*PER_PAGE, total);
    let btns = '';
    for (let i = 1; i <= pages; i++) {
        if (i===1||i===pages||(i>=page-2&&i<=page+2))
            btns += `<button class="pgb ${i===page?'active':''}" onclick="goPage(${i})">${i}</button>`;
        else if (i===page-3||i===page+3) btns += `<button class="pgb" disabled>…</button>`;
    }
    document.getElementById('pager').innerHTML = `
      <span>${total===0?'No results':`Showing ${s}–${e} of ${total}`}</span>
      <div class="pg-btns">
        <button class="pgb" onclick="goPage(${page-1})" ${page<=1?'disabled':''}><i class="bx bx-chevron-left"></i></button>
        ${btns}
        <button class="pgb" onclick="goPage(${page+1})" ${page>=pages?'disabled':''}><i class="bx bx-chevron-right"></i></button>
      </div>`;
}

window.goPage = p => { currentPage = p; loadTable(); };

// ── SIDEBAR ───────────────────────────────────────────────────────────────────
async function loadSidebar() {
    try {
        const d = await apiGet(API + '?api=sidebar');

        // Critical alerts
        const catMeta = CATS;
        document.getElementById('critList').innerHTML = d.critical.length
            ? d.critical.map(a => {
                const c = catMeta[a.category] || {};
                return `<div class="crit-item" onclick="/* open detail via separate fetch */">
                  <div class="crit-dot" style="background:${SEV_DOT[a.severity]||'#9CA3AF'}"></div>
                  <div>
                    <div class="crit-t">${esc((a.title||'').length>52?(a.title||'').slice(0,52)+'…':a.title)}</div>
                    <div class="crit-s">${esc(a.module)} · ${esc((a.zone||'').split('—').pop().trim())}</div>
                  </div>
                </div>`;
              }).join('')
            : '<div style="font-size:12px;color:var(--t3);text-align:center;padding:12px 0">No critical alerts</div>';

        // Category breakdown
        const cats = d.catBreakdown;
        const maxC = Math.max(...Object.values(cats), 1);
        const catBars = {
            'Low Stock':'#EF4444','PO Pending':'#F59E0B','Delivery Delay':'#3B82F6',
            'Maintenance Due':'#14B8A6','Document Issues':'#7C3AED'
        };
        document.getElementById('catBreakdown').innerHTML = Object.entries(cats).map(([k,v]) => `
          <div class="cat-row">
            <div class="cat-lbl">${esc(k)}</div>
            <div class="cat-bar-wrap"><div class="cat-bar" style="width:${Math.round(v/maxC*100)}%;background:${catBars[k]||'#9CA3AF'}"></div></div>
            <div class="cat-val">${v}</div>
          </div>`).join('');

        // Overdue team actions
        document.getElementById('overdueList').innerHTML = d.overdue.length
            ? d.overdue.map(o => `
                <div class="od-item">
                  <div class="od-av" style="background:${gc(o.name)}">${ini(o.name)}</div>
                  <div><div class="od-nm">${esc(o.name)}</div><div class="od-s">${esc(o.task)}</div></div>
                  <div class="od-badge"><span class="chip ${o.days>=5?'chip-red':o.days>=3?'chip-amb':'chip-blu'}">${o.days}d overdue</span></div>
                </div>`).join('')
            : '<div style="font-size:12px;color:var(--t3);text-align:center;padding:8px 0">No overdue actions</div>';

    } catch(e) { toast('Sidebar error: ' + e.message, 'w'); }
}

// ── FILTER EVENTS ─────────────────────────────────────────────────────────────
['srch','fZone','fCat','fMod','fStatus','fDate'].forEach(id =>
    document.getElementById(id).addEventListener('input', () => { currentPage = 1; loadTable(); }));

// ── ACTIONS ───────────────────────────────────────────────────────────────────
window.markRead = async id => {
    try {
        await apiPost(API + '?api=mark-read', { id });
        toast('Alert marked as read.', 's');
        loadTable(); loadStats();
    } catch(e) { toast('Error: ' + e.message, 'w'); }
};

window.dismissAlert = async id => {
    try {
        await apiPost(API + '?api=dismiss', { id });
        toast('Alert dismissed.', 's');
        loadTable(); loadStats(); loadSidebar();
    } catch(e) { toast('Error: ' + e.message, 'w'); }
};

window.openEscalate = a => {
    escTargetId = a.id;
    document.getElementById('escTitle').textContent = a.title;
    document.getElementById('escDesc').textContent  = a.description;
    document.getElementById('escRemarks').value     = '';
    document.getElementById('escalateModal').classList.add('on');
};

document.getElementById('escConfirm').addEventListener('click', async () => {
    if (!escTargetId) return;
    const priority = document.getElementById('escPriority').value;
    const remarks  = document.getElementById('escRemarks').value.trim();
    const btn = document.getElementById('escConfirm');
    btn.disabled = true;
    try {
        await apiPost(API + '?api=escalate', { id: escTargetId, priority, remarks });
        closeModal('escalateModal');
        toast('Alert escalated to Super Admin successfully.', 's');
        loadTable(); loadStats(); loadSidebar();
    } catch(e) { toast('Escalation failed: ' + e.message, 'w'); }
    finally { btn.disabled = false; escTargetId = null; }
});

window.openDetail = a => {
    detailItem = a;
    if (a.status === 'unread') markRead(a.id);
    const c = CATS[a.category] || {};
    document.getElementById('dmTitle').textContent = a.title;
    document.getElementById('dmSub').textContent   = a.notifId + ' · ' + a.zone;
    document.getElementById('dmInfoGrid').innerHTML = `
      <div class="dm-ii"><label>Alert ID</label><div class="v" style="font-family:'DM Mono',monospace;font-size:12px;color:var(--grn)">${esc(a.notifId)}</div></div>
      <div class="dm-ii"><label>Status</label><div class="v">${statusChip(a.status)}</div></div>
      <div class="dm-ii"><label>Category</label><div class="v"><span class="chip ${c.chip||'chip-gry'}">${esc(a.category)}</span></div></div>
      <div class="dm-ii"><label>Severity</label><div class="v">${sevChip(a.severity)}</div></div>
      <div class="dm-ii"><label>Module</label><div class="v"><span class="mod-tag">${esc(a.module)}</span></div></div>
      <div class="dm-ii"><label>Zone</label><div class="v muted">${esc(a.zone)}</div></div>
      <div class="dm-ii"><label>Source Record</label><div class="v muted" style="font-family:'DM Mono',monospace;font-size:12px">${esc(a.sourceTable||'—')} #${a.sourceId||'—'}</div></div>
      <div class="dm-ii"><label>Created</label><div class="v muted">${fmtTs(a.createdAt)}</div></div>
      ${a.escalatedBy ? `<div class="dm-ii"><label>Escalated By</label><div class="v">${esc(a.escalatedBy)}</div></div><div class="dm-ii"><label>Escalate Priority</label><div class="v">${esc(a.escalatePriority||'—')}</div></div>` : ''}
      <div class="dm-ii dm-full"><label>Description</label><div class="v muted" style="line-height:1.65">${esc(a.description)}</div></div>
      ${a.escalateRemarks ? `<div class="dm-ii dm-full"><label>Escalation Notes</label><div class="v muted">${esc(a.escalateRemarks)}</div></div>` : ''}`;

    const isDismissed = a.status==='dismissed', isEsc = a.status==='escalated';
    document.getElementById('dmActBody').innerHTML = `
      <div style="display:flex;flex-direction:column;gap:10px">
        ${!isDismissed&&a.status!=='read'&&!isEsc ? `<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:var(--bg);border:1px solid var(--bd);border-radius:10px"><div><div style="font-size:13px;font-weight:600">Mark as Read</div><div style="font-size:11.5px;color:var(--t2);margin-top:2px">Acknowledge this alert</div></div><button class="btn btn-read btn-sm" onclick="markRead(${a.id});closeModal('detailModal')"><i class="bx bx-check"></i> Mark Read</button></div>` : ''}
        ${!isEsc&&!isDismissed ? `<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:var(--bg);border:1px solid var(--bd);border-radius:10px"><div><div style="font-size:13px;font-weight:600">Escalate to Super Admin</div><div style="font-size:11.5px;color:var(--t2);margin-top:2px">Forward for immediate attention</div></div><button class="btn btn-escalate btn-sm" onclick="closeModal('detailModal');openEscalate(detailItem)"><i class="bx bx-upload"></i> Escalate</button></div>` : ''}
        ${!isDismissed ? `<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:var(--bg);border:1px solid var(--bd);border-radius:10px"><div><div style="font-size:13px;font-weight:600">Dismiss Alert</div><div style="font-size:11.5px;color:var(--t2);margin-top:2px">Remove from active inbox</div></div><button class="btn btn-dismiss btn-sm" onclick="dismissAlert(${a.id});closeModal('detailModal')"><i class="bx bx-x"></i> Dismiss</button></div>` : ''}
        <div style="padding:12px 14px;background:#FAFAFA;border:1px dashed #D1D5DB;border-radius:10px;font-size:12px;color:#9CA3AF;display:flex;align-items:center;gap:8px"><i class="bx bx-lock-alt" style="font-size:15px;color:#D1D5DB"></i>Override alert — Super Admin only</div>
      </div>`;
    document.getElementById('dmFoot').innerHTML = `<button class="btn btn-ghost btn-sm" onclick="closeModal('detailModal')">Close</button>`;
    setDmTab('info', document.querySelector('.dm-tab'));
    document.getElementById('detailModal').classList.add('on');
};

// ── HEADER BUTTONS ────────────────────────────────────────────────────────────
document.getElementById('markAllBtn').addEventListener('click', async () => {
    const btn = document.getElementById('markAllBtn');
    btn.disabled = true;
    try {
        await apiPost(API + '?api=mark-all-read', {});
        toast('All unread alerts marked as read.', 's');
        loadTable(); loadStats();
    } catch(e) { toast('Error: ' + e.message, 'w'); }
    finally { btn.disabled = false; }
});

document.getElementById('refreshBtn').addEventListener('click', async () => {
    const btn = document.getElementById('refreshBtn');
    btn.disabled = true;
    btn.innerHTML = `<i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i> Refreshing…`;
    try {
        const d = await apiGet(API + '?api=seed');
        toast(d.seeded > 0 ? `${d.seeded} new alert(s) generated from live module data.` : 'No new alerts found.', 's');
        loadStats(); loadTable(); loadSidebar();
    } catch(e) { toast('Refresh error: ' + e.message, 'w'); }
    finally { btn.disabled = false; btn.innerHTML = `<i class="bx bx-refresh"></i> Refresh Alerts`; }
});

document.getElementById('exportBtn').addEventListener('click', async () => {
    try {
        const d = await apiGet(API + '?api=list&page=1&per=500');
        const cols = ['notifId','category','severity','module','zone','status','title','createdAt'];
        const hdrs = ['Alert ID','Category','Severity','Module','Zone','Status','Title','Created At'];
        const lines = [hdrs.join(','), ...d.items.map(r => cols.map(c => `"${String(r[c]||'').replace(/"/g,'""')}"`).join(','))];
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([lines.join('\n')], {type:'text/csv'}));
        a.download = `notifications_${new Date().toISOString().split('T')[0]}.csv`; a.click();
        toast('Alerts exported to CSV.', 's');
    } catch(e) { toast('Export failed: ' + e.message, 'w'); }
});

document.getElementById('collapseReadBtn').addEventListener('click', function() {
    hideRead = !hideRead;
    this.innerHTML = hideRead ? '<i class="bx bx-show"></i> Show Read' : '<i class="bx bx-hide"></i> Hide Read';
    currentPage = 1; loadTable();
});

// ── MODAL HELPERS ─────────────────────────────────────────────────────────────
window.closeModal = id => document.getElementById(id).classList.remove('on');
document.getElementById('escalateModal').addEventListener('click', function(e) { if (e.target===this) closeModal('escalateModal'); });
document.getElementById('detailModal').addEventListener('click',   function(e) { if (e.target===this) closeModal('detailModal'); });

function setDmTab(name, el) {
    document.querySelectorAll('.dm-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.dm-panel').forEach(p => p.classList.remove('active'));
    if (el) el.classList.add('active');
    const panelName = name === 'info' ? 'dmt-info' : 'dmt-act';
    document.getElementById(panelName).classList.add('active');
}

// ── TOAST ─────────────────────────────────────────────────────────────────────
function toast(msg, type = 's') {
    const ic = { s:'bx-check-circle', w:'bx-error', d:'bx-error-circle' };
    const el = document.createElement('div');
    el.className = `toast t${type}`;
    el.innerHTML = `<i class="bx ${ic[type]||ic.s}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 320); }, 3500);
}
</script>
</body>
</html>