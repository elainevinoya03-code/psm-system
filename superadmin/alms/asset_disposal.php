<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE RESOLUTION (mirrors sidebar pattern) ─────────────────────────────────
function ad_resolve_role(): string {
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
$roleName = ad_resolve_role();
$roleRank = match($roleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,   // Staff / User
};
$currentUserId   = $_SESSION['user_id']   ?? null;
$currentFullName = $_SESSION['full_name'] ?? ($currentUserId ?? 'User');
$currentZone     = $_SESSION['zone']      ?? '';

// ── HELPERS ──────────────────────────────────────────────────────────────────
function ad_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function ad_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function ad_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function ad_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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

function ad_next_id(): string {
    $year = date('Y');
    $rows = ad_sb('alms_disposals', 'GET', [
        'select'      => 'disposal_id',
        'disposal_id' => 'like.DSP-' . $year . '-%',
        'order'       => 'id.desc',
        'limit'       => '1',
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/DSP-\d{4}-(\d+)/', $rows[0]['disposal_id'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return 'DSP-' . $year . '-' . sprintf('%04d', $next);
}

function ad_next_ra_ref(): string {
    $year = date('Y');
    $rows = ad_sb('alms_disposals', 'GET', [
        'select'  => 'ra_ref',
        'ra_ref'  => 'like.BAC-' . $year . '-%',
        'order'   => 'id.desc',
        'limit'   => '1',
    ]);
    $next = 1;
    if (!empty($rows) && preg_match('/BAC-\d{4}-(\d+)/', $rows[0]['ra_ref'] ?? '', $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return 'BAC-' . $year . '-' . sprintf('%04d', $next);
}

function ad_build(array $row): array {
    return [
        'id'            => (int)$row['id'],
        'disposalId'    => $row['disposal_id']    ?? '',
        'assetId'       => $row['asset_id']        ?? '',
        'assetName'     => $row['asset_name']      ?? '',
        'assetDbId'     => (int)($row['asset_db_id'] ?? 0),
        'zone'          => $row['zone']            ?? '',
        'reason'        => $row['reason']          ?? '',
        'method'        => $row['method']          ?? '',
        'disposalDate'  => $row['disposal_date']   ?? '',
        'approvedBy'    => $row['approved_by']     ?? '',
        'disposalValue' => (float)($row['disposal_value'] ?? 0),
        'bookValue'     => (float)($row['book_value']     ?? 0),
        'status'        => $row['status']          ?? 'Pending Approval',
        'raRef'         => $row['ra_ref']          ?? '',
        'remarks'       => $row['remarks']         ?? '',
        'saRemarks'     => $row['sa_remarks']      ?? '',
        'isSa'          => (bool)($row['is_sa']    ?? false),
        'createdBy'     => $row['created_by']      ?? '',
        'createdUserId' => $row['created_user_id'] ?? '',
        'createdAt'     => $row['created_at']      ?? '',
        'updatedAt'     => $row['updated_at']      ?? '',
    ];
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    global $roleRank, $currentUserId, $currentZone, $currentFullName, $roleName;
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $currentFullName;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET assets (Admin+ only for dropdown) ─────────────────────────────
        if ($api === 'assets' && $method === 'GET') {
            if ($roleRank < 3) ad_err('Forbidden', 403);
            $query = [
                'select' => 'id,asset_id,name,zone,current_value',
                'status' => 'neq.Disposed',
                'order'  => 'name.asc',
            ];
            // Admin: own zone only
            if ($roleRank === 3 && $currentZone) $query['zone'] = 'eq.' . $currentZone;
            $rows = ad_sb('alms_assets', 'GET', $query);
            ad_ok(array_map(fn($r) => [
                'id'           => (int)$r['id'],
                'assetId'      => $r['asset_id']     ?? '',
                'name'         => $r['name']          ?? '',
                'zone'         => $r['zone']          ?? '',
                'currentValue' => (float)($r['current_value'] ?? 0),
            ], $rows));
        }

        // ── GET next RA ref ───────────────────────────────────────────────────
        if ($api === 'next-ra-ref' && $method === 'GET') {
            if ($roleRank < 3) ad_err('Forbidden', 403);
            ad_ok(['raRef' => ad_next_ra_ref()]);
        }

        // ── GET disposal list ─────────────────────────────────────────────────
        if ($api === 'list' && $method === 'GET') {
            $query = [
                'select' => 'id,disposal_id,asset_id,asset_name,asset_db_id,zone,reason,method,disposal_date,approved_by,disposal_value,book_value,status,ra_ref,remarks,sa_remarks,is_sa,created_by,created_user_id,created_at,updated_at',
                'order'  => 'disposal_date.desc',
            ];

            // Role-based scoping:
            // Super Admin (4) → all zones, all records
            // Admin (3)       → own zone, cannot see Approved/Completed other zones
            // Manager (2)     → own zone only
            // Staff (1)       → only where created_user_id = me (assigned tasks)
            if ($roleRank === 3 && $currentZone) $query['zone']            = 'eq.' . $currentZone;
            if ($roleRank === 2 && $currentZone) $query['zone']            = 'eq.' . $currentZone;
            if ($roleRank === 1 && $currentUserId) $query['created_user_id'] = 'eq.' . $currentUserId;

            if (!empty($_GET['status']))    $query['status']       = 'eq.' . $_GET['status'];
            if (!empty($_GET['zone']) && $roleRank === 4) $query['zone'] = 'eq.' . $_GET['zone'];
            if (!empty($_GET['method']))    $query['method']       = 'eq.' . $_GET['method'];
            if (!empty($_GET['date_from'])) $query['disposal_date']= 'gte.' . $_GET['date_from'];
            if (!empty($_GET['date_to']))   $query['disposal_date']= 'lte.' . $_GET['date_to'];

            $rows = ad_sb('alms_disposals', 'GET', $query);
            ad_ok(array_map('ad_build', $rows));
        }

        // ── GET single ────────────────────────────────────────────────────────
        if ($api === 'get' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) ad_err('Missing id', 400);
            $rows = ad_sb('alms_disposals', 'GET', [
                'select' => 'id,disposal_id,asset_id,asset_name,asset_db_id,zone,reason,method,disposal_date,approved_by,disposal_value,book_value,status,ra_ref,remarks,sa_remarks,is_sa,created_by,created_user_id,created_at,updated_at',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) ad_err('Disposal not found', 404);
            $rec = ad_build($rows[0]);
            // Zone enforcement
            if (in_array($roleRank, [2, 3]) && $currentZone && $rec['zone'] !== $currentZone)
                ad_err('Forbidden', 403);
            // Staff: only own records
            if ($roleRank === 1 && $rec['createdUserId'] !== $currentUserId)
                ad_err('Forbidden', 403);
            ad_ok($rec);
        }

        // ── GET audit ─────────────────────────────────────────────────────────
        if ($api === 'audit' && $method === 'GET') {
            if ($roleRank < 4) ad_err('Forbidden', 403); // Super Admin only
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) ad_err('Missing id', 400);
            $rows = ad_sb('alms_disposal_audit_log', 'GET', [
                'select'      => 'id,action_label,actor_name,actor_role,note,icon,css_class,is_super_admin,ip_address,occurred_at',
                'disposal_id' => 'eq.' . $id,
                'order'       => 'occurred_at.asc',
            ]);
            ad_ok($rows);
        }

        // ── GET RA compliance ─────────────────────────────────────────────────
        if ($api === 'ra' && $method === 'GET') {
            if ($roleRank < 4) ad_err('Forbidden', 403); // Super Admin only
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) ad_err('Missing id', 400);
            $rows = ad_sb('alms_disposal_ra_compliance', 'GET', [
                'select'      => 'id,req_code,req_desc,req_key,status,notes,updated_by,updated_at',
                'disposal_id' => 'eq.' . $id,
                'order'       => 'id.asc',
            ]);
            ad_ok($rows);
        }

        // ── POST save (Admin only — initiate / edit) ──────────────────────────
        if ($api === 'save' && $method === 'POST') {
            // Only Admin (3) can initiate; Super Admin (4) can also; Manager/Staff cannot
            if ($roleRank < 3) ad_err('Insufficient permissions to initiate disposal.', 403);

            $b            = ad_body();
            $assetId      = trim($b['assetId']      ?? '');
            $assetName    = trim($b['assetName']     ?? '');
            $assetDbId    = (int)($b['assetDbId']    ?? 0);
            $zone         = trim($b['zone']          ?? '');
            $reason       = trim($b['reason']        ?? '');
            $method2      = trim($b['method']        ?? '');
            $disposalDate = trim($b['disposalDate']  ?? '') ?: null;
            $approvedBy   = trim($b['approvedBy']    ?? '');
            $disposalValue= (float)($b['disposalValue'] ?? 0);
            $bookValue    = (float)($b['bookValue']     ?? 0);
            $raRef        = trim($b['raRef']         ?? '');
            $remarks      = trim($b['remarks']       ?? '');
            $editId       = (int)($b['id']           ?? 0);

            // Admin: enforce status is only Pending Approval (cannot set to Approved)
            $status = trim($b['status'] ?? 'Pending Approval');
            if ($roleRank === 3) $status = 'Pending Approval'; // Admin can only submit for approval

            // Admin: enforce own zone
            if ($roleRank === 3 && $currentZone && $zone !== $currentZone)
                ad_err('You can only initiate disposals within your assigned zone.', 403);

            if (!$editId && $raRef === '') $raRef = ad_next_ra_ref();
            if (!$assetName)    ad_err('Asset name is required.', 400);
            if (!$zone)         ad_err('Zone is required.', 400);
            if (!$reason)       ad_err('Reason for disposal is required.', 400);
            if (!$method2)      ad_err('Disposal method is required.', 400);
            if (!$disposalDate) ad_err('Disposal date is required.', 400);

            $allowedMethod = ['Sold','Scrapped','Donated','Auctioned','Transferred'];
            if (!in_array($method2, $allowedMethod, true)) ad_err('Invalid disposal method.', 400);

            $now = date('Y-m-d H:i:s');
            $payload = [
                'asset_id'       => $assetId,
                'asset_name'     => $assetName,
                'asset_db_id'    => $assetDbId ?: null,
                'zone'           => $zone,
                'reason'         => $reason,
                'method'         => $method2,
                'disposal_date'  => $disposalDate,
                'approved_by'    => $approvedBy,
                'disposal_value' => $disposalValue,
                'book_value'     => $bookValue,
                'status'         => $status,
                'ra_ref'         => $raRef,
                'remarks'        => $remarks,
                'updated_at'     => $now,
            ];

            if ($editId) {
                $existing = ad_sb('alms_disposals', 'GET', [
                    'select' => 'id,disposal_id,status,zone',
                    'id'     => 'eq.' . $editId,
                    'limit'  => '1',
                ]);
                if (empty($existing)) ad_err('Disposal not found', 404);
                // Admin: cannot edit records they don't own zone-wise
                if ($roleRank === 3 && $currentZone && ($existing[0]['zone'] ?? '') !== $currentZone)
                    ad_err('Cannot edit disposals outside your zone.', 403);
                // Admin: can only edit Pending Approval records
                if ($roleRank === 3 && !in_array($existing[0]['status'] ?? '', ['Pending Approval', 'Rejected'], true))
                    ad_err('Admins can only edit Pending or Rejected disposal records.', 403);

                ad_sb('alms_disposals', 'PATCH', ['id' => 'eq.' . $editId], $payload);
                ad_sb('alms_disposal_audit_log', 'POST', [], [[
                    'disposal_id'   => $editId,
                    'action_label'  => 'Record Edited',
                    'actor_name'    => $actor,
                    'actor_role'    => $roleName,
                    'note'          => 'Fields updated by ' . $actor . ' (' . $roleName . ').',
                    'icon'          => 'bx-edit',
                    'css_class'     => 'ad-s',
                    'is_super_admin'=> $roleRank === 4,
                    'ip_address'    => $ip,
                    'occurred_at'   => $now,
                ]]);
                $rows = ad_sb('alms_disposals', 'GET', [
                    'select' => 'id,disposal_id,asset_id,asset_name,asset_db_id,zone,reason,method,disposal_date,approved_by,disposal_value,book_value,status,ra_ref,remarks,sa_remarks,is_sa,created_by,created_user_id,created_at,updated_at',
                    'id'     => 'eq.' . $editId, 'limit' => '1',
                ]);
                ad_ok(ad_build($rows[0]));
            }

            // Create new
            $disposalId = ad_next_id();
            $payload['disposal_id']     = $disposalId;
            $payload['sa_remarks']      = '';
            $payload['is_sa']           = false;
            $payload['created_by']      = $actor;
            $payload['created_user_id'] = $currentUserId;
            $payload['created_at']      = $now;

            $inserted = ad_sb('alms_disposals', 'POST', [], [$payload]);
            if (empty($inserted)) ad_err('Failed to create disposal record', 500);
            $newId = (int)$inserted[0]['id'];

            ad_sb('alms_disposal_audit_log', 'POST', [], [[
                'disposal_id'   => $newId,
                'action_label'  => 'Disposal Initiated',
                'actor_name'    => $actor,
                'actor_role'    => $roleName,
                'note'          => 'Disposal record created for ' . $assetName . '.',
                'icon'          => 'bx-trash',
                'css_class'     => 'ad-s',
                'is_super_admin'=> $roleRank === 4,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            // Seed RA 9184 compliance rows
            $raReqs = [
                ['req_code'=>'Sec. 79','req_desc'=>'Certificate of Unserviceability / Inspection Report','req_key'=>'certUnservice'],
                ['req_code'=>'Sec. 80','req_desc'=>'Property Disposal Report (PDR) prepared',             'req_key'=>'pdr'],
                ['req_code'=>'Sec. 81','req_desc'=>'Appraisal Report from authorized appraiser',          'req_key'=>'appraisal'],
                ['req_code'=>'Sec. 82','req_desc'=>'BAC Resolution / Disposal Authority issued',          'req_key'=>'bacRes'],
                ['req_code'=>'Sec. 83','req_desc'=>'Notice of Public Auction / Bidding published',        'req_key'=>'notice'],
                ['req_code'=>'Sec. 84','req_desc'=>'Proceeds remitted to government account',             'req_key'=>'remittance'],
            ];
            $raRows = array_map(fn($r) => [
                'disposal_id' => $newId, 'req_code' => $r['req_code'], 'req_desc' => $r['req_desc'],
                'req_key' => $r['req_key'], 'status' => 'Pending', 'notes' => '', 'updated_by' => $actor, 'updated_at' => $now,
            ], $raReqs);
            try { ad_sb('alms_disposal_ra_compliance', 'POST', [], $raRows); } catch(Throwable $e) {}

            $rows = ad_sb('alms_disposals', 'GET', [
                'select' => 'id,disposal_id,asset_id,asset_name,asset_db_id,zone,reason,method,disposal_date,approved_by,disposal_value,book_value,status,ra_ref,remarks,sa_remarks,is_sa,created_by,created_user_id,created_at,updated_at',
                'id'     => 'eq.' . $newId, 'limit' => '1',
            ]);
            ad_ok(ad_build($rows[0]));
        }

        // ── POST escalate (Manager only) ──────────────────────────────────────
        if ($api === 'escalate' && $method === 'POST') {
            if ($roleRank !== 2) ad_err('Forbidden', 403);
            $b   = ad_body();
            $id  = (int)($b['id'] ?? 0);
            $now = date('Y-m-d H:i:s');
            if (!$id) ad_err('Missing id', 400);
            $rows = ad_sb('alms_disposals', 'GET', [
                'select' => 'id,disposal_id,asset_name,status,zone',
                'id'     => 'eq.' . $id, 'limit' => '1',
            ]);
            if (empty($rows)) ad_err('Disposal not found', 404);
            if ($currentZone && $rows[0]['zone'] !== $currentZone) ad_err('Forbidden', 403);
            ad_sb('alms_disposal_audit_log', 'POST', [], [[
                'disposal_id'   => $id,
                'action_label'  => 'Urgent Escalation by Manager',
                'actor_name'    => $actor,
                'actor_role'    => 'Manager',
                'note'          => trim($b['remarks'] ?? '') ?: 'Urgent case flagged for Super Admin review.',
                'icon'          => 'bx-error',
                'css_class'     => 'ad-o',
                'is_super_admin'=> false,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);
            ad_ok(['message' => 'Escalated successfully.']);
        }

        // ── POST log-step (Staff: log disposal steps / submit complete) ────────
        if ($api === 'log-step' && $method === 'POST') {
            if ($roleRank !== 1) ad_err('Forbidden', 403);
            $b   = ad_body();
            $id  = (int)($b['id'] ?? 0);
            $step= trim($b['step'] ?? '');
            $now = date('Y-m-d H:i:s');
            if (!$id)   ad_err('Missing id', 400);
            if (!$step) ad_err('Step description is required.', 400);
            // Verify this is their own record
            $rows = ad_sb('alms_disposals', 'GET', [
                'select' => 'id,disposal_id,status,created_user_id',
                'id'     => 'eq.' . $id, 'limit' => '1',
            ]);
            if (empty($rows)) ad_err('Disposal not found', 404);
            if (($rows[0]['created_user_id'] ?? '') !== $currentUserId) ad_err('Forbidden', 403);
            $isSubmit = (bool)($b['submit'] ?? false);
            ad_sb('alms_disposal_audit_log', 'POST', [], [[
                'disposal_id'   => $id,
                'action_label'  => $isSubmit ? 'Disposal Steps Submitted for Review' : 'Disposal Step Logged',
                'actor_name'    => $actor,
                'actor_role'    => 'Staff',
                'note'          => $step,
                'icon'          => $isSubmit ? 'bx-check-circle' : 'bx-list-check',
                'css_class'     => $isSubmit ? 'ad-a' : 'ad-s',
                'is_super_admin'=> false,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);
            ad_ok(['message' => $isSubmit ? 'Submitted for review.' : 'Step logged.']);
        }

        // ── POST action (Super Admin / Admin depending on type) ───────────────
        if ($api === 'action' && $method === 'POST') {
            $b    = ad_body();
            $id   = (int)($b['id']   ?? 0);
            $type = trim($b['type']  ?? '');
            $now  = date('Y-m-d H:i:s');
            if (!$id)   ad_err('Missing id', 400);
            if (!$type) ad_err('Missing type', 400);

            $rows = ad_sb('alms_disposals', 'GET', [
                'select' => 'id,disposal_id,asset_name,status,method,disposal_value,asset_db_id,zone',
                'id'     => 'eq.' . $id, 'limit' => '1',
            ]);
            if (empty($rows)) ad_err('Disposal not found', 404);
            $d = $rows[0];

            // Zone enforcement for Admin/Manager
            if (in_array($roleRank, [2, 3]) && $currentZone && $d['zone'] !== $currentZone)
                ad_err('Cannot act on records outside your zone.', 403);

            $patch      = ['updated_at' => $now];
            $auditLabel = '';
            $auditNote  = trim($b['remarks'] ?? '');
            $auditIcon  = 'bx-info-circle';
            $auditClass = 'ad-s';
            $isSA       = $roleRank === 4;

            switch ($type) {

                case 'approve':
                    if ($roleRank < 4) ad_err('Only Super Admin can approve disposals.', 403);
                    if ($d['status'] !== 'Pending Approval')
                        ad_err('Only Pending Approval records can be approved.', 400);
                    $patch['status']      = 'Approved';
                    $patch['approved_by'] = $actor;
                    $patch['is_sa']       = true;
                    $auditLabel = 'Approved by Super Admin';
                    $auditIcon  = 'bx-check-circle';
                    $auditClass = 'ad-a';
                    $auditNote  = $auditNote ?: 'All documentation reviewed and approved.';
                    break;

                case 'reject':
                    if ($roleRank < 4) ad_err('Only Super Admin can reject disposals.', 403);
                    if ($d['status'] !== 'Pending Approval')
                        ad_err('Only Pending Approval records can be rejected.', 400);
                    $patch['status']     = 'Rejected';
                    $patch['sa_remarks'] = trim($b['remarks'] ?? '');
                    $auditLabel = 'Rejected by Super Admin';
                    $auditIcon  = 'bx-x-circle';
                    $auditClass = 'ad-r';
                    $auditNote  = $auditNote ?: 'Does not meet disposal criteria at this time.';
                    break;

                case 'complete':
                    if ($roleRank < 4) ad_err('Only Super Admin can complete disposals.', 403);
                    if ($d['status'] !== 'Approved')
                        ad_err('Only Approved records can be completed.', 400);
                    $patch['status'] = 'Completed';
                    if (!empty($d['asset_db_id'])) {
                        try { ad_sb('alms_assets', 'PATCH', ['id' => 'eq.' . $d['asset_db_id']], ['status' => 'Disposed', 'updated_at' => $now]); } catch(Throwable $e) {}
                    }
                    $auditLabel = 'Disposal Completed';
                    $auditIcon  = 'bx-check-double';
                    $auditClass = 'ad-d';
                    $auditNote  = $auditNote ?: 'Asset physically disposed. PDR filed with COA.';
                    break;

                case 'cancel':
                    // Admin: can cancel Pending Approval records only; SA: any non-final
                    if ($roleRank === 3) {
                        if ($d['status'] !== 'Pending Approval')
                            ad_err('Admins can only cancel Pending Approval records.', 400);
                    } elseif ($roleRank < 3) {
                        ad_err('Insufficient permissions.', 403);
                    }
                    if (in_array($d['status'], ['Completed', 'Cancelled'], true))
                        ad_err('Record is already ' . strtolower($d['status']) . '.', 400);
                    $patch['status']  = 'Cancelled';
                    $patch['remarks'] = trim($b['remarks'] ?? '') ?: 'Cancelled.';
                    $auditLabel = 'Disposal Cancelled';
                    $auditIcon  = 'bx-minus-circle';
                    $auditClass = 'ad-x';
                    $auditNote  = $auditNote ?: 'Cancelled by ' . $roleName . '.';
                    break;

                case 'saoverride':
                    if ($roleRank < 4) ad_err('Super Admin authority required.', 403);
                    $newStatus = trim($b['newStatus'] ?? '');
                    $allowed   = ['Pending Approval','Approved','Completed','Cancelled','Rejected'];
                    if (!in_array($newStatus, $allowed, true)) ad_err('Invalid target status.', 400);
                    $patch['status']     = $newStatus;
                    $patch['sa_remarks'] = trim($b['remarks'] ?? '');
                    if ($newStatus === 'Approved') { $patch['approved_by'] = $actor; $patch['is_sa'] = true; }
                    $auditLabel = 'Status Override by Super Admin → ' . $newStatus;
                    $auditIcon  = 'bx-shield-quarter';
                    $auditClass = 'ad-o';
                    $auditNote  = $auditNote ?: 'Super Admin override applied.';
                    break;

                default:
                    ad_err('Unsupported action', 400);
            }

            ad_sb('alms_disposals', 'PATCH', ['id' => 'eq.' . $id], $patch);
            ad_sb('alms_disposal_audit_log', 'POST', [], [[
                'disposal_id'   => $id,
                'action_label'  => $auditLabel,
                'actor_name'    => $actor,
                'actor_role'    => $isSA ? 'Super Admin' : $roleName,
                'note'          => $auditNote,
                'icon'          => $auditIcon,
                'css_class'     => $auditClass,
                'is_super_admin'=> $isSA,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            $rows = ad_sb('alms_disposals', 'GET', [
                'select' => 'id,disposal_id,asset_id,asset_name,asset_db_id,zone,reason,method,disposal_date,approved_by,disposal_value,book_value,status,ra_ref,remarks,sa_remarks,is_sa,created_by,created_user_id,created_at,updated_at',
                'id'     => 'eq.' . $id, 'limit' => '1',
            ]);
            ad_ok(ad_build($rows[0]));
        }

        // ── POST update RA (Super Admin only) ─────────────────────────────────
        if ($api === 'ra-update' && $method === 'POST') {
            if ($roleRank < 4) ad_err('Forbidden', 403);
            $b          = ad_body();
            $raId       = (int)($b['raId']       ?? 0);
            $disposalId = (int)($b['disposalId'] ?? 0);
            $newStatus  = trim($b['status']       ?? '');
            $notes      = trim($b['notes']        ?? '');
            $now        = date('Y-m-d H:i:s');
            if (!$raId || !$disposalId) ad_err('Missing raId or disposalId', 400);
            $allowed = ['Pending','Met','N/A'];
            if (!in_array($newStatus, $allowed, true)) ad_err('Invalid RA status', 400);
            ad_sb('alms_disposal_ra_compliance', 'PATCH', ['id' => 'eq.' . $raId], [
                'status' => $newStatus, 'notes' => $notes, 'updated_by' => $actor, 'updated_at' => $now,
            ]);
            $reqRow = ad_sb('alms_disposal_ra_compliance', 'GET', ['select' => 'req_code', 'id' => 'eq.' . $raId, 'limit' => '1']);
            $code = $reqRow[0]['req_code'] ?? 'Req';
            ad_sb('alms_disposal_audit_log', 'POST', [], [[
                'disposal_id'   => $disposalId,
                'action_label'  => 'RA Compliance Updated — ' . $code . ' → ' . $newStatus,
                'actor_name'    => $actor, 'actor_role' => 'Super Admin',
                'note'          => $notes ?: 'Status set to ' . $newStatus . '.',
                'icon'          => 'bx-shield-check', 'css_class' => $newStatus==='Met'?'ad-a':'ad-s',
                'is_super_admin'=> true, 'ip_address' => $ip, 'occurred_at' => $now,
            ]]);
            $rows = ad_sb('alms_disposal_ra_compliance', 'GET', [
                'select' => 'id,req_code,req_desc,req_key,status,notes,updated_by,updated_at',
                'disposal_id' => 'eq.' . $disposalId, 'order' => 'id.asc',
            ]);
            ad_ok($rows);
        }

        // ── POST batch (Super Admin only) ─────────────────────────────────────
        if ($api === 'batch' && $method === 'POST') {
            if ($roleRank < 4) ad_err('Super Admin authority required for batch actions.', 403);
            $b    = ad_body();
            $ids  = array_map('intval', $b['ids'] ?? []);
            $type = trim($b['type'] ?? '');
            $now  = date('Y-m-d H:i:s');
            if (empty($ids)) ad_err('No disposal IDs provided.', 400);
            if (!$type)      ad_err('Missing batch type.', 400);
            $updated   = 0;
            $auditNote = trim($b['remarks'] ?? '');
            foreach ($ids as $id) {
                $rows = ad_sb('alms_disposals', 'GET', ['select' => 'id,disposal_id,status,asset_db_id', 'id' => 'eq.' . $id, 'limit' => '1']);
                if (empty($rows)) continue;
                $d = $rows[0];
                $patch = ['updated_at' => $now];
                $auditLabel = ''; $auditIcon = 'bx-check-double'; $auditClass = 'ad-a';
                if ($type === 'batch-approve') {
                    if ($d['status'] !== 'Pending Approval') continue;
                    $patch = array_merge($patch, ['status' => 'Approved', 'approved_by' => $actor, 'is_sa' => true]);
                    $auditLabel = 'Bulk Approved by Super Admin';
                } elseif ($type === 'batch-complete') {
                    if ($d['status'] !== 'Approved') continue;
                    $patch['status'] = 'Completed'; $auditLabel = 'Bulk Completed by Super Admin'; $auditClass = 'ad-d';
                    if (!empty($d['asset_db_id'])) { try { ad_sb('alms_assets', 'PATCH', ['id' => 'eq.' . $d['asset_db_id']], ['status' => 'Disposed', 'updated_at' => $now]); } catch(Throwable $e) {} }
                } elseif ($type === 'batch-reject') {
                    if ($d['status'] !== 'Pending Approval') continue;
                    $patch['status'] = 'Rejected'; $auditLabel = 'Bulk Rejected by Super Admin'; $auditIcon = 'bx-x-circle'; $auditClass = 'ad-r';
                } else { continue; }
                ad_sb('alms_disposals', 'PATCH', ['id' => 'eq.' . $id], $patch);
                ad_sb('alms_disposal_audit_log', 'POST', [], [[
                    'disposal_id' => $id, 'action_label' => $auditLabel, 'actor_name' => $actor, 'actor_role' => 'Super Admin',
                    'note' => $auditNote, 'icon' => $auditIcon, 'css_class' => $auditClass,
                    'is_super_admin' => true, 'ip_address' => $ip, 'occurred_at' => $now,
                ]]);
                $updated++;
            }
            ad_ok(['updated' => $updated]);
        }

        ad_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        ad_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── HTML PAGE RENDER ──────────────────────────────────────────────────────────
$root_html = $_SERVER['DOCUMENT_ROOT'];
include $root_html . '/includes/superadmin_sidebar.php';
include $root_html . '/includes/header.php';

$jsRoleRank = (int)$roleRank;
$jsRoleName = htmlspecialchars($roleName, ENT_QUOTES);
$jsZone     = htmlspecialchars($currentZone, ENT_QUOTES);
$jsUserId   = htmlspecialchars($currentUserId ?? '', ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Asset Disposal — ALMS</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
#mainContent,#adSlider,#slOverlay,#actionModal,#viewModal,.ad-toasts{--s:#fff;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);--t1:var(--text-primary);--t2:var(--text-secondary);--t3:#9EB0A2;--hbg:var(--hover-bg-light);--bg:var(--bg-color);--grn:var(--primary-color);--gdk:var(--primary-dark);--red:#DC2626;--amb:#D97706;--blu:#2563EB;--tel:#0D9488;--pur:#7C3AED;--shmd:0 4px 20px rgba(46,125,50,.12);--shlg:0 24px 60px rgba(0,0,0,.22);--rad:12px;--tr:var(--transition);}
#mainContent *,#adSlider *,#slOverlay *,#actionModal *,#viewModal *,.ad-toasts *{box-sizing:border-box;}
.ad-wrap{max-width:1440px;margin:0 auto;padding:0 0 4rem;}
/* PAGE HEADER */
.ad-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:26px;animation:UP .4s both;}
.ad-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px;}
.ad-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15;}
.ad-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
/* ROLE BADGE */
.role-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:8px;white-space:nowrap;}
.rb-sa{background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;}
.rb-admin{background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;}
.rb-mgr{background:#F0FDF4;color:#166534;border:1px solid #BBF7D0;}
.rb-staff{background:#F3F4F6;color:#374151;border:1px solid #D1D5DB;}
/* ZONE CONTEXT */
.zone-ctx{display:flex;align-items:center;gap:8px;background:linear-gradient(90deg,#F0FDF4,#DCFCE7);border:1px solid rgba(46,125,50,.22);border-radius:10px;padding:9px 16px;font-size:12.5px;color:#166534;font-weight:600;margin-bottom:18px;animation:UP .4s .03s both;}
.zone-ctx i{font-size:15px;}
/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.32);}.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}.btn-ghost:hover{background:var(--hbg);color:var(--t1);}
.btn-approve{background:#DCFCE7;color:#166534;border:1px solid #BBF7D0;}.btn-approve:hover{background:#BBF7D0;}
.btn-reject{background:#FEE2E2;color:var(--red);border:1px solid #FECACA;}.btn-reject:hover{background:#FCA5A5;}
.btn-complete{background:#CCFBF1;color:#115E59;border:1px solid #99F6E4;}.btn-complete:hover{background:#99F6E4;}
.btn-cancel-ad{background:#F3F4F6;color:#374151;border:1px solid #D1D5DB;}.btn-cancel-ad:hover{background:#E5E7EB;}
.btn-override{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;}.btn-override:hover{background:#FDE68A;}
.btn-escalate{background:#FFF7ED;color:#C2410C;border:1px solid #FDBA74;}.btn-escalate:hover{background:#FED7AA;}
.btn-logstep{background:#F5F3FF;color:#6D28D9;border:1px solid #DDD6FE;}.btn-logstep:hover{background:#EDE9FE;}
.btn-sm{font-size:12px;padding:6px 13px;}.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px;}
.btn.ionly{width:28px;height:28px;padding:0;justify-content:center;font-size:14px;flex-shrink:0;border-radius:6px;border:1px solid var(--bdm);background:var(--s);color:var(--t2);}
.btn.ionly:hover{background:var(--hbg);color:var(--grn);border-color:var(--grn);}
.btn.ionly.btn-approve:hover{background:#DCFCE7;color:#166534;border-color:#BBF7D0;}
.btn.ionly.btn-reject:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA;}
.btn.ionly.btn-complete:hover{background:#CCFBF1;color:#115E59;border-color:#99F6E4;}
.btn.ionly.btn-cancel-ad:hover{background:#F3F4F6;color:#374151;border-color:#D1D5DB;}
.btn.ionly.btn-override:hover{background:#FEF3C7;color:#92400E;border-color:#FCD34D;}
.btn.ionly.btn-escalate:hover{background:#FFF7ED;color:#C2410C;border-color:#FDBA74;}
.btn.ionly.btn-logstep:hover{background:#F5F3FF;color:#6D28D9;border-color:#DDD6FE;}
.btn:disabled{opacity:.4;pointer-events:none;}
/* STATS */
.ad-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px;margin-bottom:22px;animation:UP .4s .05s both;}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:14px 16px;box-shadow:0 1px 4px rgba(46,125,50,.07);display:flex;align-items:center;gap:12px;}
.sc-ic{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px;}
.ic-b{background:#EFF6FF;color:var(--blu)}.ic-a{background:#FEF3C7;color:var(--amb)}.ic-g{background:#DCFCE7;color:#166534}.ic-r{background:#FEE2E2;color:var(--red)}.ic-t{background:#CCFBF1;color:var(--tel)}.ic-p{background:#F5F3FF;color:#6D28D9}
.sc-v{font-size:22px;font-weight:800;color:var(--t1);line-height:1;}.sc-l{font-size:11px;color:var(--t2);margin-top:2px;}
/* COMPLIANCE PANEL (SA only) */
.compliance-wrap{background:var(--s);border:1px solid var(--bd);border-radius:16px;padding:20px 24px;margin-bottom:18px;box-shadow:var(--shmd);animation:UP .4s .08s both;}
.compliance-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;}
.compliance-hdr h3{font-size:14px;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px;}
.compliance-hdr h3 i{color:var(--grn);font-size:16px;}
.sa-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;border-radius:6px;padding:3px 9px;}
.compliance-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.comp-item{background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;}
.comp-item .ci-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3);margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.comp-ra{display:flex;flex-direction:column;gap:7px;}
.comp-ra-row{display:flex;align-items:center;justify-content:space-between;font-size:12.5px;}
.comp-ra-row .cr-label{color:var(--t2);font-weight:500;}.comp-ra-row .cr-val{font-family:'DM Mono',monospace;font-weight:700;color:var(--t1);}
.comp-ra-row .cr-tag{font-size:10px;font-weight:700;padding:2px 7px;border-radius:6px;}
.cr-ok{background:#DCFCE7;color:#166534}.cr-warn{background:#FEF3C7;color:#92400E}.cr-bad{background:#FEE2E2;color:#991B1B}
.method-bars{display:flex;flex-direction:column;gap:6px;}
.method-bar-row{display:flex;align-items:center;gap:8px;font-size:11.5px;}
.method-bar-label{min-width:70px;color:var(--t2);font-weight:500;}
.method-bar-track{flex:1;height:6px;background:var(--bd);border-radius:3px;overflow:hidden;}
.method-bar-fill{height:100%;border-radius:3px;transition:width .5s ease;}
.method-bar-val{min-width:36px;text-align:right;font-family:'DM Mono',monospace;font-size:11px;font-weight:600;color:var(--t1);}
.value-recovery{display:flex;flex-direction:column;gap:6px;}
.vr-row{display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px dashed rgba(46,125,50,.12);font-size:12px;}
.vr-row:last-child{border-bottom:none;}
.vr-label{color:var(--t2);font-weight:500;display:flex;align-items:center;gap:5px;}
.vr-label i{font-size:13px;color:var(--grn);}
.vr-val{font-family:'DM Mono',monospace;font-weight:700;color:var(--grn);}
/* TOOLBAR */
.ad-tb{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:18px;animation:UP .4s .1s both;}
.sw{position:relative;flex:1;min-width:220px;}.sw i{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--t3);pointer-events:none;}
.si{width:100%;padding:9px 11px 9px 36px;font-family:'Inter',sans-serif;font-size:13px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);}
.si:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}.si::placeholder{color:var(--t3);}
.sel{font-family:'Inter',sans-serif;font-size:13px;padding:9px 28px 9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;}
.sel:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}
.fi-date{font-family:'Inter',sans-serif;font-size:13px;padding:9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);}
.fi-date:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}
.date-range-wrap{display:flex;align-items:center;gap:6px;}.date-range-wrap span{font-size:12px;color:var(--t3);font-weight:500;}
/* BULK BAR (SA only) */
.bulk-bar{display:none;align-items:center;gap:10px;padding:10px 16px;background:linear-gradient(135deg,#F0FDF4,#DCFCE7);border:1px solid rgba(46,125,50,.22);border-radius:12px;margin-bottom:14px;flex-wrap:wrap;}
.bulk-bar.on{display:flex;}.bulk-count{font-size:13px;font-weight:700;color:#166534;}.bulk-sep{width:1px;height:22px;background:rgba(46,125,50,.25);}
.sa-exclusive{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:linear-gradient(135deg,#FEF3C7,#FDE68A);color:#92400E;border:1px solid #FCD34D;border-radius:6px;padding:2px 7px;}
/* TABLE */
.ad-card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shmd);animation:UP .4s .13s both;}
.tbl-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;width:100%;}
.ad-tbl{width:100%;border-collapse:collapse;font-size:12.5px;table-layout:auto;}
.ad-tbl thead th{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:11px 12px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;cursor:pointer;user-select:none;}
.ad-tbl thead th.no-sort{cursor:default;}.ad-tbl thead th:hover:not(.no-sort){color:var(--grn);}.ad-tbl thead th.sorted{color:var(--grn);}
.ad-tbl thead th .sic{margin-left:3px;opacity:.4;font-size:12px;vertical-align:middle;}.ad-tbl thead th.sorted .sic{opacity:1;}
.ad-tbl thead th:first-child,.ad-tbl tbody td:first-child{padding-left:14px;padding-right:4px;}
.ad-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .13s;}.ad-tbl tbody tr:last-child{border-bottom:none;}.ad-tbl tbody tr:hover{background:var(--hbg);}.ad-tbl tbody tr.row-selected{background:#F0FDF4;}
.ad-tbl tbody td{padding:12px 12px;vertical-align:middle;cursor:pointer;overflow:hidden;text-overflow:ellipsis;}
.ad-tbl tbody td:first-child{cursor:default;}.ad-tbl tbody td:last-child{white-space:nowrap;cursor:default;overflow:visible;padding:8px;}
.ad-num{font-family:'DM Mono',monospace;font-size:11.5px;font-weight:600;color:var(--t1);white-space:nowrap;}
.ad-date{font-size:12px;color:var(--t2);white-space:nowrap;}
.ad-val{font-family:'DM Mono',monospace;font-size:12.5px;font-weight:700;color:var(--t1);white-space:nowrap;}
.asset-cell{display:flex;flex-direction:column;gap:3px;min-width:0;}
.asset-name{font-weight:600;color:var(--t1);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.asset-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.asset-id{font-family:'DM Mono',monospace;font-size:10.5px;color:var(--t3);}
.asset-zone{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:600;color:var(--t2);}
.asset-approver{font-size:10.5px;color:var(--t3);}
.reason-cell{font-size:12.5px;color:var(--t2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:220px;}
.method-pill{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
.mp-sold{background:#EFF6FF;color:#1D4ED8}.mp-scrapped{background:#F3F4F6;color:#374151}.mp-donated{background:#F5F3FF;color:#6D28D9}.mp-auctioned{background:#FEF3C7;color:#92400E}.mp-transferred{background:#CCFBF1;color:#115E59}
.act-cell{display:flex;gap:4px;align-items:center;}
.cb-wrap{display:flex;align-items:center;justify-content:center;}
input[type=checkbox].cb{width:15px;height:15px;accent-color:var(--grn);cursor:pointer;}
/* BADGES */
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;}
.b-pending{background:#FEF3C7;color:#92400E}.b-approved{background:#DCFCE7;color:#166534}.b-completed{background:#CCFBF1;color:#115E59}.b-cancelled{background:#F3F4F6;color:#374151}.b-rejected{background:#FEE2E2;color:#991B1B}
/* PAGINATION */
.ad-pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2);}
.pg-btns{display:flex;gap:5px;}.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-family:'Inter',sans-serif;font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1);}
.pgb:hover{background:var(--hbg);border-color:var(--grn);color:var(--grn);}.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff;}.pgb:disabled{opacity:.4;pointer-events:none;}
.empty{padding:72px 20px;text-align:center;color:var(--t3);}.empty i{font-size:54px;display:block;margin-bottom:14px;color:#C8E6C9;}
/* SLIDE-OVER */
#slOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;opacity:0;pointer-events:none;transition:opacity .25s;}
#slOverlay.on{opacity:1;pointer-events:all;}
#adSlider{position:fixed;top:0;right:-640px;bottom:0;width:600px;max-width:100vw;background:var(--s);z-index:9001;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18);}
#adSlider.on{right:0;}
.sl-hdr{display:flex;align-items:flex-start;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--bd);background:var(--bg);flex-shrink:0;}
.sl-title{font-size:17px;font-weight:700;color:var(--t1);}.sl-subtitle{font-size:12px;color:var(--t2);margin-top:2px;}
.sl-close{width:36px;height:36px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--t2);transition:var(--tr);flex-shrink:0;}
.sl-close:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA;}
.sl-body{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:16px;}
.sl-body::-webkit-scrollbar{width:4px;}.sl-body::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px;}
.sl-foot{padding:16px 24px;border-top:1px solid var(--bd);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;}
.fg{display:flex;flex-direction:column;gap:6px;}.fr{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fl{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2);}.fl span{color:var(--red);margin-left:2px;}
.fi,.fs,.fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);width:100%;}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;padding-right:30px;}
.fta{resize:vertical;min-height:72px;}
.fd{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px;}.fd::after{content:'';flex:1;height:1px;background:var(--bd);}
.ra-notice{display:flex;align-items:flex-start;gap:10px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:12px 14px;font-size:12px;color:#1D4ED8;line-height:1.6;}
.ra-notice i{font-size:16px;flex-shrink:0;margin-top:1px;}
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
.am-fg textarea,.am-fg select{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;width:100%;transition:var(--tr);}
.am-fg textarea{resize:vertical;min-height:68px;}
.am-fg textarea:focus,.am-fg select:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11);}
.am-fg select{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;}
.am-acts{display:flex;gap:10px;justify-content:flex-end;}
/* VIEW MODAL */
#viewModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9050;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;}
#viewModal.on{opacity:1;pointer-events:all;}
.vm-box{background:#fff;border-radius:20px;width:860px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden;}
.vm-mhd{padding:24px 28px 0;border-bottom:1px solid rgba(46,125,50,.14);background:var(--bg-color);flex-shrink:0;}
.vm-mtp{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px;}
.vm-msi{display:flex;align-items:center;gap:16px;}
.vm-mav{width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:19px;color:#fff;flex-shrink:0;}
.vm-mnm{font-size:20px;font-weight:800;color:var(--text-primary);display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.vm-mid{font-family:'DM Mono',monospace;font-size:12px;color:var(--text-secondary);margin-top:3px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.vm-mcl{width:36px;height:36px;border-radius:8px;border:1px solid rgba(46,125,50,.22);background:#fff;cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--text-secondary);transition:all .15s;flex-shrink:0;}
.vm-mcl:hover{background:#FEE2E2;color:#DC2626;border-color:#FECACA;}
.vm-mmt{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}
.vm-mc{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--text-secondary);background:#fff;border:1px solid rgba(46,125,50,.14);border-radius:8px;padding:5px 10px;line-height:1;}
.vm-mc i{font-size:14px;color:var(--primary-color);}
.vm-mtb{display:flex;gap:4px;}
.vm-tab{font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px 8px 0 0;cursor:pointer;transition:all .15s;color:var(--text-secondary);border:none;background:transparent;display:flex;align-items:center;gap:6px;white-space:nowrap;}
.vm-tab:hover{background:var(--hover-bg-light);color:var(--text-primary);}.vm-tab.active{background:var(--primary-color);color:#fff;}.vm-tab i{font-size:14px;}
.vm-mbd{flex:1;overflow-y:auto;padding:24px 28px;background:#fff;}
.vm-mbd::-webkit-scrollbar{width:4px;}.vm-mbd::-webkit-scrollbar-thumb{background:rgba(46,125,50,.22);border-radius:4px;}
.vm-tp{display:none;flex-direction:column;gap:18px;}.vm-tp.active{display:flex;}
.vm-sbs{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.vm-sb{background:var(--bg-color);border:1px solid rgba(46,125,50,.14);border-radius:10px;padding:14px 16px;}
.vm-sb .sbv{font-size:18px;font-weight:800;color:var(--text-primary);line-height:1;}.vm-sb .sbv.mono{font-family:'DM Mono',monospace;font-size:13px;color:var(--primary-color);}.vm-sb .sbl{font-size:11px;color:var(--text-secondary);margin-top:3px;}
.vm-ig{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.vm-ii label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9EB0A2;display:block;margin-bottom:4px;}
.vm-ii .v{font-size:13px;font-weight:500;color:var(--text-primary);line-height:1.5;}.vm-ii .v.muted{font-weight:400;color:#4B5563;}.vm-full{grid-column:1/-1;}
.vm-rmk{border-radius:10px;padding:12px 16px;font-size:12.5px;line-height:1.65;}
.vm-rmk .rml{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px;opacity:.7;}
.vm-rmk-a{background:#F0FDF4;color:#166534;}.vm-rmk-r{background:#FEF2F2;color:#991B1B;}.vm-rmk-n{background:#FFFBEB;color:#92400E;}
.vm-sa-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400E;}
.vm-sa-note i{font-size:15px;flex-shrink:0;margin-top:1px;}
/* RA table (SA only) */
.ra-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.ra-tbl thead th{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-secondary);padding:10px 14px;background:var(--bg-color);border-bottom:1px solid rgba(46,125,50,.14);text-align:left;}
.ra-tbl tbody tr{border-bottom:1px solid rgba(46,125,50,.08);}.ra-tbl tbody tr:last-child{border-bottom:none;}.ra-tbl tbody td{padding:11px 14px;vertical-align:middle;}
.ra-status-sel{font-family:'Inter',sans-serif;font-size:11.5px;font-weight:600;padding:4px 24px 4px 8px;border-radius:7px;border:1px solid var(--bdm);background:var(--s);outline:none;appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 6px center;transition:var(--tr);}
.ra-status-sel:focus{border-color:var(--grn);box-shadow:0 0 0 2px rgba(46,125,50,.11);}
.ra-status-sel.rs-met{background-color:#DCFCE7;color:#166534;border-color:#BBF7D0;}
.ra-status-sel.rs-pending{background-color:#FEF3C7;color:#92400E;border-color:#FCD34D;}
.ra-status-sel.rs-na{background-color:#F3F4F6;color:#6B7280;border-color:#D1D5DB;}
/* Audit trail */
.vm-audit-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid rgba(46,125,50,.10);}
.vm-audit-item:last-child{border-bottom:none;padding-bottom:0;}
.vm-audit-dot{width:28px;height:28px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;}
.ad-c{background:#DCFCE7;color:#166534}.ad-s{background:#EFF6FF;color:#2563EB}.ad-a{background:#DCFCE7;color:#166534}.ad-r{background:#FEE2E2;color:#DC2626}.ad-x{background:#F3F4F6;color:#374151}.ad-d{background:#CCFBF1;color:#115E59}.ad-o{background:#FEF3C7;color:#D97706}
.vm-audit-body{flex:1;min-width:0;}
.vm-audit-body .au{font-size:13px;font-weight:500;color:var(--text-primary);}
.vm-audit-body .at{font-size:11px;color:#9EB0A2;margin-top:3px;font-family:'DM Mono',monospace;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.vm-audit-note{font-size:11.5px;color:#6B7280;margin-top:3px;font-style:italic;}
.vm-audit-ip{font-family:'DM Mono',monospace;font-size:10px;color:#9CA3AF;background:#F3F4F6;border-radius:4px;padding:1px 6px;}
.vm-audit-ts{font-family:'DM Mono',monospace;font-size:10px;color:#9EB0A2;flex-shrink:0;margin-left:auto;padding-left:8px;white-space:nowrap;}
.sa-tag{font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 5px;border:1px solid #FCD34D;}
.vm-mft{padding:16px 28px;border-top:1px solid rgba(46,125,50,.14);background:var(--bg-color);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap;}
/* Staff log step panel */
.step-panel{background:var(--bg-color);border:1px solid var(--bd);border-radius:12px;padding:16px 18px;}
.step-panel h4{font-size:13px;font-weight:700;color:var(--t1);margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.step-panel h4 i{color:var(--grn);}
/* TOASTS */
.ad-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:TIN .3s ease;}
.toast.ts{background:var(--grn);}.toast.tw{background:var(--amb);}.toast.td{background:var(--red);}.toast.out{animation:TOUT .3s ease forwards;}
@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}
@keyframes SHK{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-5px)}40%,80%{transform:translateX(5px)}}
@media(max-width:900px){.ad-stats{grid-template-columns:repeat(2,1fr)}.fr{grid-template-columns:1fr}#adSlider{width:100vw}.vm-sbs{grid-template-columns:repeat(2,1fr)}.vm-ig{grid-template-columns:1fr}.compliance-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="ad-wrap">

  <!-- PAGE HEADER -->
  <div class="ad-ph">
    <div>
      <p class="ey">ALMS · Asset Lifecycle &amp; Maintenance</p>
      <h1>Asset Disposal</h1>
    </div>
    <div class="ad-ph-r">
      <span class="role-badge <?= match($roleName){'Super Admin'=>'rb-sa','Admin'=>'rb-admin','Manager'=>'rb-mgr',default=>'rb-staff'} ?>"
        ><i class="bx <?= match($roleName){'Super Admin'=>'bx-shield-quarter','Admin'=>'bx-user-check','Manager'=>'bx-briefcase',default=>'bx-user'} ?>"></i>
        <?= htmlspecialchars($roleName) ?>
      </span>
      <?php if ($roleRank >= 3): ?>
      <button class="btn btn-ghost" id="exportBtn"><i class="bx bx-export"></i> Export CSV</button>
      <?php endif; ?>
      <?php if ($roleRank === 3): // Admin only — initiate disposal ?>
      <button class="btn btn-primary" id="createBtn"><i class="bx bx-plus"></i> Initiate Disposal</button>
      <?php elseif ($roleRank === 4): // SA — also initiate ?>
      <button class="btn btn-primary" id="createBtn"><i class="bx bx-plus"></i> Initiate Disposal</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ZONE CONTEXT (non-SA) -->
  <?php if ($roleRank < 4 && $currentZone): ?>
  <div class="zone-ctx">
    <i class="bx bx-map-pin"></i>
    <?php if ($roleRank === 1): ?>
    Showing your assigned disposal tasks · <strong><?= htmlspecialchars($currentZone) ?></strong>
    <?php elseif ($roleRank === 2): ?>
    Zone view: <strong><?= htmlspecialchars($currentZone) ?></strong> — monitoring disposal records
    <?php else: ?>
    Managing disposals for zone: <strong><?= htmlspecialchars($currentZone) ?></strong>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="ad-stats" id="statsBar"></div>

  <!-- COMPLIANCE PANEL (Super Admin only) -->
  <?php if ($roleRank === 4): ?>
  <div class="compliance-wrap" id="compliancePanel">
    <div style="color:var(--t3);font-size:13px;text-align:center;padding:20px 0">Loading analytics…</div>
  </div>
  <?php endif; ?>

  <!-- TOOLBAR -->
  <div class="ad-tb">
    <div class="sw"><i class="bx bx-search"></i>
      <input type="text" class="si" id="srch" placeholder="<?= $roleRank===1?'Search by Disposal ID or Asset…':'Search by Disposal ID, Asset, Zone…' ?>">
    </div>
    <select class="sel" id="fStatus">
      <option value="">All Statuses</option>
      <?php if ($roleRank >= 2): ?>
      <option>Pending Approval</option><option>Approved</option>
      <option>Completed</option><option>Cancelled</option><option>Rejected</option>
      <?php else: ?>
      <option>Pending Approval</option><option>Completed</option>
      <?php endif; ?>
    </select>
    <?php if ($roleRank === 4): ?>
    <select class="sel" id="fMethod">
      <option value="">All Methods</option>
      <option>Sold</option><option>Scrapped</option><option>Donated</option><option>Auctioned</option><option>Transferred</option>
    </select>
    <select class="sel" id="fZone"><option value="">All Zones</option></select>
    <div class="date-range-wrap">
      <input type="date" class="fi-date" id="fDateFrom" title="Date From">
      <span>–</span>
      <input type="date" class="fi-date" id="fDateTo" title="Date To">
    </div>
    <?php elseif ($roleRank === 3): ?>
    <div class="date-range-wrap">
      <input type="date" class="fi-date" id="fDateFrom" title="Date From">
      <span>–</span>
      <input type="date" class="fi-date" id="fDateTo" title="Date To">
    </div>
    <?php endif; ?>
  </div>

  <!-- BULK BAR (Super Admin only) -->
  <?php if ($roleRank === 4): ?>
  <div class="bulk-bar" id="bulkBar">
    <span class="bulk-count" id="bulkCount">0 selected</span>
    <div class="bulk-sep"></div>
    <button class="btn btn-approve btn-sm" id="batchApproveBtn"><i class="bx bx-check-double"></i> Bulk Approve</button>
    <button class="btn btn-complete btn-sm" id="batchCompleteBtn"><i class="bx bx-check-circle"></i> Bulk Complete</button>
    <button class="btn btn-reject btn-sm" id="batchRejectBtn"><i class="bx bx-x"></i> Bulk Reject</button>
    <button class="btn btn-ghost btn-sm" id="clearSelBtn"><i class="bx bx-x-circle"></i> Clear</button>
    <span class="sa-exclusive" style="margin-left:auto"><i class="bx bx-shield-quarter"></i> Super Admin Exclusive</span>
  </div>
  <?php endif; ?>

  <div class="ad-card">
    <div class="tbl-scroll">
    <table class="ad-tbl" id="tbl">
      <colgroup>
        <?php if ($roleRank === 4): ?><col style="width:38px"><?php endif; ?>
        <col><!-- Disposal ID -->
        <col><!-- Asset -->
        <?php if ($roleRank >= 3): ?>
        <col><!-- Zone -->
        <col><!-- Reason -->
        <col><!-- Method -->
        <col><!-- Date -->
        <col><!-- Approved By -->
        <col><!-- Value -->
        <?php elseif ($roleRank === 2): ?>
        <col><!-- Reason -->
        <col><!-- Approved By -->
        <?php else: ?>
        <col><!-- Reason -->
        <?php endif; ?>
        <col><!-- Status -->
        <col style="width:130px"><!-- Actions -->
      </colgroup>
      <thead><tr>
        <?php if ($roleRank === 4): ?>
        <th class="no-sort"><div class="cb-wrap"><input type="checkbox" class="cb" id="checkAll"></div></th>
        <?php endif; ?>
        <th data-col="disposalId">Disposal ID <i class="bx bx-sort sic"></i></th>
        <th data-col="assetName">Asset<?= $roleRank >= 3 ? ' / Name' : '' ?> <i class="bx bx-sort sic"></i></th>
        <?php if ($roleRank >= 3): ?>
        <th data-col="zone">Zone <i class="bx bx-sort sic"></i></th>
        <th data-col="reason">Reason for Disposal <i class="bx bx-sort sic"></i></th>
        <th data-col="method">Method <i class="bx bx-sort sic"></i></th>
        <th data-col="disposalDate">Date <i class="bx bx-sort sic"></i></th>
        <th data-col="approvedBy">Approved By <i class="bx bx-sort sic"></i></th>
        <th data-col="disposalValue">Value <i class="bx bx-sort sic"></i></th>
        <?php elseif ($roleRank === 2): ?>
        <th data-col="reason">Reason <i class="bx bx-sort sic"></i></th>
        <th data-col="approvedBy">Approved By <i class="bx bx-sort sic"></i></th>
        <?php else: ?>
        <th data-col="reason">Reason <i class="bx bx-sort sic"></i></th>
        <?php endif; ?>
        <th data-col="status">Status <i class="bx bx-sort sic"></i></th>
        <th class="no-sort">Actions</th>
      </tr></thead>
      <tbody id="tbody"></tbody>
    </table>
    </div>
    <div class="ad-pager" id="pager"></div>
  </div>

</div>
</main>

<div class="ad-toasts" id="toastWrap"></div>
<div id="slOverlay"></div>

<!-- CREATE / EDIT SLIDER (Admin / SA only) -->
<?php if ($roleRank >= 3): ?>
<div id="adSlider">
  <div class="sl-hdr">
    <div><div class="sl-title" id="slTitle">Initiate Asset Disposal</div><div class="sl-subtitle" id="slSub">Fill in all required fields below</div></div>
    <button class="sl-close" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-body">
    <div class="ra-notice">
      <i class="bx bx-info-circle"></i>
      <span>All disposals are subject to <strong>RA 9184 (Government Procurement Reform Act)</strong> compliance. Ensure proper documentation and approvals are in place before proceeding.</span>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Asset <span>*</span></label>
        <select class="fs" id="fAssetSl"><option value="">Select asset…</option></select>
      </div>
      <div class="fg">
        <label class="fl">Asset Name <span>*</span></label>
        <input type="text" class="fi" id="fAssetName" placeholder="e.g. Forklift Unit 3">
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Zone <span>*</span></label>
        <select class="fs" id="fZoneSl"><option value="">Select Zone…</option></select>
      </div>
      <div class="fg">
        <label class="fl">Disposal Method <span>*</span></label>
        <select class="fs" id="fMethodSl">
          <option value="">Select…</option>
          <option>Sold</option><option>Scrapped</option><option>Donated</option><option>Auctioned</option><option>Transferred</option>
        </select>
      </div>
    </div>
    <div class="fg">
      <label class="fl">Reason for Disposal <span>*</span></label>
      <textarea class="fta" id="fReason" placeholder="Describe why this asset is being disposed of…"></textarea>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Disposal Date <span>*</span></label>
        <input type="date" class="fi" id="fDisposalDate">
      </div>
      <div class="fg">
        <label class="fl">Disposal Value (₱)</label>
        <input type="number" class="fi" id="fDisposalValue" placeholder="0.00" min="0" step="0.01">
      </div>
    </div>
    <div class="fd">Approval &amp; Documentation</div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Approved By</label>
        <input type="text" class="fi" id="fApprovedBy" placeholder="e.g. Super Admin">
      </div>
      <div class="fg">
        <label class="fl">Status</label>
        <select class="fs" id="fStatusSl">
          <option value="Pending Approval">Submit for Approval</option>
          <?php if ($roleRank === 4): ?><option value="Approved">Mark Approved</option><?php endif; ?>
        </select>
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl" style="display:flex;align-items:center;gap:7px">
          RA 9184 Reference No.
          <span id="raRefAutoTag" style="font-size:10px;font-weight:700;background:linear-gradient(135deg,#EFF6FF,#DBEAFE);color:#1D4ED8;border:1px solid #BFDBFE;border-radius:5px;padding:1px 7px;letter-spacing:.05em;text-transform:none">Auto-generated</span>
        </label>
        <div style="position:relative">
          <input type="text" class="fi" id="fRaRef" placeholder="BAC-2025-0001" readonly style="background:var(--bg);color:var(--t2);cursor:default;padding-right:72px">
          <span id="raRefEditBtn" style="display:none;position:absolute;right:8px;top:50%;transform:translateY(-50%);font-size:10.5px;font-weight:700;color:var(--grn);cursor:pointer;padding:2px 8px;border:1px solid rgba(46,125,50,.3);border-radius:6px;background:var(--s)" onclick="enableRaRefEdit()">Edit</span>
        </div>
        <div id="raRefHint" style="font-size:11px;color:var(--t3);margin-top:2px">Generated automatically as <strong id="raRefPreview" style="color:var(--t2);font-family:'DM Mono',monospace"></strong></div>
      </div>
      <div class="fg">
        <label class="fl">Book Value (₱)</label>
        <input type="number" class="fi" id="fBookValue" placeholder="0.00" min="0" step="0.01" readonly style="background:var(--bg);cursor:default;color:var(--t2)">
      </div>
    </div>
    <div class="fg">
      <label class="fl">Remarks / Notes</label>
      <textarea class="fta" id="fRemarks" placeholder="Additional notes or documentation references…" style="min-height:56px"></textarea>
    </div>
  </div>
  <div class="sl-foot">
    <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="slSubmit"><i class="bx bx-save"></i> Save Disposal</button>
  </div>
</div>
<?php endif; ?>

<!-- ACTION MODAL -->
<div id="actionModal">
  <div class="am-box">
    <div class="am-icon" id="amIcon">✅</div>
    <div class="am-title" id="amTitle">Confirm Action</div>
    <div class="am-body" id="amBody"></div>
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
        <?php if ($roleRank === 4): ?>
        <button class="vm-tab" data-t="ra"><i class="bx bx-shield-alt-2"></i> RA 9184 Compliance</button>
        <button class="vm-tab" data-t="au"><i class="bx bx-shield-quarter"></i> Audit Trail</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="vm-mbd">
      <div class="vm-tp active" id="vt-ov"></div>
      <?php if ($roleRank === 4): ?>
      <div class="vm-tp" id="vt-ra"></div>
      <div class="vm-tp" id="vt-au"></div>
      <?php endif; ?>
    </div>
    <div class="vm-mft" id="vmFoot"></div>
  </div>
</div>

<script>
// ── ROLE CONSTANTS ────────────────────────────────────────────────────────────
const ROLE_RANK = <?= $jsRoleRank ?>;
const ROLE_NAME = '<?= $jsRoleName ?>';
const MY_ZONE   = '<?= $jsZone ?>';
const MY_ID     = '<?= $jsUserId ?>';
const API       = '<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES) ?>';

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
let DISPOSALS=[], ASSETS=[];
let sortCol='disposalDate', sortDir='desc', page=1;
const PAGE=10;
let selectedIds=new Set();
let actionKey=null, actionTarget=null, actionCb=null;
let editId=null;
let currentViewId=null;

// ── LOAD ──────────────────────────────────────────────────────────────────────
async function loadAll(){
    try {
        const proms = [apiGet(API+'?api=list')];
        if (ROLE_RANK >= 3) proms.unshift(apiGet(API+'?api=assets').catch(()=>[]));
        if (ROLE_RANK >= 3) {
            [ASSETS, DISPOSALS] = await Promise.all(proms);
        } else {
            [DISPOSALS] = await Promise.all(proms);
        }
    } catch(e){ toast('Failed to load data: '+e.message,'d'); }
    if (ROLE_RANK >= 3) populateSliderDropdowns();
    renderList();
    if (ROLE_RANK === 4) renderCompliance();
    else renderStats();
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc  = s=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fD   = d=>{ if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); };
const fM   = n=>'₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const ini  = n=>String(n||'').split(/[\s–\-]+/).map(w=>w[0]).join('').slice(0,2).toUpperCase();
const today= ()=>new Date().toISOString().split('T')[0];

function zoneColor(zone){
    const m={'zone a':'#2E7D32','zone b':'#2563EB','zone c':'#D97706','zone d':'#DC2626','zone e':'#0D9488','zone f':'#7C3AED'};
    const key=String(zone||'').toLowerCase().substring(0,6);
    for(const k in m){ if(key.includes(k)) return m[k]; }
    return '#6B7280';
}
function badge(s){
    const m={'Pending Approval':'b-pending','Approved':'b-approved','Completed':'b-completed','Cancelled':'b-cancelled','Rejected':'b-rejected'};
    return `<span class="badge ${m[s]||''}">${esc(s)}</span>`;
}
function methodPill(m){
    const cls={Sold:'mp-sold',Scrapped:'mp-scrapped',Donated:'mp-donated',Auctioned:'mp-auctioned',Transferred:'mp-transferred'};
    const icon={Sold:'bx-dollar',Scrapped:'bx-trash',Donated:'bx-gift',Auctioned:'bx-gavel',Transferred:'bx-transfer'};
    return `<span class="method-pill ${cls[m]||''}"><i class="bx ${icon[m]||'bx-box'}"></i>${esc(m)}</span>`;
}

// ── SLIDER DROPDOWNS (Admin+ only) ────────────────────────────────────────────
function populateSliderDropdowns(){
    if (ROLE_RANK < 3) return;
    const aEl=document.getElementById('fAssetSl');
    if (aEl) {
        aEl.innerHTML='<option value="">Select asset…</option>'+ASSETS.map(a=>
            `<option value="${esc(a.assetId)}" data-name="${esc(a.name)}" data-zone="${esc(a.zone)}" data-dbid="${a.id}" data-val="${a.currentValue}">${esc(a.assetId)} — ${esc(a.name)}</option>`
        ).join('');
        aEl.addEventListener('change',function(){
            const opt=this.options[this.selectedIndex];
            if(opt.value){
                document.getElementById('fAssetName').value=opt.dataset.name||'';
                if(ROLE_RANK===4) document.getElementById('fZoneSl').value=opt.dataset.zone||'';
                document.getElementById('fBookValue').value=opt.dataset.val||'';
            }
        });
    }
    const zones=[...new Set(ASSETS.map(a=>a.zone).filter(Boolean))].sort();
    const zEl=document.getElementById('fZoneSl');
    if (zEl) {
        zEl.innerHTML='<option value="">Select Zone…</option>'+zones.map(z=>`<option>${esc(z)}</option>`).join('');
        // Admin: lock to own zone
        if (ROLE_RANK === 3 && MY_ZONE) { zEl.value=MY_ZONE; zEl.disabled=true; }
    }
}

// ── FILTER DROPDOWNS (SA only) ────────────────────────────────────────────────
function buildFilterDropdowns(){
    if (ROLE_RANK < 4) return;
    const zEl=document.getElementById('fZone'); if(!zEl) return;
    const zones=[...new Set(DISPOSALS.map(d=>d.zone).filter(Boolean))].sort();
    const zv=zEl.value;
    zEl.innerHTML='<option value="">All Zones</option>'+zones.map(z=>`<option ${z===zv?'selected':''}>${esc(z)}</option>`).join('');
}

// ── RENDER STATS ──────────────────────────────────────────────────────────────
function renderStats(){
    const pend    = DISPOSALS.filter(d=>d.status==='Pending Approval').length;
    const appr    = DISPOSALS.filter(d=>d.status==='Approved').length;
    const comp    = DISPOSALS.filter(d=>d.status==='Completed').length;
    const rej     = DISPOSALS.filter(d=>d.status==='Rejected').length;
    const totalVal= DISPOSALS.filter(d=>d.status==='Completed').reduce((s,d)=>s+d.disposalValue,0);
    const totalBook=DISPOSALS.filter(d=>d.status==='Completed').reduce((s,d)=>s+d.bookValue,0);
    const recovery= totalBook>0?Math.round(totalVal/totalBook*100):0;

    if (ROLE_RANK >= 3) {
        // Admin shows zone-scoped stats; SA shows full stats via renderCompliance
        let html = `
            <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-time-five"></i></div><div><div class="sc-v">${pend}</div><div class="sc-l">Pending Approval${ROLE_RANK===3?' (Zone)':''}</div></div></div>
            <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${appr}</div><div class="sc-l">Approved</div></div></div>
            <div class="sc"><div class="sc-ic ic-t"><i class="bx bx-check-double"></i></div><div><div class="sc-v">${comp}</div><div class="sc-l">Completed</div></div></div>`;
        if (ROLE_RANK === 3) {
            html += `<div class="sc"><div class="sc-ic ic-r"><i class="bx bx-x-circle"></i></div><div><div class="sc-v">${rej}</div><div class="sc-l">Rejected</div></div></div>`;
        }
        document.getElementById('statsBar').innerHTML = html;
    } else if (ROLE_RANK === 2) {
        // Manager: zone-scoped status counts
        document.getElementById('statsBar').innerHTML = `
            <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-time-five"></i></div><div><div class="sc-v">${pend}</div><div class="sc-l">Pending (Zone)</div></div></div>
            <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${appr}</div><div class="sc-l">Approved</div></div></div>
            <div class="sc"><div class="sc-ic ic-t"><i class="bx bx-check-double"></i></div><div><div class="sc-v">${comp}</div><div class="sc-l">Completed</div></div></div>`;
    } else {
        // Staff: only their own task counts
        document.getElementById('statsBar').innerHTML = `
            <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-time-five"></i></div><div><div class="sc-v">${pend}</div><div class="sc-l">My Pending Tasks</div></div></div>
            <div class="sc"><div class="sc-ic ic-t"><i class="bx bx-check-double"></i></div><div><div class="sc-v">${comp}</div><div class="sc-l">Completed</div></div></div>`;
    }
}

// ── RENDER COMPLIANCE (SA only) ───────────────────────────────────────────────
function renderCompliance(){
    renderStats();
    if (ROLE_RANK < 4) return;
    const el=document.getElementById('compliancePanel'); if(!el) return;
    const METHODS=['Sold','Scrapped','Donated','Auctioned','Transferred'];
    const METHOD_BAR={Sold:'#2563EB',Scrapped:'#6B7280',Donated:'#7C3AED',Auctioned:'#D97706',Transferred:'#0D9488'};
    const mCounts=METHODS.map(m=>({label:m,val:DISPOSALS.filter(d=>d.method===m).length}));
    const maxM=Math.max(...mCounts.map(x=>x.val),1);
    const comp=DISPOSALS.filter(d=>d.status==='Completed');
    const totalBook=comp.reduce((s,d)=>s+d.bookValue,0);
    const totalDisp=comp.reduce((s,d)=>s+d.disposalValue,0);
    const totalSold=DISPOSALS.filter(d=>d.method==='Sold'&&d.status==='Completed').reduce((s,d)=>s+d.disposalValue,0);
    const totalAuct=DISPOSALS.filter(d=>d.method==='Auctioned'&&d.status==='Completed').reduce((s,d)=>s+d.disposalValue,0);
    const raTotal=DISPOSALS.filter(d=>d.status!=='Cancelled').length;
    el.innerHTML=`
        <div class="compliance-hdr">
          <h3><i class="bx bx-shield-alt-2"></i> RA 9184 Compliance &amp; Value Recovery Tracking</h3>
          <span class="sa-badge"><i class="bx bx-shield-quarter"></i> Super Admin View</span>
        </div>
        <div class="compliance-grid">
          <div class="comp-item">
            <div class="ci-label"><i class="bx bx-shield-alt-2" style="color:var(--grn)"></i> RA 9184 Compliance Status</div>
            <div class="comp-ra">
              <div class="comp-ra-row"><span class="cr-label">Total Records (excl. cancelled)</span><span class="cr-val">${raTotal}</span></div>
              <div class="comp-ra-row"><span class="cr-label">Completed Disposals</span><span class="cr-val">${comp.length}</span></div>
              <div class="comp-ra-row"><span class="cr-label">Pending Approval</span><span class="cr-val">${DISPOSALS.filter(d=>d.status==='Pending Approval').length}</span></div>
              <div class="comp-ra-row"><span class="cr-label">Approved Awaiting Completion</span><span class="cr-val">${DISPOSALS.filter(d=>d.status==='Approved').length}</span></div>
              <div class="comp-ra-row"><span class="cr-label">Rejected Records</span><span class="cr-val cr-tag ${DISPOSALS.filter(d=>d.status==='Rejected').length>0?'cr-warn':'cr-ok'}">${DISPOSALS.filter(d=>d.status==='Rejected').length}</span></div>
            </div>
          </div>
          <div class="comp-item">
            <div class="ci-label"><i class="bx bx-bar-chart-alt-2" style="color:var(--blu)"></i> Disposals by Method</div>
            <div class="method-bars">
              ${mCounts.map(m=>`<div class="method-bar-row"><div class="method-bar-label">${m.label}</div><div class="method-bar-track"><div class="method-bar-fill" style="width:${Math.round(m.val/maxM*100)}%;background:${METHOD_BAR[m.label]}"></div></div><div class="method-bar-val">${m.val}</div></div>`).join('')}
            </div>
          </div>
          <div class="comp-item">
            <div class="ci-label"><i class="bx bx-money" style="color:var(--tel)"></i> Value Recovery Tracking</div>
            <div class="value-recovery">
              <div class="vr-row"><span class="vr-label"><i class="bx bx-book-open"></i> Total Book Value</span><span class="vr-val">${fM(totalBook)}</span></div>
              <div class="vr-row"><span class="vr-label"><i class="bx bx-money-withdraw"></i> Total Recovered</span><span class="vr-val">${fM(totalDisp)}</span></div>
              <div class="vr-row"><span class="vr-label"><i class="bx bx-dollar-circle"></i> From Sales</span><span class="vr-val">${fM(totalSold)}</span></div>
              <div class="vr-row"><span class="vr-label"><i class="bx bx-gavel"></i> From Auctions</span><span class="vr-val">${fM(totalAuct)}</span></div>
              <div class="vr-row"><span class="vr-label"><i class="bx bx-trending-up"></i> Recovery Rate</span><span class="vr-val" style="color:${totalBook>0&&totalDisp/totalBook>.3?'#166534':'#D97706'}">${totalBook>0?Math.round(totalDisp/totalBook*100):0}%</span></div>
            </div>
          </div>
        </div>`;
}

// ── FILTER / SORT ─────────────────────────────────────────────────────────────
function getFiltered(){
    const q  =document.getElementById('srch').value.trim().toLowerCase();
    const fs =document.getElementById('fStatus').value;
    const fm =(document.getElementById('fMethod')?.value)||'';
    const fz =(document.getElementById('fZone')?.value)||'';
    const df =(document.getElementById('fDateFrom')?.value)||'';
    const dt =(document.getElementById('fDateTo')?.value)||'';
    return DISPOSALS.filter(d=>{
        if(q&&!d.disposalId.toLowerCase().includes(q)&&!d.assetName.toLowerCase().includes(q)&&!d.zone.toLowerCase().includes(q)&&!d.assetId.toLowerCase().includes(q)) return false;
        if(fs&&d.status!==fs)  return false;
        if(fm&&d.method!==fm)  return false;
        if(fz&&d.zone!==fz)    return false;
        if(df&&d.disposalDate<df) return false;
        if(dt&&d.disposalDate>dt) return false;
        return true;
    });
}
function getSorted(list){
    return [...list].sort((a,b)=>{
        let va=a[sortCol], vb=b[sortCol];
        if(sortCol==='disposalValue'||sortCol==='bookValue') return sortDir==='asc'?va-vb:vb-va;
        va=String(va||'').toLowerCase(); vb=String(vb||'').toLowerCase();
        return sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va);
    });
}

// ── RENDER TABLE ──────────────────────────────────────────────────────────────
function renderList(){
    renderStats(); buildFilterDropdowns();
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
        tb.innerHTML=`<tr><td colspan="12"><div class="empty"><i class="bx bx-trash"></i><p>${ROLE_RANK===1?'No assigned disposal tasks found.':'No disposal records found.'}</p></div></td></tr>`;
    } else {
        tb.innerHTML=slice.map(d=>{
            const clr=zoneColor(d.zone);
            const chk=selectedIds.has(d.disposalId);
            const isPending =d.status==='Pending Approval';
            const isApproved=d.status==='Approved';
            const isComp    =d.status==='Completed';
            const isCancelled=d.status==='Cancelled';
            const isRejected=d.status==='Rejected';
            const zoneShort =d.zone.split('–')[0].trim();
            const isMyRecord=d.createdUserId===MY_ID;

            // Build action buttons per role
            let actBtns=`<button class="btn ionly" onclick="openView(${d.id})" title="View"><i class="bx bx-show"></i></button>`;

            if (ROLE_RANK === 4) {
                // Super Admin: full actions
                if(isPending||isRejected) actBtns+=`<button class="btn ionly" onclick="openEdit(${d.id})" title="Edit"><i class="bx bx-edit"></i></button>`;
                if(isPending) actBtns+=`<button class="btn ionly btn-approve" onclick="doAction('approve',${d.id})" title="Approve"><i class="bx bx-check"></i></button>`;
                if(isPending) actBtns+=`<button class="btn ionly btn-reject" onclick="doAction('reject',${d.id})" title="Reject"><i class="bx bx-x"></i></button>`;
                if(isApproved) actBtns+=`<button class="btn ionly btn-complete" onclick="doAction('complete',${d.id})" title="Complete"><i class="bx bx-check-double"></i></button>`;
                if(!isCancelled&&!isComp) actBtns+=`<button class="btn ionly btn-cancel-ad" onclick="doAction('cancel',${d.id})" title="Cancel"><i class="bx bx-minus-circle"></i></button>`;
                actBtns+=`<button class="btn ionly btn-override" onclick="doAction('saoverride',${d.id})" title="SA Override"><i class="bx bx-shield-quarter"></i></button>`;
            } else if (ROLE_RANK === 3) {
                // Admin: initiate, view, cancel pending only
                if(isPending) actBtns+=`<button class="btn ionly" onclick="openEdit(${d.id})" title="Edit"><i class="bx bx-edit"></i></button>`;
                if(isPending) actBtns+=`<button class="btn ionly btn-cancel-ad" onclick="doAction('cancel',${d.id})" title="Cancel"><i class="bx bx-minus-circle"></i></button>`;
            } else if (ROLE_RANK === 2) {
                // Manager: view and escalate urgent
                if(isPending||isApproved) actBtns+=`<button class="btn ionly btn-escalate" onclick="doEscalate(${d.id})" title="Escalate Urgent"><i class="bx bx-error"></i></button>`;
            } else {
                // Staff: log step / submit if their own Approved record
                if(isMyRecord&&isApproved) actBtns+=`<button class="btn ionly btn-logstep" onclick="openLogStep(${d.id})" title="Log Disposal Step"><i class="bx bx-list-check"></i></button>`;
            }

            // Build row cells per role
            let cells = '';
            if (ROLE_RANK === 4) cells+=`<td onclick="event.stopPropagation()"><div class="cb-wrap"><input type="checkbox" class="cb row-cb" data-id="${d.disposalId}" ${chk?'checked':''}></div></td>`;

            cells+=`<td onclick="openView(${d.id})"><span class="ad-num">${esc(d.disposalId)}</span></td>`;
            cells+=`<td onclick="openView(${d.id})">
                <div class="asset-cell">
                    <div class="asset-name">${esc(d.assetName)}</div>
                    <div class="asset-meta"><span class="asset-id">${esc(d.assetId)}</span></div>
                </div></td>`;

            if (ROLE_RANK >= 3) {
                cells+=`<td onclick="openView(${d.id})"><span class="asset-zone" style="gap:5px"><span style="width:7px;height:7px;border-radius:50%;background:${clr};flex-shrink:0;display:inline-block"></span>${esc(zoneShort)}</span></td>`;
                cells+=`<td onclick="openView(${d.id})"><span class="reason-cell">${esc(d.reason)}</span></td>`;
                cells+=`<td onclick="openView(${d.id})">${methodPill(d.method)}</td>`;
                cells+=`<td onclick="openView(${d.id})"><span class="ad-date">${fD(d.disposalDate)}</span></td>`;
                cells+=`<td onclick="openView(${d.id})"><span style="font-size:12px;color:var(--t2)">${d.approvedBy?esc(d.approvedBy):'<span style="color:var(--t3)">Pending</span>'}</span></td>`;
                cells+=`<td onclick="openView(${d.id})"><span class="ad-val">${d.disposalValue>0?fM(d.disposalValue):'—'}</span></td>`;
            } else if (ROLE_RANK === 2) {
                cells+=`<td onclick="openView(${d.id})"><span class="reason-cell">${esc(d.reason)}</span></td>`;
                cells+=`<td onclick="openView(${d.id})"><span style="font-size:12px;color:var(--t2)">${d.approvedBy?esc(d.approvedBy):'<span style="color:var(--t3)">Pending</span>'}</span></td>`;
            } else {
                cells+=`<td onclick="openView(${d.id})"><span class="reason-cell">${esc(d.reason)}</span></td>`;
            }

            cells+=`<td onclick="openView(${d.id})">${badge(d.status)}</td>`;
            cells+=`<td onclick="event.stopPropagation()"><div class="act-cell">${actBtns}</div></td>`;

            return `<tr class="${chk?'row-selected':''}">${cells}</tr>`;
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
    const s=(page-1)*PAGE+1, e=Math.min(page*PAGE,total);
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
window.goPage=p=>{page=p;renderList();};

document.querySelectorAll('#tbl thead th[data-col]').forEach(th=>{
    th.addEventListener('click',()=>{
        const c=th.dataset.col;
        sortDir=sortCol===c?(sortDir==='asc'?'desc':'asc'):'asc';
        sortCol=c; page=1; renderList();
    });
});
['srch','fStatus','fMethod','fZone','fDateFrom','fDateTo'].forEach(id=>{
    const el=document.getElementById(id);
    if(el) el.addEventListener('input',()=>{page=1;renderList();});
});

// ── BULK (SA only) ────────────────────────────────────────────────────────────
function updateBulkBar(){
    if(ROLE_RANK<4) return;
    const n=selectedIds.size;
    const bb=document.getElementById('bulkBar'); if(bb) bb.classList.toggle('on',n>0);
    const bc=document.getElementById('bulkCount'); if(bc) bc.textContent=n===1?'1 selected':`${n} selected`;
}
function syncCheckAll(slice){
    if(ROLE_RANK<4) return;
    const ca=document.getElementById('checkAll'); if(!ca) return;
    const ids=slice.map(d=>d.disposalId);
    const all=ids.length>0&&ids.every(id=>selectedIds.has(id));
    ca.checked=all; ca.indeterminate=!all&&ids.some(id=>selectedIds.has(id));
}
const caEl=document.getElementById('checkAll');
if(caEl) caEl.addEventListener('change',function(){
    const slice=getSorted(getFiltered()).slice((page-1)*PAGE,page*PAGE);
    slice.forEach(d=>{if(this.checked) selectedIds.add(d.disposalId); else selectedIds.delete(d.disposalId);});
    renderList(); updateBulkBar();
});
const csEl=document.getElementById('clearSelBtn');
if(csEl) csEl.addEventListener('click',()=>{selectedIds.clear();renderList();updateBulkBar();});

// Batch buttons
const batchBtns={
    batchApproveBtn:{type:'batch-approve',filter:d=>d.status==='Pending Approval',icon:'✅',title:'Bulk Approve',body:'Approve <strong>%n</strong> pending disposal record(s).',saText:'Super Admin site-wide disposal approval.',btn:'btn-approve',label:'<i class="bx bx-check-double"></i> Bulk Approve'},
    batchCompleteBtn:{type:'batch-complete',filter:d=>d.status==='Approved',icon:'🏁',title:'Bulk Complete',body:'Mark <strong>%n</strong> approved disposal(s) as Completed. Assets will be marked Disposed.',saText:'Super Admin bulk completion.',btn:'btn-complete',label:'<i class="bx bx-check-double"></i> Bulk Complete'},
    batchRejectBtn:{type:'batch-reject',filter:d=>d.status==='Pending Approval',icon:'❌',title:'Bulk Reject',body:'Reject <strong>%n</strong> pending disposal record(s).',saText:'Super Admin batch rejection.',btn:'btn-reject',label:'<i class="bx bx-x"></i> Bulk Reject'},
};
Object.entries(batchBtns).forEach(([btnId,cfg])=>{
    const btn=document.getElementById(btnId); if(!btn) return;
    btn.addEventListener('click',()=>{
        const valid=[...selectedIds].map(did=>DISPOSALS.find(d=>d.disposalId===did)).filter(d=>d&&cfg.filter(d));
        if(!valid.length){toast(`No eligible records in selection.`,'w');return;}
        showActionModal(cfg.icon,cfg.title,cfg.body.replace('%n',valid.length),true,cfg.saText,cfg.btn,cfg.label,null,async()=>{
            const rmk=document.getElementById('amRemarks').value.trim();
            try{
                const r=await apiPost(API+'?api=batch',{type:cfg.type,ids:valid.map(d=>d.id),remarks:rmk});
                const updated=await apiGet(API+'?api=list'); DISPOSALS=updated;
                selectedIds.clear(); renderList(); updateBulkBar(); renderCompliance();
                toast(`${r.updated} record(s) updated.`,'s');
            }catch(e){toast(e.message,'d');}
        });
    });
});

// ── ACTION MODAL ──────────────────────────────────────────────────────────────
function showActionModal(icon,title,body,sa,saText,btnCls,btnLabel,extraHtml,onConfirm=null){
    document.getElementById('amIcon').textContent=icon;
    document.getElementById('amTitle').textContent=title;
    document.getElementById('amBody').innerHTML=body;
    const san=document.getElementById('amSaNote');
    if(sa&&ROLE_RANK>=3){san.style.display='flex';document.getElementById('amSaText').textContent=saText;}
    else san.style.display='none';
    document.getElementById('amDynamicInputs').innerHTML=extraHtml||'';
    document.getElementById('amRemarks').value='';
    const cb=document.getElementById('amConfirm');
    cb.className=`btn btn-sm ${btnCls}`; cb.innerHTML=btnLabel;
    actionCb=onConfirm;
    document.getElementById('actionModal').classList.add('on');
}

function doAction(type,dbId){
    const d=DISPOSALS.find(x=>x.id===dbId); if(!d) return;
    actionTarget=dbId; actionKey=type;
    const body=`Disposal <strong>${esc(d.disposalId)}</strong> — <strong>${esc(d.assetName)}</strong> · <strong>${d.method||'—'}</strong>`;
    const cfg={
        approve:   {icon:'✅',sa:true, saText:'You are exercising Super Admin authority to approve this disposal.', extra:'',btn:'btn-approve',label:'<i class="bx bx-check"></i> Approve',title:'Approve Disposal'},
        reject:    {icon:'❌',sa:true, saText:'This disposal will be rejected and returned for revision.',           extra:'',btn:'btn-reject', label:'<i class="bx bx-x"></i> Reject',  title:'Reject Disposal'},
        complete:  {icon:'🏁',sa:true, saText:'Completing this disposal will mark the asset as Disposed.',           extra:'',btn:'btn-complete',label:'<i class="bx bx-check-double"></i> Complete',title:'Complete Disposal'},
        cancel:    {icon:'⛔',sa:false,saText:'',extra:'',btn:'btn-cancel-ad',label:'<i class="bx bx-minus-circle"></i> Cancel',title:'Cancel Disposal'},
        saoverride:{icon:'🛡️',sa:true, saText:'Super Admin authority to override the disposal status.',
                    extra:`<div class="am-fg"><label>Override to Status <span style="color:var(--red)">*</span></label><select id="amNewStatus"><option value="">Select…</option><option>Pending Approval</option><option>Approved</option><option>Completed</option><option>Cancelled</option><option>Rejected</option></select></div>`,
                    btn:'btn-override',label:'<i class="bx bx-shield-quarter"></i> Apply Override',title:'SA Status Override'},
    };
    const c=cfg[type]; if(!c) return;
    showActionModal(c.icon,c.title,body,c.sa,c.saText,c.btn,c.label,c.extra);
}

// Manager: escalate urgent
function doEscalate(dbId){
    const d=DISPOSALS.find(x=>x.id===dbId); if(!d) return;
    actionTarget=dbId; actionKey='escalate';
    showActionModal('⚠️','Escalate Urgent Disposal',`Flag Disposal <strong>${esc(d.disposalId)}</strong> — <strong>${esc(d.assetName)}</strong> as urgent for Super Admin.`,false,'','btn-escalate','<i class="bx bx-error"></i> Escalate',null,async()=>{
        const rmk=document.getElementById('amRemarks').value.trim();
        try{
            await apiPost(API+'?api=escalate',{id:d.id,remarks:rmk});
            document.getElementById('actionModal').classList.remove('on');
            toast(`${d.disposalId} escalated.`,'s');
        }catch(e){toast(e.message,'d');}
    });
}

// Staff: log disposal step
function openLogStep(dbId){
    const d=DISPOSALS.find(x=>x.id===dbId); if(!d) return;
    actionTarget=dbId; actionKey='log-step';
    showActionModal('📋','Log Disposal Step',`Log progress for <strong>${esc(d.assetName)}</strong> (${esc(d.disposalId)}).`,false,'','btn-logstep','<i class="bx bx-list-check"></i> Log Step',
        `<div class="am-fg"><label>Step / Documentation Note <span style="color:var(--red)">*</span></label><textarea id="amStepNote" placeholder="Describe what was done, what was uploaded…" style="resize:vertical;min-height:68px"></textarea></div>
         <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><input type="checkbox" id="amSubmitFinal" style="width:15px;height:15px;accent-color:var(--grn);cursor:pointer"><label for="amSubmitFinal" style="font-size:12.5px;color:var(--t1);cursor:pointer;font-weight:500">Submit for final review</label></div>`,
        async()=>{
            const step=document.getElementById('amStepNote')?.value.trim();
            if(!step){toast('Please describe the step taken.','w');return false;}
            const submit=document.getElementById('amSubmitFinal')?.checked||false;
            try{
                await apiPost(API+'?api=log-step',{id:d.id,step,submit});
                document.getElementById('actionModal').classList.remove('on');
                toast(submit?'Step submitted for review.':'Step logged successfully.','s');
            }catch(e){toast(e.message,'d');}
        }
    );
}

document.getElementById('amConfirm').addEventListener('click',async()=>{
    if(actionCb){const res=await actionCb();if(res===false)return;document.getElementById('actionModal').classList.remove('on');actionCb=null;return;}
    const d=DISPOSALS.find(x=>x.id===actionTarget); if(!d) return;
    const rmk=document.getElementById('amRemarks').value.trim();
    const payload={id:d.id,type:actionKey,remarks:rmk};
    if(actionKey==='saoverride'){
        const ns=document.getElementById('amNewStatus')?.value;
        if(!ns){toast('Please select a target status.','w');return;}
        payload.newStatus=ns;
    }
    try{
        const updated=await apiPost(API+'?api=action',payload);
        const idx=DISPOSALS.findIndex(x=>x.id===updated.id);
        if(idx>-1) DISPOSALS[idx]=updated;
        const msgs={approve:`${d.disposalId} approved.`,reject:`${d.disposalId} rejected.`,complete:`${d.disposalId} completed. Asset marked Disposed.`,cancel:`${d.disposalId} cancelled.`,saoverride:'Status override applied.'};
        toast(msgs[actionKey]||'Action applied.','s');
        document.getElementById('actionModal').classList.remove('on');
        renderList(); renderCompliance();
        if(document.getElementById('viewModal').classList.contains('on')) renderDetail(DISPOSALS.find(x=>x.id===actionTarget));
    }catch(e){toast(e.message,'d');}
});
document.getElementById('amCancel').addEventListener('click',()=>{document.getElementById('actionModal').classList.remove('on');actionCb=null;});
document.getElementById('actionModal').addEventListener('click',function(e){if(e.target===this){this.classList.remove('on');actionCb=null;}});

// ── VIEW MODAL ────────────────────────────────────────────────────────────────
function openView(dbId){
    const d=DISPOSALS.find(x=>x.id===dbId); if(!d) return;
    currentViewId=dbId; renderDetail(d); setVmTab('ov');
    document.getElementById('viewModal').classList.add('on');
}
function closeView(){document.getElementById('viewModal').classList.remove('on');currentViewId=null;}
document.getElementById('vmClose').addEventListener('click',closeView);
document.getElementById('viewModal').addEventListener('click',function(e){if(e.target===this)closeView();});
document.querySelectorAll('.vm-tab').forEach(t=>t.addEventListener('click',()=>{
    const name=t.dataset.t;
    setVmTab(name);
    if(name==='au'&&currentViewId&&ROLE_RANK===4) loadAuditTrail(currentViewId);
    if(name==='ra'&&currentViewId&&ROLE_RANK===4) loadRaCompliance(currentViewId);
}));
function setVmTab(name){
    document.querySelectorAll('.vm-tab').forEach(t=>t.classList.toggle('active',t.dataset.t===name));
    document.querySelectorAll('.vm-tp').forEach(p=>p.classList.toggle('active',p.id==='vt-'+name));
}

function renderDetail(d){
    const clr=zoneColor(d.zone);
    const isPending =d.status==='Pending Approval';
    const isApproved=d.status==='Approved';
    const isComp    =d.status==='Completed';
    const isCancelled=d.status==='Cancelled';
    const isRejected=d.status==='Rejected';
    const isMyRecord=d.createdUserId===MY_ID;
    const recovery=d.bookValue>0?Math.round(d.disposalValue/d.bookValue*100):0;
    const rmkCls=isApproved||isComp?'vm-rmk-a':isRejected||isCancelled?'vm-rmk-r':'vm-rmk-n';

    document.getElementById('vmAvatar').style.background=clr;
    document.getElementById('vmAvatar').textContent=ini(d.assetName);
    document.getElementById('vmName').textContent=d.assetName;
    document.getElementById('vmMid').innerHTML=`<span style="font-family:'DM Mono',monospace">${esc(d.disposalId)}</span>&nbsp;·&nbsp;${esc(d.assetId)}&nbsp;${badge(d.status)}`;
    document.getElementById('vmChips').innerHTML=`
        <div class="vm-mc"><i class="bx bx-map"></i>${esc(d.zone)}</div>
        <div class="vm-mc"><i class="bx bx-calendar"></i>${fD(d.disposalDate)}</div>
        <div class="vm-mc">${methodPill(d.method||'—')}</div>
        ${ROLE_RANK>=3?`<div class="vm-mc"><i class="bx bx-money-withdraw"></i>${d.disposalValue>0?fM(d.disposalValue):'No value set'}</div>`:''}`;

    // Footer actions per role
    let foot='';
    if (ROLE_RANK === 4) {
        if(isPending) foot+=`<button class="btn btn-approve btn-sm" onclick="closeView();doAction('approve',${d.id})"><i class="bx bx-check"></i> Approve</button>`;
        if(isPending) foot+=`<button class="btn btn-reject btn-sm" onclick="closeView();doAction('reject',${d.id})"><i class="bx bx-x"></i> Reject</button>`;
        if(isApproved) foot+=`<button class="btn btn-complete btn-sm" onclick="closeView();doAction('complete',${d.id})"><i class="bx bx-check-double"></i> Complete</button>`;
        if(isPending||isRejected) foot+=`<button class="btn btn-ghost btn-sm" onclick="closeView();openEdit(${d.id})"><i class="bx bx-edit"></i> Edit</button>`;
        if(!isComp&&!isCancelled) foot+=`<button class="btn btn-cancel-ad btn-sm" onclick="closeView();doAction('cancel',${d.id})"><i class="bx bx-minus-circle"></i> Cancel</button>`;
        foot+=`<button class="btn btn-override btn-sm" onclick="closeView();doAction('saoverride',${d.id})"><i class="bx bx-shield-quarter"></i> SA Override</button>`;
    } else if (ROLE_RANK === 3) {
        if(isPending) foot+=`<button class="btn btn-ghost btn-sm" onclick="closeView();openEdit(${d.id})"><i class="bx bx-edit"></i> Edit</button>`;
        if(isPending) foot+=`<button class="btn btn-cancel-ad btn-sm" onclick="closeView();doAction('cancel',${d.id})"><i class="bx bx-minus-circle"></i> Cancel</button>`;
    } else if (ROLE_RANK === 2) {
        if(isPending||isApproved) foot+=`<button class="btn btn-escalate btn-sm" onclick="closeView();doEscalate(${d.id})"><i class="bx bx-error"></i> Escalate Urgent</button>`;
    } else {
        if(isMyRecord&&isApproved) foot+=`<button class="btn btn-logstep btn-sm" onclick="closeView();openLogStep(${d.id})"><i class="bx bx-list-check"></i> Log Step</button>`;
    }
    foot+=`<button class="btn btn-ghost btn-sm" onclick="closeView()">Close</button>`;
    document.getElementById('vmFoot').innerHTML=foot;

    // Overview tab — filtered by role
    let ovHtml=`
        <div class="vm-sbs">
            <div class="vm-sb"><div class="sbv">${esc(d.status)}</div><div class="sbl">Status</div></div>
            ${ROLE_RANK>=3?`<div class="vm-sb"><div class="sbv mono">${d.disposalValue>0?fM(d.disposalValue):'—'}</div><div class="sbl">Disposal Value</div></div>
            <div class="vm-sb"><div class="sbv mono">${fM(d.bookValue)}</div><div class="sbl">Book Value</div></div>
            <div class="vm-sb"><div class="sbv">${recovery}%</div><div class="sbl">Recovery Rate</div></div>`:''}
        </div>
        <div class="vm-ig">
            <div class="vm-ii"><label>Asset ID</label><div class="v" style="font-family:'DM Mono',monospace;color:var(--primary-color)">${esc(d.assetId)}</div></div>
            <div class="vm-ii"><label>Zone</label><div class="v" style="color:${clr};font-weight:600">${esc(d.zone)}</div></div>`;
    if (ROLE_RANK >= 3) {
        ovHtml+=`<div class="vm-ii"><label>Disposal Method</label><div class="v">${methodPill(d.method)}</div></div>
            <div class="vm-ii"><label>Disposal Date</label><div class="v muted">${fD(d.disposalDate)}</div></div>`;
    }
    ovHtml+=`<div class="vm-ii"><label>Approved By</label><div class="v">${d.approvedBy?esc(d.approvedBy):'<span style="color:#9EB0A2">Pending</span>'}</div></div>`;
    if (ROLE_RANK >= 3) {
        ovHtml+=`<div class="vm-ii"><label>RA 9184 Reference</label><div class="v" style="font-family:'DM Mono',monospace">${esc(d.raRef)||'—'}</div></div>`;
    }
    ovHtml+=`<div class="vm-ii vm-full"><label>Reason for Disposal</label><div class="v muted">${esc(d.reason)}</div></div>`;
    if (d.remarks) ovHtml+=`<div class="vm-ii vm-full"><label>Remarks</label><div class="vm-rmk ${rmkCls}"><div class="rml">Remarks</div>${esc(d.remarks)}</div></div>`;
    if (d.saRemarks&&ROLE_RANK>=3) ovHtml+=`<div class="vm-ii vm-full"><label>SA Remarks</label><div class="vm-rmk vm-rmk-r"><div class="rml">Super Admin</div>${esc(d.saRemarks)}</div></div>`;
    ovHtml+=`</div>`;
    document.getElementById('vt-ov').innerHTML=ovHtml;

    // RA + Audit tabs (SA only)
    const raEl=document.getElementById('vt-ra');
    if(raEl) raEl.innerHTML=`<div class="vm-sa-note"><i class="bx bx-shield-alt-2"></i><span>RA 9184 compliance checklist — editable by Super Admin.</span></div><div id="raContent"><div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">Click the "RA 9184 Compliance" tab to load.</div></div>`;
    const auEl=document.getElementById('vt-au');
    if(auEl) auEl.innerHTML=`<div class="vm-sa-note"><i class="bx bx-shield-quarter"></i><span>Full audit trail — immutable, Super Admin view.</span></div><div id="auditContent"><div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">Click the "Audit Trail" tab to load.</div></div>`;
}

async function loadAuditTrail(dbId){
    const wrap=document.getElementById('auditContent'); if(!wrap) return;
    wrap.innerHTML='<div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">Loading…</div>';
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

async function loadRaCompliance(dbId){
    const wrap=document.getElementById('raContent'); if(!wrap) return;
    wrap.innerHTML='<div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">Loading…</div>';
    try{
        const rows=await apiGet(API+'?api=ra&id='+dbId);
        if(!rows.length){wrap.innerHTML=`<div style="text-align:center;color:var(--t3);padding:24px;font-size:13px">No RA compliance rows found.</div>`;return;}
        const sCount=rows.filter(r=>r.status==='Met').length, pCount=rows.filter(r=>r.status==='Pending').length, nCount=rows.filter(r=>r.status==='N/A').length;
        wrap.innerHTML=`
            <table class="ra-tbl">
              <thead><tr><th>Section</th><th>Requirement</th><th>Status</th></tr></thead>
              <tbody>${rows.map(r=>{
                const selCls=r.status==='Met'?'rs-met':r.status==='N/A'?'rs-na':'rs-pending';
                return `<tr>
                    <td><span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--primary-color)">${esc(r.req_code)}</span></td>
                    <td style="font-size:12.5px;color:#374151">${esc(r.req_desc)}</td>
                    <td><select class="ra-status-sel ${selCls}" data-raid="${r.id}" data-did="${dbId}" onchange="updateRa(this)">
                        <option ${r.status==='Pending'?'selected':''}>Pending</option>
                        <option ${r.status==='Met'?'selected':''}>Met</option>
                        <option ${r.status==='N/A'?'selected':''}>N/A</option>
                    </select></td>
                </tr>`;
              }).join('')}</tbody>
            </table>
            <div style="font-size:11.5px;color:#9EB0A2;margin-top:10px;display:flex;gap:12px;flex-wrap:wrap">
                <span>✓ Met: <strong>${sCount}</strong></span><span>⏳ Pending: <strong>${pCount}</strong></span><span>N/A: <strong>${nCount}</strong></span>
                <span style="font-weight:700;color:${pCount===0?'#166534':'#D97706'}">${pCount===0?'✓ Fully Compliant':'⚠ Items Pending'}</span>
            </div>`;
    }catch(e){wrap.innerHTML=`<div style="text-align:center;color:var(--red);padding:24px;font-size:13px">Failed to load RA compliance data.</div>`;}
}

async function updateRa(sel){
    const raId=parseInt(sel.dataset.raid), did=parseInt(sel.dataset.did), newSt=sel.value;
    sel.disabled=true;
    try{
        await apiPost(API+'?api=ra-update',{raId,disposalId:did,status:newSt,notes:''});
        sel.className=`ra-status-sel ${newSt==='Met'?'rs-met':newSt==='N/A'?'rs-na':'rs-pending'}`;
        toast('Compliance status updated.','s');
    }catch(e){toast(e.message,'d');}
    finally{sel.disabled=false;}
}

// ── RA REF PREVIEW ────────────────────────────────────────────────────────────
async function fetchRaRefPreview(){
    try{
        const d=await apiGet(API+'?api=next-ra-ref');
        const el=document.getElementById('fRaRef'), pr=document.getElementById('raRefPreview');
        if(el) el.value=d.raRef||'';
        if(pr) pr.textContent=d.raRef||'';
    }catch(e){}
}
function enableRaRefEdit(){
    const el=document.getElementById('fRaRef'), btn=document.getElementById('raRefEditBtn');
    const hint=document.getElementById('raRefHint'), tag=document.getElementById('raRefAutoTag');
    if(!el) return;
    el.removeAttribute('readonly'); el.style.background=''; el.style.color=''; el.style.cursor=''; el.style.paddingRight='12px';
    if(btn) btn.style.display='none';
    if(hint) hint.style.display='none';
    if(tag){ tag.textContent='Manual Override'; tag.style.background='linear-gradient(135deg,#FEF3C7,#FDE68A)'; tag.style.color='#92400E'; tag.style.borderColor='#FCD34D'; }
    el.focus(); el.select();
}

// ── SLIDER (Admin+ only) ──────────────────────────────────────────────────────
function openSlider(mode='create',d=null){
    if(ROLE_RANK<3) return;
    editId=mode==='edit'?d.id:null;
    document.getElementById('slTitle').textContent=mode==='edit'?`Edit Disposal — ${d.disposalId}`:'Initiate Asset Disposal';
    document.getElementById('slSub').textContent=mode==='edit'?'Update fields below':'Fill in all required fields below';
    const raEl=document.getElementById('fRaRef'), raBtn=document.getElementById('raRefEditBtn');
    const raHint=document.getElementById('raRefHint'), raTag=document.getElementById('raRefAutoTag'), raPrev=document.getElementById('raRefPreview');
    if(mode==='edit'&&d){
        document.getElementById('fAssetSl').value=d.assetId||'';
        document.getElementById('fAssetName').value=d.assetName;
        const zEl=document.getElementById('fZoneSl');
        if(ROLE_RANK===4) zEl.value=d.zone;
        document.getElementById('fMethodSl').value=d.method;
        document.getElementById('fReason').value=d.reason;
        document.getElementById('fDisposalDate').value=d.disposalDate||'';
        document.getElementById('fDisposalValue').value=d.disposalValue||'';
        document.getElementById('fApprovedBy').value=d.approvedBy||'';
        document.getElementById('fStatusSl').value=d.status==='Approved'?'Approved':'Pending Approval';
        document.getElementById('fBookValue').value=d.bookValue||'';
        document.getElementById('fRemarks').value=d.remarks||'';
        if(raEl){ raEl.value=d.raRef||''; raEl.removeAttribute('readonly'); raEl.style.background=''; raEl.style.color=''; raEl.style.cursor=''; raEl.style.paddingRight='12px'; }
        if(raBtn) raBtn.style.display='none';
        if(raHint) raHint.style.display='none';
        if(raTag){ raTag.textContent='Editable'; raTag.style.background='linear-gradient(135deg,#F0FDF4,#DCFCE7)'; raTag.style.color='#166534'; raTag.style.borderColor='#BBF7D0'; }
    } else {
        ['fAssetName','fReason','fDisposalValue','fApprovedBy','fBookValue','fRemarks'].forEach(id=>document.getElementById(id).value='');
        document.getElementById('fAssetSl').value='';
        const zEl=document.getElementById('fZoneSl');
        if(ROLE_RANK===3&&MY_ZONE) { zEl.value=MY_ZONE; } else { zEl.value=''; }
        document.getElementById('fMethodSl').value='';
        document.getElementById('fStatusSl').value='Pending Approval';
        document.getElementById('fDisposalDate').value=today();
        if(raEl){ raEl.value='Generating…'; raEl.setAttribute('readonly',''); raEl.style.background='var(--bg)'; raEl.style.color='var(--t2)'; raEl.style.cursor='default'; raEl.style.paddingRight='72px'; }
        if(raBtn) raBtn.style.display='inline-block';
        if(raHint) raHint.style.display='block';
        if(raPrev) raPrev.textContent='…';
        if(raTag){ raTag.textContent='Auto-generated'; raTag.style.background='linear-gradient(135deg,#EFF6FF,#DBEAFE)'; raTag.style.color='#1D4ED8'; raTag.style.borderColor='#BFDBFE'; }
        fetchRaRefPreview();
    }
    document.getElementById('adSlider').classList.add('on');
    document.getElementById('slOverlay').classList.add('on');
    setTimeout(()=>document.getElementById('fAssetName').focus(),100);
}
function openEdit(dbId){ if(ROLE_RANK<3) return; const d=DISPOSALS.find(x=>x.id===dbId); if(d) openSlider('edit',d); }
function closeSlider(){
    const sl=document.getElementById('adSlider'), ov=document.getElementById('slOverlay');
    if(sl) sl.classList.remove('on');
    if(ov) ov.classList.remove('on');
    editId=null;
}
const slOvEl=document.getElementById('slOverlay');
if(slOvEl) slOvEl.addEventListener('click',function(e){if(e.target===this)closeSlider();});
const slClEl=document.getElementById('slClose');
if(slClEl) slClEl.addEventListener('click',closeSlider);
const slCnEl=document.getElementById('slCancel');
if(slCnEl) slCnEl.addEventListener('click',closeSlider);
const crBnEl=document.getElementById('createBtn');
if(crBnEl) crBnEl.addEventListener('click',()=>openSlider('create'));

const slSubEl=document.getElementById('slSubmit');
if(slSubEl) slSubEl.addEventListener('click',async()=>{
    if(ROLE_RANK<3) return;
    const btn=slSubEl; btn.disabled=true;
    try{
        const assetOpt=document.getElementById('fAssetSl');
        const assetSel=assetOpt.options[assetOpt.selectedIndex];
        const assetDbId=parseInt(assetSel?.dataset.dbid||0);
        const assetId=assetOpt.value||'';
        const assetName=document.getElementById('fAssetName').value.trim();
        const zone=document.getElementById('fZoneSl').value;
        const method2=document.getElementById('fMethodSl').value;
        const reason=document.getElementById('fReason').value.trim();
        const dateStr=document.getElementById('fDisposalDate').value;
        const dispVal=parseFloat(document.getElementById('fDisposalValue').value)||0;
        const bookVal=parseFloat(document.getElementById('fBookValue').value)||0;
        const approvedBy=document.getElementById('fApprovedBy').value.trim();
        const status=document.getElementById('fStatusSl').value;
        const raRef=editId?document.getElementById('fRaRef').value.trim():'';
        const remarks=document.getElementById('fRemarks').value.trim();

        if(!assetName){shk('fAssetName');toast('Asset name is required.','w');return;}
        if(!zone){shk('fZoneSl');toast('Please select a zone.','w');return;}
        if(!method2){shk('fMethodSl');toast('Please select a disposal method.','w');return;}
        if(!reason){shk('fReason');toast('Reason for disposal is required.','w');return;}
        if(!dateStr){shk('fDisposalDate');toast('Disposal date is required.','w');return;}

        const payload={assetId,assetName,assetDbId,zone,method:method2,reason,disposalDate:dateStr,disposalValue:dispVal,bookValue:bookVal,approvedBy,status,raRef,remarks};
        if(editId) payload.id=editId;
        const saved=await apiPost(API+'?api=save',payload);
        const idx=DISPOSALS.findIndex(x=>x.id===saved.id);
        if(idx>-1) DISPOSALS[idx]=saved; else{DISPOSALS.unshift(saved);page=1;}
        toast(`${saved.disposalId} ${editId?'updated':'created'} successfully.`,'s');
        closeSlider(); renderList(); renderCompliance();
    }catch(e){toast(e.message,'d');}
    finally{btn.disabled=false;}
});

// ── EXPORT (Admin+) ────────────────────────────────────────────────────────────
const expBnEl=document.getElementById('exportBtn');
if(expBnEl) expBnEl.addEventListener('click',()=>{
    if(ROLE_RANK<3) return;
    const cols=ROLE_RANK>=3?['disposalId','assetId','assetName','zone','reason','method','disposalDate','approvedBy','disposalValue','bookValue','status','raRef']:['disposalId','assetId','assetName','reason','status'];
    const hdrs=ROLE_RANK>=3?['Disposal ID','Asset ID','Asset Name','Zone','Reason','Method','Disposal Date','Approved By','Disposal Value','Book Value','Status','RA 9184 Ref']:['Disposal ID','Asset ID','Asset Name','Reason','Status'];
    const rows=[hdrs.join(','),...getFiltered().map(d=>cols.map(c=>`"${String(d[c]||'').replace(/"/g,'""')}"`).join(','))];
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
    a.download='asset_disposal.csv'; a.click();
    toast('CSV exported.','s');
});

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