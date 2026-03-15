<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';
require_once $root . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE RESOLUTION (mirrors superadmin_sidebar.php) ─────────────────────────
function _rfq_resolve_role(): string {
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

$rfqRoleName = _rfq_resolve_role();
$rfqRoleRank = match($rfqRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,  // Staff
};
$rfqUserZone = $_SESSION['zone'] ?? '';

// ── PERMISSION GATES ──────────────────────────────────────────────────────────
// canCreate    : Super Admin, Admin
// canSend      : Super Admin, Admin
// canClose     : Super Admin, Admin
// canCancel    : Super Admin, Admin
// canExtend    : Super Admin, Admin
// canOverride  : Super Admin only
// canViewAllZones  : Super Admin only (Admin is zone-restricted)
// canViewTimestamps: Super Admin only
$pCan = [
    'create'          => $rfqRoleRank >= 3,
    'send'            => $rfqRoleRank >= 3,
    'close'           => $rfqRoleRank >= 3,
    'cancel'          => $rfqRoleRank >= 3,
    'extend'          => $rfqRoleRank >= 3,
    'override'        => $rfqRoleRank >= 4,
    'manageSups'      => $rfqRoleRank >= 3,
    'viewTimestamps'  => $rfqRoleRank >= 4,
    'viewAllZones'    => $rfqRoleRank >= 4,
    'viewPage'        => $rfqRoleRank >= 2,   // Manager+
];

// Block staff entirely
if ($rfqRoleRank < 2) {
    http_response_code(403);
    echo '<p style="font-family:sans-serif;padding:40px">Access denied.</p>';
    exit;
}

// ── HELPERS ──────────────────────────────────────────────────────────────────
function rfq_ok($payload): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function rfq_err(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function rfq_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    if ($d === null && json_last_error() !== JSON_ERROR_NONE) rfq_err('Invalid JSON', 400);
    return is_array($d) ? $d : [];
}
function rfq_sb(string $table, string $method = 'GET', array $query = [], $body = null, array $extra_headers = []): array {
    $url = SUPABASE_URL . '/rest/v1/' . $table;
    if ($query) $url .= '?' . http_build_query($query);
    $headers = array_merge([
        'Content-Type: application/json',
        'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Prefer: return=representation',
    ], $extra_headers);
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
        if ($code >= 400) rfq_err('Supabase request failed', 500);
        return [];
    }
    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = is_array($data) ? ($data['message'] ?? $data['hint'] ?? $res) : $res;
        rfq_err('Supabase: ' . $msg, 400);
    }
    return is_array($data) ? $data : [];
}

function rfq_send_email_to_suppliers(int $rfqId, string $actorName, ?string $ip): void {
    $rows = rfq_sb('psm_rfqs', 'GET', [
        'id'     => 'eq.' . $rfqId,
        'select' => 'id,rfq_no,pr_ref,department,items,deadline',
        'limit'  => 1,
    ]);
    if (empty($rows)) return;
    $rfq = $rows[0];
    $jRows = rfq_sb('psm_rfq_suppliers', 'GET', ['rfq_id' => 'eq.' . $rfqId, 'select' => 'supplier_id']);
    $supplierIds = array_column($jRows, 'supplier_id');
    if (!$supplierIds) return;
    $in = 'in.(' . implode(',', array_map('intval', $supplierIds)) . ')';
    $supRows = rfq_sb('psm_suppliers', 'GET', ['id' => $in, 'select' => 'id,name,email']);
    if (!$supRows) return;
    $now = date('Y-m-d H:i:s');
    try {
        $mail = new PHPMailer(true);
        $mail->SMTPDebug   = SMTP::DEBUG_OFF;
        $mail->Debugoutput = 'error_log';
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'noreply.microfinancial@gmail.com';
        $mail->Password   = 'dpjdwwlopkzdyfnk';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->setFrom('noreply.microfinancial@gmail.com', 'MicroFinancial');
        $mail->isHTML(true);
        foreach ($supRows as $s) {
            $email = trim($s['email'] ?? '');
            if ($email === '') continue;
            $name = $s['name'] ?? '';
            $mail->clearAllRecipients();
            $mail->addAddress($email, $name);
            $mail->Subject = 'RFQ ' . ($rfq['rfq_no'] ?? '') . ' – Request for Quotation';
            $deadline = $rfq['deadline'] ?? '';
            $items    = nl2br(htmlspecialchars($rfq['items'] ?? '', ENT_QUOTES, 'UTF-8'));
            $body  = '<p>Dear ' . htmlspecialchars($name ?: 'Supplier', ENT_QUOTES, 'UTF-8') . ',</p>';
            $body .= '<p>MicroFinancial is requesting your quotation for the following RFQ:</p>';
            $body .= '<ul>';
            $body .= '<li><strong>RFQ No.:</strong> ' . htmlspecialchars($rfq['rfq_no'] ?? '', ENT_QUOTES, 'UTF-8') . '</li>';
            $body .= '<li><strong>PR Reference:</strong> ' . htmlspecialchars($rfq['pr_ref'] ?? '', ENT_QUOTES, 'UTF-8') . '</li>';
            $body .= '<li><strong>Department:</strong> ' . htmlspecialchars($rfq['department'] ?? '', ENT_QUOTES, 'UTF-8') . '</li>';
            if ($deadline) $body .= '<li><strong>Deadline:</strong> ' . htmlspecialchars($deadline, ENT_QUOTES, 'UTF-8') . '</li>';
            $body .= '</ul>';
            $body .= '<p><strong>Items / Description:</strong></p><p>' . $items . '</p>';
            $body .= '<p>Please submit your quotation on or before the deadline.</p>';
            $body .= '<p>Thank you,<br>Procurement Team<br>MicroFinancial</p>';
            $mail->Body    = $body;
            $mail->AltBody = 'MicroFinancial RFQ ' . ($rfq['rfq_no'] ?? '') . ' – please submit your quotation before the deadline.';
            $mail->send();
        }
        rfq_sb('psm_rfq_audit_log', 'POST', [], [[
            'rfq_id'       => $rfqId,
            'action_label' => 'RFQ email notification sent to suppliers',
            'actor_name'   => $actorName,
            'dot_class'    => 'dot-b',
            'ip_address'   => $ip,
            'occurred_at'  => $now,
        ]]);
    } catch (Throwable $e) {
        rfq_sb('psm_rfq_audit_log', 'POST', [], [[
            'rfq_id'       => $rfqId,
            'action_label' => 'RFQ email send failed: ' . substr($e->getMessage(), 0, 180),
            'actor_name'   => $actorName,
            'dot_class'    => 'dot-r',
            'ip_address'   => $ip,
            'occurred_at'  => $now,
        ]]);
    }
}

function rfq_build_full(array $row, bool $showTimestamps = true): array {
    $rfqId = (int)$row['id'];
    $jRows = rfq_sb('psm_rfq_suppliers', 'GET', ['rfq_id' => 'eq.' . $rfqId, 'select' => 'supplier_id']);
    $supplierIds = array_column($jRows, 'supplier_id');
    $rRows = rfq_sb('psm_rfq_responses', 'GET', [
        'rfq_id' => 'eq.' . $rfqId,
        'select' => 'supplier_id,amount,lead_days,notes,submitted_at',
        'order'  => 'submitted_at.asc',
    ]);
    $responses = array_map(fn($r) => [
        'supId'    => (int)$r['supplier_id'],
        'amt'      => (float)$r['amount'],
        'leadDays' => (int)$r['lead_days'],
        'notes'    => $r['notes'] ?? '',
        // Only expose timestamp to Super Admin
        'ts'       => $showTimestamps ? ($r['submitted_at'] ?? '') : '',
    ], $rRows);
    $aRows = rfq_sb('psm_rfq_audit_log', 'GET', [
        'rfq_id' => 'eq.' . $rfqId,
        'select' => 'action_label,actor_name,dot_class,occurred_at',
        'order'  => 'occurred_at.desc,id.desc',
    ]);
    $audit = array_map(fn($a) => [
        't'   => $a['dot_class']    ?? 'dot-b',
        'msg' => $a['action_label'] ?? '',
        'by'  => $a['actor_name']   ?? '',
        'ts'  => $a['occurred_at']  ?? '',
    ], $aRows);
    return [
        'id'          => $rfqId,
        'rfqNo'       => $row['rfq_no'],
        'prRef'       => $row['pr_ref'],
        'dept'        => $row['department']   ?? '',
        'branch'      => $row['branch']       ?? '',
        'dateIssued'  => $row['date_issued']  ?? '',
        'deadline'    => $row['deadline']     ?? '',
        'status'      => $row['status']       ?? 'Draft',
        'items'       => $row['items']        ?? '',
        'notes'       => $row['notes']        ?? '',
        'evaluator'   => $row['evaluator']    ?? '',
        'override'    => $row['override_reason'] ?? '',
        'sentBy'      => $row['sent_by']      ?? '',
        'modBy'       => $row['mod_by']       ?? '',
        'supplierIds' => array_map('intval', $supplierIds),
        'responses'   => $responses,
        'audit'       => $audit,
    ];
}

