<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE RESOLUTION (mirrors superadmin_sidebar.php) ─────────────────────────
function _ri_resolve_role(): string {
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

$roleName = _ri_resolve_role();
$roleRank = match($roleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1, // Staff / User — no access
};

// ── PERMISSION GATES ──────────────────────────────────────────────────────────
$CAN_ACCESS         = $roleRank >= 2; // Manager+ can view
$CAN_RECORD         = $roleRank >= 3; // Admin+ can record/edit receipts
$CAN_ACCEPT_REJECT  = $roleRank >= 3; // Admin+ can confirm/reject
$CAN_FLAG           = $roleRank >= 2; // Manager+ can flag discrepancies
$CAN_OVERRIDE       = $roleRank >= 4; // Super Admin only
$CAN_SWS_TRIGGER    = $roleRank >= 4; // Super Admin only
$CAN_EXPORT         = $roleRank >= 3; // Admin+
$CAN_VIEW_FULL_COLS = $roleRank >= 3; // Admin+ see all columns

// Staff (rank 1) — hard redirect / no access
if ($roleRank <= 1 && !isset($_GET['api'])) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>Access Denied</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#F4F7F4;margin:0}.box{background:#fff;border-radius:16px;padding:40px 48px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.1)}.ic{font-size:48px;margin-bottom:12px}.t{font-size:22px;font-weight:700;color:#0A1F0D;margin-bottom:8px}.s{font-size:14px;color:#5D6F62}</style></head><body><div class="box"><div class="ic">🔒</div><div class="t">Access Denied</div><div class="s">You do not have permission to access Receiving &amp; Inspection.</div></div></body></html>';
    exit;
}

// ── HELPERS ──────────────────────────────────────────────────────────────────
function ri_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function ri_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function ri_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    if ($d === null && json_last_error() !== JSON_ERROR_NONE) ri_err('Invalid JSON', 400);
    return is_array($d) ? $d : [];
}
function ri_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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
        if ($code >= 400) ri_err('Supabase request failed', 500);
        return [];
    }
    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = is_array($data) ? ($data['message'] ?? $data['hint'] ?? $res) : $res;
        ri_err('Supabase: ' . $msg, 400);
    }
    return is_array($data) ? $data : [];
}

// ── BUILD FULL RECEIPT RECORD ─────────────────────────────────────────────────
function ri_build_full(array $row): array {
    $id = (int)$row['id'];

    $itemRows = ri_sb('psm_receipt_items', 'GET', [
        'select'     => 'description,expected,received,condition',
        'receipt_id' => 'eq.' . $id,
        'order'      => 'id.asc',
    ]);
    $items = array_map(fn($i) => [
        'desc' => $i['description'] ?? '',
        'exp'  => (int)($i['expected']  ?? 0),
        'rec'  => (int)($i['received']  ?? 0),
        'cond' => $i['condition']  ?? 'Good',
    ], $itemRows);

    $auditRows = ri_sb('psm_receipt_audit_log', 'GET', [
        'select'     => 'action_label,actor_name,dot_class,occurred_at',
        'receipt_id' => 'eq.' . $id,
        'order'      => 'occurred_at.desc,id.desc',
    ]);
    $audit = array_map(fn($a) => [
        't'   => $a['dot_class']    ?? 'blue',
        'm'   => $a['action_label'] ?? '',
        'by'  => $a['actor_name']   ?? '',
        'd'   => $a['occurred_at']  ?? '',
    ], $auditRows);

    return [
        'id'            => $id,
        'receiptNo'     => $row['receipt_no']    ?? '',
        'poRef'         => $row['po_ref']         ?? '',
        'supplier'      => $row['supplier']       ?? '',
        'deliveryDate'  => $row['delivery_date']  ?? '',
        'location'      => $row['location']       ?? '',
        'itemsExpected' => (int)($row['items_expected'] ?? 0),
        'itemsReceived' => (int)($row['items_received'] ?? 0),
        'condition'     => $row['condition']      ?? 'Good',
        'inspectedBy'   => $row['inspected_by']   ?? '—',
        'status'        => $row['status']         ?? 'Pending',
        'flag'          => (int)($row['flag']     ?? 0),
        'override'      => (int)($row['override'] ?? 0),
        'crossUpdate'   => $row['cross_update']   ?? '0',
        'notes'         => $row['notes']          ?? '',
        'saNotes'       => $row['sa_notes']       ?? '',
        'createdBy'     => $row['created_by']     ?? '',
        'createdAt'     => $row['created_at']     ?? '',
        'updatedAt'     => $row['updated_at']     ?? '',
        'items'         => $items,
        'audit'         => $audit,
    ];
}

