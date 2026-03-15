<?php
// Resolve project root based on this file location to avoid duplicate "Log1/Log1" paths.
$root = dirname(__DIR__, 2); // points to C:\xampp\htdocs\Log1

// Load shared config and start session first (no output yet)
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── ROLE & SCOPE (mirrors includes/superadmin_sidebar.php) ─────────────────────
function pr_resolve_role(): string {
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

$prRoleName = pr_resolve_role();
$prRoleRank = match($prRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,
};
$prUserDept = $_SESSION['department'] ?? ($_SESSION['zone'] ?? '');
$prUserId   = isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : null;

// ── SUPABASE REST BACKEND FOR PURCHASE REQUESTS ──────────────────────────────
// Endpoints (JSON):
//   GET  requests.php?api=list              → list all PRs (with items & audit)
//   GET  requests.php?api=get&id=PR-...     → single PR
//   POST requests.php?api=save              → create / update PR
//   POST requests.php?api=action            → status change (approve, reject…)
//
// Uses Supabase REST API (same pattern as user_management.php) — no direct PDO.

function pr_backend_error(string $message, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function pr_backend_ok($payload): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}

function pr_backend_read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        pr_backend_error('Invalid JSON body', 400);
    }
    return is_array($data) ? $data : [];
}

// Minimal Supabase REST helper (service role, same pattern as user_management)
function pr_sb_rest(string $table, string $method = 'GET', array $query = [], $body = null, array $extra = []): array {
    $url = SUPABASE_URL . '/rest/v1/' . $table;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    $headers = array_merge([
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Prefer: return=representation',
    ], $extra);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false || $res === '') {
        if ($code >= 400) {
            pr_backend_error('Supabase request failed (empty response)', 500);
        }
        return [];
    }

    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = is_array($data) ? ($data['message'] ?? $data['hint'] ?? $res) : $res;
        pr_backend_error('Supabase: ' . $msg, 400);
    }

    return is_array($data) ? $data : [];
}

function pr_backend_fetch_full_by_number(string $prNumber): ?array {
    // Header
    $rows = pr_sb_rest('psm_purchase_requests', 'GET', [
        'select'     => 'id,pr_number,requestor_name,department,date_filed,date_needed,status,purpose,remarks,total_amount,item_count,created_user_id',
        'pr_number'  => 'eq.' . $prNumber,
        'limit'      => 1,
    ]);
    if (empty($rows)) {
        return null;
    }
    $row  = $rows[0];
    $prId = (int) $row['id'];

    // Items
    $itemsRows = pr_sb_rest('psm_pr_items', 'GET', [
        'select' => 'line_no,item_name,specification,unit,quantity,unit_price',
        'pr_id'  => 'eq.' . $prId,
        'order'  => 'line_no.asc',
    ]);
    $items = array_map(static function ($it) {
        return [
            'id'   => (int) ($it['line_no'] ?? 0),
            'name' => $it['item_name'] ?? '',
            'spec' => $it['specification'] ?? '',
            'unit' => $it['unit'] ?? '',
            'qty'  => (float) ($it['quantity'] ?? 0),
            'up'   => (float) ($it['unit_price'] ?? 0),
        ];
    }, $itemsRows);

    // Audit log
    $auditRows = pr_sb_rest('psm_pr_audit_log', 'GET', [
        'select' => 'action_label,actor_name,actor_role,remarks,ip_address,icon,css_class,is_super_admin,occurred_at',
        'pr_id'  => 'eq.' . $prId,
        'order'  => 'occurred_at.asc,id.asc',
    ]);
    $audit = array_map(static function ($a) {
        return [
            'act'  => $a['action_label'] ?? '',
            'by'   => $a['actor_name'] ?? '',
            'role' => $a['actor_role'] ?? '',
            'note' => $a['remarks'] ?? '',
            'ip'   => $a['ip_address'] ?? '',
            'icon' => $a['icon'] ?: 'bx-file-blank',
            'cls'  => $a['css_class'] ?: 'ad-c',
            'isSA' => (bool) ($a['is_super_admin'] ?? false),
            'ts'   => $a['occurred_at'] ?? '',
        ];
    }, $auditRows);

    return [
        'id'         => $row['pr_number'],
        'requestor'  => $row['requestor_name'],
        'dept'       => $row['department'],
        'dateFiled'  => $row['date_filed'],
        'dateNeeded' => $row['date_needed'],
        'status'     => $row['status'],
        'purpose'    => $row['purpose'],
        'remarks'    => $row['remarks'],
        'items'      => $items,
        'amount'     => (float) $row['total_amount'],
        'itemCount'  => (int) $row['item_count'],
        'createdBy'  => $row['created_user_id'] ?? null,
        'auditLog'   => $audit,
    ];
}

function pr_backend_generate_number(): string {
    $year = date('Y');
    $rows = pr_sb_rest('psm_purchase_requests', 'GET', [
        'select'    => 'pr_number',
        'pr_number' => 'like.PR-' . $year . '-%',
        'order'     => 'pr_number.desc',
        'limit'     => 1,
    ]);
    $next = 1001;
    if (!empty($rows) && preg_match('/PR-\d{4}-(\d+)/', $rows[0]['pr_number'] ?? '', $m)) {
        $next = ((int) $m[1]) + 1;
    }
    return sprintf('PR-%s-%04d', $year, $next);
}