function rfq_next_number(): string {
    $rows = rfq_sb('psm_rfqs', 'GET', ['select' => 'rfq_no', 'order' => 'id.desc', 'limit' => 1]);
    $next = 1;
    if (!empty($rows) && preg_match('/RFQ-(\d+)/', $rows[0]['rfq_no'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return sprintf('RFQ-%04d', $next);
}

// ── API ROUTER ───────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name']  ?? ($_SESSION['user_id'] ?? 'Super Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
    $showTs = $pCan['viewTimestamps'];

    try {
        if ($api === 'next_no' && $method === 'GET') {
            if (!$pCan['create']) rfq_err('Permission denied', 403);
            rfq_ok(['rfqNo' => rfq_next_number()]);
        }

        if ($api === 'suppliers' && $method === 'GET') {
            $q = ['select' => 'id,name,category,email', 'status' => 'eq.Active', 'order' => 'name.asc'];
            // Admin: filter by zone-linked suppliers (branch column in psm_suppliers)
            // Super Admin: all suppliers
            if (!$pCan['viewAllZones'] && $rfqUserZone !== '') {
                // No branch filter on suppliers table in schema; Admin sees all active suppliers
                // (zone restriction is on RFQ list, not supplier directory)
            }
            $rows = rfq_sb('psm_suppliers', 'GET', $q);
            $out = array_map(fn($r) => [
                'id'    => (int)$r['id'],
                'name'  => $r['name']     ?? '',
                'cat'   => $r['category'] ?? '',
                'email' => $r['email']    ?? '',
            ], $rows);
            rfq_ok($out);
        }

        if ($api === 'prs' && $method === 'GET') {
            if (!$pCan['create']) rfq_err('Permission denied', 403);
            $rows = rfq_sb('psm_purchase_requests', 'GET', [
                'select' => 'pr_number,department',
                'status' => 'eq.Approved',
                'order'  => 'pr_number.desc',
            ]);
            rfq_ok($rows);
        }

        if ($api === 'list' && $method === 'GET') {
            $q = [
                'select' => 'id,rfq_no,pr_ref,branch,department,date_issued,deadline,status,items,notes,evaluator,override_reason,sent_by,mod_by',
                'order'  => 'date_issued.desc,id.desc',
            ];
            // Admin sees only their zone/branch; Manager same restriction
            if (!$pCan['viewAllZones'] && $rfqUserZone !== '') {
                $q['branch'] = 'eq.' . $rfqUserZone;
            }
            $rows = rfq_sb('psm_rfqs', 'GET', $q);
            // Manager: only Draft/Sent/Responded
            if ($rfqRoleRank === 2) {
                $rows = array_filter($rows, fn($r) => in_array($r['status'], ['Draft','Sent','Responded']));
                $rows = array_values($rows);
            }
            $out = [];
            foreach ($rows as $row) {
                $out[] = rfq_build_full($row, $showTs);
            }
            rfq_ok($out);
        }

        if ($api === 'get' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) rfq_err('Missing id', 400);
            $rows = rfq_sb('psm_rfqs', 'GET', [
                'select' => 'id,rfq_no,pr_ref,branch,department,date_issued,deadline,status,items,notes,evaluator,override_reason,sent_by,mod_by',
                'id'     => 'eq.' . $id,
                'limit'  => 1,
            ]);
            if (empty($rows)) rfq_err('RFQ not found', 404);
            rfq_ok(rfq_build_full($rows[0], $showTs));
        }

        if ($api === 'save' && $method === 'POST') {
            if (!$pCan['create']) rfq_err('Permission denied: cannot create/edit RFQs', 403);
            $b = rfq_body();
            $prRef    = trim($b['prRef']    ?? '');
            $dept     = trim($b['dept']     ?? '');
            $deadline = trim($b['deadline'] ?? '');
            $items    = trim($b['items']    ?? '');
            $status   = in_array($b['status'] ?? '', ['Draft','Sent'], true) ? $b['status'] : 'Draft';
            $supIds   = array_map('intval', (array)($b['supplierIds'] ?? []));
            $branch   = trim($b['branch']   ?? $rfqUserZone);

            if ($prRef === '')    rfq_err('PR Reference is required', 400);
            if ($dept === '')     rfq_err('Department is required', 400);
            if ($deadline === '') rfq_err('Deadline is required', 400);
            if ($items === '')    rfq_err('Items / description is required', 400);
            if (!$supIds)         rfq_err('At least one supplier is required', 400);

            $editId = (int)($b['id'] ?? 0);
            $now    = date('Y-m-d H:i:s');

            if ($editId) {
                rfq_sb('psm_rfqs', 'PATCH', ['id' => 'eq.' . $editId], [
                    'pr_ref'          => $prRef,
                    'branch'          => $branch,
                    'department'      => $dept,
                    'date_issued'     => $b['dateIssued'] ?? date('Y-m-d'),
                    'deadline'        => $deadline,
                    'status'          => $status,
                    'items'           => $items,
                    'notes'           => trim($b['notes']     ?? ''),
                    'evaluator'       => trim($b['evaluator'] ?? ''),
                    // Only SA can set override reason
                    'override_reason' => $pCan['override'] ? trim($b['override'] ?? '') : null,
                    'mod_by'          => $actor,
                    'updated_at'      => $now,
                ]);
                rfq_sb('psm_rfq_suppliers', 'DELETE', ['rfq_id' => 'eq.' . $editId]);
                foreach ($supIds as $sid) {
                    rfq_sb('psm_rfq_suppliers', 'POST', [], [['rfq_id' => $editId, 'supplier_id' => $sid]]);
                }
                rfq_sb('psm_rfq_audit_log', 'POST', [], [[
                    'rfq_id'       => $editId,
                    'action_label' => 'RFQ Edited',
                    'actor_name'   => $actor,
                    'dot_class'    => 'dot-b',
                    'ip_address'   => $ip,
                    'occurred_at'  => $now,
                ]]);
                $rows = rfq_sb('psm_rfqs', 'GET', ['id' => 'eq.' . $editId, 'select' => 'id,rfq_no,pr_ref,branch,department,date_issued,deadline,status,items,notes,evaluator,override_reason,sent_by,mod_by', 'limit' => 1]);
                rfq_ok(rfq_build_full($rows[0], $showTs));
            }

            $rfqNo = rfq_next_number();
            $inserted = rfq_sb('psm_rfqs', 'POST', [], [[
                'rfq_no'          => $rfqNo,
                'pr_ref'          => $prRef,
                'branch'          => $branch,
                'department'      => $dept,
                'date_issued'     => $b['dateIssued'] ?? date('Y-m-d'),
                'deadline'        => $deadline,
                'status'          => $status,
                'items'           => $items,
                'notes'           => trim($b['notes']     ?? ''),
                'evaluator'       => trim($b['evaluator'] ?? ''),
                'sent_by'         => $status === 'Sent' ? $actor : '',
                'created_user_id' => $_SESSION['user_id'] ?? null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]]);
            if (empty($inserted)) rfq_err('Failed to create RFQ', 500);
            $newId = (int)$inserted[0]['id'];
            foreach ($supIds as $sid) {
                rfq_sb('psm_rfq_suppliers', 'POST', [], [['rfq_id' => $newId, 'supplier_id' => $sid]]);
            }
            rfq_sb('psm_rfq_audit_log', 'POST', [], [[
                'rfq_id'       => $newId,
                'action_label' => 'RFQ Created',
                'actor_name'   => $actor,
                'dot_class'    => 'dot-g',
                'ip_address'   => $ip,
                'occurred_at'  => $now,
            ]]);
            if ($status === 'Sent') {
                rfq_sb('psm_rfq_audit_log', 'POST', [], [[
                    'rfq_id'       => $newId,
                    'action_label' => 'RFQ sent to ' . count($supIds) . ' supplier(s)',
                    'actor_name'   => $actor,
                    'dot_class'    => 'dot-b',
                    'ip_address'   => $ip,
                    'occurred_at'  => $now,
                ]]);
            }
            $rows = rfq_sb('psm_rfqs', 'GET', ['id' => 'eq.' . $newId, 'select' => 'id,rfq_no,pr_ref,branch,department,date_issued,deadline,status,items,notes,evaluator,override_reason,sent_by,mod_by', 'limit' => 1]);
            rfq_ok(rfq_build_full($rows[0], $showTs));
        }

        if ($api === 'action' && $method === 'POST') {
            $b      = rfq_body();
            $rfqId  = (int)trim($b['id']   ?? 0);
            $type   = trim($b['type']       ?? '');
            $reason = trim($b['reason']     ?? '');
            $now    = date('Y-m-d H:i:s');

            if (!$rfqId) rfq_err('Missing id', 400);
            if ($type === '') rfq_err('Missing type', 400);

            // Permission gate per action type
            $permMap = [
                'send'             => 'send',
                'close'            => 'close',
                'cancel'           => 'cancel',
                'override'         => 'override',
                'extend'           => 'extend',
                'manage_suppliers' => 'manageSups',
            ];
            if (isset($permMap[$type]) && !$pCan[$permMap[$type]]) {
                rfq_err('Permission denied: your role cannot perform this action', 403);
            }

            $rows = rfq_sb('psm_rfqs', 'GET', ['id' => 'eq.' . $rfqId, 'select' => 'id,rfq_no,status,deadline', 'limit' => 1]);
            if (empty($rows)) rfq_err('RFQ not found', 404);

            if ($type === 'send') {
                rfq_sb('psm_rfqs', 'PATCH', ['id' => 'eq.' . $rfqId], ['status' => 'Sent', 'sent_by' => $actor, 'updated_at' => $now]);
                rfq_sb('psm_rfq_audit_log', 'POST', [], [[
                    'rfq_id' => $rfqId, 'action_label' => 'RFQ sent to suppliers',
                    'actor_name' => $actor, 'dot_class' => 'dot-b', 'ip_address' => $ip, 'occurred_at' => $now,
                ]]);
                rfq_send_email_to_suppliers($rfqId, $actor, $ip);

            } elseif ($type === 'close') {
                rfq_sb('psm_rfqs', 'PATCH', ['id' => 'eq.' . $rfqId], ['status' => 'Closed', 'mod_by' => $actor, 'updated_at' => $now]);
                rfq_sb('psm_rfq_audit_log', 'POST', [], [[
                    'rfq_id' => $rfqId, 'action_label' => 'RFQ Closed' . ($reason ? ' — ' . $reason : ''),
                    'actor_name' => $actor, 'dot_class' => 'dot-b', 'ip_address' => $ip, 'occurred_at' => $now,
                ]]);

            } elseif ($type === 'cancel') {
                if ($reason === '') rfq_err('Reason is required to cancel', 400);
                rfq_sb('psm_rfqs', 'PATCH', ['id' => 'eq.' . $rfqId], ['status' => 'Cancelled', 'mod_by' => $actor, 'updated_at' => $now]);
                rfq_sb('psm_rfq_audit_log', 'POST', [], [[
                    'rfq_id' => $rfqId, 'action_label' => 'RFQ Cancelled — ' . $reason,
                    'actor_name' => $actor, 'dot_class' => 'dot-r', 'ip_address' => $ip, 'occurred_at' => $now,
                ]]);

            } elseif ($type === 'override') {
                if ($reason === '') rfq_err('Reason is required for override', 400);
                rfq_sb('psm_rfqs', 'PATCH', ['id' => 'eq.' . $rfqId], [
                    'status' => 'Sent', 'override_reason' => $reason, 'mod_by' => $actor, 'updated_at' => $now,
                ]);
                rfq_sb('psm_rfq_audit_log', 'POST', [], [[
                    'rfq_id' => $rfqId, 'action_label' => 'SA Override applied — ' . $reason,
                    'actor_name' => $actor, 'dot_class' => 'dot-g', 'ip_address' => $ip, 'occurred_at' => $now,
                ]]);

            } elseif ($type === 'extend') {
                $newDl = trim($b['newDeadline'] ?? '');
                if ($newDl === '') rfq_err('New deadline is required', 400);
                $oldDl = $rows[0]['deadline'] ?? '';
                rfq_sb('psm_rfqs', 'PATCH', ['id' => 'eq.' . $rfqId], ['deadline' => $newDl, 'updated_at' => $now]);
                rfq_sb('psm_rfq_audit_log', 'POST', [], [[
                    'rfq_id'       => $rfqId,
                    'action_label' => 'Deadline extended from ' . $oldDl . ' to ' . $newDl . ($reason ? ' — ' . $reason : ''),
                    'actor_name'   => $actor, 'dot_class' => 'dot-o', 'ip_address' => $ip, 'occurred_at' => $now,
                ]]);

            } elseif ($type === 'manage_suppliers') {
                $newIds = array_map('intval', (array)($b['supplierIds'] ?? []));
                if (!$newIds) rfq_err('At least one supplier is required', 400);
                rfq_sb('psm_rfq_suppliers', 'DELETE', ['rfq_id' => 'eq.' . $rfqId]);
                foreach ($newIds as $sid) {
                    rfq_sb('psm_rfq_suppliers', 'POST', [], [['rfq_id' => $rfqId, 'supplier_id' => $sid]]);
                }
                rfq_sb('psm_rfq_audit_log', 'POST', [], [[
                    'rfq_id' => $rfqId, 'action_label' => 'Supplier list updated (' . count($newIds) . ' supplier(s))',
                    'actor_name' => $actor, 'dot_class' => 'dot-b', 'ip_address' => $ip, 'occurred_at' => $now,
                ]]);

            } else {
                rfq_err('Unsupported action type', 400);
            }

            $rows = rfq_sb('psm_rfqs', 'GET', ['id' => 'eq.' . $rfqId, 'select' => 'id,rfq_no,pr_ref,branch,department,date_issued,deadline,status,items,notes,evaluator,override_reason,sent_by,mod_by', 'limit' => 1]);
            rfq_ok(rfq_build_full($rows[0], $showTs));
        }

        rfq_err('Unsupported API route', 404);
    } catch (Throwable $e) {
        rfq_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── HTML PAGE RENDER ─────────────────────────────────────────────────────────
$root_include = dirname(__DIR__, 2);
include $root_include . '/includes/superadmin_sidebar.php';
include $root_include . '/includes/header.php';

// Pass permissions to JS as a JSON object
$jsPerms = json_encode($pCan);
$jsRole  = json_encode($rfqRoleName);
$jsZone  = json_encode($rfqUserZone);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RFQ Management — PSM</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/base.css">
    <link rel="stylesheet" href="/css/sidebar.css">
    <link rel="stylesheet" href="/css/header.css">
<style>
#mainContent,#panel,#modal,#extModal,#supModal,#cfmModal,.rfq-page{
    --surface:#FFFFFF;--border:rgba(46,125,50,.14);--border-mid:rgba(46,125,50,.22);
    --text-1:var(--text-primary);--text-2:var(--text-secondary);--text-3:#9EB0A2;
    --hover-s:var(--hover-bg-light);--shadow-sm:var(--shadow-light);
    --shadow-md:0 4px 16px rgba(46,125,50,.12);--shadow-xl:0 20px 60px rgba(0,0,0,.22);
    --radius:12px;--tr:var(--transition);--danger:#DC2626;--warning:#D97706;
    --info:#2563EB;--bg:var(--bg-color);--primary:var(--primary-color);--prim-dark:var(--primary-dark);
}
#mainContent *,#panel *,#modal *,#extModal *,#supModal *,#cfmModal *{box-sizing:border-box}
.sa-badge,.role-badge,.user-role-badge,.header-role,.badge-superadmin,[class*="role-badge"],.header-user-role{display:none!important}
.rfq-page{max-width:1500px;margin:0 auto;padding:0 0 3rem}
.rfq-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:28px;animation:rfqFadeUp .4s both}
.rfq-ph .eyebrow{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--primary);margin-bottom:4px}
.rfq-ph h1{font-size:26px;font-weight:800;color:var(--text-1);line-height:1.15}
.rfq-acts{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

/* Role badge in page header */
.rfq-role-badge{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;letter-spacing:.04em}
.rfq-role-badge.sa{background:#E8F5E9;color:var(--primary);border:1px solid rgba(46,125,50,.25)}
.rfq-role-badge.admin{background:#EFF6FF;color:var(--info);border:1px solid rgba(37,99,235,.2)}
.rfq-role-badge.manager{background:#FEF3C7;color:var(--warning);border:1px solid rgba(217,119,6,.2)}

/* Access notice banner (Manager/Staff) */
.rfq-access-notice{display:flex;align-items:flex-start;gap:10px;background:linear-gradient(135deg,#FEF3C7,#FFFBEB);border:1px solid rgba(217,119,6,.3);border-radius:12px;padding:12px 16px;margin-bottom:20px;animation:rfqFadeUp .4s .05s both}
.rfq-access-notice i{font-size:18px;color:var(--warning);flex-shrink:0;margin-top:1px}
.rfq-access-notice p{font-size:12.5px;color:#92400E;line-height:1.5;margin:0}
.rfq-access-notice strong{color:#78350F}

.rfq-btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap}
.rfq-btn-p{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3)}
.rfq-btn-p:hover{background:var(--prim-dark);transform:translateY(-1px)}
.rfq-btn-g{background:var(--surface);color:var(--text-2);border:1px solid var(--border-mid)}
.rfq-btn-g:hover{background:var(--hover-s);color:var(--text-1)}
.rfq-btn-s{font-size:12px;padding:7px 14px}
.rfq-btn-warn{background:var(--warning);color:#fff}.rfq-btn-warn:hover{background:#B45309;transform:translateY(-1px)}
.rfq-btn-danger{background:var(--danger);color:#fff}.rfq-btn-danger:hover{background:#B91C1C;transform:translateY(-1px)}
.rfq-btn-info{background:var(--info);color:#fff}.rfq-btn-info:hover{background:#1D4ED8;transform:translateY(-1px)}
.rfq-btn:disabled{opacity:.45;pointer-events:none}
.rfq-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:14px;margin-bottom:24px}
.rfq-stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;box-shadow:var(--shadow-sm);display:flex;align-items:center;gap:12px;animation:rfqFadeUp .4s both}
.rfq-stat:nth-child(1){animation-delay:.05s}.rfq-stat:nth-child(2){animation-delay:.1s}.rfq-stat:nth-child(3){animation-delay:.15s}
.rfq-stat:nth-child(4){animation-delay:.2s}.rfq-stat:nth-child(5){animation-delay:.25s}.rfq-stat:nth-child(6){animation-delay:.3s}
.rfq-sc-ic{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.ic-g{background:#E8F5E9;color:var(--primary)}.ic-o{background:#FEF3C7;color:var(--warning)}
.ic-r{background:#FEE2E2;color:var(--danger)}.ic-b{background:#EFF6FF;color:var(--info)}.ic-t{background:#CCFBF1;color:#0D9488}
.rfq-sv{font-size:22px;font-weight:800;line-height:1}.rfq-sl{font-size:11px;color:var(--text-2);margin-top:2px}
.rfq-toolbar,.rfq-toolbar-row2{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;animation:rfqFadeUp .4s .1s both}
.rfq-sw{position:relative;flex:1;min-width:220px}
.rfq-sw i{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:18px;color:var(--text-3);pointer-events:none}
.rfq-sin{width:100%;padding:9px 12px 9px 38px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr)}
.rfq-sin:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
.rfq-sin::placeholder{color:var(--text-3)}
.rfq-fsel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 32px 9px 12px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);cursor:pointer;outline:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;transition:var(--tr)}
.rfq-fsel:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
.rfq-date-range{display:flex;align-items:center;gap:6px}
.rfq-date-range label{font-size:12px;color:var(--text-2);font-weight:500;white-space:nowrap}
.rfq-date-in{font-family:'Inter',sans-serif;font-size:13px;padding:9px 12px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr)}
.rfq-date-in:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
.rfq-tcard{background:var(--surface);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-md);animation:rfqFadeUp .4s .15s both}
.rfq-twrap{overflow-x:auto}
.rfq-tcard table{width:100%;border-collapse:collapse;font-size:13px}
.rfq-tcard thead th{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-2);padding:12px 14px;text-align:left;background:var(--bg);border-bottom:1px solid var(--border);white-space:nowrap;user-select:none}
.rfq-tcard thead th:first-child{padding-left:20px}
.rfq-tcard thead th.rfq-sort{cursor:pointer}.rfq-tcard thead th.rfq-sort:hover{color:var(--primary)}
.rfq-tcard thead th .rfq-si{margin-left:4px;opacity:.4;font-size:13px;vertical-align:middle}
.rfq-tcard thead th.rfq-sorted .rfq-si{opacity:1;color:var(--primary)}
.rfq-tcard tbody tr{border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s}
.rfq-tcard tbody tr:last-child{border-bottom:none}
.rfq-tcard tbody tr:hover{background:var(--hover-s)}
.rfq-tcard tbody tr.overdue-row{background:rgba(220,38,38,.02)}.rfq-tcard tbody tr.overdue-row:hover{background:rgba(220,38,38,.06)}
.rfq-tcard tbody td{padding:13px 14px;vertical-align:middle}
.rfq-tcard tbody td:first-child{padding-left:20px}.rfq-tcard tbody td:last-child{padding-right:20px}
.rfq-num{font-family:'DM Mono',monospace;font-size:12px;font-weight:600;color:var(--primary)}
.rfq-dept{font-size:12px;color:var(--text-2);margin-top:2px;font-family:'DM Mono',monospace}
.cell-meta{font-size:11px;color:var(--text-3);margin-top:2px}
.dl-near{color:var(--warning);font-weight:600}.dl-over{color:var(--danger);font-weight:600}
.rfq-chip{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px}
.rfq-chip::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}
.rc-draft{background:#F3F4F6;color:#6B7280}.rc-sent{background:#EFF6FF;color:var(--info)}
.rc-responded{background:#E8F5E9;color:var(--primary)}.rc-closed{background:#F3F4F6;color:#374151}
.rc-cancelled{background:#FEE2E2;color:var(--danger)}
.sup-avs{display:flex;align-items:center}
.sup-av-xs{width:24px;height:24px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:9px;color:#fff;margin-left:-4px;border:2px solid var(--surface);flex-shrink:0}
.sup-av-xs:first-child{margin-left:0}.sup-av-more{background:#E5E7EB;color:#374151}
.resp-cnt{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600}
.resp-cnt .rcd{width:8px;height:8px;border-radius:50%}
.rcd-full{background:var(--primary)}.rcd-part{background:var(--warning)}.rcd-none{background:#D1D5DB}
.rfq-pag{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--border);background:var(--bg);font-size:13px;color:var(--text-2)}
.rfq-pbtns{display:flex;gap:6px}
.rfq-pb{width:32px;height:32px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);font-family:'Inter',sans-serif;font-size:13px;font-weight:500;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--text-1)}
.rfq-pb:hover{background:var(--hover-s);border-color:var(--primary);color:var(--primary)}
.rfq-pb.active{background:var(--primary);border-color:var(--primary);color:#fff}
.rfq-pb:disabled{opacity:.4;pointer-events:none}
.rfq-empty{padding:60px 20px;text-align:center;color:var(--text-3)}
.rfq-empty i{font-size:44px;display:block;margin-bottom:10px;color:#C8E6C9}
.rfq-empty p{font-size:14px}
.rfq-ov{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1100;opacity:0;pointer-events:none;transition:opacity .25s}
.rfq-ov.show{opacity:1;pointer-events:all}
#panel{position:fixed;top:0;right:0;bottom:0;width:580px;max-width:94vw;background:var(--surface);box-shadow:-4px 0 40px rgba(0,0,0,.18);z-index:1101;transform:translateX(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden}
#panel.open{transform:translateX(0)}
.p-hd{display:flex;align-items:center;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--border);background:var(--bg);flex-shrink:0}
.p-t{font-size:17px;font-weight:700;color:var(--text-1)}.p-s{font-size:12px;color:var(--text-2);margin-top:2px}
.p-cl{width:36px;height:36px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--text-2);transition:var(--tr);flex-shrink:0}
.p-cl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA}
.p-body{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:18px}
.p-body::-webkit-scrollbar{width:4px}.p-body::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px}
.p-ft{padding:16px 24px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0}
.rfq-fg{display:flex;flex-direction:column;gap:6px}.rfq-fr{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.rfq-fl{font-size:12px;font-weight:600;color:var(--text-2);text-transform:uppercase;letter-spacing:.06em}
.rfq-fl span{color:var(--danger);margin-left:2px}
.rfq-fi,.rfq-fs,.rfq-fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);width:100%}
.rfq-fi:focus,.rfq-fs:focus,.rfq-fta:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
.rfq-fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:32px}
.rfq-fta{resize:vertical;min-height:80px}
.rfq-sdv{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);display:flex;align-items:center;gap:10px;margin:4px 0}
.rfq-sdv::after{content:'';flex:1;height:1px;background:var(--border)}
.sup-picker{border:1px solid var(--border-mid);border-radius:10px;overflow:hidden}
.sup-picker-srch{display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid var(--border);background:var(--bg)}
.sup-picker-srch i{color:var(--text-3);font-size:16px;flex-shrink:0}
.sup-picker-srch input{font-family:'Inter',sans-serif;font-size:13px;border:none;outline:none;background:transparent;width:100%;color:var(--text-1)}
.sup-picker-list{max-height:180px;overflow-y:auto}
.sup-picker-item{display:flex;align-items:center;gap:10px;padding:9px 12px;cursor:pointer;transition:background .12s}
.sup-picker-item:hover{background:var(--hover-s)}.sup-picker-item.selected{background:#F0FBF1}
.sup-picker-av{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:10px;color:#fff;flex-shrink:0}
.sup-picker-nm{font-size:13px;font-weight:500;color:var(--text-1);flex:1}.sup-picker-cat{font-size:11px;color:var(--text-3)}
.sup-picker-chk{width:18px;height:18px;border-radius:5px;border:2px solid var(--border-mid);display:grid;place-content:center;flex-shrink:0;transition:var(--tr)}
.sup-picker-item.selected .sup-picker-chk{background:var(--primary);border-color:var(--primary)}
.sup-picker-item.selected .sup-picker-chk::after{content:'';width:8px;height:6px;background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 8'%3E%3Cpath d='M1 4l3 3 5-6' stroke='%23fff' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") center/contain no-repeat}
.sup-picker-empty{padding:16px;text-align:center;font-size:12px;color:var(--text-3)}
.sel-tags{display:flex;flex-wrap:wrap;gap:6px;min-height:28px}
.stag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:4px 10px;background:#E8F5E9;color:var(--primary);border-radius:20px}
.stag button{background:none;border:none;cursor:pointer;color:inherit;font-size:14px;padding:0;line-height:1;opacity:.7}
.stag button:hover{opacity:1}
.sa-section{background:linear-gradient(135deg,rgba(27,94,32,.04),rgba(46,125,50,.06));border:1px solid rgba(46,125,50,.2);border-radius:12px;padding:16px}
.sa-hd{display:flex;align-items:center;gap:8px;margin-bottom:14px}
.sa-hd i{color:var(--primary);font-size:16px}
.sa-hd span{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--primary)}
#modal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s}
#modal.show{opacity:1;pointer-events:all}
.rfq-mbox{background:var(--surface);border-radius:20px;width:860px;max-width:100%;max-height:92vh;display:flex;flex-direction:column;box-shadow:var(--shadow-xl);transform:scale(.96);transition:transform .25s;overflow:hidden}
#modal.show .rfq-mbox{transform:scale(1)}
.m-hd{padding:24px 28px 0;border-bottom:1px solid var(--border);background:var(--bg);flex-shrink:0}
.m-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}
.m-ti{display:flex;align-items:center;gap:14px}
.m-ic{width:46px;height:46px;border-radius:12px;background:#E8F5E9;color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.m-nm{font-size:20px;font-weight:800;color:var(--text-1)}
.m-id{font-family:'DM Mono',monospace;font-size:12px;color:var(--text-2);margin-top:3px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.m-cl{width:36px;height:36px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--text-2);transition:var(--tr);flex-shrink:0}
.m-cl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA}
.m-meta{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px}
.m-mc{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-2);background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:5px 10px}
.m-mc i{font-size:14px;color:var(--primary)}
.m-tabs{display:flex;gap:4px}
.m-tab{font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px 8px 0 0;cursor:pointer;transition:var(--tr);color:var(--text-2);border:none;background:transparent}
.m-tab:hover{background:var(--hover-s)}.m-tab.active{background:var(--primary);color:#fff}
.m-body{flex:1;overflow-y:auto;padding:24px 28px}
.m-body::-webkit-scrollbar{width:4px}.m-body::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px}
.m-ft{padding:16px 28px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:8px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap}
.m-tp{display:none}.m-tp.active{display:block}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.info-item label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);display:block;margin-bottom:4px}
.info-item .v{font-size:13px;font-weight:500;color:var(--text-1)}.full{grid-column:1/-1}
.stat-boxes{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.sbox{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px 16px}
.sbox .sbv{font-size:18px;font-weight:800;color:var(--text-1)}.sbox .sbl{font-size:11px;color:var(--text-2);margin-top:2px}
.resp-tbl{width:100%;border-collapse:collapse;font-size:13px}
.resp-tbl thead th{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-2);padding:10px 12px;text-align:left;background:var(--bg);border-bottom:1px solid var(--border)}
.resp-tbl tbody tr{border-bottom:1px solid var(--border);transition:background .12s}
.resp-tbl tbody tr:hover{background:var(--hover-s)}
.resp-tbl tbody td{padding:11px 12px;vertical-align:middle}
.resp-av{width:28px;height:28px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:10px;color:#fff;vertical-align:middle;margin-right:6px}
.quote-amt{font-family:'DM Mono',monospace;font-weight:600;color:var(--primary)}
.best-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:#D1FAE5;color:#065F46;padding:2px 8px;border-radius:20px;margin-left:6px}
.no-resp{display:flex;align-items:center;gap:8px;padding:12px 0;color:var(--text-3);font-size:13px}
.sa-ts{font-family:'DM Mono',monospace;font-size:11px;color:var(--text-3)}
.audit-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)}
.audit-item:last-child{border-bottom:none}
.audit-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px}
.dot-g{background:var(--primary)}.dot-b{background:var(--info)}.dot-o{background:var(--warning)}.dot-r{background:var(--danger)}
.audit-body .au{font-size:13px;font-weight:500;color:var(--text-1)}
.audit-body .at{font-size:11px;color:var(--text-3);margin-top:2px;font-family:'DM Mono',monospace}
#extModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1300;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s}
#extModal.show{opacity:1;pointer-events:all}
.ext-box{background:var(--surface);border-radius:20px;width:420px;max-width:100%;box-shadow:var(--shadow-xl);transform:scale(.95);transition:transform .2s;overflow:hidden}
#extModal.show .ext-box{transform:scale(1)}
.ext-hd{padding:22px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.ext-hd-ic{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.ext-hd-title{font-size:16px;font-weight:700;color:var(--text-1)}.ext-hd-sub{font-size:12px;color:var(--text-2);margin-top:2px}
.ext-body{padding:20px 24px}
.ext-ft{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--bg)}
#cfmModal{position:fixed!important;inset:0!important;background:rgba(0,0,0,.55)!important;z-index:9000!important;display:flex!important;align-items:center!important;justify-content:center!important;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s;isolation:isolate}
#cfmModal.show{opacity:1!important;pointer-events:all!important}
.cfm-box{background:#FFFFFF;border-radius:20px;width:440px;max-width:calc(100vw - 40px);box-shadow:0 24px 80px rgba(0,0,0,.35);transform:scale(.94) translateY(8px);transition:transform .22s cubic-bezier(.4,0,.2,1),opacity .22s;overflow:hidden;position:relative;z-index:9001}
#cfmModal.show .cfm-box{transform:scale(1) translateY(0)}
.cfm-hd{padding:22px 24px 16px;border-bottom:1px solid rgba(46,125,50,.14);display:flex;align-items:center;gap:14px;background:#FAFAFA}
.cfm-hd-ic{width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.cfm-hd-title{font-size:16px;font-weight:700;color:#0A1F0D}.cfm-hd-sub{font-size:12px;color:#5D6F62;margin-top:3px}
.cfm-body{padding:20px 24px;font-size:13.5px;color:#374151;line-height:1.65;background:#FFFFFF}
.cfm-body strong{color:#0A1F0D}
.cfm-ft{padding:14px 24px 18px;border-top:1px solid rgba(46,125,50,.14);display:flex;gap:10px;justify-content:flex-end;background:#FAFAFA}
#supModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1300;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s}
#supModal.show{opacity:1;pointer-events:all}
.sup-mbox2{background:var(--surface);border-radius:20px;width:500px;max-width:100%;max-height:80vh;display:flex;flex-direction:column;box-shadow:var(--shadow-xl);transform:scale(.95);transition:transform .25s;overflow:hidden}
#supModal.show .sup-mbox2{transform:scale(1)}
.sup-m2-hd{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.sup-m2-body{flex:1;overflow-y:auto;padding:16px 24px}
.sup-m2-ft{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--bg)}
#rfqTw{position:fixed;bottom:28px;right:28px;display:flex;flex-direction:column;gap:10px;z-index:9999;pointer-events:none}
.rfq-toast{background:#DC2626!important;background-color:#DC2626!important;color:#fff!important;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow-xl);pointer-events:all;min-width:220px;animation:rfqTI .3s ease}
.rfq-toast.out{animation:rfqTO .3s ease forwards}
/* Manager read-only row cursor */
.rfq-tcard tbody tr.view-only{cursor:default}
@keyframes rfqFadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes rfqTI{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes rfqTO{from{opacity:1}to{opacity:0;transform:translateY(12px)}}
@keyframes rfqShake{0%,100%{transform:translateX(0)}25%,75%{transform:translateX(-5px)}50%{transform:translateX(5px)}}
@media(max-width:768px){
    #panel{width:100vw;max-width:100vw}
    .rfq-fr,.info-grid{grid-template-columns:1fr}
    .stat-boxes{grid-template-columns:1fr 1fr}
    .rfq-stats{grid-template-columns:1fr 1fr}
    .rfq-date-range{flex-wrap:wrap}
}
</style>
</head>
<body>

<main class="main-content" id="mainContent">
<div class="rfq-page">

    <div class="rfq-ph">
        <div>
            <p class="eyebrow">PSM · Procurement &amp; Sourcing Management</p>
            <h1>Request for Quotation
                <?php
                $badgeCls = match($rfqRoleName) {
                    'Super Admin' => 'sa',
                    'Admin'       => 'admin',
                    default       => 'manager',
                };
                $badgeIcon = match($rfqRoleName) {
                    'Super Admin' => 'bx-shield-quarter',
                    'Admin'       => 'bx-user-check',
                    default       => 'bx-show',
                };
                ?>
                <span class="rfq-role-badge <?= $badgeCls ?>"><i class='bx <?= $badgeIcon ?>'></i><?= htmlspecialchars($rfqRoleName) ?></span>
            </h1>
        </div>
        <div class="rfq-acts">
            <button class="rfq-btn rfq-btn-g" id="expBtn"><i class='bx bx-export'></i> Export</button>
            <?php if ($pCan['create']): ?>
            <button class="rfq-btn rfq-btn-p" id="addBtn"><i class='bx bx-plus'></i> Create RFQ</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($rfqRoleRank === 2): ?>
    <div class="rfq-access-notice">
        <i class='bx bx-info-circle'></i>
        <p><strong>Manager View:</strong> You can monitor RFQs assigned to your team. Drafts, Sent, and Responded statuses are visible. To create, send, close, or cancel an RFQ, contact your Admin or Super Admin.</p>
    </div>
    <?php elseif ($rfqRoleRank === 3): ?>
    <div class="rfq-access-notice" style="background:linear-gradient(135deg,#EFF6FF,#F0F9FF);border-color:rgba(37,99,235,.25)">
        <i class='bx bx-info-circle' style="color:var(--info)"></i>
        <p style="color:#1E3A5F"><strong>Admin View:</strong> You can create and manage RFQs within your zone (<strong><?= htmlspecialchars($rfqUserZone ?: 'All') ?></strong>). Override of closed RFQs requires Super Admin access.</p>
    </div>
    <?php endif; ?>

    <div class="rfq-stats" id="statsRow"></div>

    <div class="rfq-toolbar">
        <div class="rfq-sw">
            <i class='bx bx-search'></i>
            <input type="text" class="rfq-sin" id="srch" placeholder="Search by RFQ number, PR reference, or supplier…">
        </div>
        <select class="rfq-fsel" id="fStat">
            <option value="">All Statuses</option>
            <?php if ($rfqRoleRank >= 3): ?>
            <option>Draft</option>
            <?php endif; ?>
            <option>Sent</option>
            <option>Responded</option>
            <?php if ($rfqRoleRank >= 3): ?>
            <option>Closed</option>
            <option>Cancelled</option>
            <?php endif; ?>
        </select>
        <select class="rfq-fsel" id="fDept"><option value="">All Departments</option></select>
        <?php if ($pCan['viewAllZones']): ?>
        <select class="rfq-fsel" id="fBranch"><option value="">All Branches</option></select>
        <?php endif; ?>
    </div>

    <div class="rfq-toolbar-row2">
        <div class="rfq-date-range">
            <label>Date Issued:</label>
            <input type="date" class="rfq-date-in" id="fDateFrom">
            <span style="font-size:12px;color:var(--text-3)">to</span>
            <input type="date" class="rfq-date-in" id="fDateTo">
        </div>
        <button class="rfq-btn rfq-btn-g rfq-btn-s" id="clearDates"><i class='bx bx-x'></i> Clear Dates</button>
    </div>

    <div class="rfq-tcard">
        <div class="rfq-twrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th class="rfq-sort" data-col="rfqNo">RFQ Number <i class='bx bx-sort rfq-si'></i></th>
                        <th class="rfq-sort" data-col="prRef">PR Reference <i class='bx bx-sort rfq-si'></i></th>
                        <th class="rfq-sort" data-col="dateIssued">Date Issued <i class='bx bx-sort rfq-si'></i></th>
                        <th>Suppliers Invited</th>
                        <th class="rfq-sort" data-col="respCount">Response Count <i class='bx bx-sort rfq-si'></i></th>
                        <th class="rfq-sort" data-col="deadline">Deadline <i class='bx bx-sort rfq-si'></i></th>
                        <th class="rfq-sort" data-col="status">Status <i class='bx bx-sort rfq-si'></i></th>
                        <?php if ($rfqRoleRank >= 3): ?>
                        <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="tb"></tbody>
            </table>
        </div>
        <div class="rfq-pag" id="pag"></div>
    </div>

</div>
</main>

<div class="rfq-ov" id="rfqOv"></div>

<?php if ($pCan['create']): ?>
<div id="panel">
    <div class="p-hd">
        <div><div class="p-t" id="pT">Create RFQ</div><div class="p-s" id="pS">Fill in the details below</div></div>
        <button class="p-cl" id="pCl"><i class='bx bx-x'></i></button>
    </div>
    <div class="p-body">
        <div class="rfq-sdv">RFQ Details</div>
        <div class="rfq-fr">
            <div class="rfq-fg">
                <label class="rfq-fl">RFQ Number</label>
                <input type="text" class="rfq-fi" id="fRfq" placeholder="Auto-generated" readonly style="background:var(--bg);color:var(--text-3)">
            </div>
            <div class="rfq-fg">
                <label class="rfq-fl">PR Reference <span>*</span></label>
                <input type="text" class="rfq-fi" id="fPr" list="prList" placeholder="Search or select PR…">
                <datalist id="prList"></datalist>
            </div>
        </div>
        <div class="rfq-fg" style="margin-bottom:16px;">
            <label class="rfq-fl">Department <span>*</span></label>
            <select class="rfq-fs" id="fDp">
                <option value="">Select…</option>
                <option>Operations</option><option>Maintenance</option><option>Safety</option>
                <option>Procurement</option><option>Logistics</option><option>Finance</option>
                <option>Admin</option><option>IT</option>
            </select>
        </div>
        <div class="rfq-fr">
            <div class="rfq-fg">
                <label class="rfq-fl">Date Issued</label>
                <input type="date" class="rfq-fi" id="fDi">
            </div>
            <div class="rfq-fg">
                <label class="rfq-fl">Deadline <span>*</span></label>
                <input type="date" class="rfq-fi" id="fDl">
            </div>
        </div>
        <div class="rfq-fr">
            <div class="rfq-fg">
                <label class="rfq-fl">Status</label>
                <select class="rfq-fs" id="fSt">
                    <option value="Draft">Save as Draft</option>
                    <option value="Sent">Send to Suppliers</option>
                </select>
            </div>
            <div class="rfq-fg">
                <label class="rfq-fl">Assigned Evaluator</label>
                <input type="text" class="rfq-fi" id="fEv" placeholder="e.g. Maria Santos">
            </div>
        </div>
        <div class="rfq-fg">
            <label class="rfq-fl">Items / Description <span>*</span></label>
            <textarea class="rfq-fta" id="fIt" placeholder="Describe the items or services being quoted…"></textarea>
        </div>
        <div class="rfq-fg">
            <label class="rfq-fl">Suppliers Invited <span>*</span></label>
            <div class="sup-picker">
                <div class="sup-picker-srch">
                    <i class='bx bx-search'></i>
                    <input type="text" id="supPickerSrch" placeholder="Search suppliers…">
                </div>
                <div class="sup-picker-list" id="supPickerList"></div>
            </div>
            <div class="sel-tags" id="selectedTags" style="margin-top:8px"></div>
        </div>
        <div class="rfq-fg">
            <label class="rfq-fl">Notes / Remarks</label>
            <textarea class="rfq-fta" id="fNo" placeholder="Any special instructions or notes…" style="min-height:60px"></textarea>
        </div>
        <?php if ($pCan['override']): ?>
        <div class="sa-section">
            <div class="sa-hd"><i class='bx bx-shield-quarter'></i><span>Super Admin Controls</span></div>
            <div class="rfq-fg">
                <label class="rfq-fl">Override Reason</label>
                <input type="text" class="rfq-fi" id="fOv" placeholder="State reason for override…">
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="p-ft">
        <button class="rfq-btn rfq-btn-g" id="pCa">Cancel</button>
        <button class="rfq-btn rfq-btn-p" id="pSv"><i class='bx bx-check'></i> Save RFQ</button>
    </div>
</div>
<?php endif; ?>

<div id="modal">
    <div class="rfq-mbox">
        <div class="m-hd">
            <div class="m-top">
                <div class="m-ti">
                    <div class="m-ic"><i class='bx bx-file-find'></i></div>
                    <div><div class="m-nm" id="mNm"></div><div class="m-id" id="mId"></div></div>
                </div>
                <button class="m-cl" id="mCl"><i class='bx bx-x'></i></button>
            </div>
            <div class="m-meta" id="mMt"></div>
            <div class="m-tabs">
                <button class="m-tab active" data-t="ov">Overview</button>
                <button class="m-tab" data-t="resp">Responses</button>
                <button class="m-tab" data-t="sups">Suppliers</button>
                <button class="m-tab" data-t="au">Audit Log</button>
            </div>
        </div>
        <div class="m-body">
            <div class="m-tp active" id="tp-ov"></div>
            <div class="m-tp" id="tp-resp"></div>
            <div class="m-tp" id="tp-sups"></div>
            <div class="m-tp" id="tp-au"></div>
        </div>
        <div class="m-ft" id="mFt">
            <!-- Buttons injected by JS based on role + RFQ status -->
            <button class="rfq-btn rfq-btn-g rfq-btn-s" id="mClose"><i class='bx bx-x'></i> Dismiss</button>
        </div>
    </div>
</div>

<?php if ($pCan['extend']): ?>
<div id="extModal">
    <div class="ext-box">
        <div class="ext-hd">
            <div class="ext-hd-ic" style="background:#FEF3C7;color:var(--warning)"><i class='bx bx-calendar-plus'></i></div>
            <div><div class="ext-hd-title">Extend RFQ Deadline</div><div class="ext-hd-sub" id="extSub">Select a new deadline date</div></div>
        </div>
        <div class="ext-body">
            <div class="rfq-fg">
                <label class="rfq-fl">Current Deadline</label>
                <div style="font-size:14px;font-weight:600;color:var(--text-1);margin-bottom:12px" id="extCur">—</div>
                <label class="rfq-fl">New Deadline <span style="color:var(--danger)">*</span></label>
                <input type="date" class="rfq-fi" id="extDate">
            </div>
            <div class="rfq-fg" style="margin-top:12px">
                <label class="rfq-fl">Reason for Extension</label>
                <textarea class="rfq-fta" id="extReason" placeholder="State reason for extending the deadline…" style="min-height:60px"></textarea>
            </div>
        </div>
        <div class="ext-ft">
            <button class="rfq-btn rfq-btn-g rfq-btn-s" id="extCancel">Cancel</button>
            <button class="rfq-btn rfq-btn-warn rfq-btn-s" id="extConfirm"><i class='bx bx-check'></i> Extend Deadline</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="cfmModal">
    <div class="cfm-box">
        <div class="cfm-hd">
            <div class="cfm-hd-ic" id="cfmIc"></div>
            <div><div class="cfm-hd-title" id="cfmTitle">Confirm Action</div><div class="cfm-hd-sub" id="cfmSub"></div></div>
        </div>
        <div class="cfm-body" id="cfmBody"></div>
        <div class="rfq-fg" id="cfmReasonWrap" style="padding:0 24px 4px;display:none;background:#FFFFFF">
            <label class="rfq-fl" style="color:#5D6F62">Reason <span style="color:#DC2626">*</span></label>
            <textarea class="rfq-fta" id="cfmReason" placeholder="State your reason…" style="min-height:72px;margin-top:6px"></textarea>
        </div>
        <div class="cfm-ft">
            <button class="rfq-btn rfq-btn-g rfq-btn-s" id="cfmCancel">Cancel</button>
            <button class="rfq-btn rfq-btn-s" id="cfmConfirm">Confirm</button>
        </div>
    </div>
</div>

<?php if ($pCan['manageSups']): ?>
<div id="supModal">
    <div class="sup-mbox2">
        <div class="sup-m2-hd">
            <div>
                <div style="font-size:16px;font-weight:700;color:var(--text-1)">Manage Suppliers</div>
                <div style="font-size:12px;color:var(--text-2);margin-top:2px" id="supModalSub">Add or remove suppliers for this RFQ</div>
            </div>
            <button class="p-cl" id="supModalCl"><i class='bx bx-x'></i></button>
        </div>
        <div class="sup-m2-body">
            <div class="sup-picker">
                <div class="sup-picker-srch">
                    <i class='bx bx-search'></i>
                    <input type="text" id="supModal2Srch" placeholder="Search suppliers…">
                </div>
                <div class="sup-picker-list" id="supModal2List"></div>
            </div>
        </div>
        <div class="sup-m2-ft">
            <button class="rfq-btn rfq-btn-g rfq-btn-s" id="supModalCancel">Cancel</button>
            <button class="rfq-btn rfq-btn-p rfq-btn-s" id="supModalSave"><i class='bx bx-check'></i> Save Changes</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="rfqTw"></div>

<script>
// ── SERVER-SIDE PERMISSIONS (passed from PHP) ─────────────────────────────────
const PERMS  = <?= $jsPerms ?>;
const ROLE   = <?= $jsRole ?>;
const UZONE  = <?= $jsZone ?>;

// ── UTILS ─────────────────────────────────────────────────────────────────────
const COLS=['#2E7D32','#1B5E20','#388E3C','#0D9488','#2563EB','#7C3AED','#D97706','#DC2626','#0891B2','#059669'];
const gc =n=>{let h=0;for(const c of n)h=(h*31+c.charCodeAt(0))%COLS.length;return COLS[h]};
const ini=n=>n.split(' ').map(w=>w[0]).join('').substring(0,2).toUpperCase();
const esc=s=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmtDate=d=>d?new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'}):'—';
const todayStr=()=>new Date().toISOString().split('T')[0];
const daysLeft=d=>Math.ceil((new Date(d)-new Date())/86400000);

// ── STATE ─────────────────────────────────────────────────────────────────────
let SUPPLIERS=[], D=[], PRS=[];
let sortCol='dateIssued',sortDir='desc',pg=1,PG=8;
let editId=null,viewId=null,extTargetId=null,supModalTargetId=null;
let cfmAction=null,cfmTargetId=null;
let panelSelIds=[],supModal2SelIds=[];
let pickerQ='',picker2Q='';

// ── API ───────────────────────────────────────────────────────────────────────
async function apiFetch(path,opts={}){
    const r=await fetch(path,{headers:{'Content-Type':'application/json'},...opts});
    const j=await r.json();
    if(!j.success)throw new Error(j.error||'Request failed');
    return j.data;
}
const apiGet=path=>apiFetch(path);
const apiPost=(path,body)=>apiFetch(path,{method:'POST',body:JSON.stringify(body)});

async function loadAll(){
    try{
        const calls=[apiGet('rfq.php?api=suppliers'),apiGet('rfq.php?api=list')];
        if(PERMS.create) calls.push(apiGet('rfq.php?api=prs'));
        const res=await Promise.all(calls);
        SUPPLIERS=res[0]; D=res[1]; PRS=res[2]||[];
    }catch(e){toast('Failed to load data: '+e.message,'danger');}
    if(PERMS.create){ rPrSelect(); rPickerList(); }
    render();
}

// ── LOOKUP ────────────────────────────────────────────────────────────────────
const supById=id=>SUPPLIERS.find(s=>s.id===id);
const rfqById=id=>D.find(r=>r.id===id);
const chipCls=s=>({Draft:'rc-draft',Sent:'rc-sent',Responded:'rc-responded',Closed:'rc-closed',Cancelled:'rc-cancelled'}[s]||'rc-draft');
const chip=s=>`<span class="rfq-chip ${chipCls(s)}">${s}</span>`;
const dlDisp=(dl,status)=>{
    if(['Closed','Cancelled'].includes(status))return`<span style="color:var(--text-3)">${fmtDate(dl)}</span>`;
    const d=daysLeft(dl);
    if(d<0)return`<span class="dl-over">${fmtDate(dl)} <span style="font-size:10px">(Overdue)</span></span>`;
    if(d<=3)return`<span class="dl-near">${fmtDate(dl)} <span style="font-size:10px">(${d}d left)</span></span>`;
    return`<span>${fmtDate(dl)} <span style="font-size:10px;color:var(--text-3)">(${d}d)</span></span>`;
};
const respDisp=rfq=>{
    const cnt=rfq.responses.length,tot=rfq.supplierIds.length;
    if(!tot)return'<span style="color:var(--text-3);font-size:12px">—</span>';
    const cls=cnt===0?'rcd-none':cnt<tot?'rcd-part':'rcd-full';
    return`<div class="resp-cnt"><div class="rcd ${cls}"></div>${cnt} / ${tot}</div>`;
};
const supAvs=ids=>{
    if(!ids.length)return'<span style="color:var(--text-3);font-size:12px">None</span>';
    let h='<div class="sup-avs">';
    ids.slice(0,3).forEach(id=>{const s=supById(id);if(!s)return;h+=`<div class="sup-av-xs" style="background:${gc(s.name)}" title="${esc(s.name)}">${ini(s.name)}</div>`;});
    if(ids.length>3)h+=`<div class="sup-av-xs sup-av-more">+${ids.length-3}</div>`;
    return h+'</div>';
};

// ── FILTER / SORT / RENDER ────────────────────────────────────────────────────
function gFilt(){
    const q=document.getElementById('srch').value.trim().toLowerCase();
    const st=document.getElementById('fStat').value;
    const dp=document.getElementById('fDept').value;
    const brEl=document.getElementById('fBranch');
    const br=brEl?brEl.value:'';
    const df=document.getElementById('fDateFrom').value;
    const dt=document.getElementById('fDateTo').value;
    return D.filter(r=>{
        if(q&&!r.rfqNo.toLowerCase().includes(q)&&!r.prRef.toLowerCase().includes(q)&&!r.dept.toLowerCase().includes(q)&&!r.items.toLowerCase().includes(q)&&!r.supplierIds.some(id=>{const s=supById(id);return s&&s.name.toLowerCase().includes(q);}))return false;
        if(st&&r.status!==st)return false;
        if(dp&&r.dept!==dp)return false;
        if(br&&r.branch!==br)return false;
        if(df&&r.dateIssued<df)return false;
        if(dt&&r.dateIssued>dt)return false;
        return true;
    });
}
function gSort(list){
    return[...list].sort((a,b)=>{
        let va=a[sortCol]??'',vb=b[sortCol]??'';
        if(sortCol==='respCount'){va=a.responses.length;vb=b.responses.length;}
        if(typeof va==='number')return sortDir==='asc'?va-vb:vb-va;
        return sortDir==='asc'?String(va).localeCompare(String(vb)):String(vb).localeCompare(String(va));
    });
}
function render(){rStats();rFiltDropdowns();rTable();}

function rStats(){
    const tot=D.length,draft=D.filter(r=>r.status==='Draft').length,sent=D.filter(r=>r.status==='Sent').length;
    const responded=D.filter(r=>r.status==='Responded').length,closed=D.filter(r=>r.status==='Closed').length,cancelled=D.filter(r=>r.status==='Cancelled').length;
    // Manager sees fewer stat boxes (no closed/cancelled)
    const isManager=(ROLE==='Manager'||ROLE==='Staff');
    let html=`<div class="rfq-stat"><div class="rfq-sc-ic ic-g"><i class='bx bx-file'></i></div><div><div class="rfq-sv">${tot}</div><div class="rfq-sl">Total RFQs</div></div></div>`;
    if(!isManager)html+=`<div class="rfq-stat"><div class="rfq-sc-ic ic-o"><i class='bx bx-pencil'></i></div><div><div class="rfq-sv">${draft}</div><div class="rfq-sl">Draft</div></div></div>`;
    html+=`<div class="rfq-stat"><div class="rfq-sc-ic ic-b"><i class='bx bx-send'></i></div><div><div class="rfq-sv">${sent}</div><div class="rfq-sl">Sent</div></div></div>`;
    html+=`<div class="rfq-stat"><div class="rfq-sc-ic ic-g"><i class='bx bx-message-square-check'></i></div><div><div class="rfq-sv">${responded}</div><div class="rfq-sl">Responded</div></div></div>`;
    if(!isManager){
        html+=`<div class="rfq-stat"><div class="rfq-sc-ic ic-t"><i class='bx bx-lock-alt'></i></div><div><div class="rfq-sv">${closed}</div><div class="rfq-sl">Closed</div></div></div>`;
        html+=`<div class="rfq-stat"><div class="rfq-sc-ic ic-r"><i class='bx bx-x-circle'></i></div><div><div class="rfq-sv">${cancelled}</div><div class="rfq-sl">Cancelled</div></div></div>`;
    }
    document.getElementById('statsRow').innerHTML=html;
}

function rFiltDropdowns(){
    const depts=[...new Set(D.map(r=>r.dept))].sort();
    const dpEl=document.getElementById('fDept'),dpV=dpEl.value;
    dpEl.innerHTML='<option value="">All Departments</option>'+depts.map(d=>`<option${d===dpV?' selected':''}>${esc(d)}</option>`).join('');

    const brEl=document.getElementById('fBranch');
    if(brEl){
        const branches=[...new Set(D.map(r=>r.branch).filter(Boolean))].sort();
        const brV=brEl.value;
        brEl.innerHTML='<option value="">All Branches</option>'+branches.map(b=>`<option${b===brV?' selected':''}>${esc(b)}</option>`).join('');
    }
}

function rTable(){
    const list=gSort(gFilt()),total=list.length,pages=Math.max(1,Math.ceil(total/PG));
    if(pg>pages)pg=pages;
    const sl=list.slice((pg-1)*PG,pg*PG);
    const tb=document.getElementById('tb');
    const hasActionsCol=PERMS.send||PERMS.close||PERMS.cancel||PERMS.override;
    tb.innerHTML=sl.length?sl.map((r,i)=>{
        const rn=(pg-1)*PG+i+1,over=!['Closed','Cancelled'].includes(r.status)&&daysLeft(r.deadline)<0;
        // Build inline action buttons — only for roles that can act
        let acts='';
        if(hasActionsCol){
            if(PERMS.send&&r.status==='Draft')acts+=`<button class="rfq-btn rfq-btn-info rfq-btn-s" onclick="doSend(${r.id})" title="Send RFQ"><i class='bx bx-send'></i></button> `;
            if(PERMS.close&&!['Closed','Cancelled'].includes(r.status))acts+=`<button class="rfq-btn rfq-btn-g rfq-btn-s" onclick="doClose(${r.id})" title="Close RFQ"><i class='bx bx-lock-alt'></i></button> `;
            if(PERMS.cancel&&!['Cancelled','Closed'].includes(r.status))acts+=`<button class="rfq-btn rfq-btn-danger rfq-btn-s" onclick="doCancel(${r.id})" title="Cancel RFQ"><i class='bx bx-x-circle'></i></button> `;
            if(PERMS.override&&r.status==='Closed')acts+=`<button class="rfq-btn rfq-btn-p rfq-btn-s" onclick="doOverride(${r.id})" title="SA Override"><i class='bx bx-shield-quarter'></i></button> `;
            if(PERMS.create)acts+=`<button class="rfq-btn rfq-btn-g rfq-btn-s" onclick="oEd(${r.id})" title="Edit"><i class='bx bx-edit-alt'></i></button>`;
        }
        return`<tr data-id="${r.id}" class="${over?'overdue-row':''}${!hasActionsCol?' view-only':''}">
            <td style="color:#5D6F62;font-size:12px;font-weight:600">${rn}</td>
            <td><div class="rfq-num">${esc(r.rfqNo)}</div></td>
            <td><div style="font-size:13px;font-weight:500">${esc(r.prRef)}</div><div class="cell-meta">${esc(r.dept)}</div></td>
            <td><div style="font-size:13px">${fmtDate(r.dateIssued)}</div>${r.sentBy?`<div class="cell-meta">By ${esc(r.sentBy)}</div>`:'<div class="cell-meta">—</div>'}</td>
            <td>${supAvs(r.supplierIds)}<div class="cell-meta">${r.supplierIds.length} supplier${r.supplierIds.length!==1?'s':''}</div></td>
            <td>${respDisp(r)}</td>
            <td>${dlDisp(r.deadline,r.status)}</td>
            <td>${chip(r.status)}</td>
            ${hasActionsCol?`<td onclick="event.stopPropagation()" style="white-space:nowrap">${acts}</td>`:''}
        </tr>`;
    }).join(''):`<tr><td colspan="${hasActionsCol?9:8}"><div class="rfq-empty"><i class='bx bx-file-find'></i><p>No RFQs match your filters.</p></div></td></tr>`;

    document.querySelectorAll('thead th.rfq-sort').forEach(th=>{
        th.classList.toggle('rfq-sorted',th.dataset.col===sortCol);
        th.querySelector('.rfq-si').className=th.dataset.col===sortCol?`bx ${sortDir==='asc'?'bx-sort-up':'bx-sort-down'} rfq-si`:'bx bx-sort rfq-si';
    });
    rPag(total,pages);
}
function rPag(total,pages){
    const el=document.getElementById('pag'),s=(pg-1)*PG+1,e=Math.min(pg*PG,total);
    let btns='';
    for(let i=1;i<=pages;i++){
        if(i===1||i===pages||(i>=pg-2&&i<=pg+2))btns+=`<button class="rfq-pb${i===pg?' active':''}" onclick="goPg(${i})">${i}</button>`;
        else if(i===pg-3||i===pg+3)btns+=`<button class="rfq-pb" disabled>…</button>`;
    }
    el.innerHTML=`<span>${total?`Showing ${s}–${e} of ${total} RFQs`:'No results'}</span><div class="rfq-pbtns"><button class="rfq-pb" onclick="goPg(${pg-1})" ${pg<=1?'disabled':''}><i class='bx bx-chevron-left'></i></button>${btns}<button class="rfq-pb" onclick="goPg(${pg+1})" ${pg>=pages?'disabled':''}><i class='bx bx-chevron-right'></i></button></div>`;
}
window.goPg=p=>{pg=p;rTable();};
document.querySelectorAll('thead th.rfq-sort').forEach(th=>{
    th.addEventListener('click',()=>{
        const c=th.dataset.col;sortCol===c?(sortDir=sortDir==='asc'?'desc':'asc'):(sortCol=c,sortDir='asc');pg=1;rTable();
    });
});
['srch','fStat','fDept','fDateFrom','fDateTo'].forEach(id=>{const el=document.getElementById(id);if(el)el.addEventListener('input',()=>{pg=1;rTable();});});
const brEl=document.getElementById('fBranch');if(brEl)brEl.addEventListener('input',()=>{pg=1;rTable();});
document.getElementById('clearDates').addEventListener('click',()=>{document.getElementById('fDateFrom').value='';document.getElementById('fDateTo').value='';pg=1;rTable();});
document.getElementById('tb').addEventListener('click',function(e){
    const tr=e.target.closest('tr[data-id]');
    if(!tr||e.target.closest('button'))return;
    oPr(parseInt(tr.dataset.id));
});

// ── SUPPLIER PICKER (create/edit panel) ───────────────────────────────────────
function rPickerList(){
    const q=pickerQ.toLowerCase();
    const sups=SUPPLIERS.filter(s=>!q||s.name.toLowerCase().includes(q)||s.cat.toLowerCase().includes(q));
    const el=document.getElementById('supPickerList');
    if(!el)return;
    el.innerHTML=sups.length?sups.map(s=>{
        const sel=panelSelIds.includes(s.id);
        return`<div class="sup-picker-item${sel?' selected':''}" data-sid="${s.id}"><div class="sup-picker-av" style="background:${gc(s.name)}">${ini(s.name)}</div><div style="flex:1"><div class="sup-picker-nm">${esc(s.name)}</div><div class="sup-picker-cat">${esc(s.cat)}</div></div><div class="sup-picker-chk"></div></div>`;
    }).join(''):'<div class="sup-picker-empty">No suppliers found.</div>';
    el.querySelectorAll('.sup-picker-item[data-sid]').forEach(item=>{
        item.addEventListener('click',()=>{
            const sid=parseInt(item.dataset.sid);
            panelSelIds.includes(sid)?panelSelIds=panelSelIds.filter(x=>x!==sid):panelSelIds.push(sid);
            rPickerList();rSelectedTags();
        });
    });
}
function rSelectedTags(){
    const el=document.getElementById('selectedTags');if(!el)return;
    el.innerHTML=panelSelIds.map(id=>{
        const s=supById(id);if(!s)return'';
        return`<div class="stag"><div class="sup-av-xs" style="background:${gc(s.name)};width:16px;height:16px;border:none;margin:0;font-size:8px">${ini(s.name)}</div>${esc(s.name)}<button onclick="removeTag(${s.id})">×</button></div>`;
    }).join('');
}
window.removeTag=id=>{panelSelIds=panelSelIds.filter(x=>x!==id);rPickerList();rSelectedTags();};
const spSrch=document.getElementById('supPickerSrch');
if(spSrch)spSrch.addEventListener('input',function(){pickerQ=this.value.trim();rPickerList();});

// ── SLIDE PANEL ───────────────────────────────────────────────────────────────
function oPn(){document.getElementById('panel').classList.add('open');document.getElementById('rfqOv').classList.add('show');}
function cPn(){
    const p=document.getElementById('panel');
    if(p)p.classList.remove('open');
    document.getElementById('rfqOv').classList.remove('show');
    editId=null;
}
document.getElementById('rfqOv').addEventListener('click',()=>{if(document.getElementById('panel'))cPn();});

if(PERMS.create){
    const pCl=document.getElementById('pCl'),pCa=document.getElementById('pCa');
    if(pCl)pCl.addEventListener('click',cPn);
    if(pCa)pCa.addEventListener('click',cPn);

    const addBtn=document.getElementById('addBtn');
    if(addBtn)addBtn.addEventListener('click',async()=>{
        editId=null;clrF();
        document.getElementById('pT').textContent='Create RFQ';
        document.getElementById('pS').textContent='Fill in the details below';
        document.getElementById('pSv').innerHTML='<i class="bx bx-plus"></i> Create RFQ';
        document.getElementById('fDi').value=todayStr();
        document.getElementById('fRfq').value='Loading…';
        oPn();document.getElementById('fPr').focus();
        try{const d=await apiGet('rfq.php?api=next_no');document.getElementById('fRfq').value=d.rfqNo;}
        catch(e){document.getElementById('fRfq').value='RFQ-????';}
    });

    // PR auto-fill
    document.getElementById('fPr').addEventListener('input',function(){
        const pr=PRS.find(p=>p.pr_number===this.value);
        if(pr){const dp=document.getElementById('fDp');const opts=Array.from(dp.options).map(o=>o.value);if(opts.includes(pr.department))dp.value=pr.department;}
    });

    document.getElementById('pSv').addEventListener('click',async()=>{
        const rfqNo=document.getElementById('fRfq').value.trim();
        const prRef=document.getElementById('fPr').value.trim();
        const dept=document.getElementById('fDp').value;
        const dl=document.getElementById('fDl').value;
        const items=document.getElementById('fIt').value.trim();
        if(!prRef){shk('fPr');return toast('PR Reference is required','danger');}
        if(!dept){shk('fDp');return toast('Please select a department','danger');}
        if(!dl){shk('fDl');return toast('Deadline is required','danger');}
        if(!items){shk('fIt');return toast('Items / description is required','danger');}
        if(!panelSelIds.length)return toast('Please select at least one supplier','danger');
        const btn=document.getElementById('pSv');btn.disabled=true;
        const fOvEl=document.getElementById('fOv');
        const payload={prRef,dept,dateIssued:document.getElementById('fDi').value||todayStr(),deadline:dl,status:document.getElementById('fSt').value,items,notes:document.getElementById('fNo').value.trim(),supplierIds:[...panelSelIds],override:fOvEl?fOvEl.value.trim():'',evaluator:document.getElementById('fEv').value.trim()};
        if(editId)payload.id=editId;
        try{
            await apiPost('rfq.php?api=save',payload);
            toast(`"${rfqNo}" ${editId?'updated':'created'}`,'success');
            cPn();
            D=await apiGet('rfq.php?api=list');render();
        }catch(e){toast(e.message,'danger');}
        finally{btn.disabled=false;}
    });
}

function rPrSelect(forceVal=''){
    const el=document.getElementById('prList');if(!el)return;
    el.innerHTML=PRS.map(p=>`<option value="${esc(p.pr_number)}">${esc(p.department)}</option>`).join('');
    if(forceVal){const fPr=document.getElementById('fPr');if(fPr)fPr.value=forceVal;}
}
function oEd(id){
    if(!PERMS.create)return;
    const r=rfqById(id);if(!r)return;editId=id;
    document.getElementById('fRfq').value=r.rfqNo;
    rPrSelect(r.prRef);
    document.getElementById('fDp').value=r.dept;
    document.getElementById('fDi').value=r.dateIssued;
    document.getElementById('fDl').value=r.deadline;
    document.getElementById('fSt').value=['Draft','Sent'].includes(r.status)?r.status:'Sent';
    document.getElementById('fIt').value=r.items;
    document.getElementById('fNo').value=r.notes||'';
    const fOvEl=document.getElementById('fOv');if(fOvEl)fOvEl.value=r.override||'';
    document.getElementById('fEv').value=r.evaluator||'';
    panelSelIds=[...r.supplierIds];pickerQ='';
    const spSrch2=document.getElementById('supPickerSrch');if(spSrch2)spSrch2.value='';
    rPickerList();rSelectedTags();
    document.getElementById('pT').textContent='Edit RFQ';
    document.getElementById('pS').textContent=r.rfqNo;
    document.getElementById('pSv').innerHTML='<i class="bx bx-check"></i> Save Changes';
    oPn();
}
window.oEd=oEd;
function clrF(){
    ['fDi','fDl','fIt','fNo','fEv'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    const fOv=document.getElementById('fOv');if(fOv)fOv.value='';
    const fPr=document.getElementById('fPr');if(fPr)fPr.value='';
    rPrSelect();
    const fDp=document.getElementById('fDp');if(fDp)fDp.value='';
    const fSt=document.getElementById('fSt');if(fSt)fSt.value='Draft';
    panelSelIds=[];pickerQ='';
    const spSrch2=document.getElementById('supPickerSrch');if(spSrch2)spSrch2.value='';
    rPickerList();rSelectedTags();
}

// ── CONFIRM MODAL ─────────────────────────────────────────────────────────────
window.doSend=id=>{if(PERMS.send)openCfm(id,'send');};
window.doClose=id=>{if(PERMS.close)openCfm(id,'close');};
window.doCancel=id=>{if(PERMS.cancel)openCfm(id,'cancel');};
window.doOverride=id=>{if(PERMS.override)openCfm(id,'override');};
function openCfm(id,action){
    const r=rfqById(id);if(!r)return;cfmTargetId=id;cfmAction=action;
    const cfg={
        send:{ic:'bx-send',bg:'#EFF6FF',fc:'var(--info)',title:'Send RFQ',sub:`Send "${r.rfqNo}" to suppliers?`,body:`This will notify all <strong>${r.supplierIds.length} invited supplier(s)</strong> to submit quotations. Status will change to <strong>Sent</strong>.`,btnCls:'rfq-btn-info',btnTxt:'<i class="bx bx-send"></i> Send RFQ',reason:false},
        close:{ic:'bx-lock-alt',bg:'#FEF3C7',fc:'var(--warning)',title:'Close RFQ',sub:`Close "${r.rfqNo}"?`,body:'Closing this RFQ will mark it as <strong>Closed</strong>. No further quotations will be accepted.',btnCls:'rfq-btn-warn',btnTxt:'<i class="bx bx-lock-alt"></i> Close RFQ',reason:true},
        cancel:{ic:'bx-x-circle',bg:'#FEE2E2',fc:'var(--danger)',title:'Cancel RFQ',sub:`Cancel "${r.rfqNo}"?`,body:'This will permanently mark the RFQ as <strong>Cancelled</strong>. This cannot be undone without a Super Admin override.',btnCls:'rfq-btn-danger',btnTxt:'<i class="bx bx-x-circle"></i> Cancel RFQ',reason:true},
        override:{ic:'bx-shield-quarter',bg:'rgba(46,125,50,.1)',fc:'var(--primary)',title:'Override Closed RFQ',sub:`Override "${r.rfqNo}"?`,body:'As <strong>Super Admin</strong>, you may override this closed RFQ to re-open it for further action. Please state your reason below.',btnCls:'rfq-btn-p',btnTxt:'<i class="bx bx-shield-quarter"></i> Apply Override',reason:true},
    };
    const c=cfg[action];
    document.getElementById('cfmIc').style.cssText=`background:${c.bg};color:${c.fc}`;
    document.getElementById('cfmIc').innerHTML=`<i class='bx ${c.ic}'></i>`;
    document.getElementById('cfmTitle').textContent=c.title;
    document.getElementById('cfmSub').textContent=c.sub;
    document.getElementById('cfmBody').innerHTML=c.body;
    document.getElementById('cfmReasonWrap').style.display=c.reason?'block':'none';
    document.getElementById('cfmReason').value='';
    const btn=document.getElementById('cfmConfirm');
    btn.className=`rfq-btn rfq-btn-s ${c.btnCls}`;btn.innerHTML=c.btnTxt;
    document.getElementById('cfmModal').classList.add('show');
}
function closeCfm(){document.getElementById('cfmModal').classList.remove('show');cfmAction=null;cfmTargetId=null;}
document.getElementById('cfmCancel').addEventListener('click',closeCfm);
document.getElementById('cfmModal').addEventListener('click',function(e){if(e.target===this)closeCfm();});
document.getElementById('cfmConfirm').addEventListener('click',async()=>{
    const r=rfqById(cfmTargetId);if(!r)return;
    const reasonEl=document.getElementById('cfmReason');
    const needReason=document.getElementById('cfmReasonWrap').style.display!=='none';
    if(needReason&&!reasonEl.value.trim()){shk('cfmReason');return toast('Please state a reason','danger');}
    const reason=reasonEl.value.trim();
    const btn=document.getElementById('cfmConfirm');btn.disabled=true;
    try{
        await apiPost('rfq.php?api=action',{id:cfmTargetId,type:cfmAction,reason});
        const msgs={send:`"${r.rfqNo}" sent to ${r.supplierIds.length} supplier(s)`,close:`"${r.rfqNo}" closed`,cancel:`"${r.rfqNo}" cancelled`,override:`Override applied to "${r.rfqNo}"`};
        const typs={send:'info',close:'warning',cancel:'danger',override:'success'};
        toast(msgs[cfmAction]||'Done',typs[cfmAction]||'success');
        closeCfm();
        D=await apiGet('rfq.php?api=list');
        if(viewId===cfmTargetId){const up=rfqById(viewId);if(up)oPr(viewId);}
        render();
    }catch(e){toast(e.message,'danger');}
    finally{btn.disabled=false;}
});

// ── VIEW MODAL ────────────────────────────────────────────────────────────────
function oPr(id){
    const r=rfqById(id);if(!r)return;viewId=id;
    document.getElementById('mNm').textContent=r.rfqNo;
    document.getElementById('mId').innerHTML=`${esc(r.prRef)} &nbsp;${chip(r.status)}&nbsp;<span style="background:#F3F4F6;padding:2px 8px;border-radius:6px;font-size:11px">${esc(r.dept)}</span>`;
    document.getElementById('mMt').innerHTML=`
        <div class="m-mc"><i class='bx bx-layer'></i>${esc(r.dept)}</div>
        <div class="m-mc"><i class='bx bx-calendar'></i>Issued ${fmtDate(r.dateIssued)}</div>
        <div class="m-mc"><i class='bx bx-calendar-x'></i>Deadline ${fmtDate(r.deadline)}</div>
        <div class="m-mc"><i class='bx bx-user-circle'></i>${r.supplierIds.length} Supplier${r.supplierIds.length!==1?'s':''}</div>
        <div class="m-mc"><i class='bx bx-message-square-detail'></i>${r.responses.length} Response${r.responses.length!==1?'s':''}</div>
        ${r.sentBy?`<div class="m-mc"><i class='bx bx-send'></i>Sent by ${esc(r.sentBy)}</div>`:''}
        ${r.evaluator?`<div class="m-mc"><i class='bx bx-user-check'></i>Evaluator: ${esc(r.evaluator)}</div>`:''}`;

    const bestResp=r.responses.length?r.responses.reduce((mn,x)=>x.amt<mn.amt?x:mn):null;
    document.getElementById('tp-ov').innerHTML=`
        <div class="stat-boxes">
            <div class="sbox"><div class="sbv">${r.supplierIds.length}</div><div class="sbl">Invited</div></div>
            <div class="sbox"><div class="sbv">${r.responses.length}</div><div class="sbl">Responses</div></div>
            <div class="sbox"><div class="sbv">${bestResp?'&#8369;'+bestResp.amt.toLocaleString():'—'}</div><div class="sbl">Lowest Quote</div></div>
            <div class="sbox"><div class="sbv">${daysLeft(r.deadline)>0?daysLeft(r.deadline)+'d':'—'}</div><div class="sbl">Days Left</div></div>
        </div>
        <div class="info-grid">
            <div class="info-item"><label>RFQ Number</label><div class="v" style="font-family:'DM Mono',monospace">${esc(r.rfqNo)}</div></div>
            <div class="info-item"><label>PR Reference</label><div class="v" style="font-family:'DM Mono',monospace">${esc(r.prRef)}</div></div>
            <div class="info-item"><label>Department</label><div class="v">${esc(r.dept)}</div></div>
            <div class="info-item"><label>Status</label><div class="v">${chip(r.status)}</div></div>
            <div class="info-item"><label>Date Issued</label><div class="v">${fmtDate(r.dateIssued)}</div></div>
            <div class="info-item full"><label>Deadline</label><div class="v">${dlDisp(r.deadline,r.status)}</div></div>
            <div class="info-item full"><label>Items / Description</label><div class="v">${esc(r.items)}</div></div>
            ${r.notes?`<div class="info-item full"><label>Notes</label><div class="v">${esc(r.notes)}</div></div>`:''}
            ${r.override&&PERMS.override?`<div class="info-item full"><label>Override Reason</label><div class="v" style="color:var(--warning)">${esc(r.override)}</div></div>`:''}
        </div>`;

    // Responses tab — timestamps only for SA
    const lAmt=bestResp?bestResp.amt:null;
    const tsNote=PERMS.viewTimestamps
        ?`<div style="margin-bottom:12px;padding:10px 14px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;font-size:12px;color:#1D4ED8;display:flex;gap:8px;align-items:flex-start"><i class='bx bx-shield-quarter' style="font-size:16px;flex-shrink:0;margin-top:1px"></i><div><strong>Super Admin:</strong> Full supplier quotations with submission timestamps.</div></div>`
        :`<div style="margin-bottom:12px;padding:10px 14px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;font-size:12px;color:#166534;display:flex;gap:8px"><i class='bx bx-info-circle' style="font-size:16px;flex-shrink:0;margin-top:1px"></i><div>Showing quoted amounts and lead times. Submission timestamps are visible to Super Admin only.</div></div>`;

    const respCols=PERMS.viewTimestamps
        ?'<th>Supplier</th><th>Quoted Amount</th><th>Lead Time</th><th>Notes</th><th>Submitted</th>'
        :'<th>Supplier</th><th>Quoted Amount</th><th>Lead Time</th><th>Notes</th>';

    document.getElementById('tp-resp').innerHTML=tsNote+(r.responses.length?`<table class="resp-tbl"><thead><tr>${respCols}</tr></thead><tbody>${r.responses.map(resp=>{
        const s=supById(resp.supId),isBest=resp.amt===lAmt&&r.responses.length>1;
        const tsCell=PERMS.viewTimestamps?`<td><span class="sa-ts">${esc(resp.ts)}</span></td>`:'';
        return`<tr><td><div style="display:flex;align-items:center"><div class="resp-av" style="background:${gc(s?.name||'')}">${ini(s?.name||'?')}</div><div><div style="font-size:13px;font-weight:600">${esc(s?.name||'Unknown')}</div><div style="font-size:11px;color:var(--text-3)">${esc(s?.cat||'')}</div></div></div></td><td><span class="quote-amt">&#8369;${resp.amt.toLocaleString()}</span>${isBest?`<span class="best-badge"><i class='bx bx-star' style="font-size:10px"></i> Best</span>`:''}</td><td>${resp.leadDays} day${resp.leadDays!==1?'s':''}</td><td style="font-size:12px;color:var(--text-2)">${esc(resp.notes||'—')}</td>${tsCell}</tr>`;
    }).join('')}</tbody></table>`:`<div class="no-resp"><i class='bx bx-message-square-x' style="font-size:24px"></i>No responses received yet.</div>`);

    document.getElementById('tp-sups').innerHTML=r.supplierIds.length?`<div style="display:flex;flex-direction:column;gap:8px">${r.supplierIds.map(id=>{
        const s=supById(id);if(!s)return'';const hasResp=r.responses.some(x=>x.supId===id);
        return`<div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px"><div class="sup-av-xs" style="background:${gc(s.name)};width:34px;height:34px;border-radius:8px;font-size:12px;border:none;margin:0">${ini(s.name)}</div><div style="flex:1"><div style="font-size:13px;font-weight:600">${esc(s.name)}</div><div style="font-size:11px;color:var(--text-3)">${esc(s.cat)}</div></div><span class="rfq-chip ${hasResp?'rc-responded':'rc-draft'}">${hasResp?'Responded':'Pending'}</span></div>`;
    }).join('')}</div>`:`<div class="no-resp"><i class='bx bx-buildings' style="font-size:24px"></i>No suppliers invited.</div>`;

    const logs=r.audit||[];
    const auNote=PERMS.viewTimestamps
        ?`<div style="margin-bottom:12px;padding:10px 14px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;font-size:12px;color:#1D4ED8;display:flex;gap:8px;align-items:flex-start"><i class='bx bx-shield-quarter' style="font-size:16px;flex-shrink:0;margin-top:1px"></i><div><strong>Super Admin:</strong> Full audit trail — who sent and modified each RFQ.</div></div>`
        :'';
    document.getElementById('tp-au').innerHTML=auNote+(logs.length?logs.map(l=>`<div class="audit-item"><div class="audit-dot ${l.t}"></div><div class="audit-body"><div class="au">${esc(l.msg)}</div><div class="at">${l.by?`By ${esc(l.by)} · `:''}${esc(l.ts)}</div></div></div>`).join(''):'<div style="padding:16px;text-align:center;font-size:12px;color:var(--text-3)">No audit entries yet.</div>');

    document.querySelectorAll('.m-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.m-tp').forEach(p=>p.classList.remove('active'));
    document.querySelector('.m-tab[data-t="ov"]').classList.add('active');
    document.getElementById('tp-ov').classList.add('active');

    // Rebuild modal footer buttons based on permissions + RFQ status
    const mFt=document.getElementById('mFt');
    mFt.innerHTML=''; // clear

    const mk=(cls,icon,lbl,cb)=>{const b=document.createElement('button');b.className=`rfq-btn ${cls} rfq-btn-s`;b.innerHTML=`<i class='bx ${icon}'></i> ${lbl}`;b.addEventListener('click',cb);mFt.appendChild(b);};

    if(PERMS.override&&r.status==='Closed')
        mk('rfq-btn-p','bx-shield-quarter','Override',()=>openCfm(viewId,'override'));
    if(PERMS.send&&r.status==='Draft')
        mk('rfq-btn-info','bx-send','Send',()=>openCfm(viewId,'send'));
    if(PERMS.extend&&['Draft','Sent','Responded'].includes(r.status))
        mk('rfq-btn-g','bx-calendar-plus','Extend Deadline',()=>openExt(viewId));
    if(PERMS.manageSups&&!['Closed','Cancelled'].includes(r.status))
        mk('rfq-btn-g','bx-user-plus','Manage Suppliers',()=>openSupModal(viewId));
    if(PERMS.create)
        mk('rfq-btn-g','bx-edit-alt','Edit',()=>{cModal();oEd(viewId);});
    if(PERMS.close&&!['Closed','Cancelled'].includes(r.status))
        mk('rfq-btn-warn','bx-lock-alt','Close',()=>openCfm(viewId,'close'));
    if(PERMS.cancel&&!['Cancelled','Closed'].includes(r.status))
        mk('rfq-btn-danger','bx-x-circle','Cancel',()=>openCfm(viewId,'cancel'));

    // Always present: Dismiss
    mk('rfq-btn-g','bx-x','Dismiss',cModal);

    document.getElementById('modal').classList.add('show');
}
document.querySelectorAll('.m-tab').forEach(t=>t.addEventListener('click',()=>{
    document.querySelectorAll('.m-tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.m-tp').forEach(x=>x.classList.remove('active'));
    t.classList.add('active');document.getElementById('tp-'+t.dataset.t).classList.add('active');
}));
function cModal(){document.getElementById('modal').classList.remove('show');viewId=null;}
document.getElementById('mCl').addEventListener('click',cModal);
document.getElementById('modal').addEventListener('click',function(e){if(e.target===this)cModal();});

// ── EXTEND DEADLINE ───────────────────────────────────────────────────────────
function openExt(id){
    if(!PERMS.extend)return;
    const r=rfqById(id);if(!r)return;extTargetId=id;
    document.getElementById('extSub').textContent=`Extending deadline for ${r.rfqNo}`;
    document.getElementById('extCur').textContent=fmtDate(r.deadline);
    document.getElementById('extDate').value='';document.getElementById('extReason').value='';
    document.getElementById('extModal').classList.add('show');
}
function closeExt(){document.getElementById('extModal').classList.remove('show');extTargetId=null;}
const extCancelBtn=document.getElementById('extCancel');
if(extCancelBtn)extCancelBtn.addEventListener('click',closeExt);
const extModalEl=document.getElementById('extModal');
if(extModalEl){
    extModalEl.addEventListener('click',function(e){if(e.target===this)closeExt();});
    document.getElementById('extConfirm').addEventListener('click',async()=>{
        const nd=document.getElementById('extDate').value;
        if(!nd){shk('extDate');return toast('Please select a new deadline','danger');}
        const r=rfqById(extTargetId);if(!r)return;
        if(new Date(nd)<=new Date(r.deadline))return toast('New deadline must be after current deadline','warning');
        const btn=document.getElementById('extConfirm');btn.disabled=true;
        try{
            await apiPost('rfq.php?api=action',{id:extTargetId,type:'extend',newDeadline:nd,reason:document.getElementById('extReason').value.trim()});
            toast(`Deadline extended to ${fmtDate(nd)}`,'warning');
            closeExt();
            D=await apiGet('rfq.php?api=list');
            if(viewId===extTargetId){oPr(viewId);}
            render();
        }catch(e){toast(e.message,'danger');}
        finally{btn.disabled=false;}
    });
}

// ── MANAGE SUPPLIERS MODAL ────────────────────────────────────────────────────
function openSupModal(id){
    if(!PERMS.manageSups)return;
    const r=rfqById(id);if(!r)return;supModalTargetId=id;supModal2SelIds=[...r.supplierIds];
    document.getElementById('supModalSub').textContent=`Add or remove suppliers for ${r.rfqNo}`;
    document.getElementById('supModal2Srch').value='';picker2Q='';rPicker2();
    document.getElementById('supModal').classList.add('show');
}
function rPicker2(){
    const q=picker2Q.toLowerCase();
    const sups=SUPPLIERS.filter(s=>!q||s.name.toLowerCase().includes(q)||s.cat.toLowerCase().includes(q));
    const el=document.getElementById('supModal2List');if(!el)return;
    el.innerHTML=sups.length?sups.map(s=>{const sel=supModal2SelIds.includes(s.id);return`<div class="sup-picker-item${sel?' selected':''}" data-sid="${s.id}"><div class="sup-picker-av" style="background:${gc(s.name)}">${ini(s.name)}</div><div style="flex:1"><div class="sup-picker-nm">${esc(s.name)}</div><div class="sup-picker-cat">${esc(s.cat)}</div></div><div class="sup-picker-chk"></div></div>`;}).join(''):'<div class="sup-picker-empty">No suppliers found.</div>';
    el.querySelectorAll('.sup-picker-item[data-sid]').forEach(item=>{item.addEventListener('click',()=>{const sid=parseInt(item.dataset.sid);supModal2SelIds.includes(sid)?supModal2SelIds=supModal2SelIds.filter(x=>x!==sid):supModal2SelIds.push(sid);rPicker2();});});
}
const supModal2SrchEl=document.getElementById('supModal2Srch');
if(supModal2SrchEl)supModal2SrchEl.addEventListener('input',function(){picker2Q=this.value.trim();rPicker2();});
function closeSupModal(){const el=document.getElementById('supModal');if(el)el.classList.remove('show');supModalTargetId=null;}
const supMCl=document.getElementById('supModalCl'),supMCa=document.getElementById('supModalCancel');
if(supMCl)supMCl.addEventListener('click',closeSupModal);
if(supMCa)supMCa.addEventListener('click',closeSupModal);
const supModalEl=document.getElementById('supModal');
if(supModalEl)supModalEl.addEventListener('click',function(e){if(e.target===this)closeSupModal();});
const supSaveBtn=document.getElementById('supModalSave');
if(supSaveBtn)supSaveBtn.addEventListener('click',async()=>{
    if(!supModal2SelIds.length)return toast('Select at least one supplier','danger');
    const r=rfqById(supModalTargetId);if(!r)return;
    const btn=document.getElementById('supModalSave');btn.disabled=true;
    try{
        await apiPost('rfq.php?api=action',{id:supModalTargetId,type:'manage_suppliers',supplierIds:supModal2SelIds});
        toast('Supplier list updated','success');
        closeSupModal();
        D=await apiGet('rfq.php?api=list');
        if(viewId===supModalTargetId){oPr(viewId);}
        render();
    }catch(e){toast(e.message,'danger');}
    finally{btn.disabled=false;}
});

// ── EXPORT ────────────────────────────────────────────────────────────────────
document.getElementById('expBtn').addEventListener('click',()=>{
    const cols=['rfqNo','prRef','dept','dateIssued','deadline','status','items','notes','sentBy','evaluator'];
    const rows=[cols.join(','),...D.map(r=>cols.map(c=>`"${String(r[c]??'').replace(/"/g,'""')}"`).join(','))];
    const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));a.download='rfq_export.csv';a.click();toast('RFQ data exported','success');
});

// ── TOAST & SHAKE ─────────────────────────────────────────────────────────────
function toast(msg,type='success'){
    const icons={success:'bx-check-circle',danger:'bx-error-circle',warning:'bx-error',info:'bx-info-circle'};
    const el=document.createElement('div');el.className=`rfq-toast ${type}`;
    el.innerHTML=`<i class='bx ${icons[type]||"bx-check-circle"}' style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('rfqTw').appendChild(el);
    setTimeout(()=>{el.classList.add('out');setTimeout(()=>el.remove(),300);},3200);
}
function shk(id){
    const el=document.getElementById(id);if(!el)return;
    el.style.borderColor='#DC2626';el.style.animation='none';el.offsetHeight;el.style.animation='rfqShake .3s ease';
    setTimeout(()=>{el.style.borderColor='';el.style.animation='';},600);
}

// ── INIT ──────────────────────────────────────────────────────────────────────
loadAll();
</script>
</body>
</html>