function ri_next_number(): string {
    $rows = ri_sb('psm_receipts', 'GET', [
        'select' => 'receipt_no',
        'order'  => 'id.desc',
        'limit'  => 1,
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/REC-\d{4}-(\d+)/', $rows[0]['receipt_no'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return sprintf('REC-%s-%04d', date('Y'), $next);
}

// ── API ROUTER ───────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {

    // Staff — block all API access
    if ($roleRank <= 1) ri_err('Access denied', 403);

    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Super Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
    $SELECT = 'id,receipt_no,po_ref,supplier,delivery_date,location,items_expected,items_received,condition,inspected_by,status,flag,override,cross_update,notes,sa_notes,created_by,created_at,updated_at';

    try {

        // ── next receipt number — Admin+ ──────────────────────────────────────
        if ($api === 'next_no' && $method === 'GET') {
            if (!$CAN_RECORD) ri_err('Insufficient permissions', 403);
            ri_ok(['receiptNo' => ri_next_number()]);
        }

        // ── active suppliers — Manager+ ───────────────────────────────────────
        if ($api === 'suppliers' && $method === 'GET') {
            $rows = ri_sb('psm_suppliers', 'GET', [
                'select' => 'id,name,category',
                'status' => 'eq.Active',
                'order'  => 'name.asc',
            ]);
            ri_ok(array_map(fn($r) => [
                'id'   => (int)$r['id'],
                'name' => $r['name']     ?? '',
                'cat'  => $r['category'] ?? '',
            ], $rows));
        }

        // ── confirmed POs — Admin+ ────────────────────────────────────────────
        if ($api === 'pos' && $method === 'GET') {
            if (!$CAN_RECORD) ri_err('Insufficient permissions', 403);
            $rows = ri_sb('psm_po_summary', 'GET', [
                'select' => 'id,po_number,supplier_name,total_amount,item_count',
                'status' => 'in.(Confirmed,Sent,Partially Fulfilled,Fulfilled)',
                'order'  => 'po_number.desc',
            ]);
            $out = [];
            foreach ($rows as $row) {
                $poId    = (int)($row['id'] ?? 0);
                $qtyRows = [];
                if ($poId) {
                    $qtyRows = ri_sb('psm_po_items', 'GET', [
                        'select' => 'quantity',
                        'po_id'  => 'eq.' . $poId,
                    ]);
                }
                $totalQty = array_sum(array_column($qtyRows, 'quantity'));
                $out[] = [
                    'po_number'     => $row['po_number']    ?? '',
                    'supplier_name' => $row['supplier_name']?? '',
                    'total_amount'  => (float)($row['total_amount'] ?? 0),
                    'item_count'    => (int)($row['item_count']     ?? 0),
                    'total_qty'     => $totalQty > 0 ? (int)$totalQty : (int)($row['item_count'] ?? 0),
                ];
            }
            ri_ok($out);
        }

        // ── list — Manager+ ───────────────────────────────────────────────────
        if ($api === 'list' && $method === 'GET') {
            $query = ['select' => $SELECT, 'order' => 'delivery_date.desc,id.desc'];
            // Manager: limit visible statuses
            if ($roleRank === 2) {
                $query['status'] = 'in.(Pending,Received,Partially Received)';
            }
            $rows = ri_sb('psm_receipts', 'GET', $query);
            ri_ok(array_map('ri_build_full', $rows));
        }

        // ── get single — Manager+ ─────────────────────────────────────────────
        if ($api === 'get' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) ri_err('Missing id', 400);
            $rows = ri_sb('psm_receipts', 'GET', ['select' => $SELECT, 'id' => 'eq.' . $id, 'limit' => 1]);
            if (empty($rows)) ri_err('Receipt not found', 404);
            ri_ok(ri_build_full($rows[0]));
        }

        // ── po_refs filter — Manager+ ─────────────────────────────────────────
        if ($api === 'po_refs' && $method === 'GET') {
            $rows = ri_sb('psm_receipts', 'GET', ['select' => 'po_ref', 'order' => 'po_ref.asc']);
            $refs = array_values(array_unique(array_column($rows, 'po_ref')));
            ri_ok($refs);
        }

        // ── save (create/update) — Admin+ ─────────────────────────────────────
        if ($api === 'save' && $method === 'POST') {
            if (!$CAN_RECORD) ri_err('Insufficient permissions', 403);

            $b = ri_body();

            $receiptNo    = trim($b['receiptNo']    ?? '');
            $poRef        = trim($b['poRef']        ?? '');
            $supplier     = trim($b['supplier']     ?? '');
            $deliveryDate = trim($b['deliveryDate'] ?? '');
            $location     = trim($b['location']     ?? '');
            $expected     = (int)($b['itemsExpected'] ?? 0);
            $received     = (int)($b['itemsReceived'] ?? 0);
            $condition    = trim($b['condition']    ?? 'Good');
            $inspectedBy  = trim($b['inspectedBy']  ?? '—') ?: '—';
            $status       = trim($b['status']       ?? 'Pending');
            $flag         = (int)($b['flag']        ?? 0);
            $items        = is_array($b['items'] ?? null) ? $b['items'] : [];
            $notes        = trim($b['notes']        ?? '');

            // Override & cross-update: Super Admin only
            $override    = $CAN_OVERRIDE ? (int)($b['override']    ?? 0)  : 0;
            $crossUpdate = $CAN_SWS_TRIGGER ? trim($b['crossUpdate'] ?? '0') : '0';
            $saNotes     = $CAN_OVERRIDE ? trim($b['saNotes']      ?? '')  : '';

            if ($receiptNo    === '') ri_err('Receipt number is required', 400);
            if ($poRef        === '') ri_err('PO reference is required', 400);
            if ($supplier     === '') ri_err('Supplier is required', 400);
            if ($deliveryDate === '') ri_err('Delivery date is required', 400);

            $allowedStatus = ['Pending','Received','Partially Received','Rejected','Disputed','Completed'];
            if (!in_array($status, $allowedStatus, true)) $status = 'Pending';
            $allowedCond = ['Good','Minor Damage','Damaged','Mixed','—'];
            if (!in_array($condition, $allowedCond, true)) $condition = 'Good';

            $editId = (int)($b['id'] ?? 0);
            $now    = date('Y-m-d H:i:s');

            if ($editId) {
                ri_sb('psm_receipts', 'PATCH', ['id' => 'eq.' . $editId], [
                    'receipt_no'     => $receiptNo,
                    'po_ref'         => $poRef,
                    'supplier'       => $supplier,
                    'delivery_date'  => $deliveryDate,
                    'location'       => $location,
                    'items_expected' => $expected,
                    'items_received' => $received,
                    'condition'      => $condition,
                    'inspected_by'   => $inspectedBy,
                    'status'         => $status,
                    'flag'           => $flag,
                    'override'       => $override,
                    'cross_update'   => $crossUpdate,
                    'notes'          => $notes,
                    'sa_notes'       => $saNotes,
                    'updated_at'     => $now,
                ]);

                ri_sb('psm_receipt_items', 'DELETE', ['receipt_id' => 'eq.' . $editId]);
                foreach ($items as $item) {
                    ri_sb('psm_receipt_items', 'POST', [], [[
                        'receipt_id'  => $editId,
                        'description' => trim($item['desc'] ?? ''),
                        'expected'    => (int)($item['exp']  ?? 0),
                        'received'    => (int)($item['rec']  ?? 0),
                        'condition'   => trim($item['cond']  ?? 'Good'),
                    ]]);
                }

                ri_sb('psm_receipt_audit_log', 'POST', [], [[
                    'receipt_id'   => $editId,
                    'action_label' => 'Receipt Edited',
                    'actor_name'   => $actor,
                    'dot_class'    => 'blue',
                    'ip_address'   => $ip,
                    'occurred_at'  => $now,
                ]]);

                $rows = ri_sb('psm_receipts', 'GET', ['select' => $SELECT, 'id' => 'eq.' . $editId, 'limit' => 1]);
                ri_ok(ri_build_full($rows[0]));
            }

            // CREATE
            $inserted = ri_sb('psm_receipts', 'POST', [], [[
                'receipt_no'      => $receiptNo,
                'po_ref'          => $poRef,
                'supplier'        => $supplier,
                'delivery_date'   => $deliveryDate,
                'location'        => $location,
                'items_expected'  => $expected,
                'items_received'  => $received,
                'condition'       => $condition,
                'inspected_by'    => $inspectedBy,
                'status'          => $status,
                'flag'            => $flag,
                'override'        => $override,
                'cross_update'    => $crossUpdate,
                'notes'           => $notes,
                'sa_notes'        => $saNotes,
                'created_by'      => $actor,
                'created_user_id' => $_SESSION['user_id'] ?? null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]]);
            if (empty($inserted)) ri_err('Failed to create receipt', 500);
            $newId = (int)$inserted[0]['id'];

            foreach ($items as $item) {
                ri_sb('psm_receipt_items', 'POST', [], [[
                    'receipt_id'  => $newId,
                    'description' => trim($item['desc'] ?? ''),
                    'expected'    => (int)($item['exp']  ?? 0),
                    'received'    => (int)($item['rec']  ?? 0),
                    'condition'   => trim($item['cond']  ?? 'Good'),
                ]]);
            }

            ri_sb('psm_receipt_audit_log', 'POST', [], [[
                'receipt_id'   => $newId,
                'action_label' => 'Receipt recorded — ' . $receiptNo,
                'actor_name'   => $actor,
                'dot_class'    => 'blue',
                'ip_address'   => $ip,
                'occurred_at'  => $now,
            ]]);

            $rows = ri_sb('psm_receipts', 'GET', ['select' => $SELECT, 'id' => 'eq.' . $newId, 'limit' => 1]);
            ri_ok(ri_build_full($rows[0]));
        }

        // ── action — permission-gated per type ───────────────────────────────
        if ($api === 'action' && $method === 'POST') {
            $b      = ri_body();
            $id     = (int)($b['id']     ?? 0);
            $type   = trim($b['type']    ?? '');
            $now    = date('Y-m-d H:i:s');

            if (!$id)   ri_err('Missing id', 400);
            if (!$type) ri_err('Missing type', 400);

            // Gate per action type
            if ($type === 'accept'   && !$CAN_ACCEPT_REJECT) ri_err('Insufficient permissions', 403);
            if ($type === 'reject'   && !$CAN_ACCEPT_REJECT) ri_err('Insufficient permissions', 403);
            if ($type === 'flag'     && !$CAN_FLAG)          ri_err('Insufficient permissions', 403);
            if ($type === 'override' && !$CAN_OVERRIDE)      ri_err('Insufficient permissions', 403);

            $rows = ri_sb('psm_receipts', 'GET', ['select' => 'id,receipt_no,status,notes', 'id' => 'eq.' . $id, 'limit' => 1]);
            if (empty($rows)) ri_err('Receipt not found', 404);
            $rec = $rows[0];

            if ($type === 'accept') {
                ri_sb('psm_receipts', 'PATCH', ['id' => 'eq.' . $id], ['status' => 'Completed', 'updated_at' => $now]);
                ri_sb('psm_receipt_audit_log', 'POST', [], [[
                    'receipt_id'   => $id,
                    'action_label' => 'Delivery confirmed and completed',
                    'actor_name'   => $actor,
                    'dot_class'    => 'green',
                    'ip_address'   => $ip,
                    'occurred_at'  => $now,
                ]]);

            } elseif ($type === 'reject') {
                $reason = trim($b['reason'] ?? '');
                $notes2 = trim($b['notes']  ?? '');
                if ($reason === '') ri_err('Rejection reason is required', 400);
                ri_sb('psm_receipts', 'PATCH', ['id' => 'eq.' . $id], [
                    'status'     => 'Rejected',
                    'notes'      => ($rec['notes'] ?? '') . ($rec['notes'] ? ' | ' : '') . 'Rejected: ' . $reason . ($notes2 ? '. ' . $notes2 : ''),
                    'updated_at' => $now,
                ]);
                ri_sb('psm_receipt_audit_log', 'POST', [], [[
                    'receipt_id'   => $id,
                    'action_label' => 'Delivery rejected — ' . $reason,
                    'actor_name'   => $actor,
                    'dot_class'    => 'red',
                    'ip_address'   => $ip,
                    'occurred_at'  => $now,
                ]]);

            } elseif ($type === 'flag') {
                $flagType = (int)($b['flagType'] ?? 0);
                $notes2   = trim($b['notes']     ?? '');
                if (!$flagType) ri_err('Flag type is required', 400);
                $flagLabels = ['','Short Delivery','Damage','Wrong Items','Missing Docs','Quality Issue'];
                $label      = $flagLabels[$flagType] ?? 'Unknown';
                $newStatus  = $rec['status'] === 'Completed' ? 'Disputed' : $rec['status'];
                ri_sb('psm_receipts', 'PATCH', ['id' => 'eq.' . $id], [
                    'flag'       => $flagType,
                    'status'     => $newStatus,
                    'updated_at' => $now,
                ]);
                ri_sb('psm_receipt_audit_log', 'POST', [], [[
                    'receipt_id'   => $id,
                    'action_label' => 'Flagged: ' . $label . ($notes2 ? ' — ' . $notes2 : '') . ' (by ' . $roleName . ')',
                    'actor_name'   => $actor,
                    'dot_class'    => 'orange',
                    'ip_address'   => $ip,
                    'occurred_at'  => $now,
                ]]);

            } elseif ($type === 'override') {
                // SA only — already gated above
                $action      = trim($b['action']      ?? '');
                $reason      = trim($b['reason']      ?? '');
                $crossUpdate = trim($b['crossUpdate'] ?? '0');
                if (!$action) ri_err('Override action is required', 400);
                if (!$reason) ri_err('Override justification is required', 400);

                $newStatus = match($action) {
                    'force_accept' => 'Completed',
                    'force_reject' => 'Rejected',
                    default        => 'Partially Received',
                };

                ri_sb('psm_receipts', 'PATCH', ['id' => 'eq.' . $id], [
                    'status'       => $newStatus,
                    'override'     => 1,
                    'cross_update' => $crossUpdate,
                    'sa_notes'     => $reason,
                    'updated_at'   => $now,
                ]);

                $cuMsg = match($crossUpdate) {
                    'sws'  => ' · SWS stock updated',
                    'alms' => ' · ALMS asset entry created',
                    'both' => ' · SWS + ALMS updated',
                    default => '',
                };
                ri_sb('psm_receipt_audit_log', 'POST', [], [[
                    'receipt_id'   => $id,
                    'action_label' => 'Inspection result overridden — ' . $reason . $cuMsg,
                    'actor_name'   => $actor,
                    'dot_class'    => 'teal',
                    'ip_address'   => $ip,
                    'occurred_at'  => $now,
                ]]);

            } else {
                ri_err('Unsupported action type', 400);
            }

            $rows = ri_sb('psm_receipts', 'GET', ['select' => $SELECT, 'id' => 'eq.' . $id, 'limit' => 1]);
            ri_ok(ri_build_full($rows[0]));
        }

        ri_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        ri_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── JS ROLE CAPABILITIES ──────────────────────────────────────────────────────
$jsRole = json_encode([
    'name'           => $roleName,
    'rank'           => $roleRank,
    'canRecord'      => $CAN_RECORD,
    'canAcceptReject'=> $CAN_ACCEPT_REJECT,
    'canFlag'        => $CAN_FLAG,
    'canOverride'    => $CAN_OVERRIDE,
    'canSwsTrigger'  => $CAN_SWS_TRIGGER,
    'canExport'      => $CAN_EXPORT,
    'canViewFullCols'=> $CAN_VIEW_FULL_COLS,
]);

// ── HTML PAGE RENDER ─────────────────────────────────────────────────────────
$root_include = dirname(__DIR__, 2);
include $root_include . '/includes/superadmin_sidebar.php';
include $root_include . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receiving &amp; Inspection — PSM</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
    <link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
    <link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
/* ── SCOPED VARS ── */
#mainContent,#panel,#modal,#overrideModal,#rejectModal,#flagModal,#tw,.act-drop {
    --surface:#FFFFFF; --border:rgba(46,125,50,.14); --border-mid:rgba(46,125,50,.22);
    --text-1:var(--text-primary); --text-2:var(--text-secondary); --text-3:#9EB0A2;
    --hover-s:var(--hover-bg-light); --shadow-sm:var(--shadow-light);
    --shadow-md:0 4px 16px rgba(46,125,50,.12); --shadow-xl:0 20px 60px rgba(0,0,0,.22);
    --radius:12px; --tr:var(--transition); --danger:#DC2626; --warning:#D97706;
    --info:#2563EB; --purple:#7C3AED; --teal:#0D9488;
    --bg:var(--bg-color); --primary:var(--primary-color); --prim-dark:var(--primary-dark);
}
#mainContent *,#panel *,#modal *,#overrideModal *,#rejectModal *,#flagModal *,#tw *,.act-drop * { box-sizing:border-box; }
.sa-badge,.role-badge,.user-role-badge,.header-role,.badge-superadmin,[class*="role-badge"],.header-user-role{display:none!important}
#mainContent { overflow-x:hidden; min-width:0; }
.ri-page { max-width:100%; margin:0 auto; padding:0 0 3rem; overflow-x:hidden; }
.ri-ph { display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:28px; animation:riFU .4s both; }
.ri-ph .ey { font-size:11px; font-weight:600; letter-spacing:.14em; text-transform:uppercase; color:var(--primary); margin-bottom:4px; }
.ri-ph h1 { font-size:26px; font-weight:800; color:var(--text-1); line-height:1.15; }
.ri-acts { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

/* ── Access Banner ── */
.access-banner{display:flex;align-items:flex-start;gap:10px;padding:10px 16px;border-radius:10px;font-size:12px;margin-bottom:16px;animation:riFU .4s both}
.ab-warn{background:#FEF3C7;border:1px solid #FDE68A;color:var(--warning)}
.access-banner i{font-size:16px;flex-shrink:0;margin-top:1px}

.btn { display:inline-flex; align-items:center; gap:7px; font-family:'Inter',sans-serif; font-size:13px; font-weight:600; padding:9px 18px; border-radius:10px; border:none; cursor:pointer; transition:var(--tr); white-space:nowrap; }
.btn-p { background:var(--primary); color:#fff; box-shadow:0 2px 8px rgba(46,125,50,.3); }
.btn-p:hover { background:var(--prim-dark); transform:translateY(-1px); }
.btn-g { background:var(--surface); color:var(--text-2); border:1px solid var(--border-mid); }
.btn-g:hover { background:var(--hover-s); color:var(--text-1); }
.btn-s { font-size:12px; padding:6px 13px; }
.btn-danger { background:var(--danger); color:#fff; box-shadow:0 2px 8px rgba(220,38,38,.3); }
.btn-danger:hover { background:#B91C1C; transform:translateY(-1px); }
.btn-warn { background:var(--warning); color:#fff; }
.btn-warn:hover { background:#B45309; transform:translateY(-1px); }
.btn-teal { background:var(--teal); color:#fff; box-shadow:0 2px 8px rgba(13,148,136,.3); }
.btn-teal:hover { background:#0F766E; transform:translateY(-1px); }
.btn:disabled { opacity:.45; pointer-events:none; }
.ri-banner { background:linear-gradient(135deg,#FFF5F5,#FEF2F2); border:1px solid #FECACA; border-radius:12px; padding:12px 18px; margin-bottom:18px; display:flex; align-items:center; gap:12px; animation:riFU .4s .05s both; }
.ri-banner.hidden { display:none; }
.ri-banner i { font-size:20px; color:var(--danger); flex-shrink:0; }
.ri-banner-txt { flex:1; font-size:13px; color:#7F1D1D; }
.ri-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:14px; margin-bottom:24px; }
.ri-stat { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:14px 16px; box-shadow:var(--shadow-sm); display:flex; align-items:center; gap:12px; animation:riFU .4s both; }
.ri-stat:nth-child(1){animation-delay:.04s}.ri-stat:nth-child(2){animation-delay:.08s}.ri-stat:nth-child(3){animation-delay:.12s}.ri-stat:nth-child(4){animation-delay:.16s}.ri-stat:nth-child(5){animation-delay:.20s}.ri-stat:nth-child(6){animation-delay:.24s}
.sc-ic { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.ic-g{background:#E8F5E9;color:var(--primary)}.ic-o{background:#FEF3C7;color:var(--warning)}.ic-r{background:#FEE2E2;color:var(--danger)}.ic-b{background:#EFF6FF;color:var(--info)}.ic-t{background:#CCFBF1;color:var(--teal)}.ic-pu{background:#EDE9FE;color:var(--purple)}
.ri-sv { font-size:21px; font-weight:800; line-height:1; }
.ri-sl { font-size:11px; color:var(--text-2); margin-top:2px; }
.ri-toolbar { display:flex; align-items:center; gap:8px; flex-wrap:nowrap; overflow-x:auto; overflow-y:visible; padding-bottom:2px; margin-bottom:16px; animation:riFU .4s .1s both; min-width:0; }
.ri-toolbar::-webkit-scrollbar{height:3px}.ri-toolbar::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px}
.ri-sw { position:relative; flex:0 0 260px; min-width:0; }
.ri-sw i { position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:14px; color:var(--text-3); pointer-events:none; }
.ri-sin { width:100%; height:34px; padding:0 10px 0 30px; font-family:'Inter',sans-serif; font-size:12px; border:1px solid var(--border-mid); border-radius:9px; background:var(--surface); color:var(--text-1); outline:none; transition:var(--tr); }
.ri-sin:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}.ri-sin::placeholder{color:var(--text-3)}
.fsel { font-family:'Inter',sans-serif; font-size:12px; height:34px; padding:0 26px 0 10px; border:1px solid var(--border-mid); border-radius:9px; background:var(--surface); color:var(--text-1); cursor:pointer; outline:none; transition:var(--tr); appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 8px center; flex-shrink:0; }
.fsel:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
.filter-pill{display:inline-flex;flex-direction:row;flex-wrap:nowrap;align-items:center;background:var(--surface);border:1px solid var(--border-mid);border-radius:9px;flex-shrink:0;height:34px;overflow:hidden;width:max-content}
.filter-pill .pill-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);padding:0 8px;background:var(--bg);border-right:1px solid var(--border-mid);height:34px;display:flex;align-items:center;white-space:nowrap;flex-shrink:0}
.filter-pill input[type=date]{font-family:'Inter',sans-serif;font-size:11px;border:none;outline:none;background:transparent;color:var(--text-1);padding:0 4px;height:34px;width:120px;min-width:120px;max-width:120px;flex:0 0 120px;cursor:pointer;box-sizing:border-box}
.filter-pill .pill-sep{font-size:11px;color:var(--text-3);padding:0 2px;flex-shrink:0}
.filter-pill input[type=date]:focus{background:rgba(46,125,50,.06)}
.clear-btn{font-size:11px;font-weight:600;color:var(--text-3);background:none;border:1px solid var(--border-mid);cursor:pointer;padding:0 10px;border-radius:9px;transition:var(--tr);white-space:nowrap;display:inline-flex;align-items:center;gap:4px;flex-shrink:0;height:34px}
.clear-btn:hover{color:var(--danger);background:#FEE2E2;border-color:#FECACA}
.ri-tcard{background:var(--surface);border:1px solid var(--border);border-radius:16px;overflow:visible;box-shadow:var(--shadow-md);animation:riFU .4s .15s both}
.ri-twrap{overflow-x:auto;overflow-y:visible;-webkit-overflow-scrolling:touch;width:100%;border-radius:16px;position:relative}
.ri-tcard table{width:100%;min-width:860px;border-collapse:collapse;font-size:12px;table-layout:fixed}
.ri-tcard thead th{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-2);padding:11px 8px;text-align:left;background:var(--bg);border-bottom:1px solid var(--border);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;user-select:none}
.ri-tcard thead th:first-child{padding-left:14px}.ri-tcard thead th:last-child{padding-right:8px}
.ri-tcard thead th.sortable{cursor:pointer}.ri-tcard thead th.sortable:hover{color:var(--primary)}
.ri-tcard thead th .si{margin-left:3px;opacity:.4;font-size:12px;vertical-align:middle}
.ri-tcard thead th.sorted .si{opacity:1;color:var(--primary)}
.ri-tcard tbody tr{border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;overflow:visible}
.ri-tcard tbody tr:last-child{border-bottom:none}.ri-tcard tbody tr:hover{background:var(--hover-s)}
.ri-tcard tbody tr.disputed-row{background:rgba(220,38,38,.03)}.ri-tcard tbody tr.disputed-row:hover{background:rgba(220,38,38,.07)}
.ri-tcard tbody tr.partial-row{background:rgba(217,119,6,.03)}.ri-tcard tbody tr.partial-row:hover{background:rgba(217,119,6,.07)}
.ri-tcard tbody td{padding:10px 8px;vertical-align:middle;overflow:hidden}
.ri-tcard tbody td:first-child{padding-left:14px}.ri-tcard tbody td:last-child{padding-right:8px;overflow:visible;position:relative}
.mono{font-family:'DM Mono',monospace;font-size:11px;font-weight:600;color:var(--primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
.bold{font-size:12px;font-weight:600;color:var(--text-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
.sub{font-size:10px;color:var(--text-3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
.qty-wrap{display:flex;align-items:center;gap:4px;font-size:12px;font-weight:600}
.qty-ok{color:var(--primary)}.qty-warn{color:var(--warning)}.qty-low{color:var(--danger)}
.qty-bar-wrap{width:100%;height:4px;background:var(--border);border-radius:4px;overflow:hidden;margin-top:3px}
.qty-bar{height:100%;border-radius:4px}.bar-g{background:var(--primary)}.bar-o{background:var(--warning)}.bar-r{background:var(--danger)}
.cond{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 7px;border-radius:8px;white-space:nowrap}
.cond-good{background:#E8F5E9;color:var(--primary)}.cond-minor{background:#FEF3C7;color:var(--warning)}.cond-damaged{background:#FEE2E2;color:var(--danger)}.cond-mixed{background:#EDE9FE;color:var(--purple)}.cond-na{background:#F3F4F6;color:#6B7280}
.chip{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:600;padding:3px 8px;border-radius:20px;white-space:nowrap}
.chip::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0}
.chip-pending{background:#EDE9FE;color:var(--purple)}.chip-received{background:#E8F5E9;color:var(--primary)}.chip-partial{background:#FEF3C7;color:var(--warning)}.chip-rejected{background:#FEE2E2;color:var(--danger)}.chip-disputed{background:#FFF5F5;color:#9B1C1C}.chip-completed{background:#CCFBF1;color:var(--teal)}
.flag-ic{color:var(--danger);font-size:13px;flex-shrink:0}
.act-wrap{display:flex;gap:4px;align-items:center;flex-wrap:nowrap;justify-content:flex-end}
.act-wrap .btn{padding:5px 7px;font-size:11px;font-weight:600;border-radius:7px;line-height:1;gap:3px}
.act-wrap .btn i{margin:0;font-size:13px;flex-shrink:0}
.act-more{position:relative;display:inline-block}
.act-more-btn{padding:5px 7px;font-size:12px;border-radius:7px;line-height:1;background:var(--surface);color:var(--text-2);border:1px solid var(--border-mid);cursor:pointer;transition:var(--tr);display:inline-flex;align-items:center}
.act-more-btn:hover{background:var(--hover-s);color:var(--text-1)}
.act-more-btn i{font-size:15px;display:block}
.act-drop{display:none;position:fixed;background:var(--surface);border:1px solid var(--border-mid);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.18);z-index:9000;min-width:180px;overflow:hidden}
.act-drop button{display:flex;align-items:center;gap:8px;width:100%;padding:9px 14px;font-family:'Inter',sans-serif;font-size:12px;font-weight:500;color:var(--text-1);background:none;border:none;cursor:pointer;transition:background .15s;text-align:left;white-space:nowrap}
.act-drop button:hover{background:var(--hover-s)}.act-drop button i{font-size:15px;color:var(--text-3);flex-shrink:0}.act-drop button:hover i{color:var(--primary)}
.act-drop button.danger{color:var(--danger)}.act-drop button.danger i{color:var(--danger)}.act-drop button.teal{color:var(--teal)}.act-drop button.teal i{color:var(--teal)}
.act-drop .drop-div{height:1px;background:var(--border);margin:3px 0}
.ri-pag{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--border);background:var(--bg);font-size:13px;color:var(--text-2)}
.ri-pbtns{display:flex;gap:6px}
.ri-pb{width:32px;height:32px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);font-family:'Inter',sans-serif;font-size:13px;font-weight:500;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--text-1)}
.ri-pb:hover{background:var(--hover-s);border-color:var(--primary);color:var(--primary)}.ri-pb.active{background:var(--primary);border-color:var(--primary);color:#fff}.ri-pb:disabled{opacity:.4;pointer-events:none}
.ri-empty{padding:60px 20px;text-align:center;color:var(--text-3)}
.ri-empty i{font-size:44px;display:block;margin-bottom:10px;color:#C8E6C9}.ri-empty p{font-size:14px}
.ri-ov{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1100;opacity:0;pointer-events:none;transition:opacity .25s}
.ri-ov.show{opacity:1;pointer-events:all}
#panel{position:fixed;top:0;right:0;bottom:0;width:540px;max-width:92vw;background:var(--surface);box-shadow:-4px 0 40px rgba(0,0,0,.18);z-index:1101;transform:translateX(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden}
#panel.open{transform:translateX(0)}
.p-hd{display:flex;align-items:center;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--border);background:var(--bg);flex-shrink:0}
.p-t{font-size:17px;font-weight:700;color:var(--text-1)}.p-s{font-size:12px;color:var(--text-2);margin-top:2px}
.p-cl{width:36px;height:36px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--text-2);transition:var(--tr);flex-shrink:0}
.p-cl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA}
.p-bdy{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:18px}
.p-bdy::-webkit-scrollbar{width:4px}.p-bdy::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px}
.p-ft{padding:16px 24px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0}
.fg{display:flex;flex-direction:column;gap:6px}.fr{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.fl{font-size:12px;font-weight:600;color:var(--text-2);text-transform:uppercase;letter-spacing:.06em}.fl span{color:var(--danger);margin-left:2px}
.fi,.fs,.fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);width:100%}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:32px}
.fta{resize:vertical;min-height:80px}
.sdv{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);display:flex;align-items:center;gap:10px;margin:4px 0}
.sdv::after{content:'';flex:1;height:1px;background:var(--border)}
.sa-sec{background:linear-gradient(135deg,rgba(27,94,32,.04),rgba(46,125,50,.06));border:1px solid rgba(46,125,50,.2);border-radius:12px;padding:16px}
.sa-hd{display:flex;align-items:center;gap:8px;margin-bottom:14px}.sa-hd i{color:var(--primary);font-size:16px}.sa-hd span{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--primary)}
#modal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s}
#modal.show{opacity:1;pointer-events:all}
.mbox{background:var(--surface);border-radius:20px;width:840px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:var(--shadow-xl);transform:scale(.96);transition:transform .25s;overflow:hidden;min-height:0}
#modal.show .mbox{transform:scale(1)}
.m-hd{padding:24px 28px 0;border-bottom:1px solid var(--border);background:var(--bg);flex-shrink:0}
.m-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}
.m-si{display:flex;align-items:center;gap:16px}
.m-av{width:50px;height:50px;border-radius:14px;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0}
.m-nm{font-size:18px;font-weight:800;color:var(--text-1);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.m-id{font-family:'DM Mono',monospace;font-size:12px;color:var(--text-2);margin-top:3px}
.m-cl{width:36px;height:36px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--text-2);transition:var(--tr);flex-shrink:0}
.m-cl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA}
.m-mt{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px}
.m-mc{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-2);background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:5px 10px}
.m-mc i{font-size:14px;color:var(--primary)}
.m-tabs{display:flex;gap:4px}
.m-tab{font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px 8px 0 0;cursor:pointer;transition:var(--tr);color:var(--text-2);border:none;background:transparent}
.m-tab:hover{background:var(--hover-s)}.m-tab.active{background:var(--primary);color:#fff}
.m-bd{flex:1;overflow-y:auto;padding:24px 28px}
.m-bd::-webkit-scrollbar{width:4px}.m-bd::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px}
.m-ft{padding:12px 20px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:6px;justify-content:flex-end;flex-shrink:0;flex-wrap:nowrap;overflow-x:auto}
.tp{display:none}.tp.active{display:block}
.ig{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.ii label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);display:block;margin-bottom:4px}
.ii .v{font-size:13px;font-weight:500;color:var(--text-1)}.full{grid-column:1/-1}
.sbs{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.sb{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px 16px}
.sb .sbv{font-size:18px;font-weight:800;color:var(--text-1)}.sb .sbl{font-size:11px;color:var(--text-2);margin-top:2px}
.itbl{width:100%;border-collapse:collapse;font-size:12px;margin-top:4px}
.itbl thead th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-3);padding:8px 10px;background:var(--bg);border-bottom:1px solid var(--border);text-align:left}
.itbl tbody td{padding:10px;border-bottom:1px solid var(--border);vertical-align:middle}
.itbl tbody tr:last-child td{border-bottom:none}.itbl tbody tr:hover{background:var(--hover-s)}
.tl-item{display:flex;gap:14px;padding:12px 0;border-bottom:1px solid var(--border)}
.tl-item:last-child{border-bottom:none}
.tl-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:6px}
.tl-dot.green{background:var(--primary)}.tl-dot.red{background:var(--danger)}.tl-dot.orange{background:var(--warning)}.tl-dot.blue{background:var(--info)}.tl-dot.teal{background:var(--teal)}.tl-dot.purple{background:var(--purple)}
.tl-body .au{font-size:13px;font-weight:500;color:var(--text-1)}.tl-body .at{font-size:11px;color:var(--text-3);margin-top:2px;font-family:'DM Mono',monospace}
.cross-panel{background:linear-gradient(135deg,rgba(13,148,136,.04),rgba(124,58,237,.04));border:1px solid rgba(13,148,136,.2);border-radius:12px;padding:16px;margin-bottom:18px}
.cross-hd{display:flex;align-items:center;gap:8px;margin-bottom:12px}.cross-hd i{color:var(--teal);font-size:16px}.cross-hd span{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--teal)}
.cross-item{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(13,148,136,.1)}.cross-item:last-child{border-bottom:none}
.cross-ic{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.cross-ic.sws{background:#CCFBF1;color:var(--teal)}.cross-ic.alms{background:#EDE9FE;color:var(--purple)}
.cross-lbl{font-size:12px;font-weight:600;color:var(--text-1)}.cross-sub{font-size:11px;color:var(--text-3);margin-top:1px}
.cross-st{margin-left:auto;font-size:11px;font-weight:700}.cross-st.done{color:var(--primary)}.cross-st.pend{color:var(--warning)}
.cross-badge{display:inline-flex;align-items:center;font-size:10px;font-weight:700;padding:2px 7px;border-radius:6px;margin-left:4px}
.cb-sws{background:#CCFBF1;color:var(--teal)}.cb-alms{background:#EDE9FE;color:var(--purple)}
#overrideModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1300;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s}
#overrideModal.show{opacity:1;pointer-events:all}
.ov-box{background:var(--surface);border-radius:20px;width:480px;max-width:100%;box-shadow:var(--shadow-xl);transform:scale(.95);transition:transform .2s;overflow:hidden}
#overrideModal.show .ov-box{transform:scale(1)}
.ov-hd{padding:22px 24px 16px;border-bottom:1px solid var(--border);display:flex;gap:14px;align-items:flex-start}
.ov-ic{width:44px;height:44px;border-radius:12px;background:#CCFBF1;color:var(--teal);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.ov-title{font-size:16px;font-weight:700;color:var(--text-1)}.ov-sub{font-size:12px;color:var(--text-2);margin-top:4px}
.ov-body{padding:20px 24px;display:flex;flex-direction:column;gap:14px}
.ov-warn{background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:10px 14px;font-size:12px;color:#78350F;display:flex;gap:8px}
.ov-warn i{font-size:16px;color:var(--warning);flex-shrink:0}
.ov-ft{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--bg)}
#rejectModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1300;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s}
#rejectModal.show{opacity:1;pointer-events:all}
.rj-box{background:var(--surface);border-radius:20px;width:460px;max-width:100%;box-shadow:var(--shadow-xl);transform:scale(.95);transition:transform .2s;overflow:hidden}
#rejectModal.show .rj-box{transform:scale(1)}
.rj-hd{padding:22px 24px 16px;border-bottom:1px solid var(--border);display:flex;gap:14px;align-items:flex-start}
.rj-ic{width:44px;height:44px;border-radius:12px;background:#FEE2E2;color:var(--danger);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.rj-title{font-size:16px;font-weight:700;color:var(--text-1)}.rj-sub{font-size:12px;color:var(--text-2);margin-top:4px}
.rj-body{padding:20px 24px;display:flex;flex-direction:column;gap:14px}
.rj-warn{background:#FFF5F5;border:1px solid #FECACA;border-radius:10px;padding:10px 14px;font-size:12px;color:#7F1D1D;display:flex;gap:8px}
.rj-warn i{font-size:16px;color:var(--danger);flex-shrink:0}
.rj-ft{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--bg)}
#flagModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1300;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s}
#flagModal.show{opacity:1;pointer-events:all}
.fl-box{background:var(--surface);border-radius:20px;width:440px;max-width:100%;box-shadow:var(--shadow-xl);transform:scale(.95);transition:transform .2s;overflow:hidden}
#flagModal.show .fl-box{transform:scale(1)}
.fl-hd{padding:22px 24px 16px;border-bottom:1px solid var(--border);display:flex;gap:14px;align-items:flex-start}
.fl-ic{width:44px;height:44px;border-radius:12px;background:#FEF3C7;color:var(--warning);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.fl-title{font-size:16px;font-weight:700;color:var(--text-1)}.fl-sub{font-size:12px;color:var(--text-2);margin-top:4px}
.fl-body{padding:20px 24px;display:flex;flex-direction:column;gap:14px}
.fl-ft{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--bg)}
#tw{position:fixed;bottom:28px;right:28px;display:flex;flex-direction:column;gap:10px;z-index:9999;pointer-events:none}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow-xl);pointer-events:all;min-width:220px;animation:riTI .3s ease}
.toast.success{background:var(--primary)}.toast.warning{background:var(--warning)}.toast.danger{background:var(--danger)}.toast.info{background:var(--info)}.toast.teal{background:var(--teal)}
.toast.fo{animation:riTO .3s ease forwards}
@keyframes riFU{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes riTI{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes riTO{from{opacity:1}to{opacity:0;transform:translateY(12px)}}
@keyframes riShk{0%,100%{transform:translateX(0)}25%,75%{transform:translateX(-5px)}50%{transform:translateX(5px)}}
@media(max-width:768px){#panel{width:100vw;max-width:100vw}.fr,.ig{grid-template-columns:1fr}.sbs{grid-template-columns:1fr 1fr}.ri-stats{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="ri-page">

    <div class="ri-ph">
        <div>
            <p class="ey">PSM · Procurement &amp; Sourcing Management</p>
            <h1>Receiving &amp; Inspection</h1>
        </div>
        <div class="ri-acts">
            <?php if ($roleRank >= 3): // Admin+ see disputed/pending quick filters ?>
            <button class="btn btn-g" id="viewDisputedBtn"><i class='bx bx-error-circle'></i> Disputed</button>
            <button class="btn btn-g" id="viewPendingBtn"><i class='bx bx-time-five'></i> Pending</button>
            <?php endif; ?>
            <?php if ($CAN_EXPORT): ?>
            <button class="btn btn-g" id="expBtn"><i class='bx bx-export'></i> Export</button>
            <?php endif; ?>
            <?php if ($CAN_RECORD): ?>
            <button class="btn btn-p" id="addBtn"><i class='bx bx-plus'></i> Record Receipt</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($roleName === 'Manager'): ?>
    <div class="access-banner ab-warn"><i class='bx bx-lock-open-alt'></i><div>You have <strong>Manager access</strong> — you can view receipts and flag discrepancies. Recording, confirming, rejecting, and overriding deliveries require Admin or Super Admin.</div></div>
    <?php endif; ?>

    <div class="ri-banner hidden" id="dispBanner">
        <i class='bx bx-error-circle'></i>
        <div class="ri-banner-txt"><strong>Disputed Deliveries:</strong> <span id="bannerTxt"></span> — Requires immediate review.</div>
        <?php if ($CAN_ACCEPT_REJECT): ?>
        <button class="btn btn-s" style="background:var(--danger);color:#fff;border:none" id="bannerViewBtn">Review Now</button>
        <?php endif; ?>
    </div>

    <div class="ri-stats" id="statsRow"></div>

    <div class="ri-toolbar">
        <div class="ri-sw"><i class='bx bx-search'></i><input type="text" class="ri-sin" id="srch" placeholder="Search PO ref, receipt no. or supplier…"></div>
        <select class="fsel" id="fSupplier"><option value="">All Suppliers</option></select>
        <select class="fsel" id="fPO"><option value="">All PO Refs</option></select>
        <select class="fsel" id="fStatus">
            <option value="">All Statuses</option>
            <option>Pending</option>
            <option>Received</option>
            <option>Partially Received</option>
            <?php if ($roleRank >= 3): // Admin+ see all statuses ?>
            <option>Rejected</option>
            <option>Disputed</option>
            <option>Completed</option>
            <?php endif; ?>
        </select>
        <div class="filter-pill">
            <span class="pill-lbl">DATE</span>
            <input type="date" id="fDateFrom" title="Delivery from">
            <span class="pill-sep">—</span>
            <input type="date" id="fDateTo" title="Delivery to" style="border-left:1px solid var(--border-mid)">
        </div>
        <button class="clear-btn" id="clearFilters"><i class='bx bx-x'></i> Clear</button>
    </div>

    <div class="ri-tcard">
        <div class="ri-twrap">
            <table>
                <colgroup>
                    <col style="width:30px">
                    <col style="width:115px"> <!-- PO Ref -->
                    <col style="width:170px"> <!-- Supplier -->
                    <col style="width:85px">  <!-- Date -->
                    <col style="width:70px">  <!-- Expected -->
                    <col style="width:90px">  <!-- Received -->
                    <?php if ($CAN_VIEW_FULL_COLS): ?>
                    <col style="width:90px">  <!-- Condition — Admin+ only -->
                    <?php endif; ?>
                    <col style="width:100px"> <!-- Inspector -->
                    <col style="width:110px"> <!-- Status -->
                    <col style="width:105px"> <!-- Actions -->
                </colgroup>
                <thead>
                    <tr>
                        <th>#</th>
                        <th class="sortable" data-col="poRef">PO Ref <i class='bx bx-sort si'></i></th>
                        <th class="sortable" data-col="supplier">Supplier <i class='bx bx-sort si'></i></th>
                        <th class="sortable" data-col="deliveryDate">Date <i class='bx bx-sort si'></i></th>
                        <th class="sortable" data-col="itemsExpected">Expected <i class='bx bx-sort si'></i></th>
                        <th class="sortable" data-col="itemsReceived">Received <i class='bx bx-sort si'></i></th>
                        <?php if ($CAN_VIEW_FULL_COLS): ?>
                        <th class="sortable" data-col="condition">Condition <i class='bx bx-sort si'></i></th>
                        <?php endif; ?>
                        <th class="sortable" data-col="inspectedBy">Inspector <i class='bx bx-sort si'></i></th>
                        <th class="sortable" data-col="status">Status <i class='bx bx-sort si'></i></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="tb"></tbody>
            </table>
        </div>
        <div class="ri-pag" id="pag"></div>
    </div>
</div>
</main>

<div class="ri-ov" id="mainOv"></div>

<!-- SIDE PANEL — Admin+ only -->
<?php if ($CAN_RECORD): ?>
<div id="panel">
    <div class="p-hd">
        <div><div class="p-t" id="pT">Record Receipt</div><div class="p-s" id="pS">Log a new delivery against a purchase order</div></div>
        <button class="p-cl" id="pCl"><i class='bx bx-x'></i></button>
    </div>
    <div class="p-bdy">
        <div class="sdv">Delivery Identity</div>
        <div class="fr">
            <div class="fg"><label class="fl">PO Reference <span>*</span></label><input type="text" class="fi" id="fPOi" list="poList" placeholder="Search PO number…"><datalist id="poList"></datalist></div>
            <div class="fg"><label class="fl">Receipt No. <span>*</span></label><input type="text" class="fi" id="fRN" placeholder="Auto-generated" readonly style="background:var(--bg);color:var(--text-3)"></div>
        </div>
        <div class="fg">
            <label class="fl">Supplier <span>*</span></label>
            <select class="fs" id="fSup"><option value="">Select supplier…</option></select>
        </div>
        <div class="fr">
            <div class="fg"><label class="fl">Delivery Date <span>*</span></label><input type="date" class="fi" id="fDD"></div>
            <div class="fg"><label class="fl">Delivery Location</label><input type="text" class="fi" id="fDL" placeholder="e.g. Warehouse A, Bay 3"></div>
        </div>
        <div class="sdv">Quantity</div>
        <div class="fr">
            <div class="fg"><label class="fl">Items Expected <span>*</span></label><input type="number" class="fi" id="fIE" placeholder="0" min="0"></div>
            <div class="fg"><label class="fl">Items Received <span>*</span></label><input type="number" class="fi" id="fIR" placeholder="0" min="0"></div>
        </div>
        <div class="sdv">Inspection</div>
        <div class="fr">
            <div class="fg">
                <label class="fl">Condition</label>
                <select class="fs" id="fCond"><option>Good</option><option>Minor Damage</option><option>Damaged</option><option>Mixed</option></select>
            </div>
            <div class="fg"><label class="fl">Inspected By</label><input type="text" class="fi" id="fIB" placeholder="Inspector name"></div>
        </div>
        <div class="fr">
            <div class="fg">
                <label class="fl">Status</label>
                <select class="fs" id="fSt">
                    <option>Pending</option><option>Received</option><option>Partially Received</option>
                    <option>Rejected</option><option>Disputed</option><option>Completed</option>
                </select>
            </div>
            <div class="fg">
                <label class="fl">Flag Issue</label>
                <select class="fs" id="fFlagSel">
                    <option value="0">No Flag</option><option value="1">Flag — Short Delivery</option>
                    <option value="2">Flag — Damage</option><option value="3">Flag — Wrong Items</option>
                    <option value="4">Flag — Missing Docs</option>
                </select>
            </div>
        </div>
        <div class="fg"><label class="fl">Inspection Notes</label><textarea class="fta" id="fNote" placeholder="Describe condition, discrepancies, or issues found…"></textarea></div>
        <div class="fg"><label class="fl">Items Detail (one per line: Description × Qty)</label><textarea class="fta" id="fItems" placeholder="e.g. Safety Gloves L × 100&#10;Hard Hat Yellow × 50" style="min-height:80px"></textarea></div>
        <?php if ($CAN_OVERRIDE): ?>
        <div class="sa-sec">
            <div class="sa-hd"><i class='bx bx-shield-quarter'></i><span>Super Admin Controls</span></div>
            <div class="fg" style="margin-bottom:12px">
                <label class="fl">Override Inspection Result</label>
                <select class="fs" id="fOverride"><option value="0">No Override</option><option value="1">Override — Force Accept</option><option value="2">Override — Force Reject</option></select>
            </div>
            <div class="fg"><label class="fl">Override Reason (SA Only)</label><textarea class="fta" id="fSaN" placeholder="Reason for overriding…" style="min-height:60px"></textarea></div>
            <div class="fg" style="margin-top:12px">
                <label class="fl">Trigger Cross-Module Update</label>
                <select class="fs" id="fCrossUpdate">
                    <option value="0">No Action</option><option value="sws">Update SWS Stock</option>
                    <option value="alms">Create ALMS Asset Entry</option><option value="both">Both SWS &amp; ALMS</option>
                </select>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="p-ft">
        <button class="btn btn-g" id="pCa">Cancel</button>
        <button class="btn btn-p" id="pSv"><i class='bx bx-check'></i> Save Receipt</button>
    </div>
</div>
<?php endif; ?>

<!-- VIEW MODAL -->
<div id="modal">
    <div class="mbox">
        <div class="m-hd">
            <div class="m-top">
                <div class="m-si">
                    <div class="m-av"><i class='bx bx-package'></i></div>
                    <div><div class="m-nm" id="mNm"></div><div class="m-id" id="mId"></div></div>
                </div>
                <button class="m-cl" id="mCl"><i class='bx bx-x'></i></button>
            </div>
            <div class="m-mt" id="mMt"></div>
            <div class="m-tabs">
                <button class="m-tab active" data-t="ov">Overview</button>
                <button class="m-tab" data-t="items">Items</button>
                <?php if ($CAN_VIEW_FULL_COLS): // Admin+ see cross-module tab ?>
                <button class="m-tab" data-t="cross">Cross-Module</button>
                <?php endif; ?>
                <button class="m-tab" data-t="hist">Audit Log</button>
            </div>
        </div>
        <div class="m-bd">
            <div class="tp active" id="tp-ov"><div id="mSbs" class="sbs"></div><div id="mIn" class="ig"></div></div>
            <div class="tp" id="tp-items"><table class="itbl"><thead><tr><th>Item Description</th><th>Expected</th><th>Received</th><th>Variance</th><th>Condition</th></tr></thead><tbody id="mItems"></tbody></table></div>
            <?php if ($CAN_VIEW_FULL_COLS): ?>
            <div class="tp" id="tp-cross"><div id="mCross"></div></div>
            <?php endif; ?>
            <div class="tp" id="tp-hist"><div id="mHist"></div></div>
        </div>
        <div class="m-ft">
            <?php if ($CAN_ACCEPT_REJECT): ?>
            <button class="btn btn-teal btn-s" id="mConfirmAccept" title="Confirm Acceptance"><i class='bx bx-check-circle'></i> Accept</button>
            <button class="btn btn-warn btn-s" id="mReject" title="Reject Delivery"><i class='bx bx-x-circle'></i> Reject</button>
            <?php endif; ?>
            <?php if ($CAN_OVERRIDE): ?>
            <button class="btn btn-teal btn-s" id="mOverride" style="display:none" title="SA Override"><i class='bx bx-transfer-alt'></i> Override</button>
            <?php endif; ?>
            <?php if ($CAN_FLAG): ?>
            <button class="btn btn-g btn-s" id="mFlag" title="Flag Issue"><i class='bx bx-flag'></i> Flag</button>
            <?php endif; ?>
            <?php if ($CAN_RECORD): ?>
            <button class="btn btn-g btn-s" id="mEd" title="Edit"><i class='bx bx-edit-alt'></i> Edit</button>
            <?php endif; ?>
            <button class="btn btn-g btn-s" id="mCf">Close</button>
        </div>
    </div>
</div>

<!-- OVERRIDE MODAL — Super Admin only -->
<?php if ($CAN_OVERRIDE): ?>
<div id="overrideModal">
    <div class="ov-box">
        <div class="ov-hd"><div class="ov-ic"><i class='bx bx-transfer-alt'></i></div><div><div class="ov-title">Override Inspection Result</div><div class="ov-sub" id="ovSub">Super Admin override.</div></div></div>
        <div class="ov-body">
            <div class="ov-warn"><i class='bx bx-error'></i><div><strong>Warning:</strong> Overriding bypasses normal QC workflow. This action is logged and visible to all admins.</div></div>
            <div class="fg"><label class="fl">Override Action <span>*</span></label><select class="fs" id="ovAction"><option value="">Select action…</option><option value="force_accept">Force Accept — Mark as Completed</option><option value="force_reject">Force Reject — Block from inventory</option><option value="partial">Partial Override — Accept with conditions</option></select></div>
            <div class="fg"><label class="fl">Justification <span>*</span></label><textarea class="fta" id="ovReason" placeholder="State the operational reason…"></textarea></div>
            <div class="fg"><label class="fl">Trigger Cross-Module Update</label><select class="fs" id="ovCrossUpdate"><option value="0">No Action</option><option value="sws">Update SWS Stock</option><option value="alms">Create ALMS Asset Entry</option><option value="both">Both SWS &amp; ALMS</option></select></div>
        </div>
        <div class="ov-ft"><button class="btn btn-g btn-s" id="ovCancel">Cancel</button><button class="btn btn-teal btn-s" id="ovConfirm"><i class='bx bx-transfer-alt'></i> Apply Override</button></div>
    </div>
</div>
<?php endif; ?>

<!-- REJECT MODAL — Admin+ only -->
<?php if ($CAN_ACCEPT_REJECT): ?>
<div id="rejectModal">
    <div class="rj-box">
        <div class="rj-hd"><div class="rj-ic"><i class='bx bx-x-circle'></i></div><div><div class="rj-title">Reject Delivery</div><div class="rj-sub" id="rjSub">Supplier will be notified.</div></div></div>
        <div class="rj-body">
            <div class="rj-warn"><i class='bx bx-error-circle'></i><div>Rejecting this delivery will notify the supplier and log a formal dispute. Items will not be added to inventory.</div></div>
            <div class="fg"><label class="fl">Rejection Reason <span>*</span></label><select class="fs" id="rjReason"><option value="">Select reason…</option><option>Items damaged beyond use</option><option>Wrong items delivered</option><option>Significant quantity shortage</option><option>Missing documentation</option><option>Failed quality inspection</option><option>Delivery past due date</option><option>Other</option></select></div>
            <div class="fg"><label class="fl">Rejection Notes</label><textarea class="fta" id="rjNotes" placeholder="Provide details for the supplier notification…"></textarea></div>
        </div>
        <div class="rj-ft"><button class="btn btn-g btn-s" id="rjCancel">Cancel</button><button class="btn btn-danger btn-s" id="rjConfirm"><i class='bx bx-x-circle'></i> Confirm Rejection</button></div>
    </div>
</div>
<?php endif; ?>

<!-- FLAG MODAL — Manager+ -->
<?php if ($CAN_FLAG): ?>
<div id="flagModal">
    <div class="fl-box">
        <div class="fl-hd"><div class="fl-ic"><i class='bx bx-flag'></i></div><div><div class="fl-title">Flag Issue</div><div class="fl-sub" id="flSub">Flag a delivery discrepancy for review.</div></div></div>
        <div class="fl-body">
            <?php if ($roleName === 'Manager'): ?>
            <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:10px 14px;font-size:12px;color:#78350F;display:flex;gap:8px"><i class='bx bx-info-circle' style="font-size:16px;color:var(--warning);flex-shrink:0"></i><div>This flag will be sent to Admin for review. You cannot confirm or reject directly.</div></div>
            <?php endif; ?>
            <div class="fg"><label class="fl">Issue Type <span>*</span></label><select class="fs" id="flType"><option value="">Select issue…</option><option value="1">Short Delivery</option><option value="2">Damage</option><option value="3">Wrong Items</option><option value="4">Missing Docs</option><option value="5">Quality Issue</option></select></div>
            <div class="fg"><label class="fl">Flag Notes</label><textarea class="fta" id="flNotes" placeholder="Describe the issue in detail…"></textarea></div>
        </div>
        <div class="fl-ft"><button class="btn btn-g btn-s" id="flCancel">Cancel</button><button class="btn btn-warn btn-s" id="flConfirm"><i class='bx bx-flag'></i> Submit Flag</button></div>
    </div>
</div>
<?php endif; ?>

<div id="tw"></div>

<script>
// ── ROLE CAPABILITIES (from PHP) ─────────────────────────────────────────────
const ROLE = <?= $jsRole ?>;

// ── API BASE URL ──────────────────────────────────────────────────────────────
const API = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';

// ── UTILS ─────────────────────────────────────────────────────────────────────
const esc     = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'}) : '—';
const FLAG_LABELS = ['','Short Delivery','Damage','Wrong Items','Missing Docs','Quality Issue'];
const FLAG_COLORS = ['','var(--warning)','var(--danger)','var(--purple)','var(--info)','var(--teal)'];

// ── API HELPERS ───────────────────────────────────────────────────────────────
async function apiFetch(path, opts={}) {
    const r = await fetch(path, {headers:{'Content-Type':'application/json'}, ...opts});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = path => apiFetch(path);
const apiPost = (path, body) => apiFetch(path, {method:'POST', body:JSON.stringify(body)});

// ── STATE ─────────────────────────────────────────────────────────────────────
let D=[], SUPPLIERS=[], POS=[], SUPPLIERS_LIST=[];
let eId=null, prId=null, pg=1, PG=10, sc='deliveryDate', sd='desc';
let ovTargetId=null, rjTargetId=null, flTargetId=null;

// ── LOAD ALL ──────────────────────────────────────────────────────────────────
async function loadAll() {
    try {
        const fetches = [apiGet(API+'?api=list'), apiGet(API+'?api=suppliers')];
        if (ROLE.canRecord) fetches.push(apiGet(API+'?api=pos'));
        const results = await Promise.all(fetches);
        D = results[0];
        SUPPLIERS_LIST = results[1];
        POS = ROLE.canRecord ? results[2] : [];
        rSupplierDropdowns();
        rPoFilter();
        if (ROLE.canRecord) rPoList();
    } catch(e) { toast('Failed to load data: '+e.message, 'danger'); }
    render();
}

function rSupplierDropdowns() {
    SUPPLIERS = [...new Set(D.map(x=>x.supplier))].sort();
    const fSup = document.getElementById('fSupplier');
    const prev = fSup.value;
    fSup.innerHTML = '<option value="">All Suppliers</option>' +
        SUPPLIERS.map(s=>`<option${s===prev?' selected':''}>${esc(s)}</option>`).join('');

    const pSup = document.getElementById('fSup');
    if (!pSup) return;
    const prevP = pSup.value;
    const list = SUPPLIERS_LIST.length ? SUPPLIERS_LIST : SUPPLIERS.map(s=>({name:s,cat:''}));
    pSup.innerHTML = '<option value="">Select supplier…</option>' +
        list.map(s=>`<option value="${esc(s.name)}">${esc(s.name)}${s.cat?' — '+esc(s.cat):''}</option>`).join('');
    if (prevP) pSup.value = prevP;
}

function rPoFilter() {
    const el = document.getElementById('fPO');
    const prev = el.value;
    const refs = [...new Set(D.map(x=>x.poRef))].sort();
    el.innerHTML = '<option value="">All PO Refs</option>' +
        refs.map(r=>`<option${r===prev?' selected':''}>${esc(r)}</option>`).join('');
}

function rPoList() {
    const dl = document.getElementById('poList');
    if (!dl) return;
    dl.innerHTML = POS.map(p=>`<option value="${esc(p.po_number)}">${esc(p.po_number)} — ${esc(p.supplier_name)}${p.total_qty?' ('+p.total_qty+' items)':''}</option>`).join('');
}

// Auto-fill when PO selected
const fPOiEl = document.getElementById('fPOi');
if (fPOiEl) {
    fPOiEl.addEventListener('input', function(){
        const val = this.value.trim();
        const po = POS.find(p => p.po_number === val);
        if (po) {
            const supEl = document.getElementById('fSup');
            if (po.supplier_name && supEl) supEl.value = po.supplier_name;
            if (po.total_qty && po.total_qty > 0) document.getElementById('fIE').value = po.total_qty;
            else if (po.item_count && po.item_count > 0) document.getElementById('fIE').value = po.item_count;
        }
    });
}

// ── CHIPS ─────────────────────────────────────────────────────────────────────
function statusChip(s) {
    const m={Pending:'chip-pending',Received:'chip-received','Partially Received':'chip-partial',Rejected:'chip-rejected',Disputed:'chip-disputed',Completed:'chip-completed'};
    return `<span class="chip ${m[s]||'chip-pending'}">${esc(s)}</span>`;
}
function condBadge(c) {
    const m={Good:'cond-good','Minor Damage':'cond-minor',Damaged:'cond-damaged',Mixed:'cond-mixed','—':'cond-na'};
    const ic={Good:'bx-check-circle','Minor Damage':'bx-error',Damaged:'bx-x-circle',Mixed:'bx-git-merge','—':'bx-minus'};
    return `<span class="cond ${m[c]||'cond-na'}"><i class='bx ${ic[c]||'bx-minus'}'></i>${esc(c)}</span>`;
}
function qtyDisplay(exp,rec) {
    const pct=exp>0?Math.round(rec/exp*100):100;
    const cls=pct>=100?'qty-ok':pct>=80?'qty-warn':'qty-low';
    const bar=pct>=100?'bar-g':pct>=80?'bar-o':'bar-r';
    return `<div class="qty-wrap ${cls}">${rec}<span style="font-weight:400;color:var(--text-3);font-size:11px"> / ${exp}</span></div>
            <div class="qty-bar-wrap"><div class="qty-bar ${bar}" style="width:${Math.min(pct,100)}%"></div></div>`;
}

// ── FILTER / SORT ─────────────────────────────────────────────────────────────
function gFilt() {
    const q  = document.getElementById('srch').value.trim().toLowerCase();
    const sp = document.getElementById('fSupplier').value;
    const po = document.getElementById('fPO').value;
    const st = document.getElementById('fStatus').value;
    const df = document.getElementById('fDateFrom').value;
    const dt = document.getElementById('fDateTo').value;
    return D.filter(s=>{
        if(q && !s.poRef.toLowerCase().includes(q) && !s.supplier.toLowerCase().includes(q) && !s.receiptNo.toLowerCase().includes(q)) return false;
        if(sp && s.supplier!==sp) return false;
        if(po && s.poRef!==po)    return false;
        if(st && s.status!==st)   return false;
        if(df && s.deliveryDate<df) return false;
        if(dt && s.deliveryDate>dt) return false;
        return true;
    });
}
function gSort(list) {
    return [...list].sort((a,b)=>{
        let va=a[sc]??'', vb=b[sc]??'';
        if(sc==='itemsExpected'||sc==='itemsReceived'){va=Number(va);vb=Number(vb);}
        if(typeof va==='number') return sd==='asc'?va-vb:vb-va;
        return sd==='asc'?String(va).localeCompare(String(vb)):String(vb).localeCompare(String(va));
    });
}

// ── RENDER ────────────────────────────────────────────────────────────────────
function render(){rStats();rTable();rBanner();}

function rStats(){
    const total    =D.length;
    const pending  =D.filter(s=>s.status==='Pending').length;
    const received =D.filter(s=>s.status==='Received').length;
    const partial  =D.filter(s=>s.status==='Partially Received').length;

    if (ROLE.rank <= 2) {
        // Manager: simplified stats (only visible statuses)
        document.getElementById('statsRow').innerHTML=`
            <div class="ri-stat"><div class="sc-ic ic-b"><i class='bx bx-package'></i></div><div><div class="ri-sv">${total}</div><div class="ri-sl">Total Receipts</div></div></div>
            <div class="ri-stat"><div class="sc-ic ic-pu"><i class='bx bx-time-five'></i></div><div><div class="ri-sv">${pending}</div><div class="ri-sl">Pending</div></div></div>
            <div class="ri-stat"><div class="sc-ic ic-g"><i class='bx bx-check'></i></div><div><div class="ri-sv">${received}</div><div class="ri-sl">Received</div></div></div>
            <div class="ri-stat"><div class="sc-ic ic-o"><i class='bx bx-git-merge'></i></div><div><div class="ri-sv">${partial}</div><div class="ri-sl">Partially Received</div></div></div>`;
        return;
    }

    // Admin / SA: full stats
    const disputed =D.filter(s=>s.status==='Disputed').length;
    const completed=D.filter(s=>s.status==='Completed').length;
    document.getElementById('statsRow').innerHTML=`
        <div class="ri-stat"><div class="sc-ic ic-b"><i class='bx bx-package'></i></div><div><div class="ri-sv">${total}</div><div class="ri-sl">Total Receipts</div></div></div>
        <div class="ri-stat"><div class="sc-ic ic-pu"><i class='bx bx-time-five'></i></div><div><div class="ri-sv">${pending}</div><div class="ri-sl">Pending</div></div></div>
        <div class="ri-stat"><div class="sc-ic ic-g"><i class='bx bx-check'></i></div><div><div class="ri-sv">${received}</div><div class="ri-sl">Received</div></div></div>
        <div class="ri-stat"><div class="sc-ic ic-o"><i class='bx bx-git-merge'></i></div><div><div class="ri-sv">${partial}</div><div class="ri-sl">Partially Received</div></div></div>
        <div class="ri-stat"><div class="sc-ic ic-r"><i class='bx bx-error-circle'></i></div><div><div class="ri-sv">${disputed}</div><div class="ri-sl">Disputed</div></div></div>
        <div class="ri-stat"><div class="sc-ic ic-t"><i class='bx bx-check-circle'></i></div><div><div class="ri-sv">${completed}</div><div class="ri-sl">Completed</div></div></div>`;
}

function rBanner(){
    const disp=D.filter(s=>s.status==='Disputed');
    const b=document.getElementById('dispBanner');
    if(!disp.length||!b){b&&b.classList.add('hidden');return;}
    b.classList.remove('hidden');
    document.getElementById('bannerTxt').innerHTML=`<strong>${disp.length} delivery${disp.length>1?'s':''}</strong> disputed: ${disp.slice(0,3).map(s=>`<strong>${esc(s.poRef)}</strong>`).join(', ')}${disp.length>3?` +${disp.length-3} more`:''}`;
}

function rTable(){
    const list=gSort(gFilt()), total=list.length, pages=Math.max(1,Math.ceil(total/PG));
    if(pg>pages) pg=pages;
    const sl=list.slice((pg-1)*PG,pg*PG);
    const tb=document.getElementById('tb');
    tb.innerHTML=sl.length?sl.map((s,i)=>{
        const rn=(pg-1)*PG+i+1;
        const rowCls=s.status==='Disputed'||s.status==='Rejected'?'disputed-row':s.status==='Partially Received'?'partial-row':'';
        const flagIco=s.flag?`<i class='bx bx-error-circle flag-ic' title="Flagged: ${FLAG_LABELS[s.flag]}" style="color:${FLAG_COLORS[s.flag]}"></i>`:'';

        // Condition column — Admin+ only
        const condCol = ROLE.canViewFullCols ? `<td>${condBadge(s.condition)}</td>` : '';

        // Build action buttons based on role
        let actions = `<button class="btn btn-g" onclick="oPr(${s.id})" title="View"><i class='bx bx-show'></i></button>`;
        if (ROLE.canRecord)
            actions += ` <button class="btn btn-g" onclick="oEd(${s.id})" title="Edit"><i class='bx bx-edit-alt'></i></button>`;

        // Dropdown: contents depend on role
        let dropItems = '';
        if (ROLE.canAcceptReject) {
            dropItems += `<button onclick="closeDrop(${s.id});quickAccept(${s.id})" class="teal"><i class='bx bx-check-circle'></i> Confirm Acceptance</button>`;
            dropItems += `<div class="drop-div"></div>`;
            dropItems += `<button onclick="closeDrop(${s.id});openReject(${s.id})" class="danger"><i class='bx bx-x-circle'></i> Reject Delivery</button>`;
        }
        if (ROLE.canFlag) {
            if (dropItems) dropItems += `<div class="drop-div"></div>`;
            dropItems += `<button onclick="closeDrop(${s.id});openFlag(${s.id})"><i class='bx bx-flag'></i> Flag Issue</button>`;
        }

        const dropMenu = dropItems ? `
            <div class="act-more" id="more-${s.id}">
                <button class="act-more-btn" onclick="toggleDrop(${s.id},event)"><i class='bx bx-dots-vertical-rounded'></i></button>
                <div class="act-drop" id="drop-${s.id}">${dropItems}</div>
            </div>` : '';

        return `<tr data-id="${s.id}" class="${rowCls}">
            <td style="color:#5D6F62;font-size:11px;font-weight:600">${rn}</td>
            <td><span class="mono">${esc(s.poRef)}</span><span class="sub">${esc(s.receiptNo)}</span></td>
            <td><span class="bold">${esc(s.supplier)}</span></td>
            <td><span style="font-size:11px;color:var(--text-2)">${fmtDate(s.deliveryDate)}</span></td>
            <td><span style="font-size:12px;font-weight:600">${s.itemsExpected}</span></td>
            <td>${qtyDisplay(s.itemsExpected,s.itemsReceived)}</td>
            ${condCol}
            <td><span style="font-size:11px;font-weight:600;color:var(--text-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block">${esc(s.inspectedBy)}</span></td>
            <td><div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap">${statusChip(s.status)}${flagIco}</div></td>
            <td onclick="event.stopPropagation()" style="overflow:visible">
                <div class="act-wrap">${actions}${dropMenu}</div>
            </td>
        </tr>`;
    }).join(''):`<tr><td colspan="${ROLE.canViewFullCols?10:9}"><div class="ri-empty"><i class='bx bx-package'></i><p>No receipts match your filters.</p></div></td></tr>`;

    document.querySelectorAll('thead th.sortable').forEach(th=>{
        th.classList.toggle('sorted',th.dataset.col===sc);
        th.querySelector('.si').className=th.dataset.col===sc?`bx ${sd==='asc'?'bx-sort-up':'bx-sort-down'} si`:'bx bx-sort si';
    });
    rPag(total,pages);
}

function toggleDrop(id,e){
    e.stopPropagation();
    document.querySelectorAll('.act-drop.open').forEach(d=>{d.classList.remove('open');d.style.display='';});
    const drop=document.getElementById('drop-'+id);
    if(!drop) return;
    const btn=e.currentTarget;
    const rect=btn.getBoundingClientRect();
    if(drop.parentElement!==document.body) document.body.appendChild(drop);
    const dropH=130;
    const right=window.innerWidth-rect.right;
    const top=rect.top-dropH-4;
    drop.style.cssText=`position:fixed;display:block;z-index:9000;top:${top}px;right:${right}px;left:auto;min-width:180px;`;
    drop.classList.add('open');
}
function closeDrop(id){
    const d=document.getElementById('drop-'+id);
    if(d){d.classList.remove('open');d.style.display='';}
}
document.addEventListener('click',()=>document.querySelectorAll('.act-drop.open').forEach(d=>{d.classList.remove('open');d.style.display='';}));
window.toggleDrop=toggleDrop; window.closeDrop=closeDrop;

function rPag(total,pages){
    const el=document.getElementById('pag'),s=(pg-1)*PG+1,e=Math.min(pg*PG,total);
    let btns='';
    for(let i=1;i<=pages;i++){
        if(i===1||i===pages||(i>=pg-2&&i<=pg+2)) btns+=`<button class="ri-pb${i===pg?' active':''}" onclick="goPg(${i})">${i}</button>`;
        else if(i===pg-3||i===pg+3) btns+=`<button class="ri-pb" disabled>…</button>`;
    }
    el.innerHTML=`<span>${total?`Showing ${s}–${e} of ${total} receipts`:'No results'}</span>
        <div class="ri-pbtns">
            <button class="ri-pb" onclick="goPg(${pg-1})" ${pg<=1?'disabled':''}><i class='bx bx-chevron-left'></i></button>
            ${btns}
            <button class="ri-pb" onclick="goPg(${pg+1})" ${pg>=pages?'disabled':''}><i class='bx bx-chevron-right'></i></button>
        </div>`;
}
window.goPg=p=>{pg=p;rTable();};

document.querySelectorAll('thead th.sortable').forEach(th=>{
    th.addEventListener('click',()=>{
        const c=th.dataset.col;
        sc===c?sd=sd==='asc'?'desc':'asc':(sc=c,sd='asc');
        pg=1;rTable();
    });
});

['srch','fSupplier','fPO','fStatus','fDateFrom','fDateTo'].forEach(id=>
    document.getElementById(id)?.addEventListener('input',()=>{pg=1;rTable();})
);
document.getElementById('clearFilters').addEventListener('click',()=>{
    ['srch','fDateFrom','fDateTo'].forEach(id=>document.getElementById(id).value='');
    ['fSupplier','fPO','fStatus'].forEach(id=>document.getElementById(id).value='');
    pg=1;rTable();
});

// Quick filter buttons — Admin+ only
document.getElementById('viewDisputedBtn')?.addEventListener('click',()=>{
    document.getElementById('fStatus').value='Disputed';pg=1;rTable();
    toast(`Showing ${D.filter(s=>s.status==='Disputed').length} disputed deliveries`,'danger');
});
document.getElementById('viewPendingBtn')?.addEventListener('click',()=>{
    document.getElementById('fStatus').value='Pending';pg=1;rTable();
    toast(`Showing ${D.filter(s=>s.status==='Pending').length} pending deliveries`,'info');
});
document.getElementById('bannerViewBtn')?.addEventListener('click',()=>{
    document.getElementById('fStatus').value='Disputed';pg=1;rTable();
});
document.getElementById('expBtn')?.addEventListener('click',()=>{
    const cols=ROLE.canViewFullCols
        ?['receiptNo','poRef','supplier','deliveryDate','itemsExpected','itemsReceived','condition','inspectedBy','status']
        :['receiptNo','poRef','supplier','deliveryDate','itemsExpected','itemsReceived','inspectedBy','status'];
    const rows=[cols.join(','),...D.map(s=>cols.map(c=>`"${String(s[c]??'').replace(/"/g,'""')}"`).join(','))];
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
    a.download='receiving_inspection_export.csv';a.click();
    toast('Receipts exported','success');
});

document.getElementById('tb').addEventListener('click',function(e){
    const tr=e.target.closest('tr[data-id]');
    if(!tr||e.target.closest('button')||e.target.closest('.act-more')) return;
    oPr(parseInt(tr.dataset.id));
});

// ── QUICK ACCEPT — Admin+ ─────────────────────────────────────────────────────
window.quickAccept = async function(id) {
    if (!ROLE.canAcceptReject) { toast('Insufficient permissions','danger'); return; }
    const s=D.find(x=>x.id===id); if(!s)return;
    if(s.status==='Completed'){toast('"'+s.receiptNo+'" already completed','info');return;}
    try {
        const updated = await apiPost(API+'?api=action', {id, type:'accept'});
        const idx=D.findIndex(x=>x.id===id); if(idx>-1) D[idx]=updated;
        toast('"'+updated.receiptNo+'" confirmed and completed','success');
        if(prId===id){cPr();oPr(id);}
        render();
    } catch(e){toast(e.message,'danger');}
};

// ── VIEW MODAL ────────────────────────────────────────────────────────────────
function oPr(id){
    const s=D.find(x=>x.id===id);if(!s)return;
    prId=id;
    const flagStr=s.flag?`<span style="font-size:11px;background:#FEF3C7;color:var(--warning);padding:3px 10px;border-radius:20px;font-weight:700;margin-left:4px"><i class='bx bx-flag' style='font-size:11px;vertical-align:-1px'></i> ${FLAG_LABELS[s.flag]}</span>`:'';
    const ovStr  =s.override?`<span style="font-size:11px;background:#CCFBF1;color:var(--teal);padding:3px 10px;border-radius:20px;font-weight:700;margin-left:4px"><i class='bx bx-transfer-alt' style='font-size:11px;vertical-align:-1px'></i> Overridden</span>`:'';
    document.getElementById('mNm').innerHTML=esc(s.receiptNo)+flagStr+ovStr;
    document.getElementById('mId').innerHTML=`${esc(s.poRef)} &nbsp;·&nbsp; ${esc(s.supplier)} &nbsp;${statusChip(s.status)}`;
    const pct=s.itemsExpected>0?Math.round(s.itemsReceived/s.itemsExpected*100):100;
    const barC=pct>=100?'bar-g':pct>=80?'bar-o':'bar-r';
    document.getElementById('mMt').innerHTML=`
        <div class="m-mc"><i class='bx bx-buildings'></i>${esc(s.supplier)}</div>
        <div class="m-mc"><i class='bx bx-calendar'></i>${fmtDate(s.deliveryDate)}</div>
        <div class="m-mc"><i class='bx bx-map-pin'></i>${esc(s.location||'—')}</div>
        <div class="m-mc"><i class='bx bx-user'></i>Inspected by ${esc(s.inspectedBy)}</div>
        <div class="m-mc" style="flex-direction:column;align-items:flex-start;gap:4px;min-width:160px">
            <span style="font-size:11px;color:var(--text-3)">Fulfillment (${pct}%)</span>
            <div class="qty-bar-wrap"><div class="qty-bar ${barC}" style="width:${Math.min(pct,100)}%"></div></div>
        </div>`;

    // Scorecard — Manager sees simpler 2-box version
    if (ROLE.rank <= 2) {
        document.getElementById('mSbs').innerHTML=`
            <div class="sb"><div class="sbv">${s.itemsExpected}</div><div class="sbl">Expected</div></div>
            <div class="sb"><div class="sbv">${s.itemsReceived}</div><div class="sbl">Received</div></div>`;
    } else {
        document.getElementById('mSbs').innerHTML=`
            <div class="sb"><div class="sbv">${s.itemsExpected}</div><div class="sbl">Expected</div></div>
            <div class="sb"><div class="sbv">${s.itemsReceived}</div><div class="sbl">Received</div></div>
            <div class="sb"><div class="sbv" style="color:${s.itemsReceived<s.itemsExpected?'var(--danger)':'var(--primary)'}">${s.itemsExpected-s.itemsReceived}</div><div class="sbl">Variance</div></div>
            <div class="sb"><div class="sbv">${pct}%</div><div class="sbl">Fulfillment</div></div>`;
    }

    // Info grid — Manager: limited fields
    if (ROLE.rank <= 2) {
        document.getElementById('mIn').innerHTML=`
            <div class="ii"><label>PO Reference</label><div class="v" style="font-family:'DM Mono',monospace;color:var(--info);font-weight:700">${esc(s.poRef)}</div></div>
            <div class="ii"><label>Supplier</label><div class="v">${esc(s.supplier)}</div></div>
            <div class="ii"><label>Delivery Date</label><div class="v">${fmtDate(s.deliveryDate)}</div></div>
            <div class="ii"><label>Inspected By</label><div class="v">${esc(s.inspectedBy)}</div></div>
            <div class="ii"><label>Status</label><div class="v">${statusChip(s.status)}</div></div>
            <div class="ii"><label>Flag</label><div class="v">${s.flag?`<span style="color:${FLAG_COLORS[s.flag]};font-weight:700"><i class='bx bx-flag' style='vertical-align:-2px;margin-right:4px'></i>${FLAG_LABELS[s.flag]}</span>`:'<span style="color:#6B7280">None</span>'}</div></div>`;
    } else {
        document.getElementById('mIn').innerHTML=`
            <div class="ii"><label>Receipt No.</label><div class="v" style="font-family:'DM Mono',monospace;color:var(--primary);font-weight:700">${esc(s.receiptNo)}</div></div>
            <div class="ii"><label>PO Reference</label><div class="v" style="font-family:'DM Mono',monospace;color:var(--info);font-weight:700">${esc(s.poRef)}</div></div>
            <div class="ii"><label>Supplier</label><div class="v">${esc(s.supplier)}</div></div>
            <div class="ii"><label>Delivery Date</label><div class="v">${fmtDate(s.deliveryDate)}</div></div>
            <div class="ii"><label>Location</label><div class="v">${esc(s.location||'—')}</div></div>
            <div class="ii"><label>Condition</label><div class="v">${condBadge(s.condition)}</div></div>
            <div class="ii"><label>Inspected By</label><div class="v">${esc(s.inspectedBy)}</div></div>
            <div class="ii"><label>Status</label><div class="v">${statusChip(s.status)}</div></div>
            <div class="ii"><label>Flag</label><div class="v">${s.flag?`<span style="color:${FLAG_COLORS[s.flag]};font-weight:700"><i class='bx bx-flag' style='vertical-align:-2px;margin-right:4px'></i>${FLAG_LABELS[s.flag]}</span>`:'<span style="color:#6B7280">None</span>'}</div></div>
            ${s.notes?`<div class="ii full"><label>Inspection Notes</label><div class="v">${esc(s.notes)}</div></div>`:''}
            ${s.saNotes?`<div class="ii full" style="background:rgba(46,125,50,.04);border:1px solid rgba(46,125,50,.12);border-radius:10px;padding:12px"><label style="color:var(--primary)">SA Notes / Override Reason</label><div class="v" style="color:var(--primary)">${esc(s.saNotes)}</div></div>`:''}`;
    }

    // Items tab
    document.getElementById('mItems').innerHTML=(s.items||[]).map(it=>{
        const v=it.exp-it.rec;
        const pct2=it.exp>0?Math.min(100,Math.round(it.rec/it.exp*100)):100;
        const bar2=it.rec>=it.exp?'bar-g':it.rec>=it.exp*.8?'bar-o':'bar-r';
        return `<tr>
            <td style="font-weight:600">${esc(it.desc)}</td>
            <td>${it.exp}</td>
            <td><div class="qty-wrap ${it.rec>=it.exp?'qty-ok':it.rec>=it.exp*.8?'qty-warn':'qty-low'}">${it.rec}</div>
                <div class="qty-bar-wrap"><div class="qty-bar ${bar2}" style="width:${pct2}%"></div></div></td>
            <td style="font-weight:700;color:${v>0?'var(--danger)':v<0?'var(--warning)':'var(--primary)'}">${v>0?'-'+v:v<0?'+'+Math.abs(v):'✓'}</td>
            <td>${condBadge(it.cond)}</td>
        </tr>`;
    }).join('') || '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-3)">No line items recorded.</td></tr>';

    // Cross-module tab — Admin+ only (element only exists in DOM for them)
    const mCrossEl = document.getElementById('mCross');
    if (mCrossEl) {
        const cu=s.crossUpdate;
        const hasSws=cu==='sws'||cu==='both';
        const hasAlms=cu==='alms'||cu==='both';
        const crossItems=[];
        if(s.status==='Completed'||s.override){
            if(hasSws)  crossItems.push({mod:'SWS',ic:'bx-cube',cls:'sws',lbl:'Smart Warehousing System',sub:`Stock updated — ${s.itemsReceived} units added to inventory`,st:'done'});
            if(hasAlms) crossItems.push({mod:'ALMS',ic:'bx-wrench',cls:'alms',lbl:'Asset Lifecycle & Maintenance',sub:'Asset entry created for received equipment items',st:'done'});
            if(!hasSws&&!hasAlms) crossItems.push({mod:'SWS',ic:'bx-cube',cls:'sws',lbl:'Smart Warehousing System',sub:'No cross-module update triggered',st:'pend'});
        } else if(s.status==='Pending') {
            crossItems.push({mod:'SWS',ic:'bx-cube',cls:'sws',lbl:'Smart Warehousing System',sub:'Awaiting acceptance before inventory update',st:'pend'});
        } else if(s.status==='Disputed'||s.status==='Rejected') {
            crossItems.push({mod:'SWS',ic:'bx-cube',cls:'sws',lbl:'Smart Warehousing System',sub:'Inventory update blocked — delivery disputed or rejected',st:'pend'});
        } else if(s.status==='Partially Received') {
            crossItems.push({mod:'SWS',ic:'bx-cube',cls:'sws',lbl:'Smart Warehousing System',sub:`Partial update — ${s.itemsReceived} units added`,st:hasSws?'done':'pend'});
        }
        mCrossEl.innerHTML=crossItems.length?`
            <div class="cross-panel">
                <div class="cross-hd"><i class='bx bx-link-external'></i><span>Cross-Module Updates</span></div>
                ${crossItems.map(c=>`
                <div class="cross-item">
                    <div class="cross-ic ${c.cls}"><i class='bx ${c.ic}'></i></div>
                    <div><div class="cross-lbl">${c.lbl} <span class="cross-badge cb-${c.cls}">${c.mod}</span></div><div class="cross-sub">${c.sub}</div></div>
                    <div class="cross-st ${c.st}">${c.st==='done'?'✓ Updated':'⏳ Pending'}</div>
                </div>`).join('')}
            </div>
            <div class="ig">
                <div class="ii"><label>SWS Stock Status</label><div class="v">${hasSws&&(s.status==='Completed'||s.override)?'<span style="color:var(--teal);font-weight:700">Updated</span>':s.status==='Partially Received'?'<span style="color:var(--warning);font-weight:700">Partial</span>':'<span style="color:#6B7280">Pending</span>'}</div></div>
                <div class="ii"><label>ALMS Asset Entry</label><div class="v">${hasAlms&&(s.status==='Completed'||s.override)?'<span style="color:var(--purple);font-weight:700">Created</span>':'<span style="color:#6B7280">Not Triggered</span>'}</div></div>
            </div>`
            :`<div class="ri-empty"><i class='bx bx-link'></i><p>No cross-module updates for this delivery.</p></div>`;
    }

    // Audit log tab
    const logs=s.audit||[];
    document.getElementById('mHist').innerHTML=logs.length
        ?logs.map(h=>`<div class="tl-item"><div class="tl-dot ${h.t}"></div><div class="tl-body"><div class="au">${esc(h.m)}</div><div class="at">${h.by?`By ${esc(h.by)} · `:''}${esc(h.d)}</div></div></div>`).join('')
        :`<div class="tl-item"><div class="tl-dot blue"></div><div class="tl-body"><div class="au">Receipt recorded — ${esc(s.receiptNo)}</div><div class="at">${fmtDate(s.deliveryDate)}</div></div></div>`;

    // Override button visibility — SA only, and only for non-terminal statuses
    const mOverrideBtn = document.getElementById('mOverride');
    if (mOverrideBtn) mOverrideBtn.style.display=!['Completed','Rejected'].includes(s.status)?'inline-flex':'none';

    // Reset tabs — only activate tabs that exist in DOM
    document.querySelectorAll('.m-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tp').forEach(p=>p.classList.remove('active'));
    document.querySelector('.m-tab[data-t="ov"]').classList.add('active');
    document.getElementById('tp-ov').classList.add('active');
    document.getElementById('modal').classList.add('show');
}
window.oPr=oPr;

document.querySelectorAll('.m-tab').forEach(t=>t.addEventListener('click',()=>{
    document.querySelectorAll('.m-tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.tp').forEach(x=>x.classList.remove('active'));
    t.classList.add('active');
    document.getElementById('tp-'+t.dataset.t).classList.add('active');
}));
['mCl','mCf'].forEach(id=>document.getElementById(id)?.addEventListener('click',cPr));
document.getElementById('modal').addEventListener('click',function(e){if(e.target===this)cPr();});
function cPr(){document.getElementById('modal').classList.remove('show');prId=null;}
document.getElementById('mEd')?.addEventListener('click',()=>{const id=prId;cPr();oEd(id);});
document.getElementById('mConfirmAccept')?.addEventListener('click',()=>{if(prId)quickAccept(prId);});
document.getElementById('mReject')?.addEventListener('click',()=>{if(prId)openReject(prId);});
document.getElementById('mOverride')?.addEventListener('click',()=>{if(prId)openOverride(prId);});
document.getElementById('mFlag')?.addEventListener('click',()=>{if(prId)openFlag(prId);});

// ── OVERRIDE MODAL — SA only ──────────────────────────────────────────────────
<?php if ($CAN_OVERRIDE): ?>
function openOverride(id){
    const s=D.find(x=>x.id===id);if(!s)return;
    ovTargetId=id;
    document.getElementById('ovSub').textContent=`${s.receiptNo} — ${s.supplier}`;
    document.getElementById('ovAction').value='';
    document.getElementById('ovReason').value='';
    document.getElementById('ovCrossUpdate').value='0';
    document.getElementById('overrideModal').classList.add('show');
}
function closeOverride(){document.getElementById('overrideModal').classList.remove('show');ovTargetId=null;}
document.getElementById('ovCancel').addEventListener('click',closeOverride);
document.getElementById('overrideModal').addEventListener('click',function(e){if(e.target===this)closeOverride();});
document.getElementById('ovConfirm').addEventListener('click',async()=>{
    const action=document.getElementById('ovAction').value;
    const reason=document.getElementById('ovReason').value.trim();
    if(!action){shk('ovAction');return toast('Please select an override action','danger');}
    if(!reason){shk('ovReason');return toast('Justification is required','danger');}
    const btn=document.getElementById('ovConfirm');btn.disabled=true;
    try{
        const updated=await apiPost(API+'?api=action',{id:ovTargetId,type:'override',action,reason,crossUpdate:document.getElementById('ovCrossUpdate').value});
        const idx=D.findIndex(x=>x.id===ovTargetId);if(idx>-1)D[idx]=updated;
        const cuMsg={sws:' · SWS stock updated',alms:' · ALMS asset entry created',both:' · SWS + ALMS updated'}[updated.crossUpdate]||'';
        toast(`"${updated.receiptNo}" override applied${cuMsg}`,'teal');
        closeOverride();
        if(prId===ovTargetId){cPr();oPr(ovTargetId);}
        render();
    }catch(e){toast(e.message,'danger');}
    finally{btn.disabled=false;}
});
<?php else: ?>
function openOverride(id){toast('Insufficient permissions','danger');}
<?php endif; ?>

// ── REJECT MODAL — Admin+ ─────────────────────────────────────────────────────
<?php if ($CAN_ACCEPT_REJECT): ?>
function openReject(id){
    const s=D.find(x=>x.id===id);if(!s)return;
    rjTargetId=id;
    document.getElementById('rjSub').textContent=`${s.receiptNo} — ${s.supplier}`;
    document.getElementById('rjReason').value='';
    document.getElementById('rjNotes').value='';
    document.getElementById('rejectModal').classList.add('show');
}
window.openReject=openReject;
function closeReject(){document.getElementById('rejectModal').classList.remove('show');rjTargetId=null;}
document.getElementById('rjCancel').addEventListener('click',closeReject);
document.getElementById('rejectModal').addEventListener('click',function(e){if(e.target===this)closeReject();});
document.getElementById('rjConfirm').addEventListener('click',async()=>{
    const reason=document.getElementById('rjReason').value;
    if(!reason){shk('rjReason');return toast('Please select a rejection reason','danger');}
    const btn=document.getElementById('rjConfirm');btn.disabled=true;
    try{
        const updated=await apiPost(API+'?api=action',{id:rjTargetId,type:'reject',reason,notes:document.getElementById('rjNotes').value.trim()});
        const idx=D.findIndex(x=>x.id===rjTargetId);if(idx>-1)D[idx]=updated;
        toast(`"${updated.receiptNo}" rejected — supplier notified`,'danger');
        closeReject();
        if(prId===rjTargetId){cPr();oPr(rjTargetId);}
        render();
    }catch(e){toast(e.message,'danger');}
    finally{btn.disabled=false;}
});
<?php else: ?>
window.openReject=id=>toast('Insufficient permissions','danger');
<?php endif; ?>

// ── FLAG MODAL — Manager+ ─────────────────────────────────────────────────────
<?php if ($CAN_FLAG): ?>
function openFlag(id){
    const s=D.find(x=>x.id===id);if(!s)return;
    flTargetId=id;
    document.getElementById('flSub').textContent=`${s.receiptNo} — ${s.supplier}`;
    document.getElementById('flType').value='';
    document.getElementById('flNotes').value='';
    document.getElementById('flagModal').classList.add('show');
}
window.openFlag=openFlag;
function closeFlag(){document.getElementById('flagModal').classList.remove('show');flTargetId=null;}
document.getElementById('flCancel').addEventListener('click',closeFlag);
document.getElementById('flagModal').addEventListener('click',function(e){if(e.target===this)closeFlag();});
document.getElementById('flConfirm').addEventListener('click',async()=>{
    const flagType=parseInt(document.getElementById('flType').value);
    if(!flagType){shk('flType');return toast('Please select an issue type','danger');}
    const btn=document.getElementById('flConfirm');btn.disabled=true;
    try{
        const updated=await apiPost(API+'?api=action',{id:flTargetId,type:'flag',flagType,notes:document.getElementById('flNotes').value.trim()});
        const idx=D.findIndex(x=>x.id===flTargetId);if(idx>-1)D[idx]=updated;
        toast(`"${updated.receiptNo}" flagged: ${FLAG_LABELS[flagType]}`,'warning');
        closeFlag();
        if(prId===flTargetId){cPr();oPr(flTargetId);}
        render();
    }catch(e){toast(e.message,'danger');}
    finally{btn.disabled=false;}
});
<?php else: ?>
window.openFlag=id=>toast('Insufficient permissions','danger');
<?php endif; ?>

// ── SIDE PANEL — Admin+ ───────────────────────────────────────────────────────
<?php if ($CAN_RECORD): ?>
function oPn(){document.getElementById('panel').classList.add('open');document.getElementById('mainOv').classList.add('show');}
function cPn(){document.getElementById('panel').classList.remove('open');document.getElementById('mainOv').classList.remove('show');eId=null;}
document.getElementById('mainOv').addEventListener('click',cPn);
document.getElementById('pCl').addEventListener('click',cPn);
document.getElementById('pCa').addEventListener('click',cPn);

document.getElementById('addBtn').addEventListener('click',async()=>{
    eId=null;clrF();
    document.getElementById('pT').textContent='Record Receipt';
    document.getElementById('pS').textContent='Log a new delivery against a purchase order';
    document.getElementById('pSv').innerHTML='<i class="bx bx-plus"></i> Save Receipt';
    document.getElementById('fDD').value=new Date().toISOString().split('T')[0];
    oPn();document.getElementById('fPOi').focus();
    try{
        const d=await apiGet(API+'?api=next_no');
        document.getElementById('fRN').value=d.receiptNo;
    } catch(e){
        document.getElementById('fRN').value='';
        toast('Could not generate receipt number','warning');
    }
});

function oEd(id){
    const s=D.find(x=>x.id===id);if(!s)return;eId=id;
    document.getElementById('fPOi').value      = s.poRef;
    document.getElementById('fRN').value       = s.receiptNo;
    document.getElementById('fSup').value      = s.supplier;
    document.getElementById('fDD').value       = s.deliveryDate;
    document.getElementById('fDL').value       = s.location||'';
    document.getElementById('fIE').value       = s.itemsExpected;
    document.getElementById('fIR').value       = s.itemsReceived;
    document.getElementById('fCond').value     = s.condition;
    document.getElementById('fIB').value       = s.inspectedBy==='—'?'':s.inspectedBy;
    document.getElementById('fSt').value       = s.status;
    document.getElementById('fFlagSel').value  = String(s.flag||0);
    document.getElementById('fNote').value     = s.notes||'';
    document.getElementById('fItems').value    = (s.items||[]).map(x=>`${x.desc} × ${x.exp}`).join('\n');
    <?php if ($CAN_OVERRIDE): ?>
    document.getElementById('fOverride').value = String(s.override||0);
    document.getElementById('fSaN').value      = s.saNotes||'';
    document.getElementById('fCrossUpdate').value = s.crossUpdate||'0';
    <?php endif; ?>
    document.getElementById('pT').textContent  = 'Edit Receipt';
    document.getElementById('pS').textContent  = s.receiptNo;
    document.getElementById('pSv').innerHTML   = '<i class="bx bx-check"></i> Save Changes';
    oPn();
}
window.oEd=oEd;

function clrF(){
    ['fPOi','fDL','fIE','fIR','fIB','fNote','fItems','fDD'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('fRN').value='Generating…';
    document.getElementById('fSup').value='';
    document.getElementById('fCond').value='Good';document.getElementById('fSt').value='Pending';
    document.getElementById('fFlagSel').value='0';
    <?php if ($CAN_OVERRIDE): ?>
    document.getElementById('fOverride').value='0';
    document.getElementById('fSaN').value='';
    document.getElementById('fCrossUpdate').value='0';
    <?php endif; ?>
}

document.getElementById('pSv').addEventListener('click',async()=>{
    const po =document.getElementById('fPOi').value.trim();
    let rn = document.getElementById('fRN').value.trim();
    if (!rn || rn === 'Generating…') {
        try {
            const d = await apiGet(API+'?api=next_no');
            rn = d.receiptNo;
            document.getElementById('fRN').value = rn;
        } catch(e) {
            shk('fRN'); return toast('Could not generate receipt number — please try again','danger');
        }
    }
    const sup=document.getElementById('fSup').value;
    const dd =document.getElementById('fDD').value;
    const ie =document.getElementById('fIE').value;
    const ir =document.getElementById('fIR').value;
    if(!po){shk('fPOi');return toast('PO reference is required','danger');}
    if(!sup){shk('fSup');return toast('Please select a supplier','danger');}
    if(!dd){shk('fDD'); return toast('Delivery date is required','danger');}
    if(!ie){shk('fIE'); return toast('Items expected is required','danger');}
    if(ir===''||ir===null){shk('fIR');return toast('Items received is required','danger');}

    const rawItems=document.getElementById('fItems').value.trim().split('\n').filter(Boolean);
    const parsedItems=rawItems.map(line=>{
        const m=line.match(/^(.+?)[\s×x*]+(\d+)$/i);
        return m
            ?{desc:m[1].trim(),exp:parseInt(m[2]),rec:parseInt(m[2]),cond:document.getElementById('fCond').value}
            :{desc:line.trim(),exp:parseInt(ie)||0,rec:parseInt(ir)||0,cond:document.getElementById('fCond').value};
    });
    if(!parsedItems.length) parsedItems.push({
        desc:'General Items',exp:parseInt(ie)||0,
        rec:parseInt(ir)||0,cond:document.getElementById('fCond').value
    });

    const payload={
        receiptNo:rn, poRef:po, supplier:sup, deliveryDate:dd,
        location:document.getElementById('fDL').value.trim(),
        itemsExpected:parseInt(ie)||0, itemsReceived:parseInt(ir)||0,
        condition:document.getElementById('fCond').value,
        inspectedBy:document.getElementById('fIB').value.trim()||'—',
        status:document.getElementById('fSt').value,
        flag:parseInt(document.getElementById('fFlagSel').value)||0,
        notes:document.getElementById('fNote').value.trim(),
        items:parsedItems,
        <?php if ($CAN_OVERRIDE): ?>
        override:parseInt(document.getElementById('fOverride').value)||0,
        saNotes:document.getElementById('fSaN').value.trim(),
        crossUpdate:document.getElementById('fCrossUpdate').value,
        <?php else: ?>
        override:0, saNotes:'', crossUpdate:'0',
        <?php endif; ?>
    };
    if(eId) payload.id=eId;

    const btn=document.getElementById('pSv');btn.disabled=true;
    try{
        await apiPost(API+'?api=save',payload);
        toast(`"${rn}" ${eId?'updated':'recorded'}`,'success');
        cPn();
        D=await apiGet(API+'?api=list');
        rSupplierDropdowns();rPoFilter();
        render();
    }catch(e){toast(e.message,'danger');}
    finally{btn.disabled=false;}
});
<?php else: ?>
// Manager: no panel/record access
window.oEd=id=>toast('Insufficient permissions to edit receipts','danger');
<?php endif; ?>

// ── TOAST & SHAKE ─────────────────────────────────────────────────────────────
function shk(id){
    const el=document.getElementById(id);if(!el)return;
    el.style.borderColor='#DC2626';el.style.animation='none';el.offsetHeight;
    el.style.animation='riShk .3s ease';
    setTimeout(()=>{el.style.borderColor='';el.style.animation='';},600);
}
function toast(msg,type='success'){
    const icons={success:'bx-check-circle',danger:'bx-error-circle',warning:'bx-error',info:'bx-info-circle',teal:'bx-transfer-alt'};
    const el=document.createElement('div');
    el.className='toast '+type;
    el.innerHTML=`<i class='bx ${icons[type]||'bx-check-circle'}' style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('tw').appendChild(el);
    setTimeout(()=>{el.classList.add('fo');setTimeout(()=>el.remove(),300);},3200);
}

// ── INIT ──────────────────────────────────────────────────────────────────────
loadAll();
</script>
</body>
</html>