if (isset($_GET['api'])) {
    $api    = $_GET['api'];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $currentUserId = $prUserId;

    try {
        if ($api === 'list' && $method === 'GET') {
            $query = [
                'select' => 'id,pr_number,requestor_name,department,date_filed,date_needed,status,purpose,remarks,total_amount,item_count,created_user_id',
                'order'  => 'date_filed.desc,pr_number.desc',
            ];
            // Role-based scoping
            if ($prRoleName === 'Manager' && $prUserDept) {
                $query['department'] = 'eq.' . $prUserDept;
            } elseif ($prRoleRank <= 1 && $currentUserId) {
                $query['created_user_id'] = 'eq.' . $currentUserId;
            }
            $rows = pr_sb_rest('psm_purchase_requests', 'GET', $query);
            $out = [];
            foreach ($rows as $row) {
                $pr = pr_backend_fetch_full_by_number($row['pr_number']);
                if ($pr) {
                    $out[] = $pr;
                }
            }
            pr_backend_ok($out);
        }

        if ($api === 'get' && $method === 'GET') {
            $id = $_GET['id'] ?? '';
            if ($id === '') {
                pr_backend_error('Missing id parameter', 400);
            }
            $pr = pr_backend_fetch_full_by_number($id);
            if (!$pr) {
                pr_backend_error('Purchase request not found', 404);
            }
            // Role-based access: Manager limited to own dept; Staff to own PRs
            if ($prRoleName === 'Manager' && $prUserDept && ($pr['dept'] ?? '') !== $prUserDept) {
                pr_backend_error('Not authorized to access this purchase request', 403);
            }
            if ($prRoleRank <= 1 && $currentUserId && ($pr['createdBy'] ?? null) !== $currentUserId) {
                pr_backend_error('Not authorized to access this purchase request', 403);
            }
            pr_backend_ok($pr);
        }

        if ($api === 'save' && $method === 'POST') {
            $body = pr_backend_read_json_body();

            $requestor   = trim($body['requestor']   ?? '');
            $dept        = trim($body['dept']        ?? '');
            $dateNeeded  = trim($body['dateNeeded']  ?? '');
            $purpose     = trim($body['purpose']     ?? '');
            $statusInput = trim($body['status']      ?? '');
            $items       = $body['items']            ?? [];

            if ($requestor === '')  pr_backend_error('Requestor is required', 400);
            if ($dept === '')       pr_backend_error('Department is required', 400);
            if ($dateNeeded === '') pr_backend_error('Date needed is required', 400);
            if (!is_array($items) || count($items) === 0) {
                pr_backend_error('At least one line item is required', 400);
            }

            $cleanItems = [];
            $amount     = 0;
            $lineNo     = 1;
            foreach ($items as $it) {
                $name = trim($it['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $qty = (float) ($it['qty'] ?? 0);
                if ($qty < 1) $qty = 1;
                $up  = (float) ($it['up'] ?? 0);
                $sub = $qty * $up;
                $amount += $sub;
                $cleanItems[] = [
                    'line_no'      => $lineNo++,
                    'item_name'    => $name,
                    'specification'=> trim($it['spec'] ?? ''),
                    'unit'         => trim($it['unit'] ?? 'pcs'),
                    'quantity'     => $qty,
                    'unit_price'   => $up,
                    'line_total'   => $sub,
                ];
            }

            if (!$cleanItems) {
                pr_backend_error('All line items are empty', 400);
            }

            $nowDate = date('Y-m-d');
            $status  = in_array($statusInput, ['Draft', 'Pending Approval'], true)
                ? $statusInput
                : 'Pending Approval';

            $existingNumber = trim($body['id'] ?? '');
            $ip             = $_SERVER['REMOTE_ADDR'] ?? null;
            $nowTs          = date('Y-m-d H:i:s');

            if ($existingNumber !== '') {
                // Find existing header
                $hdrRows = pr_sb_rest('psm_purchase_requests', 'GET', [
                    'select'    => 'id,department,created_user_id,status',
                    'pr_number' => 'eq.' . $existingNumber,
                    'limit'     => 1,
                ]);
                if (empty($hdrRows)) {
                    pr_backend_error('Purchase request not found for update', 404);
                }
                $hdr  = $hdrRows[0];
                $prId = (int) $hdr['id'];

                // Editing rules:
                // - Super Admin/Admin: any PR
                // - Manager: only within own department/zone
                // - Staff/User: only own Draft PRs
                if ($prRoleName === 'Manager' && $prUserDept && ($hdr['department'] ?? '') !== $prUserDept) {
                    pr_backend_error('Not authorized to edit this purchase request', 403);
                }
                if ($prRoleRank <= 1) {
                    if (($hdr['created_user_id'] ?? null) !== $currentUserId || ($hdr['status'] ?? '') !== 'Draft') {
                        pr_backend_error('You can only edit your own draft requests', 403);
                    }
                }

                // Update header
                pr_sb_rest('psm_purchase_requests', 'PATCH', [
                    'pr_number' => 'eq.' . $existingNumber,
                ], [
                    'requestor_name' => $requestor,
                    'department'     => $dept,
                    'date_needed'    => $dateNeeded,
                    'purpose'        => $purpose,
                    'status'         => $status,
                    'total_amount'   => $amount,
                    'item_count'     => count($cleanItems),
                ]);

                // Replace items
                pr_sb_rest('psm_pr_items', 'DELETE', [
                    'pr_id' => 'eq.' . $prId,
                ]);
                foreach ($cleanItems as $ci) {
                    $ci['pr_id'] = $prId;
                    pr_sb_rest('psm_pr_items', 'POST', [], [$ci]);
                }

                // Audit log
                pr_sb_rest('psm_pr_audit_log', 'POST', [], [[
                    'pr_id'        => $prId,
                    'action_label' => 'PR Edited',
                    'actor_name'   => 'Super Admin',
                    'actor_role'   => 'Super Admin',
                    'remarks'      => 'Fields updated via backend.',
                    'ip_address'   => $ip,
                    'icon'         => 'bx-edit',
                    'css_class'    => 'ad-s',
                    'is_super_admin' => true,
                    'occurred_at'  => $nowTs,
                ]]);

                $saved = pr_backend_fetch_full_by_number($existingNumber);
                pr_backend_ok($saved);
            }

            // New PR
            $newNumber = pr_backend_generate_number();
            $inserted = pr_sb_rest('psm_purchase_requests', 'POST', [], [[
                'pr_number'      => $newNumber,
                'requestor_name' => $requestor,
                'department'     => $dept,
                'date_filed'     => $nowDate,
                'date_needed'    => $dateNeeded,
                'status'         => $status,
                'purpose'        => $purpose,
                'remarks'        => '',
                'total_amount'   => $amount,
                'item_count'     => count($cleanItems),
                'created_user_id'=> $currentUserId,
                'created_by'     => 'Super Admin',
            ]]);
            if (empty($inserted)) {
                pr_backend_error('Failed to create purchase request', 500);
            }
            $prId = (int) $inserted[0]['id'];

            foreach ($cleanItems as $ci) {
                $ci['pr_id'] = $prId;
                pr_sb_rest('psm_pr_items', 'POST', [], [$ci]);
            }

            // Audit: created
            pr_sb_rest('psm_pr_audit_log', 'POST', [], [[
                'pr_id'        => $prId,
                'action_label' => 'PR Created',
                'actor_name'   => $requestor,
                'actor_role'   => 'Requestor',
                'remarks'      => 'PR created via backend.',
                'ip_address'   => $ip,
                'icon'         => 'bx-file-blank',
                'css_class'    => 'ad-c',
                'is_super_admin' => false,
                'occurred_at'  => $nowTs,
            ]]);

            if ($status === 'Pending Approval') {
                pr_sb_rest('psm_pr_audit_log', 'POST', [], [[
                    'pr_id'        => $prId,
                    'action_label' => 'Submitted for Approval',
                    'actor_name'   => $requestor,
                    'actor_role'   => 'Requestor',
                    'remarks'      => 'Draft → Pending Approval.',
                    'ip_address'   => $ip,
                    'icon'         => 'bx-send',
                    'css_class'    => 'ad-s',
                    'is_super_admin' => false,
                    'occurred_at'  => $nowTs,
                ]]);
            }

            $saved = pr_backend_fetch_full_by_number($newNumber);
            pr_backend_ok($saved);
        }

        if ($api === 'action' && $method === 'POST') {
            $body    = pr_backend_read_json_body();
            $id      = trim($body['id'] ?? '');
            $type    = trim($body['type'] ?? '');
            $remarks = trim($body['remarks'] ?? '');

            if ($id === '')   pr_backend_error('Missing id', 400);
            if ($type === '') pr_backend_error('Missing type', 400);

            $map = [
                'approve'  => ['Approved',  'PR Approved',                        'bx-check-circle',  'ad-a'],
                'reject'   => ['Rejected',  'PR Rejected',                        'bx-x-circle',      'ad-r'],
                'override' => ['Approved',  'Rejection Overridden — Approved',    'bx-revision',      'ad-o'],
                'cancel'   => ['Cancelled', 'PR Cancelled',                       'bx-minus-circle',  'ad-x'],
            ];
            if (!isset($map[$type])) {
                pr_backend_error('Unsupported action type', 400);
            }

            // Only Super Admin can override; Admin/Super Admin can approve/reject; lower roles no direct action here
            if ($type === 'override' && $prRoleRank < 4) {
                pr_backend_error('Only Super Admin may override PR decisions', 403);
            }
            if (in_array($type, ['approve','reject','cancel'], true) && $prRoleRank < 3) {
                pr_backend_error('Only Admin or Super Admin may perform this action', 403);
            }

            [$newStatus, $actLabel, $icon, $cls] = $map[$type];
            // Find header by PR number
            $hdrRows = pr_sb_rest('psm_purchase_requests', 'GET', [
                'select'    => 'id,department',
                'pr_number' => 'eq.' . $id,
                'limit'     => 1,
            ]);
            if (empty($hdrRows)) {
                pr_backend_error('Purchase request not found', 404);
            }
            $hdr  = $hdrRows[0];
            $prId = (int) $hdr['id'];

            // Admins restricted to their own department/zone
            if ($prRoleName === 'Admin' && $prUserDept && ($hdr['department'] ?? '') !== $prUserDept) {
                pr_backend_error('Not authorized to manage this PR', 403);
            }

            // Update status / remarks
            pr_sb_rest('psm_purchase_requests', 'PATCH', [
                'pr_number' => 'eq.' . $id,
            ], [
                'status'  => $newStatus,
                'remarks' => $remarks,
            ]);

            // Audit entry
            pr_sb_rest('psm_pr_audit_log', 'POST', [], [[
                'pr_id'        => $prId,
                'action_label' => $actLabel,
                'actor_name'   => $_SESSION['full_name'] ?? 'System',
                'actor_role'   => $prRoleName,
                'remarks'      => $remarks,
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
                'icon'         => $icon,
                'css_class'    => $cls,
                'is_super_admin' => ($prRoleName === 'Super Admin'),
                'occurred_at'  => date('Y-m-d H:i:s'),
            ]]);

            $updated = pr_backend_fetch_full_by_number($id);
            pr_backend_ok($updated);
        }

        if ($api === 'lookup_users' && $method === 'GET') {
            $q = trim($_GET['q'] ?? '');
            if ($q === '') {
                pr_backend_ok([]);
            }
            // Strip PostgREST special chars from the search term, then build the or-filter.
            // We must NOT use http_build_query here because it encodes '*' → '%2A',
            // which would break Supabase ilike wildcard matching.
            $safe = rawurlencode(str_replace(['(', ')', ',', '*'], '', $q));
            $orFilter  = '(first_name.ilike.*' . $safe . '*,last_name.ilike.*' . $safe . '*)';
            $urlParams = 'select=first_name%2Clast_name%2Czone'
                       . '&or=' . $orFilter
                       . '&status=eq.Active'
                       . '&order=first_name.asc'
                       . '&limit=10';
            $sbUrl = SUPABASE_URL . '/rest/v1/users?' . $urlParams;
            $headers = [
                'Content-Type: application/json',
                'apikey: ' . SUPABASE_SERVICE_ROLE_KEY,
                'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
                'Prefer: return=representation',
            ];
            $ch = curl_init($sbUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
            $sbRes  = curl_exec($ch);
            $sbCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $rows = ($sbRes && $sbCode < 400) ? (json_decode($sbRes, true) ?? []) : [];
            $out = array_map(static function ($u) {
                return [
                    'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
                    'dept' => $u['zone'] ?? '',
                ];
            }, is_array($rows) ? $rows : []);
            pr_backend_ok($out);
        }

        pr_backend_error('Unsupported API route', 404);
    } catch (Throwable $e) {
        pr_backend_error('Server error: ' . $e->getMessage(), 500);
    }
    // pr_backend_* already exited; but keep a safety exit here.
    exit;
}

// ── NORMAL PAGE RENDER (HTML) ────────────────────────────────────────────────
include $root . '/includes/superadmin_sidebar.php';
include $root . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Purchase Requests — PSM</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/base.css">
<link rel="stylesheet" href="/css/sidebar.css">
<link rel="stylesheet" href="/css/header.css">
<style>
/* ── TOKENS ─────────────────────────────────────────────── */
#mainContent,#prSlider,#slOverlay,#actionModal,#viewModal,.pr-toasts {
  --s:#fff; --bd:rgba(46,125,50,.13); --bdm:rgba(46,125,50,.26);
  --t1:var(--text-primary); --t2:var(--text-secondary); --t3:#9EB0A2;
  --hbg:var(--hover-bg-light); --bg:var(--bg-color);
  --grn:var(--primary-color); --gdk:var(--primary-dark);
  --red:#DC2626; --amb:#D97706; --blu:#2563EB; --tel:#0D9488;
  --shmd:0 4px 20px rgba(46,125,50,.12); --shlg:0 24px 60px rgba(0,0,0,.22);
  --rad:12px; --tr:var(--transition);
}
#mainContent *,#prSlider *,#slOverlay *,#actionModal *,#viewModal *,.pr-toasts * { box-sizing:border-box; }

/* ── PAGE ─────────────────────────────────────────────────── */
.pr-wrap { max-width:1440px; margin:0 auto; padding:0 0 4rem; }
.pr-ph { display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:26px; animation:UP .4s both; }
.pr-ph .ey { font-size:11px; font-weight:600; letter-spacing:.14em; text-transform:uppercase; color:var(--grn); margin-bottom:4px; }
.pr-ph h1  { font-size:26px; font-weight:800; color:var(--t1); line-height:1.15; }
.pr-ph-r   { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

/* ── BUTTONS ─────────────────────────────────────────────── */
.btn { display:inline-flex; align-items:center; gap:7px; font-family:'Inter',sans-serif; font-size:13px; font-weight:600; padding:9px 18px; border-radius:10px; border:none; cursor:pointer; transition:var(--tr); white-space:nowrap; }
.btn-primary   { background:var(--grn); color:#fff; box-shadow:0 2px 8px rgba(46,125,50,.32); }
.btn-primary:hover { background:var(--gdk); transform:translateY(-1px); }
.btn-ghost     { background:var(--s); color:var(--t2); border:1px solid var(--bdm); }
.btn-ghost:hover { background:var(--hbg); color:var(--t1); }
.btn-approve   { background:#DCFCE7; color:#166534; border:1px solid #BBF7D0; }
.btn-approve:hover { background:#BBF7D0; }
.btn-reject    { background:#FEE2E2; color:var(--red); border:1px solid #FECACA; }
.btn-reject:hover { background:#FCA5A5; }
.btn-override  { background:#EFF6FF; color:var(--blu); border:1px solid #BFDBFE; }
.btn-override:hover { background:#DBEAFE; }
.btn-cancel-pr { background:#F3F4F6; color:#374151; border:1px solid #D1D5DB; }
.btn-cancel-pr:hover { background:#E5E7EB; }
.btn-batch-approve { background:#DCFCE7; color:#166534; border:1px solid #BBF7D0; }
.btn-batch-approve:hover { background:#BBF7D0; }
.btn-batch-reject  { background:#FEE2E2; color:var(--red); border:1px solid #FECACA; }
.btn-batch-reject:hover { background:#FCA5A5; }
.btn-sm { font-size:12px; padding:6px 13px; }
.btn-xs { font-size:11px; padding:4px 9px; border-radius:7px; }
.btn.ionly { width:26px; height:26px; padding:0; justify-content:center; font-size:14px; flex-shrink:0; border-radius:6px; }

/* ── STATS ─────────────────────────────────────────────── */
.pr-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:22px; animation:UP .4s .05s both; }
.sc { background:var(--s); border:1px solid var(--bd); border-radius:var(--rad); padding:14px 16px; box-shadow:0 1px 4px rgba(46,125,50,.07); display:flex; align-items:center; gap:12px; }
.sc-ic { width:38px; height:38px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:18px; }
.ic-b{background:#EFF6FF;color:var(--blu)} .ic-a{background:#FEF3C7;color:var(--amb)}
.ic-g{background:#DCFCE7;color:#166534}    .ic-r{background:#FEE2E2;color:var(--red)}
.ic-t{background:#CCFBF1;color:var(--tel)} .ic-p{background:#F5F3FF;color:#6D28D9}
.ic-d{background:#F3F4F6;color:#374151}
.sc-v { font-size:22px; font-weight:800; color:var(--t1); line-height:1; }
.sc-l { font-size:11px; color:var(--t2); margin-top:2px; }

/* ── TOOLBAR ─────────────────────────────────────────────── */
.pr-tb { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:18px; animation:UP .4s .1s both; }
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

/* ── BULK ACTION BAR ─────────────────────────────────────── */
.bulk-bar { display:none; align-items:center; gap:10px; padding:10px 16px; background:linear-gradient(135deg,#F0FDF4,#DCFCE7); border:1px solid rgba(46,125,50,.22); border-radius:12px; margin-bottom:14px; flex-wrap:wrap; animation:UP .25s both; }
.bulk-bar.on { display:flex; }
.bulk-count { font-size:13px; font-weight:700; color:#166534; }
.bulk-sep { width:1px; height:22px; background:rgba(46,125,50,.25); }
.sa-exclusive { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; background:linear-gradient(135deg,#FEF3C7,#FDE68A); color:#92400E; border:1px solid #FCD34D; border-radius:6px; padding:2px 7px; }
.sa-exclusive i { font-size:11px; }

/* ── TABLE ─────────────────────────────────────────────── */
.pr-card { background:var(--s); border:1px solid var(--bd); border-radius:16px; overflow:hidden; box-shadow:var(--shmd); animation:UP .4s .13s both; }
.pr-tbl { width:100%; border-collapse:collapse; font-size:12.5px; table-layout:fixed; }
.pr-tbl thead th { font-size:10.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--t2); padding:10px 10px; text-align:left; background:var(--bg); border-bottom:1px solid var(--bd); white-space:nowrap; cursor:pointer; user-select:none; overflow:hidden; }
.pr-tbl thead th.no-sort { cursor:default; }
.pr-tbl thead th:hover:not(.no-sort) { color:var(--grn); }
.pr-tbl thead th.sorted { color:var(--grn); }
.pr-tbl thead th .sic { margin-left:3px; opacity:.4; font-size:12px; vertical-align:middle; }
.pr-tbl thead th.sorted .sic { opacity:1; }
.pr-tbl col.col-cb     { width:38px; }
.pr-tbl col.col-id     { width:130px; }
.pr-tbl col.col-req    { width:170px; }
.pr-tbl col.col-dept   { width:110px; }
.pr-tbl col.col-date   { width:100px; }
.pr-tbl col.col-items  { width:60px; }
.pr-tbl col.col-amt    { width:130px; }
.pr-tbl col.col-status { width:150px; }
.pr-tbl col.col-act    { width:170px; }
.pr-tbl thead th:first-child,
.pr-tbl tbody td:first-child { padding-left:12px; padding-right:4px; }
.pr-tbl tbody tr { border-bottom:1px solid var(--bd); transition:background .13s; }
.pr-tbl tbody tr:last-child { border-bottom:none; }
.pr-tbl tbody tr:hover { background:var(--hbg); }
.pr-tbl tbody tr.row-selected { background:#F0FDF4; }
.pr-tbl tbody td { padding:12px 10px; vertical-align:middle; cursor:pointer; max-width:0; overflow:hidden; text-overflow:ellipsis; }
.pr-tbl tbody td:first-child { cursor:default; }
.pr-tbl tbody td:last-child  { white-space:nowrap; cursor:default; overflow:visible; padding:10px 8px; max-width:none; }
.pr-num  { font-family:'DM Mono',monospace; font-size:11.5px; font-weight:600; color:var(--t1); white-space:nowrap; }
.pr-date { font-size:11.5px; color:var(--t2); white-space:nowrap; }
.pr-amt  { font-family:'DM Mono',monospace; font-size:12px; font-weight:700; color:var(--t1); white-space:nowrap; }
.req-cell  { display:flex; align-items:center; gap:7px; min-width:0; }
.req-av    { width:28px; height:28px; border-radius:50%; font-size:10px; font-weight:700; color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.req-name  { font-weight:600; color:var(--t1); font-size:12.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.act-cell  { display:flex; gap:4px; align-items:center; flex-wrap:nowrap; width:100%; }
.items-cell { font-size:11.5px; color:var(--t2); font-weight:600; white-space:nowrap; }
.dept-dot   { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; }
.cb-wrap { display:flex; align-items:center; justify-content:center; }
input[type=checkbox].cb { width:15px; height:15px; accent-color:var(--grn); cursor:pointer; border-radius:4px; }

/* ── BADGES ─────────────────────────────────────────────── */
.badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; white-space:nowrap; }
.badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }
.b-draft     { background:#F3F4F6; color:#6B7280; }
.b-pending   { background:#FEF3C7; color:#92400E; }
.b-approved  { background:#DCFCE7; color:#166534; }
.b-rejected  { background:#FEE2E2; color:#991B1B; }
.b-cancelled { background:#F3F4F6; color:#374151; }

/* ── PAGINATION ─────────────────────────────────────────── */
.pr-pager { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:14px 20px; border-top:1px solid var(--bd); background:var(--bg); font-size:13px; color:var(--t2); }
.pg-btns  { display:flex; gap:5px; }
.pgb { width:32px; height:32px; border-radius:8px; border:1px solid var(--bdm); background:var(--s); font-family:'Inter',sans-serif; font-size:13px; cursor:pointer; display:grid; place-content:center; transition:var(--tr); color:var(--t1); }
.pgb:hover   { background:var(--hbg); border-color:var(--grn); color:var(--grn); }
.pgb.active  { background:var(--grn); border-color:var(--grn); color:#fff; }
.pgb:disabled { opacity:.4; pointer-events:none; }
.empty { padding:72px 20px; text-align:center; color:var(--t3); }
.empty i { font-size:54px; display:block; margin-bottom:14px; color:#C8E6C9; }

/* ── VIEW MODAL ─────────────────────────────────────────── */
#viewModal {
  position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9050;
  display:flex; align-items:center; justify-content:center; padding:20px;
  opacity:0; pointer-events:none; transition:opacity .25s;
}
#viewModal.on { opacity:1; pointer-events:all; }
.vm-box {
  background:#fff; border-radius:20px;
  width:780px; max-width:100%; max-height:90vh;
  display:flex; flex-direction:column;
  box-shadow:0 20px 60px rgba(0,0,0,.22);
  overflow:hidden;
}
.vm-mhd {
  padding:24px 28px 0;
  border-bottom:1px solid rgba(46,125,50,.14);
  background:var(--bg-color);
  flex-shrink:0;
}
.vm-mtp {
  display:flex; align-items:flex-start; justify-content:space-between;
  gap:12px; margin-bottom:16px;
}
.vm-msi { display:flex; align-items:center; gap:16px; }
.vm-mav {
  width:56px; height:56px; border-radius:14px;
  display:flex; align-items:center; justify-content:center;
  font-weight:800; font-size:19px; color:#fff;
  flex-shrink:0;
}
.vm-mnm { font-size:20px; font-weight:800; color:var(--text-primary); display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.vm-mid { font-family:'DM Mono',monospace; font-size:12px; color:var(--text-secondary); margin-top:3px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.vm-mcl {
  width:36px; height:36px; border-radius:8px;
  border:1px solid rgba(46,125,50,.22); background:#fff;
  cursor:pointer; display:grid; place-content:center;
  font-size:20px; color:var(--text-secondary);
  transition:all .15s; flex-shrink:0;
}
.vm-mcl:hover { background:#FEE2E2; color:#DC2626; border-color:#FECACA; }
.vm-mmt { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
.vm-mc {
  display:inline-flex; align-items:center; gap:6px;
  font-size:12px; color:var(--text-secondary);
  background:#fff; border:1px solid rgba(46,125,50,.14);
  border-radius:8px; padding:5px 10px; line-height:1;
}
.vm-mc i { font-size:14px; color:var(--primary-color); }
.vm-mtb { display:flex; gap:4px; }
.vm-tab {
  font-family:'Inter',sans-serif; font-size:13px; font-weight:600;
  padding:8px 16px; border-radius:8px 8px 0 0;
  cursor:pointer; transition:all .15s;
  color:var(--text-secondary); border:none; background:transparent;
  display:flex; align-items:center; gap:6px; white-space:nowrap;
}
.vm-tab:hover { background:var(--hover-bg-light); color:var(--text-primary); }
.vm-tab.active { background:var(--primary-color); color:#fff; }
.vm-tab i { font-size:14px; }
.vm-mbd {
  flex:1; overflow-y:auto; padding:24px 28px;
  background:#fff;
}
.vm-mbd::-webkit-scrollbar { width:4px; }
.vm-mbd::-webkit-scrollbar-thumb { background:rgba(46,125,50,.22); border-radius:4px; }
.vm-tp { display:none; flex-direction:column; gap:18px; }
.vm-tp.active { display:flex; }
.vm-sbs { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
.vm-sb { background:var(--bg-color); border:1px solid rgba(46,125,50,.14); border-radius:10px; padding:14px 16px; }
.vm-sb .sbv { font-size:18px; font-weight:800; color:var(--text-primary); line-height:1; }
.vm-sb .sbv.mono { font-family:'DM Mono',monospace; font-size:13px; color:var(--primary-color); }
.vm-sb .sbl { font-size:11px; color:var(--text-secondary); margin-top:3px; }
.vm-ig { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.vm-ii label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9EB0A2; display:block; margin-bottom:4px; }
.vm-ii .v { font-size:13px; font-weight:500; color:var(--text-primary); line-height:1.5; }
.vm-ii .v.muted { font-weight:400; color:#4B5563; }
.vm-full { grid-column:1/-1; }
.vm-rmk { border-radius:10px; padding:12px 16px; font-size:12.5px; line-height:1.65; }
.vm-rmk .rml { font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; margin-bottom:4px; opacity:.7; }
.vm-rmk-a { background:#F0FDF4; color:#166534; }
.vm-rmk-r { background:#FEF2F2; color:#991B1B; }
.vm-rmk-n { background:#FFFBEB; color:#92400E; }
.vm-sa-note { display:flex; align-items:flex-start; gap:8px; background:#FFFBEB; border:1px solid #FCD34D; border-radius:10px; padding:10px 14px; font-size:12px; color:#92400E; }
.vm-sa-note i { font-size:15px; flex-shrink:0; margin-top:1px; }
.vm-txnt { width:100%; border-collapse:collapse; font-size:13px; }
.vm-txnt thead th { font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--text-secondary); padding:10px 12px; text-align:left; background:var(--bg-color); border-bottom:1px solid rgba(46,125,50,.14); white-space:nowrap; }
.vm-txnt thead th:last-child { text-align:right; }
.vm-txnt tbody tr { border-bottom:1px solid rgba(46,125,50,.14); transition:background .12s; }
.vm-txnt tbody tr:last-child { border-bottom:none; }
.vm-txnt tbody tr:hover { background:var(--hover-bg-light); }
.vm-txnt tbody td { padding:11px 12px; vertical-align:middle; }
.vm-txnt tbody td:last-child { text-align:right; }
.vm-txnt tfoot td { padding:11px 12px; font-weight:700; border-top:2px solid rgba(46,125,50,.14); background:var(--bg-color); }
.vm-txnt tfoot td:last-child { text-align:right; color:var(--primary-color); font-family:'DM Mono',monospace; font-size:14px; }
.vm-ta { font-family:'DM Mono',monospace; font-size:12px; font-weight:600; color:var(--primary-color); }
.li-name { font-weight:600; color:var(--text-primary); }
.li-spec { font-size:11px; color:#9CA3AF; margin-top:1px; }
.vm-audit-item { display:flex; gap:12px; padding:12px 0; border-bottom:1px solid rgba(46,125,50,.14); }
.vm-audit-item:last-child { border-bottom:none; padding-bottom:0; }
.vm-audit-dot { width:28px; height:28px; border-radius:7px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:13px; }
.ad-c{background:#DCFCE7;color:#166534} .ad-s{background:#EFF6FF;color:#2563EB}
.ad-a{background:#DCFCE7;color:#166534} .ad-r{background:#FEE2E2;color:#DC2626}
.ad-e{background:#F3F4F6;color:#6B7280} .ad-o{background:#FEF3C7;color:#D97706}
.ad-x{background:#F3F4F6;color:#374151} .ad-d{background:#F5F3FF;color:#6D28D9}
.vm-audit-body { flex:1; min-width:0; }
.vm-audit-body .au { font-size:13px; font-weight:500; color:var(--text-primary); }
.vm-audit-body .at { font-size:11px; color:#9EB0A2; margin-top:3px; font-family:'DM Mono',monospace; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.vm-audit-note { font-size:11.5px; color:#6B7280; margin-top:3px; font-style:italic; }
.vm-audit-ip { font-family:'DM Mono',monospace; font-size:10px; color:#9CA3AF; background:#F3F4F6; border-radius:4px; padding:1px 6px; }
.vm-audit-ts { font-family:'DM Mono',monospace; font-size:10px; color:#9EB0A2; flex-shrink:0; margin-left:auto; padding-left:8px; white-space:nowrap; }
.sa-tag { font-size:10px; font-weight:700; background:#FEF3C7; color:#92400E; border-radius:4px; padding:1px 5px; border:1px solid #FCD34D; }
.vm-mft { padding:16px 28px; border-top:1px solid rgba(46,125,50,.14); background:var(--bg-color); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; flex-wrap:wrap; }

/* ── SLIDE-OVER ─────────────────────────────────────────── */
#slOverlay {
  position:fixed; inset:0; background:rgba(0,0,0,.52); z-index:9000;
  opacity:0; pointer-events:none; transition:opacity .25s;
}
#slOverlay.on { opacity:1; pointer-events:all; }
#prSlider {
  position:fixed; top:0; right:-600px; bottom:0;
  width:560px; max-width:100vw; background:var(--s);
  z-index:9001; transition:right .3s cubic-bezier(.4,0,.2,1);
  display:flex; flex-direction:column; overflow:hidden;
  box-shadow:-4px 0 40px rgba(0,0,0,.18);
}
#prSlider.on { right:0; }
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
.fi,.fs,.fta { font-family:'Inter',sans-serif; font-size:13px; padding:10px 12px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; transition:var(--tr); width:100%; }
.fi:focus,.fs:focus,.fta:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.11); }
.fs { appearance:none; cursor:pointer; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; padding-right:30px; }
.fta { resize:vertical; min-height:70px; }
.fd { font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--t3); display:flex; align-items:center; gap:10px; }
.fd::after { content:''; flex:1; height:1px; background:var(--bd); }
.li-rows { display:flex; flex-direction:column; gap:10px; }
.li-row  { background:var(--bg); border:1px solid var(--bd); border-radius:10px; padding:13px 14px; display:flex; flex-direction:column; gap:9px; position:relative; }
.li-rr1  { display:grid; grid-template-columns:2fr 1fr 1fr; gap:10px; }
.li-rr2  { display:grid; grid-template-columns:2fr 1fr; gap:10px; }
.li-rm   { position:absolute; top:10px; right:10px; width:24px; height:24px; border-radius:6px; border:1px solid #FECACA; background:#FEE2E2; cursor:pointer; display:grid; place-content:center; font-size:14px; color:var(--red); transition:var(--tr); }
.li-rm:hover { background:#FCA5A5; }
.li-sub  { font-family:'DM Mono',monospace; font-size:12px; font-weight:700; color:var(--grn); text-align:right; padding:6px 0 0; border-top:1px dashed var(--bd); }
.add-li  { display:flex; align-items:center; justify-content:center; gap:7px; padding:10px; border:1.5px dashed var(--bdm); border-radius:10px; cursor:pointer; font-size:13px; font-weight:600; color:var(--t3); background:transparent; transition:var(--tr); font-family:'Inter',sans-serif; width:100%; }
.add-li:hover { border-color:var(--grn); color:var(--grn); background:#F0FAF0; }
.total-row { display:flex; align-items:center; justify-content:space-between; background:#E8F5E9; border:1px solid rgba(46,125,50,.2); border-radius:10px; padding:12px 16px; font-size:13px; font-weight:700; color:var(--grn); }
.total-row span:last-child { font-size:17px; font-family:'DM Mono',monospace; }

/* ── ACTION MODAL ─────────────────────────────────────────── */
#actionModal { position:fixed; inset:0; background:rgba(0,0,0,.52); z-index:9100; display:grid; place-content:center; opacity:0; pointer-events:none; transition:opacity .2s; }
#actionModal.on { opacity:1; pointer-events:all; }
.am-box   { background:var(--s); border-radius:16px; padding:28px 28px 24px; width:420px; max-width:92vw; box-shadow:var(--shlg); }
.am-icon  { font-size:46px; margin-bottom:10px; line-height:1; }
.am-title { font-size:18px; font-weight:700; color:var(--t1); margin-bottom:6px; }
.am-body  { font-size:13px; color:var(--t2); line-height:1.6; margin-bottom:16px; }
.am-fg    { display:flex; flex-direction:column; gap:5px; margin-bottom:18px; }
.am-fg label { font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--t2); }
.am-fg textarea { font-family:'Inter',sans-serif; font-size:13px; padding:10px 12px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; resize:vertical; min-height:72px; width:100%; transition:var(--tr); }
.am-fg textarea:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.11); }
.am-acts  { display:flex; gap:10px; justify-content:flex-end; }
.am-sa-note { display:flex; align-items:flex-start; gap:8px; background:#FFFBEB; border:1px solid #FCD34D; border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:12px; color:#92400E; }
.am-sa-note i { font-size:15px; flex-shrink:0; margin-top:1px; }

/* ── TOAST ─────────────────────────────────────────────── */
.pr-toasts { position:fixed; bottom:28px; right:28px; z-index:9999; display:flex; flex-direction:column; gap:10px; pointer-events:none; }
.toast { background:#0A1F0D; color:#fff; padding:12px 18px; border-radius:10px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:10px; box-shadow:var(--shlg); pointer-events:all; min-width:220px; animation:TIN .3s ease; }
.toast.ts { background:var(--grn); } .toast.tw { background:var(--amb); } .toast.td { background:var(--red); }
.toast.out { animation:TOUT .3s ease forwards; }

@keyframes UP   { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
@keyframes TIN  { from{opacity:0;transform:translateY(8px)}  to{opacity:1;transform:translateY(0)} }
@keyframes TOUT { from{opacity:1;transform:translateY(0)}    to{opacity:0;transform:translateY(8px)} }
@keyframes SHK  { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-5px)} 40%,80%{transform:translateX(5px)} }

@media(max-width:768px){
  #prSlider { width:100vw; }
  .fr,.li-rr1,.li-rr2 { grid-template-columns:1fr; }
  .pr-stats { grid-template-columns:repeat(2,1fr); }
  .vm-sbs { grid-template-columns:repeat(2,1fr); }
  .vm-ig  { grid-template-columns:1fr; }
}
</style>
</head>
<body>

<main class="main-content" id="mainContent">
<div class="pr-wrap">

  <!-- PAGE HEADER -->
  <div class="pr-ph">
    <div>
      <p class="ey">PSM · Procurement &amp; Sourcing Management</p>
      <h1>Purchase Requests</h1>
    </div>
    <div class="pr-ph-r">
      <button class="btn btn-ghost" id="exportBtn"><i class="bx bx-export"></i> Export CSV</button>
      <button class="btn btn-primary" id="createBtn"><i class="bx bx-plus"></i> Create PR</button>
    </div>
  </div>

  <!-- STAT CARDS -->
  <div class="pr-stats" id="statsBar"></div>

  <!-- FILTERS: Requestor, Branch, Department, Status, Date Range -->
  <div class="pr-tb">
    <div class="sw">
      <i class="bx bx-search"></i>
      <input type="text" class="si" id="srch" placeholder="Search by requestor, PR number, or branch…">
    </div>
    <select class="sel" id="fStatus">
      <option value="">All Statuses</option>
      <option>Draft</option>
      <option>Pending Approval</option>
      <option>Approved</option>
      <option>Rejected</option>
      <option>Cancelled</option>
    </select>
    <select class="sel" id="fDept"><option value="">All Departments</option></select>
    <div class="date-range-wrap">
      <input type="date" class="fi-date" id="fDateFrom" title="Date From">
      <span>–</span>
      <input type="date" class="fi-date" id="fDateTo" title="Date To">
    </div>
  </div>

  <!-- BULK ACTION BAR (Super Admin) -->
  <div class="bulk-bar" id="bulkBar">
    <span class="bulk-count" id="bulkCount">0 selected</span>
    <div class="bulk-sep"></div>
    <button class="btn btn-batch-approve btn-sm" id="batchApproveBtn"><i class="bx bx-check-double"></i> Batch Approve</button>
    <button class="btn btn-batch-reject btn-sm" id="batchRejectBtn"><i class="bx bx-x"></i> Batch Reject</button>
    <button class="btn btn-ghost btn-sm" id="clearSelBtn"><i class="bx bx-x-circle"></i> Clear</button>
    <span class="sa-exclusive" style="margin-left:auto"><i class="bx bx-shield-quarter"></i> Super Admin Exclusive</span>
  </div>

  <!-- TABLE -->
  <div class="pr-card">
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
      <table class="pr-tbl" id="tbl">
        <colgroup>
          <col class="col-cb">
          <col class="col-id">
          <col class="col-req">
          <col class="col-dept">
          <col class="col-date">
          <col class="col-items">
          <col class="col-amt">
          <col class="col-status">
          <col class="col-act">
        </colgroup>
        <thead>
          <tr>
            <th class="no-sort"><div class="cb-wrap"><input type="checkbox" class="cb" id="checkAll" title="Select all"></div></th>
            <th data-col="id">PR Number <i class="bx bx-sort sic"></i></th>
            <th data-col="requestor">Requestor <i class="bx bx-sort sic"></i></th>
            <th data-col="dept">Department <i class="bx bx-sort sic"></i></th>
            <th data-col="dateFiled">Date Filed <i class="bx bx-sort sic"></i></th>
            <th data-col="itemCount">Items <i class="bx bx-sort sic"></i></th>
            <th data-col="amount">Total Est. Cost <i class="bx bx-sort sic"></i></th>
            <th data-col="status">Status <i class="bx bx-sort sic"></i></th>
            <th class="no-sort">Actions</th>
          </tr>
        </thead>
        <tbody id="tbody"></tbody>
      </table>
    </div>
    <div class="pr-pager" id="pager"></div>
  </div>

</div>
</main>

<!-- TOAST CONTAINER -->
<div class="pr-toasts" id="toastWrap"></div>

<!-- ═══════════════════════════════════════
     CREATE / EDIT SLIDER
     ═══════════════════════════════════════ -->
<div id="slOverlay">
<div id="prSlider">
  <div class="sl-hdr">
    <div>
      <div class="sl-title" id="slTitle">Create Purchase Request</div>
      <div class="sl-subtitle" id="slSub">Fill in all required fields below</div>
    </div>
    <button class="sl-close" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-body">
    <div class="fr">
      <div class="fg">
        <label class="fl">Requestor Name <span>*</span></label>
        <input type="text" class="fi" id="fReq" placeholder="Type a name to search…"
               autocomplete="off" spellcheck="false">
      </div>
      <div class="fg">
        <label class="fl">Department <span>*</span></label>
        <select class="fs" id="fDeptSl">
          <option value="">Select…</option>
          <option>Logistics</option><option>Procurement</option><option>Operations</option>
          <option>Finance</option><option>HR</option><option>IT</option>
          <option>Engineering</option><option>Admin</option>
        </select>
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Date Needed <span>*</span></label>
        <input type="date" class="fi" id="fDateNeeded">
      </div>
      <div class="fg">
        <label class="fl">Status</label>
        <select class="fs" id="fStatusSl">
          <option value="Draft">Save as Draft</option>
          <option value="Pending Approval">Submit for Approval</option>
        </select>
      </div>
    </div>
    <div class="fg">
      <label class="fl">Purpose / Justification</label>
      <textarea class="fta" id="fPurpose" placeholder="Explain why this purchase is needed…"></textarea>
    </div>
    <div class="fd">Line Items</div>
    <div class="li-rows" id="liRows"></div>
    <button class="add-li" id="addLiBtn" type="button"><i class="bx bx-plus"></i> Add Line Item</button>
    <div class="total-row">
      <span>Grand Total</span>
      <span id="grandTotal">₱0.00</span>
    </div>
  </div>
  <div class="sl-foot">
    <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-send"></i> Submit PR</button>
  </div>
</div>
</div>

<!-- REQUESTOR NAME AUTOCOMPLETE DROPDOWN (body-level, position:fixed) -->
<div id="reqSuggest" style="
  display:none; position:fixed; z-index:9999;
  background:#fff; border:1px solid rgba(46,125,50,.26); border-radius:10px;
  box-shadow:0 8px 24px rgba(0,0,0,.14); overflow:hidden;
  max-height:220px; overflow-y:auto; min-width:200px;
"></div>

<!-- ═══════════════════════════════════════
     ACTION CONFIRM MODAL

     ═══════════════════════════════════════ -->
<div id="actionModal">
  <div class="am-box">
    <div class="am-icon" id="amIcon">✅</div>
    <div class="am-title" id="amTitle">Confirm Action</div>
    <div class="am-body" id="amBody"></div>
    <div class="am-sa-note" id="amSaNote" style="display:none">
      <i class="bx bx-shield-quarter"></i>
      <span id="amSaText"></span>
    </div>
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

<!-- ═══════════════════════════════════════
     VIEW PR MODAL
     ═══════════════════════════════════════ -->
<div id="viewModal">
  <div class="vm-box">
    <div class="vm-mhd">
      <div class="vm-mtp">
        <div class="vm-msi">
          <div class="vm-mav" id="vmAvatar"></div>
          <div>
            <div class="vm-mnm" id="vmName"></div>
            <div class="vm-mid" id="vmMid"></div>
          </div>
        </div>
        <button class="vm-mcl" id="vmClose"><i class="bx bx-x"></i></button>
      </div>
      <div class="vm-mmt" id="vmChips"></div>
      <div class="vm-mtb">
        <button class="vm-tab active" data-t="ov"><i class="bx bx-grid-alt"></i> Overview</button>
        <button class="vm-tab" data-t="li"><i class="bx bx-list-ul"></i> Line Items</button>
        <button class="vm-tab" data-t="au"><i class="bx bx-shield-quarter"></i> Audit Trail</button>
      </div>
    </div>
    <div class="vm-mbd" id="vmBody">
      <div class="vm-tp active" id="vt-ov"></div>
      <div class="vm-tp"        id="vt-li"></div>
      <div class="vm-tp"        id="vt-au"></div>
    </div>
    <div class="vm-mft" id="vmFoot"></div>
  </div>
</div>

<script>
/* ── STATIC DATA / CONSTANTS ──────────────────────────────────────── */
const ROLE      = '<?= addslashes($prRoleName) ?>';
const USER_DEPT = '<?= addslashes((string)$prUserDept) ?>';
const USER_ID   = '<?= addslashes((string)$prUserId) ?>';
const DC = {
  Logistics:'#2E7D32',Procurement:'#0D9488',Operations:'#2563EB',
  Finance:'#D97706',HR:'#7C3AED',IT:'#DC2626',Engineering:'#0891B2',Admin:'#059669'
};
const IPS = ['192.168.1.101','192.168.1.45','10.0.0.22','172.16.3.88','10.10.1.200','192.168.2.55'];
function randIP() { return IPS[Math.floor(Math.random() * IPS.length)]; }
function nowStr() { return new Date().toISOString().split('T')[0]; }
function nowTS()  { return new Date().toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}); }

/* ── DATASET (START EMPTY; REAL DATA COMES FROM BACKEND) ─────────── */
let prs = [];
const API_BASE = '/superadmin/psm/requests.php';
const USER_API  = '/superadmin/admin/user_management.php';

/* ── STATE ─────────────────────────────────────────────── */
let sortCol = 'dateFiled', sortDir = 'desc', page = 1;
const PAGE_SIZE = 10;
let actionTarget = null, actionKey = null;
let lineItems = [], editId = null;
let selectedIds = new Set();

/* ── HELPERS ───────────────────────────────────────────── */
const esc  = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const ini  = n => String(n||'').split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
const fD   = d => { if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); };
const fM   = n => '₱' + Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const dc   = d => DC[d] || '#6B7280';
function badge(s) {
  const m = {Draft:'b-draft','Pending Approval':'b-pending',Approved:'b-approved',Rejected:'b-rejected',Cancelled:'b-cancelled'};
  return `<span class="badge ${m[s]||''}">${s}</span>`;
}

/* ── FILTER & SORT ─────────────────────────────────────── */
function getFiltered() {
  const q    = document.getElementById('srch').value.trim().toLowerCase();
  const st   = document.getElementById('fStatus').value;
  const dp   = document.getElementById('fDept').value;
  const df   = document.getElementById('fDateFrom').value;
  const dt   = document.getElementById('fDateTo').value;
  return prs.filter(p => {
    if (q && !p.id.toLowerCase().includes(q) && !p.requestor.toLowerCase().includes(q)) return false;
    if (st && p.status !== st) return false;
    if (dp && p.dept !== dp)   return false;
    if (df && p.dateFiled < df) return false;
    if (dt && p.dateFiled > dt) return false;
    return true;
  });
}
function getSorted(list) {
  return [...list].sort((a, b) => {
    let va = a[sortCol], vb = b[sortCol];
    if (sortCol === 'amount' || sortCol === 'itemCount') return sortDir==='asc' ? va-vb : vb-va;
    va = String(va||'').toLowerCase(); vb = String(vb||'').toLowerCase();
    return sortDir==='asc' ? va.localeCompare(vb) : vb.localeCompare(va);
  });
}

async function apiRequest(path, options = {}) {
  const opts = {
    method: options.method || 'GET',
    headers: {
      'Content-Type': 'application/json',
      ...(options.headers || {})
    },
    body: options.body || null
  };
  const res = await fetch(path, opts);
  let data;
  try {
    data = await res.json();
  } catch (_) {
    throw new Error('Invalid server response');
  }
  if (!res.ok || !data || data.success === false) {
    throw new Error(data && data.error ? data.error : 'Request failed');
  }
  return data;
}

async function loadPrs() {
  try {
    const resp = await apiRequest(`${API_BASE}?api=list`);
    prs = Array.isArray(resp.data) ? resp.data : [];
    renderList();
  } catch (e) {
    prs = [];
    renderList();
    toast(e.message || 'Failed to load purchase requests','d');
  }
}

/* ── RENDER ─────────────────────────────────────────────── */
function renderStats() {
  const tot  = prs.length;
  const draft = prs.filter(p=>p.status==='Draft').length;
  const pend  = prs.filter(p=>p.status==='Pending Approval').length;
  const appr  = prs.filter(p=>p.status==='Approved').length;
  const rej   = prs.filter(p=>p.status==='Rejected').length;
  const val   = prs.filter(p=>p.status==='Approved').reduce((s,p)=>s+p.amount,0);
  document.getElementById('statsBar').innerHTML = `
    <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-receipt"></i></div><div><div class="sc-v">${tot}</div><div class="sc-l">Total PRs${ROLE==='Staff'||ROLE==='User'?' (My PRs)':ROLE==='Manager'?' (My Dept)':''}</div></div></div>
    <div class="sc"><div class="sc-ic ic-d"><i class="bx bx-pencil"></i></div><div><div class="sc-v">${draft}</div><div class="sc-l">Drafts</div></div></div>
    <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-time-five"></i></div><div><div class="sc-v">${pend}</div><div class="sc-l">Pending Approval</div></div></div>
    <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${appr}</div><div class="sc-l">Approved</div></div></div>
    <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-x-circle"></i></div><div><div class="sc-v">${rej}</div><div class="sc-l">Rejected</div></div></div>
    <div class="sc"><div class="sc-ic ic-t"><i class="bx bx-money-withdraw"></i></div><div><div class="sc-v" style="font-size:13px">${fM(val)}</div><div class="sc-l">Approved Value</div></div></div>`;
}

function buildDropdowns() {
  const depts = [...new Set(prs.map(p=>p.dept))].sort();
  const dEl = document.getElementById('fDept'), dv = dEl.value;
  dEl.innerHTML = '<option value="">All Departments</option>' + depts.map(d=>`<option ${d===dv?'selected':''}>${d}</option>`).join('');
}

function updateBulkBar() {
  const n = selectedIds.size;
  document.getElementById('bulkBar').classList.toggle('on', n > 0);
  document.getElementById('bulkCount').textContent = n === 1 ? '1 selected' : `${n} selected`;
}

function syncCheckAll(slice) {
  const ca = document.getElementById('checkAll');
  const pageIds = slice.map(p => p.id);
  const allChecked = pageIds.length > 0 && pageIds.every(id => selectedIds.has(id));
  const someChecked = pageIds.some(id => selectedIds.has(id));
  ca.checked = allChecked;
  ca.indeterminate = !allChecked && someChecked;
}

function renderList() {
  renderStats();
  buildDropdowns();
  const data  = getSorted(getFiltered());
  const total = data.length;
  const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
  if (page > pages) page = pages;
  const slice = data.slice((page-1)*PAGE_SIZE, page*PAGE_SIZE);

  // Sort icons
  document.querySelectorAll('#tbl thead th[data-col]').forEach(th => {
    const c = th.dataset.col;
    th.classList.toggle('sorted', c === sortCol);
    const ic = th.querySelector('.sic');
    if (ic) ic.className = `bx ${c===sortCol ? (sortDir==='asc'?'bx-sort-up':'bx-sort-down') : 'bx-sort'} sic`;
  });

  const tb = document.getElementById('tbody');
  if (!slice.length) {
    tb.innerHTML = `<tr><td colspan="9"><div class="empty"><i class="bx bx-receipt"></i><p>No purchase requests found.</p></div></td></tr>`;
  } else {
    tb.innerHTML = slice.map(p => {
      const clr  = dc(p.dept);
      const isPending   = p.status === 'Pending Approval';
      const isDraft     = p.status === 'Draft';
      const isRejected  = p.status === 'Rejected';
      const isCancelled = p.status === 'Cancelled';
      const isApproved  = p.status === 'Approved';
      const isOwn       = p.createdBy && String(p.createdBy) === USER_ID;
      const chk = selectedIds.has(p.id);
      // Role-based action visibility
      const canEdit =
        (ROLE==='Super Admin' || ROLE==='Admin')
          ? (isDraft || isPending)
          : (ROLE==='Manager'
              ? false
              : (ROLE==='Staff' || ROLE==='User')
                  ? (isDraft && isOwn)
                  : false);
      const canApproveReject =
        (ROLE==='Super Admin' || ROLE==='Admin') && isPending;
      const canCancel =
        (ROLE==='Super Admin' || ROLE==='Admin') && !isCancelled && !isDraft;
      const canOverride =
        ROLE==='Super Admin' && isRejected;
      return `<tr class="${chk?'row-selected':''}">
        <td onclick="event.stopPropagation()">
          <div class="cb-wrap"><input type="checkbox" class="cb row-cb" data-id="${p.id}" ${chk?'checked':''}></div>
        </td>
        <td onclick="openView('${p.id}')">
          <span class="pr-num">${esc(p.id)}</span>
        </td>
        <td onclick="openView('${p.id}')">
          <div class="req-cell">
            <div class="req-av" style="background:${clr}">${ini(p.requestor)}</div>
            <div class="req-name">${esc(p.requestor)}</div>
          </div>
        </td>
        <td onclick="openView('${p.id}')">
          <span class="dept-dot"><span style="width:7px;height:7px;border-radius:50%;background:${clr};flex-shrink:0"></span>${esc(p.dept)}</span>
        </td>
        <td onclick="openView('${p.id}')">
          <span class="pr-date">${fD(p.dateFiled)}</span>
        </td>
        <td onclick="openView('${p.id}')">
          <span class="items-cell">${p.items.length}</span>
        </td>
        <td onclick="openView('${p.id}')">
          <span class="pr-amt">${fM(p.amount)}</span>
        </td>
        <td onclick="openView('${p.id}')">${badge(p.status)}</td>
        <td onclick="event.stopPropagation()">
          <div class="act-cell">
            <button class="btn btn-ghost btn-xs ionly" onclick="openView('${p.id}')" title="View"><i class="bx bx-show"></i></button>
            ${canEdit ? `<button class="btn btn-ghost btn-xs ionly" onclick="openEdit('${p.id}')" title="Edit"><i class="bx bx-edit"></i></button>` : ''}
            ${canApproveReject ? `
              <button class="btn btn-approve btn-xs ionly" onclick="promptAct('${p.id}','approve')" title="Approve"><i class="bx bx-check"></i></button>
              <button class="btn btn-reject btn-xs ionly" onclick="promptAct('${p.id}','reject')" title="Reject"><i class="bx bx-x"></i></button>` : ''}
            ${canOverride ? `<button class="btn btn-override btn-xs ionly" onclick="promptAct('${p.id}','override')" title="Override (Super Admin)"><i class="bx bx-revision"></i></button>` : ''}
            ${canCancel ? `<button class="btn btn-cancel-pr btn-xs ionly" onclick="promptAct('${p.id}','cancel')" title="Cancel"><i class="bx bx-minus-circle"></i></button>` : ''}
          </div>
        </td>
      </tr>`;
    }).join('');

    document.querySelectorAll('.row-cb').forEach(cb => {
      cb.addEventListener('change', function() {
        const id = this.dataset.id;
        if (this.checked) selectedIds.add(id); else selectedIds.delete(id);
        this.closest('tr').classList.toggle('row-selected', this.checked);
        updateBulkBar(); syncCheckAll(slice);
      });
    });
  }
  syncCheckAll(slice);

  // Pagination
  const s = (page-1)*PAGE_SIZE+1, e = Math.min(page*PAGE_SIZE, total);
  let btns = '';
  for (let i=1; i<=pages; i++) {
    if (i===1||i===pages||(i>=page-2&&i<=page+2)) btns += `<button class="pgb ${i===page?'active':''}" onclick="goPage(${i})">${i}</button>`;
    else if (i===page-3||i===page+3) btns += `<button class="pgb" disabled>…</button>`;
  }
  document.getElementById('pager').innerHTML = `
    <span>${total===0 ? 'No results' : `Showing ${s}–${e} of ${total} records`}</span>
    <div class="pg-btns">
      <button class="pgb" onclick="goPage(${page-1})" ${page<=1?'disabled':''}><i class="bx bx-chevron-left"></i></button>
      ${btns}
      <button class="pgb" onclick="goPage(${page+1})" ${page>=pages?'disabled':''}><i class="bx bx-chevron-right"></i></button>
    </div>`;
}

function goPage(p) { page = p; renderList(); }

/* ── SORT HEADERS ─────────────────────────────────────── */
document.querySelectorAll('#tbl thead th[data-col]').forEach(th => {
  th.addEventListener('click', () => {
    const c = th.dataset.col;
    sortDir = sortCol===c ? (sortDir==='asc'?'desc':'asc') : 'asc';
    sortCol = c; page = 1; renderList();
  });
});

/* ── FILTERS ─────────────────────────────────────────── */
['srch','fStatus','fDept','fDateFrom','fDateTo'].forEach(id =>
  document.getElementById(id).addEventListener('input', () => { page=1; renderList(); })
);

/* ── CHECK ALL ─────────────────────────────────────────── */
document.getElementById('checkAll').addEventListener('change', function() {
  const slice = getSorted(getFiltered()).slice((page-1)*PAGE_SIZE, page*PAGE_SIZE);
  slice.forEach(p => { if (this.checked) selectedIds.add(p.id); else selectedIds.delete(p.id); });
  renderList(); updateBulkBar();
});
document.getElementById('clearSelBtn').addEventListener('click', () => { selectedIds.clear(); renderList(); updateBulkBar(); });

/* ── BATCH ACTIONS ─────────────────────────────────────── */
document.getElementById('batchApproveBtn').addEventListener('click', () => {
  const pending = [...selectedIds].filter(id => prs.find(p=>p.id===id&&p.status==='Pending Approval'));
  if (!pending.length) return toast('No Pending Approval PRs in selection.','w');
  actionKey = 'batch-approve'; actionTarget = null;
  showActionModal('✅',`Batch Approve ${pending.length} PR(s)`,
    `Force-approve <strong>${pending.length}</strong> Pending Approval PR(s).`,
    true,'Super Admin batch approval across all branches.',
    'btn-approve','<i class="bx bx-check-double"></i> Batch Approve');
  window._batchIds = pending;
});
document.getElementById('batchRejectBtn').addEventListener('click', () => {
  const pending = [...selectedIds].filter(id => prs.find(p=>p.id===id&&p.status==='Pending Approval'));
  if (!pending.length) return toast('No Pending Approval PRs in selection.','w');
  actionKey = 'batch-reject'; actionTarget = null;
  showActionModal('❌',`Batch Reject ${pending.length} PR(s)`,
    `Force-reject <strong>${pending.length}</strong> Pending Approval PR(s).`,
    true,'Super Admin batch rejection across all branches.',
    'btn-reject','<i class="bx bx-x"></i> Batch Reject');
  window._batchIds = pending;
});

/* ── ACTION MODAL ─────────────────────────────────────── */
function showActionModal(icon, title, body, sa, saText, btnClass, btnLabel) {
  document.getElementById('amIcon').textContent  = icon;
  document.getElementById('amTitle').textContent = title;
  document.getElementById('amBody').innerHTML    = body;
  const saNote = document.getElementById('amSaNote');
  if (sa) { saNote.style.display='flex'; document.getElementById('amSaText').textContent=saText; }
  else     { saNote.style.display='none'; }
  document.getElementById('amRemarks').value = '';
  const cb = document.getElementById('amConfirm');
  cb.className = `btn btn-sm ${btnClass}`; cb.innerHTML = btnLabel;
  document.getElementById('actionModal').classList.add('on');
}

function promptAct(id, type) {
  const pr = prs.find(p=>p.id===id); if (!pr) return;
  actionTarget = id; actionKey = type;
  const cfg = {
    approve:  {icon:'✅',title:'Force Approve PR',  sa:true, saText:'You are exercising Super Admin authority to force-approve this request.',     btn:'btn-approve',  label:'<i class="bx bx-check"></i> Force Approve'},
    reject:   {icon:'❌',title:'Force Reject PR',   sa:true, saText:'You are exercising Super Admin authority to force-reject this request.',      btn:'btn-reject',   label:'<i class="bx bx-x"></i> Force Reject'},
    override: {icon:'🔄',title:'Override Rejection',sa:true, saText:'Super Admin override — reverses the rejected status and approves this PR.',   btn:'btn-override', label:'<i class="bx bx-revision"></i> Override & Approve'},
    cancel:   {icon:'⛔',title:'Cancel PR',         sa:false,saText:'',                                                                             btn:'btn-cancel-pr',label:'<i class="bx bx-minus-circle"></i> Cancel PR'},
  };
  const c = cfg[type];
  showActionModal(c.icon, c.title,
    `PR <strong>${esc(pr.id)}</strong> — <strong>${esc(pr.requestor)}</strong> (${esc(pr.dept)}) · <strong>${fM(pr.amount)}</strong>`,
    c.sa, c.saText, c.btn, c.label);
}

document.getElementById('amConfirm').addEventListener('click', async () => {
  const rmk    = document.getElementById('amRemarks').value.trim();
  const btn    = document.getElementById('amConfirm');
  const origLbl = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Please wait…';

  try {
    if (actionKey === 'batch-approve' || actionKey === 'batch-reject') {
      const ids    = window._batchIds || [];
      const type   = actionKey === 'batch-approve' ? 'approve' : 'reject';
      // Call api=action for each selected PR
      await Promise.all(ids.map(id =>
        apiRequest(`${API_BASE}?api=action`, {
          method: 'POST',
          body: JSON.stringify({ id, type, remarks: rmk })
        }).catch(() => null)  // ignore individual failures, reload will sync
      ));
      selectedIds.clear();
      document.getElementById('actionModal').classList.remove('on');
      toast(`${ids.length} PR(s) ${type === 'approve' ? 'approved' : 'rejected'} successfully.`, 's');
      await loadPrs();  // reload from server
      updateBulkBar();
    } else {
      // Single action
      const resp = await apiRequest(`${API_BASE}?api=action`, {
        method: 'POST',
        body: JSON.stringify({ id: actionTarget, type: actionKey, remarks: rmk })
      });
      const updated = resp.data;
      if (updated) {
        const idx = prs.findIndex(p => p.id === updated.id);
        if (idx >= 0) prs[idx] = updated;
      }
      document.getElementById('actionModal').classList.remove('on');
      toast(`${actionTarget} — action applied successfully.`, 's');
      renderList();
      // Refresh view modal if open
      if (document.getElementById('viewModal').classList.contains('on') && updated) {
        renderDetail(updated);
      }
    }
  } catch (e) {
    toast(e.message || 'Action failed', 'd');
  } finally {
    btn.disabled = false;
    btn.innerHTML = origLbl;
  }
});
document.getElementById('amCancel').addEventListener('click', () => document.getElementById('actionModal').classList.remove('on'));
document.getElementById('actionModal').addEventListener('click', function(e) { if(e.target===this) this.classList.remove('on'); });

/* ── VIEW MODAL ─────────────────────────────────────────── */
let vmCurrentId = null;

function openView(id) {
  const pr = prs.find(p=>p.id===id); if (!pr) return;
  vmCurrentId = id;
  renderDetail(pr);
  setVmTab('ov');
  document.getElementById('viewModal').classList.add('on');
}
function closeView() { document.getElementById('viewModal').classList.remove('on'); vmCurrentId=null; }

document.getElementById('vmClose').addEventListener('click', closeView);
document.getElementById('viewModal').addEventListener('click', function(e) { if(e.target===this) closeView(); });
document.querySelectorAll('.vm-tab').forEach(t => t.addEventListener('click', () => setVmTab(t.dataset.t)));

function setVmTab(name) {
  document.querySelectorAll('.vm-tab').forEach(t => t.classList.toggle('active', t.dataset.t===name));
  document.querySelectorAll('.vm-tp').forEach(p => p.classList.toggle('active', p.id==='vt-'+name));
}

function renderDetail(pr) {
  const clr  = dc(pr.dept);
  const isPending = pr.status==='Pending Approval', isDraft=pr.status==='Draft', isRej=pr.status==='Rejected';
  const tot = pr.items.reduce((s,i) => s+i.qty*i.up, 0);

  document.getElementById('vmAvatar').textContent   = ini(pr.requestor);
  document.getElementById('vmAvatar').style.background = clr;
  document.getElementById('vmName').innerHTML       = esc(pr.requestor);
  document.getElementById('vmMid').innerHTML        =
    `<span style="font-family:'DM Mono',monospace">${esc(pr.id)}</span>
     &nbsp;·&nbsp;${esc(pr.dept)}
     &nbsp;${badge(pr.status)}`;

  document.getElementById('vmChips').innerHTML = `
    <div class="vm-mc"><i class="bx bx-calendar"></i>Filed ${fD(pr.dateFiled)}</div>
    <div class="vm-mc"><i class="bx bx-alarm"></i>Needed by ${fD(pr.dateNeeded)}</div>
    <div class="vm-mc"><i class="bx bx-list-ul"></i>${pr.items.length} Items</div>
    <div class="vm-mc"><i class="bx bx-money-withdraw"></i>${fM(tot)}</div>`;

  document.getElementById('vmFoot').innerHTML = `
    ${isPending ? `
      <button class="btn btn-approve btn-sm" onclick="closeView();promptAct('${pr.id}','approve')"><i class="bx bx-check"></i> Force Approve</button>
      <button class="btn btn-reject btn-sm"  onclick="closeView();promptAct('${pr.id}','reject')"><i class="bx bx-x"></i> Force Reject</button>` : ''}
    ${isDraft||isPending ? `<button class="btn btn-ghost btn-sm" onclick="closeView();openEdit('${pr.id}')"><i class="bx bx-edit"></i> Edit</button>` : ''}
    ${isRej ? `<button class="btn btn-override btn-sm" onclick="closeView();promptAct('${pr.id}','override')"><i class="bx bx-revision"></i> Override</button>` : ''}
    ${pr.status!=='Cancelled'&&pr.status!=='Draft' ? `<button class="btn btn-cancel-pr btn-sm" onclick="closeView();promptAct('${pr.id}','cancel')"><i class="bx bx-minus-circle"></i> Cancel</button>` : ''}
    <button class="btn btn-ghost btn-sm" onclick="closeView()">Close</button>`;

  // Overview tab
  const rkc = pr.status==='Approved'?'vm-rmk-a':pr.status==='Rejected'||pr.status==='Cancelled'?'vm-rmk-r':'vm-rmk-n';
  document.getElementById('vt-ov').innerHTML = `
    <div class="vm-sbs">
      <div class="vm-sb"><div class="sbv">${pr.items.length}</div><div class="sbl">Line Items</div></div>
      <div class="vm-sb"><div class="sbv mono">${fM(tot)}</div><div class="sbl">Total Est. Cost</div></div>
      <div class="vm-sb"><div class="sbv">${pr.items.reduce((s,i)=>s+i.qty,0).toLocaleString()}</div><div class="sbl">Total Units</div></div>
      <div class="vm-sb"><div class="sbv">${pr.items.filter(i=>i.up>0).length}</div><div class="sbl">Priced Items</div></div>
    </div>
    <div class="vm-ig">
      <div class="vm-ii"><label>Department</label><div class="v" style="color:${clr}">${esc(pr.dept)}</div></div>
      <div class="vm-ii"><label>Date Filed</label><div class="v muted">${fD(pr.dateFiled)}</div></div>
      <div class="vm-ii"><label>Date Needed</label><div class="v muted">${fD(pr.dateNeeded)}</div></div>
      <div class="vm-ii"><label>Status</label><div class="v">${badge(pr.status)}</div></div>
      ${pr.purpose ? `<div class="vm-ii vm-full"><label>Purpose / Justification</label><div class="v muted">${esc(pr.purpose)}</div></div>` : ''}
      ${pr.remarks ? `<div class="vm-ii vm-full"><label>Admin Remarks</label><div class="vm-rmk ${rkc}"><div class="rml">Remarks</div>${esc(pr.remarks)}</div></div>` : ''}
    </div>`;

  // Line Items tab
  document.getElementById('vt-li').innerHTML = `
    <table class="vm-txnt">
      <thead><tr>
        <th style="width:28px">#</th>
        <th>Item Name</th><th>Specification</th>
        <th style="text-align:right">Qty</th>
        <th>Unit</th>
        <th style="text-align:right">Unit Price</th>
        <th style="text-align:right">Total</th>
      </tr></thead>
      <tbody>
        ${pr.items.map((it,i) => `<tr>
          <td style="color:#9CA3AF;font-size:11px;font-weight:600">${i+1}</td>
          <td><div class="li-name">${esc(it.name)}</div></td>
          <td><div class="li-spec">${esc(it.spec)}</div></td>
          <td style="text-align:right;font-weight:700">${it.qty.toLocaleString()}</td>
          <td style="font-size:12px;color:#6B7280">${esc(it.unit)}</td>
          <td class="vm-ta">${fM(it.up)}</td>
          <td style="font-family:'DM Mono',monospace;font-size:12px;font-weight:700">${fM(it.qty*it.up)}</td>
        </tr>`).join('')}
      </tbody>
      <tfoot><tr>
        <td colspan="6" style="text-align:right;color:#9CA3AF;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">Grand Total</td>
        <td>${fM(tot)}</td>
      </tr></tfoot>
    </table>`;

  // Audit Trail tab
  document.getElementById('vt-au').innerHTML = `
    <div class="vm-sa-note">
      <i class="bx bx-shield-quarter"></i>
      <span>Full audit trail — visible to Super Admin only. All timestamps, actions, and IP addresses are read-only and immutable.</span>
    </div>
    <div>${pr.auditLog.map(a => `
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

/* ── CREATE / EDIT SLIDER ──────────────────────────────── */
function openSlider(mode='create', pr=null) {
  editId = mode==='edit' ? pr.id : null;
  document.getElementById('slTitle').textContent    = mode==='edit' ? `Edit PR — ${pr.id}` : 'Create Purchase Request';
  document.getElementById('slSub').textContent      = mode==='edit' ? 'Update fields below' : 'Fill in all required fields below';
  if (mode==='edit' && pr) {
    document.getElementById('fReq').value         = pr.requestor;
    document.getElementById('fDeptSl').value      = pr.dept;
    document.getElementById('fDateNeeded').value  = pr.dateNeeded;
    document.getElementById('fPurpose').value     = pr.purpose;
    document.getElementById('fStatusSl').value    = pr.status==='Draft' ? 'Draft' : 'Pending Approval';
    lineItems = pr.items.map(i => ({...i, _id:Date.now()+Math.random()}));
  } else {
    ['fReq','fPurpose'].forEach(id => document.getElementById(id).value='');
    ['fDeptSl'].forEach(id => document.getElementById(id).value='');
    document.getElementById('fDateNeeded').value  = '';
    document.getElementById('fStatusSl').value    = 'Pending Approval';
    lineItems = [{_id:Date.now(),name:'',spec:'',unit:'pcs',qty:1,up:0}];
  }
  renderLineItems();
  document.getElementById('prSlider').classList.add('on');
  document.getElementById('slOverlay').classList.add('on');
  setTimeout(() => document.getElementById('fReq').focus(), 350);
}

function openEdit(id) {
  const pr = prs.find(p=>p.id===id); if (pr) openSlider('edit', pr);
}

function closeSlider() {
  document.getElementById('prSlider').classList.remove('on');
  document.getElementById('slOverlay').classList.remove('on');
  editId = null;
}

document.getElementById('slOverlay').addEventListener('click', function(e) { if(e.target===this) closeSlider(); });
document.getElementById('slClose').addEventListener('click', closeSlider);
document.getElementById('slCancel').addEventListener('click', closeSlider);
document.getElementById('createBtn').addEventListener('click', () => openSlider('create'));
document.getElementById('addLiBtn').addEventListener('click', () => {
  lineItems.push({_id:Date.now(), name:'',spec:'',unit:'pcs',qty:1,up:0});
  renderLineItems();
});

/* ── REQUESTOR AUTOCOMPLETE + AUTO-FILL DEPARTMENT ───────────────────────── */
(function () {
  const fReq    = document.getElementById('fReq');
  const suggest = document.getElementById('reqSuggest');
  const deptSel = document.getElementById('fDeptSl');

  let _nameMap = {};
  let _debounce = null;

  // Position the fixed dropdown directly below the input
  function positionDropdown() {
    const r = fReq.getBoundingClientRect();
    suggest.style.top   = (r.bottom + 4) + 'px';
    suggest.style.left  = r.left + 'px';
    suggest.style.width = r.width + 'px';
  }

  function setDept(zone) {
    if (!zone) return;
    let found = false;
    Array.from(deptSel.options).forEach(opt => {
      if (opt.value === zone || opt.text === zone) { opt.selected = true; found = true; }
    });
    if (!found) {
      const newOpt = new Option(zone, zone, true, true);
      deptSel.add(newOpt);
    }
  }

  function hideSuggest() {
    suggest.style.display = 'none';
    suggest.innerHTML = '';
  }

  function showSuggestions(users) {
    if (!users.length) { hideSuggest(); return; }
    _nameMap = {};
    suggest.innerHTML = users.map(u => {
      _nameMap[u.name] = u.dept;
      return `<div class="req-sug-item" data-name="${u.name.replace(/"/g,'&quot;')}" style="
        padding:9px 14px; cursor:pointer; font-size:13px;
        display:flex; align-items:center; justify-content:space-between; gap:10px;
        border-bottom:1px solid rgba(46,125,50,.1); transition:background .12s;
      " onmouseenter="this.style.background='#F0FDF4'" onmouseleave="this.style.background=''">
        <span style="font-weight:600;color:var(--t1)">${u.name}</span>
        <span style="font-size:11px;color:var(--t3);white-space:nowrap">${u.dept}</span>
      </div>`;
    }).join('');
    suggest.querySelectorAll('.req-sug-item').forEach(item => {
      item.addEventListener('mousedown', e => {
        e.preventDefault();
        const name = item.dataset.name;
        fReq.value = name;
        if (_nameMap[name]) setDept(_nameMap[name]);
        hideSuggest();
        fReq.focus();
      });
    });
    positionDropdown();
    suggest.style.display = 'block';
  }

  async function fetchSuggestions(q) {
    try {
      const res  = await fetch(`${API_BASE}?api=lookup_users&q=${encodeURIComponent(q)}`);
      const json = await res.json();
      if (json.success && Array.isArray(json.data)) showSuggestions(json.data);
      else hideSuggest();
    } catch { hideSuggest(); }
  }

  fReq.addEventListener('input', () => {
    clearTimeout(_debounce);
    const q = fReq.value.trim();
    if (q.length < 2) { hideSuggest(); return; }
    _debounce = setTimeout(() => fetchSuggestions(q), 250);
  });

  fReq.addEventListener('blur', () => {
    setTimeout(hideSuggest, 180);
    const name = fReq.value.trim();
    if (name && _nameMap[name]) setDept(_nameMap[name]);
  });

  fReq.addEventListener('keydown', e => {
    if (e.key === 'Escape') hideSuggest();
  });

  // Re-position if slider is scrolled or window resized
  document.querySelector('.sl-body')?.addEventListener('scroll', () => {
    if (suggest.style.display !== 'none') positionDropdown();
  });
  window.addEventListener('resize', () => {
    if (suggest.style.display !== 'none') positionDropdown();
  });
  // Hide when slider closes
  document.getElementById('slClose') .addEventListener('click',  hideSuggest);
  document.getElementById('slCancel').addEventListener('click',  hideSuggest);
  document.getElementById('slOverlay').addEventListener('click', hideSuggest);
})();

function renderLineItems() {
  document.getElementById('liRows').innerHTML = lineItems.map((l, i) => `
    <div class="li-row" id="lr${l._id}">
      ${lineItems.length>1 ? `<button class="li-rm" onclick="removeLineItem(${l._id})"><i class="bx bx-trash" style="font-size:13px"></i></button>` : ''}
      <div style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.06em">Item ${i+1}</div>
      <div class="li-rr1">
        <div class="fg"><label class="fl">Item Name <span>*</span></label><input type="text" class="fi" placeholder="Item name" value="${esc(l.name)}" oninput="updateLineItem(${l._id},'name',this.value)"></div>
        <div class="fg"><label class="fl">Qty <span>*</span></label><input type="number" class="fi" min="1" value="${l.qty}" oninput="updateLineItem(${l._id},'qty',+this.value)"></div>
        <div class="fg"><label class="fl">Unit Price (₱)</label><input type="number" class="fi" min="0" step="0.01" value="${l.up||''}" placeholder="0.00" oninput="updateLineItem(${l._id},'up',+this.value)"></div>
      </div>
      <div class="li-rr2">
        <div class="fg"><label class="fl">Specifications</label><input type="text" class="fi" placeholder="Brand, size, grade…" value="${esc(l.spec)}" oninput="updateLineItem(${l._id},'spec',this.value)"></div>
        <div class="fg"><label class="fl">Unit</label><select class="fs" onchange="updateLineItem(${l._id},'unit',this.value)">${['pcs','sets','boxes','rolls','bags','liters','kg','meters','pairs','reams','cans'].map(u=>`<option ${l.unit===u?'selected':''}>${u}</option>`).join('')}</select></div>
      </div>
      <div class="li-sub">Subtotal: ${fM(l.qty*l.up)}</div>
    </div>`).join('');
  document.getElementById('grandTotal').textContent = fM(lineItems.reduce((s,l) => s+l.qty*l.up, 0));
}
function updateLineItem(id, k, v) {
  const l = lineItems.find(x => x._id === id);
  if (!l) return;
  l[k] = v;
  // Only patch the subtotal of this row + grand total — do NOT re-render whole list
  const sub = l.qty * l.up;
  const subEl = document.querySelector(`#lr${id} .li-sub`);
  if (subEl) subEl.textContent = 'Subtotal: ' + fM(sub);
  document.getElementById('grandTotal').textContent = fM(lineItems.reduce((s, x) => s + x.qty * x.up, 0));
}
function removeLineItem(id) { lineItems = lineItems.filter(l => l._id !== id); renderLineItems(); }

document.getElementById('slSubmit').addEventListener('click', async () => {
  const req    = document.getElementById('fReq').value.trim();
  const dept   = document.getElementById('fDeptSl').value;
  const dn     = document.getElementById('fDateNeeded').value;
  const purp   = document.getElementById('fPurpose').value.trim();
  const stChoice = document.getElementById('fStatusSl').value;

  if (!req)    { shk('fReq');       return toast('Requestor name is required','w'); }
  if (!dept)   { shk('fDeptSl');    return toast('Please select a department','w'); }
  if (!dn)     { shk('fDateNeeded');return toast('Date needed is required','w'); }
  if (lineItems.some(l=>!l.name.trim())) return toast('All line items must have a name','w');
  if (lineItems.some(l=>l.qty<1))        return toast('Quantity must be at least 1','w');

  const items = lineItems.map((l,i) => ({id:i+1,name:l.name,spec:l.spec,unit:l.unit,qty:l.qty,up:l.up}));
  const finalStatus = stChoice==='Draft' ? 'Draft' : 'Pending Approval';

  const payload = {
    requestor: req,
    dept: dept,
    dateNeeded: dn,
    purpose: purp,
    status: finalStatus,
    items
  };
  if (editId) {
    payload.id = editId;
  }

  try {
    const resp = await apiRequest(`${API_BASE}?api=save`, {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    const saved = resp.data;
    if (!saved || !saved.id) {
      throw new Error('Server did not return a PR number');
    }
    const idx = prs.findIndex(p => p.id === saved.id);
    if (idx >= 0) {
      prs[idx] = saved;
    } else {
      prs.unshift(saved);
      page = 1;
    }
    toast(editId ? `${saved.id} updated successfully.` : `${saved.id} ${finalStatus==='Draft'?'saved as Draft':'submitted for approval'}.`,'s');
    editId = null;
    closeSlider();
    renderList();
  } catch (e) {
    toast(e.message || 'Failed to save purchase request','d');
  }
});

/* ── EXPORT ─────────────────────────────────────────────── */
document.getElementById('exportBtn').addEventListener('click', () => {
  const cols = ['id','requestor','dept','dateFiled','itemCount','amount','status'];
  const hdrs = ['PR Number','Requestor','Department','Date Filed','Items','Total Est. Cost','Status'];
  const rows = [hdrs.join(','), ...getFiltered().map(p => cols.map(c=>`"${String(p[c]||'').replace(/"/g,'""')}"`).join(','))];
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
  a.download = 'purchase_requests.csv'; a.click();
  toast('CSV exported successfully.','s');
});

/* ── UTILITIES ──────────────────────────────────────────── */
function shk(id) {
  const el = document.getElementById(id);
  el.style.borderColor='var(--red)'; el.style.animation='none';
  el.offsetHeight; el.style.animation='SHK .3s ease';
  setTimeout(() => { el.style.borderColor=''; el.style.animation=''; }, 600);
}
function toast(msg, type='s') {
  const ic = {s:'bx-check-circle',w:'bx-error',d:'bx-error-circle'};
  const el = document.createElement('div');
  el.className=`toast t${type}`;
  el.innerHTML=`<i class="bx ${ic[type]}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(() => { el.classList.add('out'); setTimeout(()=>el.remove(),320); }, 3500);
}

/* ── INIT ────────────────────────────────────────────────── */
loadPrs();
</script>
</body>
</html>