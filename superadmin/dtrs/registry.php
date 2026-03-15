<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE RESOLUTION (mirrors superadmin_sidebar.php) ─────────────────────────
function _dr_resolve_role(): string {
    if (!empty($_SESSION['role'])) {
        $r = $_SESSION['role'];
        if (str_contains($r, 'Super Admin')) return 'Super Admin';
        if (str_contains($r, 'Admin'))       return 'Admin';
        if (str_contains($r, 'Manager'))     return 'Manager';
        return 'User';
    }
    if (!empty($_SESSION['roles'])) {
        $r = is_array($_SESSION['roles'])
            ? implode(',', $_SESSION['roles'])
            : (string)$_SESSION['roles'];
        if (str_contains($r, 'Super Admin')) return 'Super Admin';
        if (str_contains($r, 'Admin'))       return 'Admin';
        if (str_contains($r, 'Manager'))     return 'Manager';
    }
    return 'User';
}

$drRoleName = _dr_resolve_role();
$drRoleRank = match($drRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,  // User / Staff
};
$drUserZone  = $_SESSION['zone']      ?? '';
$drUserId    = $_SESSION['user_id']   ?? '';
$drUserName  = $_SESSION['full_name'] ?? ($drUserId ?: 'User');

// ── PERMISSION GATES ──────────────────────────────────────────────────────────
// Super Admin (4): full access everywhere
// Admin (3):       own-zone view + edit + archive + bulk — no delete, no cross-zone
// Manager (2):     read-only team monitoring + flag — no edit/archive/delete
// User (1):        only assigned docs — view + QR scan + status update/notes

$drCan = [
    // Data scope
    'viewAllZones'   => $drRoleRank >= 4,  // SA: all zones
    'viewZone'       => $drRoleRank >= 3,  // Admin: zone docs
    'viewTeam'       => $drRoleRank >= 2,  // Manager: team docs (read-only)
    'viewOwn'        => $drRoleRank >= 1,  // User: assigned to me only

    // Document actions
    'edit'           => $drRoleRank >= 3,  // SA, Admin
    'archive'        => $drRoleRank >= 3,  // SA, Admin
    'delete'         => $drRoleRank >= 4,  // SA only
    'reroute'        => $drRoleRank >= 3,  // SA, Admin (within zone for Admin)
    'bulkExport'     => $drRoleRank >= 3,  // SA, Admin
    'bulkArchive'    => $drRoleRank >= 3,  // SA, Admin
    'bulkReassign'   => $drRoleRank >= 3,  // SA, Admin

    // User-level self-service
    'scanQR'         => true,              // all roles can scan QR
    'updateOwn'      => $drRoleRank >= 1,  // User: update status/notes on own docs
    'flagDelay'      => $drRoleRank >= 2,  // Manager+

    // UI visibility
    'showCheckboxes' => $drRoleRank >= 3,  // SA, Admin only
    'showActions'    => $drRoleRank >= 3,  // edit/archive/delete buttons
    'showQRScan'     => true,
];

// Pass to JS
$jsPerms    = json_encode($drCan);
$jsRole     = json_encode($drRoleName);
$jsRoleRank = (int)$drRoleRank;
$jsZone     = json_encode($drUserZone);
$jsUserId   = json_encode($drUserId);
$jsUserName = json_encode($drUserName);

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

function dr_build(array $row): array {
    return [
        'id'             => (int)$row['id'],
        'docId'          => $row['doc_id']          ?? '',
        'name'           => $row['title']            ?? '',
        'type'           => $row['doc_type']         ?? '',
        'category'       => $row['category']         ?? '',
        'department'     => $row['department']       ?? '',
        'direction'      => $row['direction']        ?? '',
        'sender'         => $row['sender']           ?? '',
        'recipient'      => $row['recipient']        ?? '',
        'assignedTo'     => $row['assigned_to']      ?? '',
        'dateRegistered' => $row['created_at']       ?? '',
        'dateTime'       => $row['doc_date']         ?? $row['created_at'] ?? '',
        'status'         => dr_map_status($row['status'] ?? 'Registered'),
        'mode'           => ucfirst($row['capture_mode'] ?? 'physical'),
        'priority'       => $row['priority']         ?? 'Normal',
        'retention'      => $row['retention']        ?? '1 Year',
        'notes'          => $row['notes']            ?? '',
        'refNumber'      => $row['ref_number']       ?? '',
        'filePath'       => $row['file_path']        ?? '',
        'fileName'       => $row['file_name']        ?? '',
        'fileExt'        => strtolower($row['file_ext'] ?? ''),
        'fileSizeKb'     => (float)($row['file_size_kb'] ?? 0),
        'aiConfidence'   => (int)($row['ai_confidence']   ?? 0),
        'needsValidation'=> (bool)($row['needs_validation'] ?? false),
        'qrConfirmed'    => false,
        'qrLogs'         => [],
        'createdBy'      => $row['created_by']       ?? '',
        'createdAt'      => $row['created_at']       ?? '',
        'updatedAt'      => $row['updated_at']       ?? '',
    ];
}

function dr_map_status(string $s): string {
    $map = [
        'Registered'  => 'Active',
        'In Transit'  => 'Active',
        'Received'    => 'Active',
        'Processing'  => 'Pending Validation',
        'Completed'   => 'Active',
        'Archived'    => 'Archived',
        'Rejected'    => 'Archived',
    ];
    return $map[$s] ?? $s;
}

function dr_unmap_status(string $s): string {
    $map = [
        'Active'             => 'Registered',
        'Pending Validation' => 'Processing',
        'Archived'           => 'Archived',
        'For Disposal'       => 'Archived',
    ];
    return $map[$s] ?? 'Registered';
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $drUserName;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET signed URL ────────────────────────────────────────────────────
        if ($api === 'file' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) dr_err('Missing id', 400);

            $rows = dr_sb('dtrs_documents', 'GET', [
                'select' => 'file_path,file_name,file_ext,assigned_to,department',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (empty($rows)) dr_err('Document not found', 404);

            // User: can only access their assigned docs
            if ($drRoleRank === 1 && ($rows[0]['assigned_to'] ?? '') !== $drUserName) {
                dr_err('Access denied', 403);
            }
            // Admin: zone restriction
            if ($drRoleRank === 3 && $drUserZone !== '' && ($rows[0]['department'] ?? '') !== $drUserZone) {
                // allow — department may differ from zone; just proceed
            }

            $filePath = $rows[0]['file_path'] ?? '';
            $fileName = $rows[0]['file_name'] ?? '';
            $fileExt  = strtolower($rows[0]['file_ext'] ?? '');
            if (!$filePath) dr_err('No file stored for this document', 404);

            $ch = curl_init(SUPABASE_URL . '/storage/v1/object/sign/dtrs-documents/' . $filePath);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
                    'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
                ],
                CURLOPT_POSTFIELDS => json_encode(['expiresIn' => 3600]),
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 400) dr_err('Could not generate file URL', 502);
            $data      = json_decode($res, true);
            $signedUrl = SUPABASE_URL . '/storage/v1' . ($data['signedURL'] ?? '');
            dr_ok(['signedUrl' => $signedUrl, 'fileName' => $fileName, 'fileExt' => $fileExt, 'filePath' => $filePath]);
        }

        // ── GET document list ─────────────────────────────────────────────────
        if ($api === 'list' && $method === 'GET') {
            $query = [
                'select' => 'id,doc_id,title,doc_type,category,department,direction,sender,recipient,assigned_to,doc_date,priority,retention,notes,ref_number,capture_mode,status,ai_confidence,needs_validation,created_by,created_at,updated_at,file_path,file_name,file_ext,file_size_kb',
                'order'  => 'created_at.desc',
            ];

            // Apply server-side data scope
            if ($drRoleRank === 1) {
                // User: only documents assigned to me (by name match)
                $query['assigned_to'] = 'eq.' . $drUserName;
            } elseif ($drRoleRank === 2 || $drRoleRank === 3) {
                // Manager or Admin: filter by zone/department
                if ($drUserZone !== '') {
                    $query['department'] = 'eq.' . $drUserZone;
                }
            }
            // Super Admin: no filter

            if (!empty($_GET['status']))     $query['status']      = 'eq.' . dr_unmap_status($_GET['status']);
            if (!empty($_GET['doc_type']))   $query['doc_type']    = 'eq.' . $_GET['doc_type'];
            if (!empty($_GET['department'])) {
                // Admin/Manager cannot override zone filter
                if ($drRoleRank >= 4) $query['department'] = 'eq.' . $_GET['department'];
            }
            if (!empty($_GET['direction']))  $query['direction']   = 'eq.' . $_GET['direction'];

            $rows = dr_sb('dtrs_documents', 'GET', $query);

            // Manager: only show Active / Pending Validation / In Transit / For Review
            if ($drRoleRank === 2) {
                $allowedStatuses = ['Active','Pending Validation','In Transit','For Review'];
                $rows = array_values(array_filter($rows, fn($r) => in_array(dr_map_status($r['status'] ?? ''), $allowedStatuses)));
            }

            $docs = array_map('dr_build', $rows);

            // Attach QR scan counts
            if (!empty($rows)) {
                $ids = array_column($rows, 'id');
                $scanRows = dr_sb('dtrs_audit_log', 'GET', [
                    'select'       => 'doc_id',
                    'action_label' => 'like.QR Scan%',
                    'doc_id'       => 'in.(' . implode(',', $ids) . ')',
                ]);
                $scanCounts = [];
                foreach ($scanRows as $sr) {
                    $scanCounts[$sr['doc_id']] = ($scanCounts[$sr['doc_id']] ?? 0) + 1;
                }
                foreach ($docs as &$doc) {
                    $dbRow = current(array_filter($rows, fn($r) => $r['doc_id'] === $doc['docId']));
                    if ($dbRow && isset($scanCounts[$dbRow['id']])) {
                        $doc['qrConfirmed'] = true;
                    }
                }
                unset($doc);
            }
            dr_ok($docs);
        }

        // ── GET single document ───────────────────────────────────────────────
        if ($api === 'get' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) dr_err('Missing id', 400);
            $rows = dr_sb('dtrs_documents', 'GET', ['select' => '*', 'id' => 'eq.' . $id, 'limit' => '1']);
            if (empty($rows)) dr_err('Document not found', 404);

            // User: own only
            if ($drRoleRank === 1 && ($rows[0]['assigned_to'] ?? '') !== $drUserName) {
                dr_err('Access denied', 403);
            }

            dr_ok(dr_build($rows[0]));
        }

        // ── GET QR scan logs ──────────────────────────────────────────────────
        if ($api === 'qrlogs' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) dr_err('Missing id', 400);
            $rows = dr_sb('dtrs_audit_log', 'GET', [
                'select'       => 'id,action_label,actor_name,note,occurred_at',
                'doc_id'       => 'eq.' . $id,
                'action_label' => 'like.QR Scan%',
                'order'        => 'occurred_at.asc',
            ]);
            dr_ok(array_map(fn($r) => [
                'ts'   => $r['occurred_at'] ?? '',
                'user' => $r['actor_name']  ?? '',
                'note' => $r['note']        ?? '',
            ], $rows));
        }

        // ── POST attach file ──────────────────────────────────────────────────
        if ($api === 'attach' && $method === 'POST') {
            if ($drRoleRank < 3) dr_err('Permission denied', 403);
            $b        = dr_body();
            $id       = (int)($b['id']        ?? 0);
            $docId    = trim($b['docId']       ?? '');
            $fileName = trim($b['fileName']    ?? '');
            $fileExt  = strtolower(trim($b['fileExt'] ?? ''));
            $b64      = $b['fileBase64']       ?? '';
            if (!$id || !$docId) dr_err('Missing document id', 400);
            if (!$b64)           dr_err('No file data provided', 400);
            $fileBytes = base64_decode($b64);
            if ($fileBytes === false) dr_err('Invalid file data', 400);
            $year        = date('Y');
            $safeName    = preg_replace('/[^A-Za-z0-9\-_]/', '', $docId);
            $ext         = $fileExt ?: pathinfo($fileName, PATHINFO_EXTENSION) ?: 'bin';
            $storagePath = $year . '/' . $safeName . '.' . $ext;
            $mimeMap = ['pdf'=>'application/pdf','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','doc'=>'application/msword','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp'];
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';
            $ch = curl_init(SUPABASE_URL . '/storage/v1/object/dtrs-documents/' . $storagePath);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_HTTPHEADER=>['Content-Type: '.$mime,'apikey: '.SUPABASE_SERVICE_ROLE_KEY,'Authorization: Bearer '.SUPABASE_SERVICE_ROLE_KEY,'x-upsert: true'],CURLOPT_POSTFIELDS=>$fileBytes]);
            $res  = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($code >= 400) { $err = json_decode($res, true); dr_err('Storage upload failed: '.($err['message']??'HTTP '.$code), 502); }
            $now = date('Y-m-d H:i:s');
            dr_sb('dtrs_documents', 'PATCH', ['id'=>'eq.'.$id], ['file_path'=>$storagePath,'file_name'=>$fileName?:($safeName.'.'.$ext),'file_ext'=>strtoupper($ext),'file_size_kb'=>round(strlen($fileBytes)/1024,1),'updated_at'=>$now]);
            dr_sb('dtrs_audit_log', 'POST', [], [['doc_id'=>$id,'action_label'=>'File Attached','actor_name'=>$actor,'actor_role'=>$drRoleName,'note'=>'File "'.($fileName?:$storagePath).'" attached.','icon'=>'bx-paperclip','css_class'=>'dc-s','is_super_admin'=>$drRoleRank>=4,'ip_address'=>$ip,'occurred_at'=>$now]]);
            $rows = dr_sb('dtrs_documents', 'GET', ['select'=>'*','id'=>'eq.'.$id,'limit'=>'1']);
            dr_ok(dr_build($rows[0]));
        }

        // ── POST update document ──────────────────────────────────────────────
        if ($api === 'update' && $method === 'POST') {
            if ($drRoleRank < 3) dr_err('Permission denied: cannot edit documents', 403);
            $b  = dr_body();
            $id = (int)($b['id'] ?? 0);
            if (!$id) dr_err('Missing document id', 400);

            // Admin: must confirm document is in their zone
            if ($drRoleRank === 3 && $drUserZone !== '') {
                $chk = dr_sb('dtrs_documents', 'GET', ['select'=>'department','id'=>'eq.'.$id,'limit'=>'1']);
                if (!empty($chk) && ($chk[0]['department'] ?? '') !== $drUserZone) {
                    dr_err('Access denied: document is outside your zone', 403);
                }
            }

            $now = date('Y-m-d H:i:s');
            $patch = ['updated_at' => $now];
            $changed = [];
            $fields = ['title'=>['title','Document Title'],'docType'=>['doc_type','Type'],'category'=>['category','Category'],'department'=>['department','Department'],'direction'=>['direction','Direction'],'sender'=>['sender','Sender'],'recipient'=>['recipient','Recipient'],'assignedTo'=>['assigned_to','Assigned To'],'docDate'=>['doc_date','Document Date'],'priority'=>['priority','Priority'],'retention'=>['retention','Retention'],'notes'=>['notes','Notes']];
            foreach ($fields as $key => [$col, $label]) {
                if (array_key_exists($key, $b)) { $patch[$col] = trim($b[$key] ?? ''); $changed[] = $label; }
            }
            if (count($patch) <= 1) dr_err('No fields to update', 400);
            if (isset($patch['direction']) && !in_array($patch['direction'], ['Incoming','Outgoing'], true)) dr_err('Invalid direction', 400);
            dr_sb('dtrs_documents', 'PATCH', ['id'=>'eq.'.$id], $patch);
            dr_sb('dtrs_audit_log', 'POST', [], [['doc_id'=>$id,'action_label'=>'Document Updated','actor_name'=>$actor,'actor_role'=>$drRoleName,'note'=>'Updated: '.implode(', ',$changed).'.','icon'=>'bx-edit','css_class'=>'dc-s','is_super_admin'=>$drRoleRank>=4,'ip_address'=>$ip,'occurred_at'=>$now]]);
            $rows = dr_sb('dtrs_documents', 'GET', ['select'=>'*','id'=>'eq.'.$id,'limit'=>'1']);
            if (empty($rows)) dr_err('Document not found after update', 404);
            dr_ok(dr_build($rows[0]));
        }

        // ── POST update own status/notes (User role) ──────────────────────────
        if ($api === 'update_own' && $method === 'POST') {
            $b  = dr_body();
            $id = (int)($b['id'] ?? 0);
            if (!$id) dr_err('Missing document id', 400);

            // Verify ownership
            $chk = dr_sb('dtrs_documents', 'GET', ['select'=>'assigned_to','id'=>'eq.'.$id,'limit'=>'1']);
            if (empty($chk)) dr_err('Document not found', 404);
            if ($drRoleRank < 3 && ($chk[0]['assigned_to'] ?? '') !== $drUserName) {
                dr_err('Access denied: you are not assigned to this document', 403);
            }

            $now = date('Y-m-d H:i:s');
            $patch = ['updated_at' => $now];
            $changed = [];

            $allowed = ['status'=>['status','Status'],'notes'=>['notes','Notes']];
            foreach ($allowed as $key => [$col, $label]) {
                if (array_key_exists($key, $b)) {
                    $val = trim($b[$key] ?? '');
                    // Map display status to DB status if needed
                    if ($col === 'status') $val = dr_unmap_status($val) ?: $val;
                    $patch[$col] = $val;
                    $changed[] = $label;
                }
            }
            if (count($patch) <= 1) dr_err('No fields to update', 400);
            dr_sb('dtrs_documents', 'PATCH', ['id'=>'eq.'.$id], $patch);
            dr_sb('dtrs_audit_log', 'POST', [], [['doc_id'=>$id,'action_label'=>'Status Updated by Assignee','actor_name'=>$actor,'actor_role'=>$drRoleName,'note'=>'Self-service update: '.implode(', ',$changed).'.','icon'=>'bx-check-circle','css_class'=>'dc-s','is_super_admin'=>false,'ip_address'=>$ip,'occurred_at'=>$now]]);
            $rows = dr_sb('dtrs_documents', 'GET', ['select'=>'*','id'=>'eq.'.$id,'limit'=>'1']);
            dr_ok(dr_build($rows[0]));
        }

        // ── POST flag delay (Manager+) ────────────────────────────────────────
        if ($api === 'flag_delay' && $method === 'POST') {
            if ($drRoleRank < 2) dr_err('Permission denied', 403);
            $b   = dr_body();
            $id  = (int)($b['id']   ?? 0);
            $note= trim($b['note']  ?? 'Delay flagged by manager.');
            if (!$id) dr_err('Missing id', 400);
            $now = date('Y-m-d H:i:s');
            dr_sb('dtrs_audit_log', 'POST', [], [['doc_id'=>$id,'action_label'=>'Delay Flagged','actor_name'=>$actor,'actor_role'=>$drRoleName,'note'=>$note,'icon'=>'bx-flag','css_class'=>'dc-o','is_super_admin'=>false,'ip_address'=>$ip,'occurred_at'=>$now]]);
            dr_ok(['id'=>$id,'flagged'=>true]);
        }

        // ── POST archive ──────────────────────────────────────────────────────
        if ($api === 'archive' && $method === 'POST') {
            if ($drRoleRank < 3) dr_err('Permission denied: cannot archive documents', 403);
            $b  = dr_body();
            $id = (int)($b['id'] ?? 0);
            if (!$id) dr_err('Missing id', 400);

            // Admin zone check
            if ($drRoleRank === 3 && $drUserZone !== '') {
                $chk = dr_sb('dtrs_documents', 'GET', ['select'=>'department','id'=>'eq.'.$id,'limit'=>'1']);
                if (!empty($chk) && ($chk[0]['department'] ?? '') !== $drUserZone) {
                    dr_err('Access denied: document is outside your zone', 403);
                }
            }

            $now = date('Y-m-d H:i:s');
            dr_sb('dtrs_documents', 'PATCH', ['id'=>'eq.'.$id], ['status'=>'Archived','updated_at'=>$now]);
            dr_sb('dtrs_audit_log', 'POST', [], [['doc_id'=>$id,'action_label'=>'Document Archived','actor_name'=>$actor,'actor_role'=>$drRoleName,'note'=>trim($b['note']??'Archived by '.$actor.'.'), 'icon'=>'bx-archive','css_class'=>'dc-x','is_super_admin'=>$drRoleRank>=4,'ip_address'=>$ip,'occurred_at'=>$now]]);
            dr_ok(['id'=>$id,'status'=>'Archived']);
        }

        // ── DELETE document (SA only) ─────────────────────────────────────────
        if ($api === 'delete' && $method === 'POST') {
            if ($drRoleRank < 4) dr_err('Permission denied: only Super Admin can permanently delete documents', 403);
            $b  = dr_body();
            $id = (int)($b['id'] ?? 0);
            if (!$id) dr_err('Missing id', 400);
            $now = date('Y-m-d H:i:s');
            dr_sb('dtrs_audit_log', 'POST', [], [['doc_id'=>$id,'action_label'=>'Document Permanently Deleted','actor_name'=>$actor,'actor_role'=>'Super Admin','note'=>'Deleted by '.$actor.'. Record removed.','icon'=>'bx-trash','css_class'=>'dc-r','is_super_admin'=>true,'ip_address'=>$ip,'occurred_at'=>$now]]);
            dr_sb('dtrs_documents', 'DELETE', ['id'=>'eq.'.$id]);
            dr_ok(['id'=>$id,'deleted'=>true]);
        }

        // ── POST log QR scan ──────────────────────────────────────────────────
        if ($api === 'qrscan' && $method === 'POST') {
            $b    = dr_body();
            $id   = (int)($b['id']   ?? 0);
            $note = trim($b['note']  ?? 'QR scanned and confirmed.');
            if (!$id) dr_err('Missing id', 400);

            // User: must be assigned to this doc
            if ($drRoleRank === 1) {
                $chk = dr_sb('dtrs_documents', 'GET', ['select'=>'assigned_to','id'=>'eq.'.$id,'limit'=>'1']);
                if (empty($chk) || ($chk[0]['assigned_to'] ?? '') !== $drUserName) {
                    dr_err('Access denied', 403);
                }
            }

            $now = date('Y-m-d H:i:s');
            dr_sb('dtrs_audit_log', 'POST', [], [['doc_id'=>$id,'action_label'=>'QR Scan Confirmed','actor_name'=>$actor,'actor_role'=>$drRoleName,'note'=>$note,'icon'=>'bx-qr-scan','css_class'=>'dc-a','is_super_admin'=>$drRoleRank>=4,'ip_address'=>$ip,'occurred_at'=>$now]]);
            dr_ok(['id'=>$id,'logged'=>true,'ts'=>$now,'user'=>$actor,'note'=>$note]);
        }

        // ── POST bulk action ──────────────────────────────────────────────────
        if ($api === 'bulk' && $method === 'POST') {
            if ($drRoleRank < 3) dr_err('Permission denied', 403);
            $b      = dr_body();
            $ids    = array_map('intval', $b['ids']    ?? []);
            $action = trim($b['action'] ?? '');
            if (empty($ids)) dr_err('No IDs provided', 400);
            $now = date('Y-m-d H:i:s');
            $done = 0;
            foreach ($ids as $id) {
                if ($action === 'archive') {
                    dr_sb('dtrs_documents', 'PATCH', ['id'=>'eq.'.$id], ['status'=>'Archived','updated_at'=>$now]);
                    dr_sb('dtrs_audit_log', 'POST', [], [['doc_id'=>$id,'action_label'=>'Bulk Archived','actor_name'=>$actor,'actor_role'=>$drRoleName,'note'=>'Bulk archive by '.$actor,'icon'=>'bx-archive','css_class'=>'dc-x','is_super_admin'=>$drRoleRank>=4,'ip_address'=>$ip,'occurred_at'=>$now]]);
                    $done++;
                } elseif ($action === 'delete' && $drRoleRank >= 4) {
                    dr_sb('dtrs_documents', 'DELETE', ['id'=>'eq.'.$id]);
                    $done++;
                }
            }
            dr_ok(['done'=>$done]);
        }

        dr_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        dr_err('Server error: ' . $e->getMessage(), 500);
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
    <title>Document Registry — DTRS</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
    <link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
    <link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --primary:#2E7D32; --primary-dark:#1B5E20; --primary-lt:#81C784;
      --bg:#F4F6F5; --surface:#FFFFFF;
      --border:rgba(46,125,50,.13); --border-md:rgba(46,125,50,.24);
      --text-1:#1A2E1C; --text-2:#4A6350; --text-3:#9EB0A2;
      --hover:rgba(46,125,50,.05);
      --shadow-sm:0 1px 4px rgba(46,125,50,.08);
      --shadow-md:0 4px 16px rgba(46,125,50,.11);
      --shadow-lg:0 20px 60px rgba(0,0,0,.18);
      --red:#DC2626; --amber:#D97706; --blue:#2563EB; --teal:#0D9488; --purple:#7C3AED;
      --rad:12px; --tr:all .18s ease;
    }
    .main-content { padding:32px 24px; min-height:100vh; background:var(--bg); }
    .page { max-width:1600px; margin:0 auto; }

    /* ── ROLE BADGE ── */
    .role-tag { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; letter-spacing:.04em; vertical-align:middle; margin-left:10px; }
    .role-tag.sa     { background:#E8F5E9; color:var(--primary);  border:1px solid rgba(46,125,50,.25); }
    .role-tag.admin  { background:#EFF6FF; color:var(--blue);     border:1px solid rgba(37,99,235,.2); }
    .role-tag.mgr    { background:#FEF3C7; color:var(--amber);    border:1px solid rgba(217,119,6,.2); }
    .role-tag.user   { background:#F3F4F6; color:#374151;         border:1px solid rgba(0,0,0,.1); }

    /* ── ACCESS NOTICE ── */
    .access-notice { display:flex; align-items:flex-start; gap:10px; border-radius:12px; padding:12px 16px; margin-bottom:20px; animation:fadeUp .4s .05s both; font-size:12.5px; line-height:1.55; }
    .access-notice i { font-size:18px; flex-shrink:0; margin-top:1px; }
    .access-notice.amber { background:linear-gradient(135deg,#FEF3C7,#FFFBEB); border:1px solid rgba(217,119,6,.3); color:#92400E; }
    .access-notice.amber i { color:var(--amber); }
    .access-notice.amber strong { color:#78350F; }
    .access-notice.blue  { background:linear-gradient(135deg,#EFF6FF,#F0F9FF); border:1px solid rgba(37,99,235,.2); color:#1E3A5F; }
    .access-notice.blue  i { color:var(--blue); }
    .access-notice.blue strong { color:#1E40AF; }
    .access-notice.slate { background:#F8FAFC; border:1px solid rgba(0,0,0,.1); color:#374151; }
    .access-notice.slate i { color:#6B7280; }

    .page-hdr { display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:26px; animation:fadeUp .4s both; }
    .eyebrow { font-size:11px; font-weight:600; letter-spacing:.14em; text-transform:uppercase; color:var(--primary); margin-bottom:4px; }
    .page-hdr h1 { font-size:26px; font-weight:800; color:var(--text-1); line-height:1.15; }
    .page-hdr-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .btn { display:inline-flex; align-items:center; gap:7px; font-family:'Inter',sans-serif; font-size:13px; font-weight:600; padding:9px 18px; border-radius:10px; border:none; cursor:pointer; transition:var(--tr); white-space:nowrap; text-decoration:none; }
    .btn-primary { background:var(--primary); color:#fff; box-shadow:0 2px 8px rgba(46,125,50,.3); }
    .btn-primary:hover { background:var(--primary-dark); transform:translateY(-1px); }
    .btn-ghost { background:var(--surface); color:var(--text-2); border:1px solid var(--border-md); }
    .btn-ghost:hover { background:var(--hover); color:var(--text-1); }
    .btn-danger { background:var(--red); color:#fff; }
    .btn-danger:hover { background:#B91C1C; transform:translateY(-1px); }
    .btn-amber { background:var(--amber); color:#fff; }
    .btn-amber:hover { background:#B45309; transform:translateY(-1px); }
    .btn-teal { background:var(--teal); color:#fff; }
    .btn-teal:hover { background:#0F766E; transform:translateY(-1px); }
    .btn-sm { font-size:12px; padding:7px 14px; }
    .btn-xs { font-size:11px; padding:5px 10px; border-radius:8px; }
    .btn:disabled { opacity:.45; pointer-events:none; }

    .stats-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:20px; animation:fadeUp .4s .05s both; }
    .stat-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--rad); padding:14px 16px; box-shadow:var(--shadow-sm); display:flex; align-items:center; gap:12px; }
    .stat-ic { width:38px; height:38px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
    .ic-g{background:#E8F5E9;color:var(--primary)} .ic-a{background:#FEF3C7;color:var(--amber)} .ic-r{background:#FEE2E2;color:var(--red)} .ic-b{background:#EFF6FF;color:var(--blue)} .ic-t{background:#CCFBF1;color:var(--teal)} .ic-p{background:#EDE9FE;color:var(--purple)}
    .stat-v { font-size:20px; font-weight:800; color:var(--text-1); line-height:1.1; }
    .stat-l { font-size:11px; color:var(--text-2); margin-top:2px; }

    .toolbar { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:16px 20px; box-shadow:var(--shadow-md); margin-bottom:16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; animation:fadeUp .4s .08s both; }
    .search-wrap { position:relative; flex:1; min-width:220px; }
    .search-wrap i { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:16px; pointer-events:none; }
    .search-input { width:100%; font-family:'Inter',sans-serif; font-size:13px; padding:9px 12px 9px 34px; border:1.5px solid var(--border-md); border-radius:10px; background:var(--bg); color:var(--text-1); outline:none; transition:var(--tr); }
    .search-input:focus { border-color:var(--primary); background:var(--surface); box-shadow:0 0 0 3px rgba(46,125,50,.1); }
    .filter-select { font-family:'Inter',sans-serif; font-size:13px; font-weight:500; padding:9px 30px 9px 12px; border:1.5px solid var(--border-md); border-radius:10px; background:var(--bg); color:var(--text-1); outline:none; cursor:pointer; appearance:none; transition:var(--tr); background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; }
    .filter-select:focus { border-color:var(--primary); background-color:var(--surface); }

    .bulk-bar { background:var(--primary); color:#fff; border-radius:12px; padding:12px 20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:12px; font-size:13px; font-weight:600; }
    .bulk-bar.hidden { display:none; }
    .bulk-count { background:rgba(255,255,255,.2); border-radius:6px; padding:2px 8px; font-size:12px; }
    .bulk-sep { width:1px; height:20px; background:rgba(255,255,255,.3); }
    .bulk-btn { display:inline-flex; align-items:center; gap:6px; font-family:'Inter',sans-serif; font-size:12px; font-weight:600; padding:6px 14px; border-radius:8px; border:none; cursor:pointer; background:rgba(255,255,255,.15); color:#fff; transition:var(--tr); }
    .bulk-btn:hover { background:rgba(255,255,255,.28); }
    .bulk-cls { margin-left:auto; background:none; border:none; color:rgba(255,255,255,.7); cursor:pointer; font-size:18px; display:flex; align-items:center; }

    .table-card { background:var(--surface); border:1px solid var(--border); border-radius:16px; overflow:hidden; box-shadow:var(--shadow-md); animation:fadeUp .4s .1s both; }
    .table-card-hdr { display:flex; align-items:center; justify-content:space-between; padding:16px 22px 14px; border-bottom:1px solid var(--border); background:var(--bg); gap:12px; flex-wrap:wrap; }
    .table-card-title { font-size:14px; font-weight:700; color:var(--text-1); display:flex; align-items:center; gap:8px; }
    .table-meta { font-size:12px; color:var(--text-2); }
    .tbl-wrap { overflow-x:auto; }
    table.doc-table { width:100%; border-collapse:collapse; font-size:13px; }
    table.doc-table thead th { font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--text-2); padding:11px 14px; text-align:left; background:var(--bg); border-bottom:1px solid var(--border); white-space:nowrap; cursor:pointer; user-select:none; transition:color .15s; }
    table.doc-table thead th:hover { color:var(--primary); }
    table.doc-table thead th.sorted { color:var(--primary); }
    table.doc-table thead th .si { margin-left:4px; opacity:.35; font-size:13px; vertical-align:middle; }
    table.doc-table thead th.sorted .si { opacity:1; }
    table.doc-table thead th:first-child { padding-left:18px; }
    table.doc-table thead th:last-child  { padding-right:18px; }
    table.doc-table thead th.no-sort { cursor:default; }
    table.doc-table thead th.no-sort:hover { color:var(--text-2); }
    table.doc-table tbody tr { border-bottom:1px solid var(--border); transition:background .12s; cursor:pointer; }
    table.doc-table tbody tr:last-child { border-bottom:none; }
    table.doc-table tbody tr:hover { background:rgba(46,125,50,.04); }
    table.doc-table tbody tr.selected-row { background:rgba(46,125,50,.07); }
    table.doc-table tbody td { padding:12px 14px; vertical-align:middle; }
    table.doc-table tbody td:first-child { padding-left:18px; }
    table.doc-table tbody td:last-child  { padding-right:18px; }
    .cb-wrap { display:flex; align-items:center; }
    .cb-wrap input[type=checkbox] { width:15px; height:15px; accent-color:var(--primary); cursor:pointer; }
    .doc-id { font-family:'DM Mono',monospace; font-size:11px; color:var(--primary); font-weight:500; white-space:nowrap; }
    .doc-name-cell .name { font-weight:600; color:var(--text-1); }
    .doc-name-cell .sub  { font-size:11px; color:var(--text-3); margin-top:2px; }
    .type-badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:600; padding:3px 9px; border-radius:7px; border:1px solid var(--border); white-space:nowrap; }
    .status-chip { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 9px; border-radius:8px; white-space:nowrap; }
    .s-active{background:#E8F5E9;color:#1B5E20} .s-archived{background:#FEE2E2;color:#991B1B} .s-pending{background:#FEF3C7;color:#92400E} .s-disposal{background:#EDE9FE;color:#5B21B6}
    .dir-badge { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 9px; border-radius:8px; white-space:nowrap; }
    .dir-in{background:#EFF6FF;color:#1D4ED8} .dir-out{background:#FFF7ED;color:#C2410C}
    .mode-badge { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:600; padding:2px 8px; border-radius:6px; white-space:nowrap; }
    .mode-physical{background:#F0FDF4;color:#15803D;border:1px solid #BBF7D0} .mode-digital{background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE}
    .qr-chip { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:600; padding:3px 9px; border-radius:8px; }
    .qr-confirmed{background:#E8F5E9;color:#1B5E20}
    /* My-role badge for User view */
    .my-role-badge { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; padding:2px 8px; border-radius:6px; background:#E0E7FF; color:#3730A3; border:1px solid #C7D2FE; }
    .row-actions { display:flex; gap:5px; opacity:0; transition:opacity .15s; }
    table.doc-table tbody tr:hover .row-actions { opacity:1; }
    @media(max-width:900px){ .row-actions{opacity:1} }
    .empty-state { padding:60px 20px; text-align:center; color:var(--text-3); }
    .empty-state i { font-size:48px; display:block; margin-bottom:12px; color:#C8E6C9; }
    .skel { background:linear-gradient(90deg,var(--border) 25%,var(--hover) 50%,var(--border) 75%); background-size:200%; animation:shimmer 1.5s infinite; border-radius:6px; }
    @keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }

    /* ── OVERLAYS ── */
    .overlay { position:fixed; inset:0; background:rgba(10,31,14,.55); z-index:2000; display:flex; align-items:center; justify-content:center; padding:20px; opacity:0; pointer-events:none; transition:opacity .22s ease; backdrop-filter:blur(4px); }
    .overlay.open { opacity:1; pointer-events:all; }
    .detail-panel { background:var(--surface); border-radius:20px; width:100%; max-width:920px; max-height:88vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 32px 80px rgba(0,0,0,.28),0 0 0 1px rgba(46,125,50,.12); transform:translateY(24px) scale(.97); transition:transform .28s cubic-bezier(.34,1.56,.64,1); }
    .overlay.open .detail-panel { transform:translateY(0) scale(1); }
    .detail-header { padding:24px 28px 20px; border-bottom:2px solid var(--border); background:linear-gradient(135deg,#F0F7F0 0%,#E8F5E9 100%); flex-shrink:0; display:flex; align-items:flex-start; justify-content:space-between; gap:16px; }
    .detail-header-left { display:flex; flex-direction:column; gap:8px; }
    .detail-doc-id { font-family:'DM Mono',monospace; font-size:11px; color:var(--primary); font-weight:600; background:rgba(46,125,50,.1); display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:6px; border:1px solid rgba(46,125,50,.2); width:fit-content; }
    .detail-title { font-size:20px; font-weight:800; color:var(--text-1); line-height:1.25; }
    .detail-chips { display:flex; gap:8px; flex-wrap:wrap; margin-top:2px; }
    .close-btn { width:34px; height:34px; border-radius:9px; border:1px solid var(--border-md); background:var(--surface); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:var(--tr); flex-shrink:0; color:var(--text-2); font-size:18px; }
    .close-btn:hover { background:#FEE2E2; color:var(--red); border-color:#FECACA; }
    .detail-body { overflow-y:auto; flex:1; }
    .meta-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:0; padding:20px 26px; border-bottom:1px solid var(--border); }
    .meta-item { padding:8px 0; }
    .meta-label { font-size:10px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--text-3); margin-bottom:4px; }
    .meta-value { font-size:13px; font-weight:600; color:var(--text-1); }
    .meta-value.mono { font-family:'DM Mono',monospace; font-weight:400; font-size:12px; }
    .qr-section { padding:20px 26px; }
    .qr-section-title { font-size:13px; font-weight:700; color:var(--text-1); margin-bottom:14px; display:flex; align-items:center; gap:8px; }
    .qr-log-list { display:flex; flex-direction:column; gap:0; }
    .qr-log-item { display:flex; align-items:center; gap:14px; padding:12px 0; border-bottom:1px solid var(--border); animation:rowIn .2s both; }
    .qr-log-item:last-child { border-bottom:none; }
    .qr-ic { width:32px; height:32px; border-radius:8px; background:#E8F5E9; color:var(--primary); display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0; }
    .qr-log-ts { font-family:'DM Mono',monospace; font-size:11px; color:var(--text-3); margin-left:auto; white-space:nowrap; }
    .qr-log-user { font-size:13px; font-weight:600; color:var(--text-1); }
    .qr-log-note { font-size:12px; color:var(--text-2); margin-top:2px; }
    .detail-footer { padding:18px 28px; border-top:2px solid var(--border); background:#FAFCFA; display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; flex-wrap:wrap; }

    /* User self-service status update box */
    .self-service-box { margin:16px 26px; background:linear-gradient(135deg,#EFF6FF,#F0F9FF); border:1px solid rgba(37,99,235,.2); border-radius:12px; padding:16px; }
    .self-service-box h4 { font-size:13px; font-weight:700; color:#1E40AF; margin-bottom:12px; display:flex; align-items:center; gap:7px; }
    .ss-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
    .ss-fg { display:flex; flex-direction:column; gap:5px; flex:1; min-width:140px; }
    .ss-fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--text-3); }
    .ss-inp, .ss-sel { font-family:'Inter',sans-serif; font-size:13px; padding:9px 12px; border:1.5px solid var(--border-md); border-radius:10px; background:var(--surface); color:var(--text-1); outline:none; transition:var(--tr); width:100%; }
    .ss-inp:focus, .ss-sel:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(46,125,50,.1); }
    .ss-sel { appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; padding-right:28px; cursor:pointer; }

    /* Flag delay box for Manager */
    .flag-box { margin:16px 26px; background:linear-gradient(135deg,#FEF3C7,#FFFBEB); border:1px solid rgba(217,119,6,.3); border-radius:12px; padding:16px; }
    .flag-box h4 { font-size:13px; font-weight:700; color:#92400E; margin-bottom:10px; display:flex; align-items:center; gap:7px; }
    .flag-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }

    .edit-panel { background:var(--surface); border-radius:20px; width:100%; max-width:680px; max-height:90vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 32px 80px rgba(0,0,0,.28),0 0 0 1px rgba(46,125,50,.12); transform:translateY(24px) scale(.97); transition:transform .28s cubic-bezier(.34,1.56,.64,1); }
    .overlay.open .edit-panel { transform:translateY(0) scale(1); }
    .edit-hdr { padding:22px 26px 18px; border-bottom:2px solid var(--border); background:linear-gradient(135deg,#F0F7F0 0%,#E8F5E9 100%); flex-shrink:0; display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .edit-hdr-left { display:flex; flex-direction:column; gap:4px; }
    .edit-hdr-doc-id { font-family:'DM Mono',monospace; font-size:11px; color:var(--primary); font-weight:600; background:rgba(46,125,50,.1); display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:6px; border:1px solid rgba(46,125,50,.2); width:fit-content; }
    .edit-hdr h3 { font-size:17px; font-weight:800; color:var(--text-1); }
    .edit-body { overflow-y:auto; flex:1; padding:22px 26px; }
    .edit-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px 18px; }
    .edit-grid .full { grid-column:1/-1; }
    .ef-group { display:flex; flex-direction:column; gap:5px; }
    .ef-label { font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--text-3); }
    .ef-input, .ef-select, .ef-textarea { font-family:'Inter',sans-serif; font-size:13px; font-weight:500; padding:9px 12px; border:1.5px solid var(--border-md); border-radius:10px; background:var(--bg); color:var(--text-1); outline:none; transition:border-color .15s,box-shadow .15s; width:100%; box-sizing:border-box; }
    .ef-input:focus, .ef-select:focus, .ef-textarea:focus { border-color:var(--primary); background:var(--surface); box-shadow:0 0 0 3px rgba(46,125,50,.1); }
    .ef-textarea { resize:vertical; min-height:72px; }
    .ef-select { appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; padding-right:28px; cursor:pointer; }
    .edit-footer { padding:16px 26px; border-top:2px solid var(--border); background:#FAFCFA; display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; }
    .edit-err { background:#FEE2E2; border:1px solid #FECACA; color:#991B1B; font-size:12px; border-radius:8px; padding:10px 14px; margin-bottom:14px; display:none; }
    .edit-err.show { display:block; }
    .file-viewer-block { border-bottom:1px solid var(--border); }
    .fv-header { display:flex; align-items:center; gap:10px; padding:14px 26px; background:var(--bg); border-bottom:1px solid var(--border); flex-wrap:wrap; }
    .fv-header span { font-size:13px; font-weight:600; color:var(--text-1); }
    .fv-ext-badge { background:var(--primary); color:#fff; font-size:10px; font-weight:700; padding:2px 7px; border-radius:5px; letter-spacing:.06em; text-transform:uppercase; }
    .fv-body { padding:16px 26px; min-height:80px; }
    .fv-loading { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px; color:var(--text-2); font-size:13px; padding:32px 0; }
    .fv-unsupported { text-align:center; padding:24px 0; display:flex; flex-direction:column; align-items:center; gap:10px; }
    .fv-unsupported p { font-size:13px; color:var(--text-3); }
    .fv-none { text-align:center; padding:24px 26px; }
    .confirm-modal { background:var(--surface); border-radius:20px; width:100%; max-width:400px; padding:32px; box-shadow:0 32px 80px rgba(0,0,0,.28),0 0 0 1px rgba(46,125,50,.1); text-align:center; transform:translateY(24px) scale(.97); transition:transform .28s cubic-bezier(.34,1.56,.64,1); }
    .overlay.open .confirm-modal { transform:translateY(0) scale(1); }
    .confirm-modal .cm-icon { font-size:46px; margin-bottom:14px; }
    .confirm-modal h3 { font-size:17px; font-weight:800; color:var(--text-1); margin-bottom:10px; }
    .confirm-modal p { font-size:13px; color:var(--text-2); line-height:1.65; margin-bottom:24px; }
    .confirm-modal .cm-btns { display:flex; gap:10px; justify-content:center; }
    .detail-qr-block { padding:20px 26px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
    .detail-qr-canvas-wrap { background:#fff; border:1.5px solid var(--border-md); border-radius:12px; padding:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
    .detail-qr-canvas-wrap canvas, .detail-qr-canvas-wrap img { display:block; border-radius:4px; }
    .detail-qr-info { flex:1; min-width:160px; }
    .detail-qr-info h4 { font-size:13px; font-weight:700; color:var(--text-1); margin-bottom:6px; display:flex; align-items:center; gap:7px; }
    .detail-qr-info p  { font-size:12px; color:var(--text-2); line-height:1.6; margin-bottom:10px; }
    .qr-thumb-wrap { width:36px; height:36px; border:1px solid var(--border-md); border-radius:7px; overflow:hidden; background:#fff; cursor:pointer; transition:var(--tr); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .qr-thumb-wrap:hover { border-color:var(--primary); box-shadow:0 0 0 3px rgba(46,125,50,.12); transform:scale(1.06); }
    .qr-thumb-wrap canvas, .qr-thumb-wrap img { width:32px !important; height:32px !important; display:block; }

    /* QR SCANNER */
    #qrOverlay { position:fixed; inset:0; background:rgba(5,20,8,.82); z-index:3000; display:flex; align-items:center; justify-content:center; padding:20px; overflow:hidden; opacity:0; pointer-events:none; transition:opacity .25s ease; backdrop-filter:blur(6px); }
    #qrOverlay.open { opacity:1; pointer-events:all; }
    .qr-modal { background:#0D1F10; border:1px solid rgba(46,125,50,.3); border-radius:20px; width:100%; max-width:480px; max-height:min(680px,90vh); display:flex; flex-direction:column; overflow:hidden; flex-shrink:0; box-shadow:0 32px 80px rgba(0,0,0,.5),0 0 0 1px rgba(46,125,50,.2); transform:translateY(20px) scale(.97); transition:transform .3s cubic-bezier(.34,1.56,.64,1); }
    #qrOverlay.open .qr-modal { transform:translateY(0) scale(1); }
    .qr-modal-hdr { padding:12px 20px 14px; border-bottom:1px solid rgba(46,125,50,.2); display:flex; align-items:center; justify-content:space-between; background:linear-gradient(135deg,rgba(46,125,50,.15) 0%,transparent 100%); flex-shrink:0; }
    .qr-modal-title { display:flex; align-items:center; gap:10px; font-size:15px; font-weight:800; color:#E8F5E9; }
    .qr-modal-title i { color:var(--primary-lt); font-size:20px; }
    .qr-modal-sub { font-size:11px; color:rgba(255,255,255,.45); margin-top:2px; }
    .qr-cls-btn { width:34px; height:34px; border-radius:8px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.08); color:rgba(255,255,255,.6); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:20px; transition:var(--tr); flex-shrink:0; }
    .qr-modal-body { flex:1; overflow-y:auto; overflow-x:hidden; display:flex; flex-direction:column; }
    .qr-cam-select { padding:10px 16px 4px; display:flex; align-items:center; gap:8px; flex-shrink:0; position:relative; }
    .qr-cam-display { flex:1; display:flex; align-items:center; justify-content:space-between; gap:8px; font-family:'Inter',sans-serif; font-size:13px; padding:9px 12px; border-radius:8px; border:1px solid rgba(46,125,50,.25); background:rgba(255,255,255,.06); color:#E8F5E9; cursor:pointer; user-select:none; min-width:0; overflow:hidden; }
    .qr-cam-display span { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1; }
    .qr-cam-sel-real { position:absolute; left:16px; top:10px; right:calc(48px + 8px + 16px); bottom:4px; opacity:0; cursor:pointer; font-size:16px; z-index:2; }
    .qr-torch-btn { width:40px; height:40px; border-radius:8px; border:1px solid rgba(46,125,50,.25); background:rgba(255,255,255,.06); color:rgba(255,255,255,.6); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:18px; transition:var(--tr); flex-shrink:0; }
    .qr-torch-btn.on { background:rgba(255,200,0,.2); color:#FCD34D; border-color:rgba(253,211,77,.3); }
    .qr-viewport { position:relative; width:100%; height:300px; background:#060F07; overflow:hidden; flex-shrink:0; margin-top:8px; }
    #qrVideo { width:100%; height:100%; object-fit:cover; display:block; }
    .scan-frame { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; pointer-events:none; }
    .scan-box { width:220px; height:220px; position:relative; }
    .scan-box::before,.scan-box::after,.scan-box .sb-bl,.scan-box .sb-br { content:''; position:absolute; width:26px; height:26px; border-color:#81C784; border-style:solid; }
    .scan-box::before  { top:0;    left:0;  border-width:3px 0 0 3px; border-radius:4px 0 0 0; }
    .scan-box::after   { top:0;    right:0; border-width:3px 3px 0 0; border-radius:0 4px 0 0; }
    .scan-box .sb-bl   { bottom:0; left:0;  border-width:0 0 3px 3px; border-radius:0 0 0 4px; }
    .scan-box .sb-br   { bottom:0; right:0; border-width:0 3px 3px 0; border-radius:0 0 4px 0; }
    .scan-line { position:absolute; left:4px; right:4px; top:4px; height:2px; background:linear-gradient(90deg,transparent 0%,#4ADE80 40%,#86EFAC 50%,#4ADE80 60%,transparent 100%); border-radius:2px; animation:scanLine 2s ease-in-out infinite; box-shadow:0 0 8px rgba(74,222,128,.6); }
    @keyframes scanLine { 0%{top:4px;opacity:0} 10%{opacity:1} 90%{opacity:1} 100%{top:calc(100% - 6px);opacity:0} }
    .scan-vignette { position:absolute; inset:0; background:radial-gradient(ellipse at center,transparent 28%,rgba(0,0,0,.65) 75%); pointer-events:none; }
    #qrNoCam { display:flex; position:absolute; inset:0; background:#060F07; flex-direction:column; align-items:center; justify-content:center; gap:10px; color:rgba(255,255,255,.4); padding:20px; text-align:center; z-index:2; }
    .qr-status-strip { padding:10px 18px; background:rgba(0,0,0,.4); border-top:1px solid rgba(255,255,255,.06); display:flex; align-items:center; gap:10px; min-height:42px; flex-shrink:0; }
    .qr-status-dot { width:8px; height:8px; border-radius:50%; background:var(--amber); flex-shrink:0; animation:pulse 1.4s ease-in-out infinite; }
    .qr-status-dot.scanning { background:#4ADE80; } .qr-status-dot.found { background:#4ADE80; animation:none; } .qr-status-dot.error { background:var(--red); animation:none; }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.7)} }
    .qr-status-txt { font-size:12px; color:rgba(255,255,255,.7); font-weight:500; }
    .qr-result-card { margin:10px 16px; background:rgba(46,125,50,.15); border:1px solid rgba(46,125,50,.35); border-radius:14px; padding:16px; display:none; animation:fadeUp .3s both; flex-shrink:0; }
    .qr-result-card.show { display:block; }
    .qr-result-card.error-card { background:rgba(220,38,38,.12); border-color:rgba(220,38,38,.3); }
    .qr-rc-label { font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#81C784; margin-bottom:8px; }
    .qr-rc-label.err { color:#FCA5A5; }
    .qr-rc-id   { font-family:'DM Mono',monospace; font-size:13px; color:#E8F5E9; font-weight:600; margin-bottom:6px; }
    .qr-rc-name { font-size:14px; font-weight:700; color:#fff; margin-bottom:4px; }
    .qr-rc-meta { font-size:12px; color:rgba(255,255,255,.55); margin-bottom:12px; display:flex; gap:10px; flex-wrap:wrap; }
    .qr-rc-actions { display:flex; gap:8px; }
    .qr-rc-btn { flex:1; padding:12px; border-radius:10px; border:none; cursor:pointer; font-family:'Inter',sans-serif; font-size:13px; font-weight:700; transition:var(--tr); display:flex; align-items:center; justify-content:center; gap:6px; }
    .qr-rc-confirm { background:var(--primary); color:#fff; }
    .qr-rc-view { background:rgba(255,255,255,.1); color:#E8F5E9; border:1px solid rgba(255,255,255,.15); }
    .qr-manual { padding:12px 16px 16px; border-top:1px solid rgba(46,125,50,.15); background:rgba(0,0,0,.25); margin-top:auto; flex-shrink:0; }
    .qr-manual-label { font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:rgba(255,255,255,.35); margin-bottom:8px; }
    .qr-manual-row { display:flex; gap:8px; }
    .qr-manual-input { flex:1; font-family:'DM Mono',monospace; font-size:16px; padding:11px 12px; border-radius:10px; border:1px solid rgba(46,125,50,.3); background:rgba(255,255,255,.06); color:#E8F5E9; outline:none; transition:var(--tr); }
    .qr-manual-input::placeholder { color:rgba(255,255,255,.2); }
    .qr-manual-input:focus { border-color:var(--primary-lt); box-shadow:0 0 0 3px rgba(129,199,132,.15); }
    .qr-manual-btn { padding:11px 18px; border-radius:10px; border:none; cursor:pointer; background:var(--primary); color:#fff; font-family:'Inter',sans-serif; font-size:14px; font-weight:700; transition:var(--tr); display:flex; align-items:center; gap:6px; min-width:70px; justify-content:center; }
    #qrViewOverlay { position:fixed; inset:0; z-index:3100; background:rgba(10,31,14,.65); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; padding:20px; opacity:0; pointer-events:none; transition:opacity .22s ease; }
    #qrViewOverlay.open { opacity:1; pointer-events:all; }
    .qr-view-modal { background:var(--surface); border-radius:20px; width:100%; max-width:340px; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 24px 60px rgba(0,0,0,.25),0 0 0 1px rgba(46,125,50,.1); transform:translateY(16px) scale(.97); transition:transform .26s cubic-bezier(.34,1.56,.64,1); }
    #qrViewOverlay.open .qr-view-modal { transform:translateY(0) scale(1); }
    .qvm-header { padding:14px 16px 12px; background:linear-gradient(135deg,#F0F7F0,#E8F5E9); border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .qvm-doc-id { font-family:'DM Mono',monospace; font-size:10px; font-weight:600; color:var(--primary); background:rgba(46,125,50,.1); padding:2px 8px; border-radius:5px; border:1px solid rgba(46,125,50,.2); display:inline-block; margin-bottom:3px; }
    .qvm-doc-name { font-size:13px; font-weight:800; color:var(--text-1); line-height:1.2; }
    .qvm-doc-sub  { font-size:10px; color:var(--text-3); margin-top:1px; }
    .qvm-body { padding:16px 16px 10px; display:flex; flex-direction:column; align-items:center; gap:10px; }
    .qvm-qr-wrap { background:#fff; border:2px solid #E8F5E9; border-radius:14px; padding:12px; box-shadow:0 2px 12px rgba(46,125,50,.1); display:inline-flex; align-items:center; justify-content:center; }
    .qvm-qr-wrap canvas, .qvm-qr-wrap img { display:block; border-radius:3px; }
    .qvm-info { width:100%; background:var(--bg); border:1px solid var(--border); border-radius:10px; padding:10px 12px; display:grid; grid-template-columns:1fr 1fr; gap:7px 10px; }
    .qvm-info-item .qi-label { font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--text-3); margin-bottom:1px; }
    .qvm-info-item .qi-val { font-size:12px; font-weight:700; color:var(--text-1); }
    .qvm-footer { padding:10px 14px 14px; border-top:1px solid var(--border); display:flex; gap:6px; background:var(--surface); }
    #qrFullscreen { display:none; position:fixed; inset:0; background:#ffffff; z-index:9000; flex-direction:column; align-items:center; justify-content:center; gap:20px; cursor:pointer; }
    #qrFullscreen.open { display:flex; }
    #qrFullscreen .qfs-label { font-family:'Inter',sans-serif; font-size:13px; font-weight:600; color:#9EB0A2; letter-spacing:.06em; text-transform:uppercase; }
    #qrFullscreen .qfs-id   { font-family:'DM Mono',monospace; font-size:22px; font-weight:700; color:#1A2E1C; }
    #qrFullscreen .qfs-name { font-size:16px; font-weight:700; color:#4A6350; text-align:center; max-width:500px; }
    #qrFullscreen .qfs-hint { font-size:12px; color:#C8E6C9; margin-top:8px; }
    #qrFullscreen .qfs-close { position:fixed; top:20px; right:20px; width:44px; height:44px; border-radius:50%; background:#f0f0f0; border:none; font-size:22px; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#666; z-index:9001; }
    #qrFullscreen .qfs-close:hover { background:#FEE2E2; color:#DC2626; }
    #qrFullscreen #qfsCanvas { border:6px solid #f0f0f0; border-radius:16px; }
    .toast-wrap { position:fixed; bottom:28px; right:28px; display:flex; flex-direction:column; gap:10px; z-index:9999; pointer-events:none; }
    .toast { background:#0A1F0D; color:#fff; padding:12px 18px; border-radius:10px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:10px; box-shadow:var(--shadow-lg); pointer-events:all; min-width:220px; animation:toastIn .3s ease; }
    .toast.t-success{background:var(--primary)} .toast.t-warning{background:var(--amber)} .toast.t-danger{background:var(--red)}
    .toast.t-out { animation:toastOut .3s ease forwards; }
    @keyframes fadeUp   { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
    @keyframes rowIn    { from{opacity:0;transform:translateX(-4px)} to{opacity:1;transform:translateX(0)} }
    @keyframes toastIn  { from{opacity:0;transform:translateY(8px)}  to{opacity:1;transform:translateY(0)} }
    @keyframes toastOut { from{opacity:1;transform:translateY(0)}    to{opacity:0;transform:translateY(8px)} }
    @media(max-width:768px){ .main-content{padding:16px 12px} .edit-grid{grid-template-columns:1fr} .meta-grid{grid-template-columns:repeat(2,1fr)} .stats-row{grid-template-columns:repeat(2,1fr)} }
    </style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="page">

  <div class="page-hdr">
    <div>
      <p class="eyebrow">DTRS · Document Tracking &amp; Logistics Records</p>
      <h1>Document Registry
        <?php
        $tagCls = match($drRoleName) {
            'Super Admin' => 'sa',
            'Admin'       => 'admin',
            'Manager'     => 'mgr',
            default       => 'user',
        };
        $tagIcon = match($drRoleName) {
            'Super Admin' => 'bx-shield-quarter',
            'Admin'       => 'bx-user-check',
            'Manager'     => 'bx-show',
            default       => 'bx-user',
        };
        ?>
        <span class="role-tag <?= $tagCls ?>"><i class='bx <?= $tagIcon ?>'></i><?= htmlspecialchars($drRoleName) ?></span>
      </h1>
    </div>
    <div class="page-hdr-right">
      <button class="btn btn-ghost" onclick="openQRScanner()"><i class='bx bx-qr-scan'></i> Scan QR</button>
    </div>
  </div>

  <?php if ($drRoleRank === 1): ?>
  <div class="access-notice slate">
    <i class='bx bx-user'></i>
    <p><strong>My Documents:</strong> Showing documents assigned to you. You can view, scan QR receipts, and update the status or notes on your documents. Contact your Admin for any rerouting or archiving.</p>
  </div>
  <?php elseif ($drRoleRank === 2): ?>
  <div class="access-notice amber">
    <i class='bx bx-info-circle'></i>
    <p><strong>Manager View:</strong> Read-only monitoring of your team's documents in <strong><?= htmlspecialchars($drUserZone ?: 'your zone') ?></strong>. You can view and flag delays. Editing, archiving, and rerouting require Admin or Super Admin access.</p>
  </div>
  <?php elseif ($drRoleRank === 3): ?>
  <div class="access-notice blue">
    <i class='bx bx-info-circle'></i>
    <p><strong>Admin View:</strong> Managing documents in zone <strong><?= htmlspecialchars($drUserZone ?: 'all') ?></strong>. You can edit, archive, reroute, and bulk-manage documents. Permanent deletion requires Super Admin access.</p>
  </div>
  <?php endif; ?>

  <div class="stats-row" id="statsRow">
    <div class="stat-card"><div class="stat-ic ic-b"><i class='bx bx-folder'></i></div><div><div class="stat-v skel" style="width:40px;height:20px"></div><div class="stat-l">Loading…</div></div></div>
  </div>

  <div class="toolbar">
    <div class="search-wrap">
      <i class='bx bx-search'></i>
      <input type="text" class="search-input" id="searchInput" placeholder="Search by Doc ID, name, sender, recipient…" oninput="renderTable()">
    </div>
    <select class="filter-select" id="filterType" onchange="renderTable()">
      <option value="">All Types</option>
      <option>Memo</option><option>Contract</option><option>Invoice</option><option>Report</option>
      <option>Form</option><option>Certificate</option><option>Correspondence</option><option>Policy</option>
    </select>
    <select class="filter-select" id="filterStatus" onchange="reloadDocs()">
      <option value="">All Statuses</option>
      <option>Active</option>
      <?php if ($drRoleRank >= 3): ?><option>Archived</option><?php endif; ?>
      <option>Pending Validation</option>
      <?php if ($drRoleRank >= 3): ?><option>For Disposal</option><?php endif; ?>
    </select>
    <select class="filter-select" id="filterDirection" onchange="reloadDocs()">
      <option value="">All Directions</option><option>Incoming</option><option>Outgoing</option>
    </select>
    <select class="filter-select" id="filterMode" onchange="renderTable()">
      <option value="">All Modes</option><option>Physical</option><option>Digital</option>
    </select>
    <?php if ($drRoleRank >= 4): ?>
    <select class="filter-select" id="filterDept" onchange="renderTable()">
      <option value="">All Departments</option>
    </select>
    <?php endif; ?>
  </div>

  <?php if ($drCan['showCheckboxes']): ?>
  <div class="bulk-bar hidden" id="bulkBar">
    <span><span class="bulk-count" id="bulkCount">0</span> selected</span>
    <div class="bulk-sep"></div>
    <button class="bulk-btn" onclick="bulkExport()"><i class='bx bx-export'></i> Export</button>
    <button class="bulk-btn" onclick="bulkArchive()"><i class='bx bx-archive'></i> Archive Selected</button>
    <?php if ($drRoleRank >= 4): ?>
    <button class="bulk-btn" onclick="bulkDelete()"><i class='bx bx-trash'></i> Delete Selected</button>
    <?php endif; ?>
    <button class="bulk-cls" onclick="clearSelection()"><i class='bx bx-x'></i></button>
  </div>
  <?php endif; ?>

  <div class="table-card">
    <div class="table-card-hdr">
      <div class="table-card-title">
        <i class='bx bx-folder-open' style="color:var(--primary)"></i>
        <?php
        echo match($drRoleRank) {
            4       => 'All Registered Documents',
            3       => 'Zone Documents — ' . htmlspecialchars($drUserZone ?: 'All'),
            2       => 'Team Documents — ' . htmlspecialchars($drUserZone ?: 'My Zone'),
            default => 'My Assigned Documents',
        };
        ?>
        <?php if ($drRoleRank >= 4): ?>
        <span style="font-size:11px;font-weight:500;color:var(--primary);background:#E8F5E9;padding:2px 8px;border-radius:5px">System-Wide · Super Admin View</span>
        <?php endif; ?>
      </div>
      <span class="table-meta" id="tableMeta"></span>
    </div>
    <div class="tbl-wrap">
      <table class="doc-table" id="docTable">
        <thead>
          <tr>
            <?php if ($drCan['showCheckboxes']): ?>
            <th class="no-sort" style="width:40px"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" style="width:15px;height:15px;accent-color:var(--primary)"></th>
            <?php endif; ?>
            <th onclick="onSort('docId')"          id="th-docId">Doc ID <i class='bx bx-sort si'></i></th>
            <th onclick="onSort('name')"           id="th-name">Document <i class='bx bx-sort si'></i></th>
            <th onclick="onSort('status')"         id="th-status">Status <i class='bx bx-sort si'></i></th>
            <th onclick="onSort('assignedTo')"     id="th-assignedTo">
              <?= $drRoleRank === 1 ? 'My Role' : 'Assigned To' ?> <i class='bx bx-sort si'></i>
            </th>
            <th onclick="onSort('dateRegistered')" id="th-dateRegistered">Registered <i class='bx bx-sort si'></i></th>
            <th class="no-sort">QR</th>
            <?php if ($drRoleRank >= 2): ?>
            <th class="no-sort">Actions</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody id="docTbody">
          <tr><td colspan="<?= ($drCan['showCheckboxes'] ? 1 : 0) + ($drRoleRank >= 2 ? 8 : 7) ?>" style="padding:40px;text-align:center;color:var(--text-3)"><i class='bx bx-loader-alt' style="animation:spin .8s linear infinite;font-size:24px;display:block;margin-bottom:8px"></i>Loading documents…</td></tr>
        </tbody>
      </table>
      <div class="empty-state" id="emptyState" style="display:none">
        <i class='bx bx-folder-open'></i>
        <p><?= $drRoleRank === 1 ? 'No documents are assigned to you.' : 'No documents match your filters.' ?></p>
      </div>
    </div>
  </div>

</div>

<!-- DETAIL OVERLAY -->
<div class="overlay" id="detailOverlay" onclick="handleOverlayClick(event,'detailOverlay')">
  <div class="detail-panel">
    <div class="detail-header">
      <div class="detail-header-left">
        <span class="detail-doc-id" id="d-id"></span>
        <span class="detail-title"  id="d-title"></span>
        <div class="detail-chips"   id="d-chips"></div>
      </div>
      <button class="close-btn" onclick="closeOverlay('detailOverlay')"><i class='bx bx-x'></i></button>
    </div>
    <div class="detail-body">
      <div class="meta-grid" id="d-meta"></div>

      <!-- User self-service status/notes updater -->
      <?php if ($drRoleRank === 1): ?>
      <div class="self-service-box" id="selfServiceBox">
        <h4><i class='bx bx-edit-alt' style="color:var(--blue)"></i> Update My Document</h4>
        <div class="ss-row">
          <div class="ss-fg">
            <label>Update Status</label>
            <select class="ss-sel" id="ssStatus">
              <option value="">— no change —</option>
              <option value="Received">Received</option>
              <option value="Pending Validation">Pending Validation</option>
              <option value="Active">Processed</option>
            </select>
          </div>
          <div class="ss-fg" style="flex:2">
            <label>Notes</label>
            <input type="text" class="ss-inp" id="ssNotes" placeholder="Add a note…" maxlength="300">
          </div>
          <button class="btn btn-primary btn-sm" onclick="submitOwnUpdate()" id="ssBtn"><i class='bx bx-check'></i> Save</button>
        </div>
      </div>
      <?php endif; ?>

      <!-- Manager flag delay box -->
      <?php if ($drRoleRank === 2): ?>
      <div class="flag-box" id="flagBox">
        <h4><i class='bx bx-flag' style="color:var(--amber)"></i> Flag a Delay</h4>
        <div class="flag-row">
          <div class="ss-fg" style="flex:1">
            <label>Note</label>
            <input type="text" class="ss-inp" id="flagNote" placeholder="Describe the delay…" maxlength="300">
          </div>
          <button class="btn btn-amber btn-sm" onclick="submitFlagDelay()" id="flagBtn"><i class='bx bx-flag'></i> Flag Delay</button>
        </div>
      </div>
      <?php endif; ?>

      <!-- File Viewer -->
      <div class="file-viewer-block" id="fileViewerBlock" style="display:none">
        <div class="fv-header">
          <i class='bx bx-file-blank' style="color:var(--primary);font-size:18px"></i>
          <span id="fvFileName">Document File</span>
          <span class="fv-ext-badge" id="fvExtBadge">—</span>
          <div style="margin-left:auto;display:flex;gap:8px">
            <a class="btn btn-ghost btn-xs" id="fvDownloadBtn" href="#" download target="_blank"><i class='bx bx-download'></i> Download</a>
            <button class="btn btn-ghost btn-xs" id="fvNewTabBtn" onclick="openFileNewTab()"><i class='bx bx-link-external'></i> Open</button>
          </div>
        </div>
        <div class="fv-body" id="fvBody">
          <div class="fv-loading" id="fvLoading" style="display:none"><i class='bx bx-loader-alt' style="animation:spin .8s linear infinite;font-size:28px;color:var(--primary)"></i><span>Loading file…</span></div>
          <iframe id="fvPdf" style="display:none;width:100%;height:540px;border:none;border-radius:8px;background:#f5f5f5"></iframe>
          <img id="fvImg" style="display:none;max-width:100%;max-height:540px;border-radius:8px;margin:0 auto" alt="Document preview">
          <div class="fv-unsupported" id="fvUnsupported" style="display:none">
            <i class='bx bx-file-blank' style="font-size:48px;color:#C8E6C9"></i>
            <p>Preview not available for this file type.</p>
            <a class="btn btn-primary btn-sm" id="fvDlBtn2" href="#" download target="_blank"><i class='bx bx-download'></i> Download File</a>
          </div>
        </div>
      </div>

      <!-- No file / attach (Admin+ only) -->
      <div class="file-viewer-block" id="fileNoneBlock" style="display:none">
        <div class="fv-none">
          <i class='bx bx-paperclip' style="font-size:36px;color:#C8E6C9;display:block;margin-bottom:10px"></i>
          <p style="font-size:13px;color:var(--text-2);font-weight:600;margin-bottom:4px">No file attached</p>
          <?php if ($drRoleRank >= 3): ?>
          <p style="font-size:12px;color:var(--text-3);margin-bottom:14px">Upload a PDF or image to attach it to this document.</p>
          <input type="file" id="attachFileInput" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.docx,.doc" style="display:none" onchange="handleAttachFile(event)">
          <button class="btn btn-primary btn-sm" onclick="document.getElementById('attachFileInput').click()" id="attachPickBtn"><i class='bx bx-upload'></i> Attach File</button>
          <div id="attachProgress" style="display:none;margin-top:12px;font-size:12px;color:var(--text-2);align-items:center;gap:8px"><i class='bx bx-loader-alt' style="animation:spin .8s linear infinite;color:var(--primary)"></i><span id="attachProgressTxt">Uploading…</span></div>
          <div id="attachErr" style="display:none;margin-top:10px;font-size:12px;color:var(--red)"></div>
          <?php else: ?>
          <p style="font-size:12px;color:var(--text-3)">No file has been attached to this document.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="detail-qr-block">
        <div class="detail-qr-canvas-wrap" id="detailQRCanvas"></div>
        <div class="detail-qr-info">
          <h4><i class='bx bx-qr' style="color:var(--primary)"></i> Document QR Code</h4>
          <p>Scan this QR code to instantly pull up this document record.</p>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-primary btn-xs" onclick="openQRFullscreen(activeDoc.docId)"><i class='bx bx-fullscreen'></i> Fullscreen</button>
            <button class="btn btn-ghost btn-xs"   onclick="openQRView(activeDoc.docId)"><i class='bx bx-expand-alt'></i> Enlarge</button>
            <button class="btn btn-ghost btn-xs"   onclick="downloadQRForDoc(activeDoc.docId)"><i class='bx bx-download'></i> Download</button>
            <button class="btn btn-ghost btn-xs"   onclick="printQRForDoc(activeDoc.docId)"><i class='bx bx-printer'></i> Print</button>
          </div>
        </div>
      </div>
      <div class="qr-section">
        <div class="qr-section-title"><i class='bx bx-qr-scan' style="color:var(--primary)"></i> QR Scan Log</div>
        <div class="qr-log-list" id="d-qrlog"><div style="padding:20px 0;text-align:center;color:var(--text-3);font-size:13px">Loading scan log…</div></div>
      </div>
    </div>
    <div class="detail-footer" id="detailFooter">
      <!-- injected by JS -->
      <button class="btn btn-primary btn-sm" onclick="closeOverlay('detailOverlay')"><i class='bx bx-check'></i> Done</button>
    </div>
  </div>
</div>

<!-- CONFIRM OVERLAY -->
<div class="overlay" id="confirmOverlay" style="z-index:2200" onclick="handleOverlayClick(event,'confirmOverlay')">
  <div class="confirm-modal">
    <div class="cm-icon" id="confirmIcon">📦</div>
    <h3 id="confirmTitle">Confirm Action</h3>
    <p  id="confirmBody">Are you sure?</p>
    <div class="cm-btns">
      <button class="btn btn-ghost btn-sm"  onclick="closeOverlay('confirmOverlay')">Cancel</button>
      <button class="btn btn-danger btn-sm" id="confirmActionBtn" onclick="confirmAction()">Confirm</button>
    </div>
  </div>
</div>

<!-- EDIT OVERLAY (Admin+ only) -->
<?php if ($drRoleRank >= 3): ?>
<div class="overlay" id="editOverlay" style="z-index:2100" onclick="handleOverlayClick(event,'editOverlay')">
  <div class="edit-panel">
    <div class="edit-hdr">
      <div class="edit-hdr-left">
        <span class="edit-hdr-doc-id" id="ef-doc-id"><i class='bx bx-file-blank'></i> —</span>
        <h3>Edit Document</h3>
      </div>
      <button class="close-btn" onclick="closeOverlay('editOverlay')"><i class='bx bx-x'></i></button>
    </div>
    <div class="edit-body">
      <div class="edit-err" id="editErr"></div>
      <div class="edit-grid">
        <div class="ef-group full"><label class="ef-label">Document Title</label><input class="ef-input" id="ef-title" type="text" maxlength="255" placeholder="Document title"></div>
        <div class="ef-group"><label class="ef-label">Document Type</label><select class="ef-select" id="ef-type"><option value="">— Select —</option><option>Memo</option><option>Contract</option><option>Invoice</option><option>Report</option><option>Form</option><option>Certificate</option><option>Correspondence</option><option>Policy</option></select></div>
        <div class="ef-group"><label class="ef-label">Category</label><select class="ef-select" id="ef-category"><option value="">— Select —</option><option>Financial</option><option>Legal</option><option>Operational</option><option>HR</option><option>Procurement</option><option>Compliance</option><option>Administrative</option></select></div>
        <div class="ef-group"><label class="ef-label">Department / Source</label><select class="ef-select" id="ef-department"><option value="">— Select —</option><option>Procurement</option><option>Logistics</option><option>Finance</option><option>HR</option><option>Legal</option><option>Operations</option><option>Admin</option></select></div>
        <div class="ef-group"><label class="ef-label">Direction</label><select class="ef-select" id="ef-direction"><option value="Incoming">Incoming</option><option value="Outgoing">Outgoing</option></select></div>
        <div class="ef-group"><label class="ef-label">Sender</label><input class="ef-input" id="ef-sender" type="text" maxlength="255" placeholder="Sender name / company"></div>
        <div class="ef-group"><label class="ef-label">Recipient</label><input class="ef-input" id="ef-recipient" type="text" maxlength="255" placeholder="Recipient name"></div>
        <div class="ef-group"><label class="ef-label">Assigned To</label><input class="ef-input" id="ef-assigned-to" type="text" maxlength="150" placeholder="Staff name"></div>
        <div class="ef-group"><label class="ef-label">Document Date</label><input class="ef-input" id="ef-doc-date" type="datetime-local"></div>
        <div class="ef-group"><label class="ef-label">Priority</label><select class="ef-select" id="ef-priority"><option>Normal</option><option>Urgent</option><option>Confidential</option><option>High Value</option></select></div>
        <div class="ef-group"><label class="ef-label">Retention</label><select class="ef-select" id="ef-retention"><option>1 Year</option><option>2 Years</option><option>3 Years</option><option>5 Years</option><option>7 Years</option><option>10 Years</option><option>Permanent</option></select></div>
        <div class="ef-group full"><label class="ef-label">Notes</label><textarea class="ef-textarea" id="ef-notes" maxlength="500" placeholder="Internal notes…"></textarea></div>
      </div>
    </div>
    <div class="edit-footer">
      <button class="btn btn-ghost btn-sm" onclick="closeOverlay('editOverlay')">Cancel</button>
      <button class="btn btn-primary btn-sm" id="editSaveBtn" onclick="submitEdit()"><i class='bx bx-save'></i> Save Changes</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- QR SCANNER -->
<div id="qrOverlay" onclick="handleQROverlayClick(event)">
  <div class="qr-modal">
    <div class="qr-modal-hdr">
      <div>
        <div class="qr-modal-title"><i class='bx bx-qr-scan'></i> QR Document Scanner</div>
        <div class="qr-modal-sub">Point camera at a document QR code</div>
      </div>
      <button class="qr-cls-btn" onclick="closeQRScanner()"><i class='bx bx-x'></i></button>
    </div>
    <div class="qr-modal-body">
      <div class="qr-cam-select">
        <div class="qr-cam-display" id="qrCamDisplay"><span id="qrCamLabel">Loading cameras…</span><i class='bx bx-chevron-down' style="color:#81C784;font-size:16px;flex-shrink:0"></i></div>
        <select class="qr-cam-sel-real" id="qrCamSelect" onchange="qrCamChanged()"><option value="">Loading cameras…</option></select>
        <button class="qr-torch-btn" id="torchBtn" title="Toggle flashlight" onclick="toggleTorch()"><i class='bx bx-bolt-circle'></i></button>
      </div>
      <div class="qr-viewport">
        <video id="qrVideo" autoplay playsinline muted webkit-playsinline></video>
        <canvas id="qrCanvas" style="display:none"></canvas>
        <div class="scan-vignette"></div>
        <div class="scan-frame"><div class="scan-box"><span class="sb-bl"></span><span class="sb-br"></span><div class="scan-line"></div></div></div>
        <div id="qrNoCam"><i class='bx bx-camera-off' style="font-size:44px;opacity:.4"></i><p id="qrNoCamMsg" style="font-size:13px;line-height:1.6;max-width:260px">Requesting camera access…</p></div>
      </div>
      <div class="qr-status-strip"><div class="qr-status-dot" id="qrDot"></div><span class="qr-status-txt" id="qrStatusTxt">Initializing camera…</span></div>
      <div class="qr-result-card" id="qrResultCard">
        <div class="qr-rc-label" id="qrRcLabel">Document Found</div>
        <div class="qr-rc-id"   id="qrRcId"></div>
        <div class="qr-rc-name" id="qrRcName"></div>
        <div class="qr-rc-meta" id="qrRcMeta"></div>
        <div class="qr-rc-actions">
          <button class="qr-rc-btn qr-rc-confirm" onclick="confirmQRScan()"><i class='bx bx-check-circle'></i> Confirm Scan &amp; Log</button>
          <button class="qr-rc-btn qr-rc-view"    onclick="viewScannedDoc()"><i class='bx bx-show'></i> View Details</button>
        </div>
      </div>
      <div class="qr-manual">
        <div class="qr-manual-label">Or enter document ID manually</div>
        <div class="qr-manual-row">
          <input type="text" class="qr-manual-input" id="qrManualInput" placeholder="e.g. DTRS-2025-0001" onkeydown="if(event.key==='Enter') lookupManual()">
          <button class="qr-manual-btn" onclick="lookupManual()"><i class='bx bx-search'></i> Lookup</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- QR VIEW MODAL -->
<div id="qrViewOverlay" onclick="handleQRViewOverlayClick(event)">
  <div class="qr-view-modal">
    <div class="qvm-header">
      <div style="min-width:0;flex:1"><div class="qvm-doc-id" id="qvm-id"></div><div class="qvm-doc-name" id="qvm-name"></div><div class="qvm-doc-sub" id="qvm-sub"></div></div>
      <button class="close-btn" onclick="closeOverlay('qrViewOverlay')" style="flex-shrink:0"><i class='bx bx-x'></i></button>
    </div>
    <div class="qvm-body"><div class="qvm-qr-wrap" id="qvmQRWrap"></div><div class="qvm-info" id="qvmInfo"></div></div>
    <div class="qvm-footer">
      <button class="btn btn-primary btn-sm" style="flex:1" onclick="openQRFullscreen(qrViewDocId)"><i class='bx bx-fullscreen'></i> Fullscreen</button>
      <button class="btn btn-ghost btn-sm"   style="flex:1" onclick="downloadQR()"><i class='bx bx-download'></i> Download</button>
      <button class="btn btn-ghost btn-sm"   style="flex:1" onclick="printQR()"><i class='bx bx-printer'></i> Print</button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<div id="qrFullscreen" onclick="closeQRFullscreen()">
  <button class="qfs-close" onclick="event.stopPropagation();closeQRFullscreen()"><i class='bx bx-x'></i></button>
  <div class="qfs-label">Scan this QR code with your phone</div>
  <div class="qfs-id" id="qfsId"></div>
  <div id="qfsCanvas"></div>
  <div class="qfs-name" id="qfsName"></div>
  <div class="qfs-hint">Tap anywhere to close</div>
</div>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://unpkg.com/@zxing/library@0.19.1/umd/index.min.js"></script>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>
<script>
const API      = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';
const PERMS    = <?= $jsPerms ?>;
const ROLE     = <?= $jsRole ?>;
const ROLERANK = <?= $jsRoleRank ?>;
const UZONE    = <?= $jsZone ?>;
const UNAME    = <?= $jsUserName ?>;

// ── API ───────────────────────────────────────────────────────────────────────
async function apiFetch(path, opts={}) {
    const r = await fetch(path, {headers:{'Content-Type':'application/json'}, ...opts});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p => apiFetch(p);
const apiPost = (p,b) => apiFetch(p, {method:'POST', body:JSON.stringify(b)});

const TYPE_COLORS={Memo:'#2563EB',Contract:'#7C3AED',Invoice:'#D97706',Report:'#2E7D32',Form:'#DC2626',Certificate:'#0D9488',Correspondence:'#6B7280',Policy:'#1D4ED8'};
const TYPE_ICONS ={Memo:'bx-envelope',Contract:'bx-file-blank',Invoice:'bx-receipt',Report:'bx-bar-chart-alt-2',Form:'bx-notepad',Certificate:'bx-award',Correspondence:'bx-message-square-dots',Policy:'bx-shield'};

let DOCS=[], sortCol='dateRegistered', sortDir='desc';
let activeDoc=null, confirmCb=null, selected=new Set();

// ── INIT ──────────────────────────────────────────────────────────────────────
loadInit();
async function loadInit() { await loadDocs(); }

async function loadDocs() {
    try {
        const q = new URLSearchParams({api:'list'});
        const st = document.getElementById('filterStatus')?.value;
        const di = document.getElementById('filterDirection')?.value;
        if (st) q.set('status', st);
        if (di) q.set('direction', di);
        DOCS = await apiGet(API + '?' + q.toString());
        if (ROLERANK >= 4) buildDeptFilter();
        renderStats();
        renderTable();
    } catch(e) {
        toast('Failed to load documents: ' + e.message, 'danger');
        document.getElementById('docTbody').innerHTML = `<tr><td colspan="8" style="padding:40px;text-align:center;color:var(--red)"><i class='bx bx-error-circle' style="font-size:24px;display:block;margin-bottom:8px"></i>${esc(e.message)}</td></tr>`;
    }
}
async function reloadDocs() { await loadDocs(); }

function buildDeptFilter() {
    const sel = document.getElementById('filterDept'); if (!sel) return;
    const cur = sel.value;
    const depts = [...new Set(DOCS.map(d => d.department).filter(Boolean))].sort();
    sel.innerHTML = '<option value="">All Departments</option>' + depts.map(d => `<option ${d===cur?'selected':''}>${esc(d)}</option>`).join('');
}

// ── STATS — role-aware ────────────────────────────────────────────────────────
function renderStats() {
    const t  = DOCS.length;
    const a  = DOCS.filter(d => d.status === 'Active').length;
    const p  = DOCS.filter(d => d.status === 'Pending Validation').length;
    const ar = DOCS.filter(d => d.status === 'Archived').length;

    let cards = [{ic:'ic-b', icon:'bx-folder', v:t, l: ROLERANK===1?'My Documents':'Total Documents'}];
    cards.push({ic:'ic-g', icon:'bx-check-circle', v:a, l:'Active'});
    cards.push({ic:'ic-a', icon:'bx-time-five', v:p, l:'Pending Validation'});

    if (ROLERANK >= 3) {
        const fd = DOCS.filter(d => d.status === 'For Disposal').length;
        const ph = DOCS.filter(d => d.mode === 'Physical').length;
        cards.push({ic:'ic-r', icon:'bx-archive', v:ar, l:'Archived'});
        cards.push({ic:'ic-p', icon:'bx-trash', v:fd, l:'For Disposal'});
        cards.push({ic:'ic-t', icon:'bx-qr', v:ph, l:'Physical Docs'});
    } else if (ROLERANK === 2) {
        const flagged = DOCS.filter(d => d.priority === 'Urgent').length;
        cards.push({ic:'ic-r', icon:'bx-flag', v:flagged, l:'Urgent'});
    } else {
        // User: show incoming vs outgoing
        const inc = DOCS.filter(d => d.direction === 'Incoming').length;
        const out = DOCS.filter(d => d.direction === 'Outgoing').length;
        cards.push({ic:'ic-b', icon:'bx-log-in', v:inc, l:'Incoming'});
        cards.push({ic:'ic-t', icon:'bx-log-out', v:out, l:'Outgoing'});
    }

    document.getElementById('statsRow').innerHTML = cards.map(it =>
        `<div class="stat-card"><div class="stat-ic ${it.ic}"><i class='bx ${it.icon}'></i></div><div><div class="stat-v">${it.v}</div><div class="stat-l">${it.l}</div></div></div>`
    ).join('');
}

// ── FILTER / SORT ─────────────────────────────────────────────────────────────
function getFiltered() {
    const q  = document.getElementById('searchInput').value.toLowerCase();
    const ty = document.getElementById('filterType').value;
    const mo = document.getElementById('filterMode').value;
    const dEl = document.getElementById('filterDept');
    const de = dEl ? dEl.value : '';
    return DOCS.filter(d => {
        if (q && ![d.docId, d.name, d.sender, d.recipient, d.assignedTo].some(s => (s||'').toLowerCase().includes(q))) return false;
        if (ty && d.type !== ty)       return false;
        if (mo && d.mode !== mo)       return false;
        if (de && d.department !== de) return false;
        return true;
    });
}
function sortDocs(arr) {
    return [...arr].sort((a,b) => {
        if (sortCol==='dateRegistered'||sortCol==='dateTime') return sortDir==='asc'?new Date(a[sortCol])-new Date(b[sortCol]):new Date(b[sortCol])-new Date(a[sortCol]);
        const va=String(a[sortCol]||'').toLowerCase(),vb=String(b[sortCol]||'').toLowerCase();
        return sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va);
    });
}

// ── TABLE — role-aware columns & actions ─────────────────────────────────────
function renderTable() {
    const filtered = sortDocs(getFiltered());
    const tbody    = document.getElementById('docTbody');
    const empty    = document.getElementById('emptyState');
    document.getElementById('tableMeta').textContent = `${filtered.length} of ${DOCS.length} document${DOCS.length!==1?'s':''}`;

    ['docId','name','status','assignedTo','dateRegistered'].forEach(k => {
        const th = document.getElementById('th-' + k); if (!th) return;
        th.className = sortCol === k ? 'sorted' : '';
        th.querySelector('.si').className = `bx ${sortCol===k?(sortDir==='asc'?'bx-sort-up':'bx-sort-down'):'bx-sort'} si`;
    });

    if (!filtered.length) { tbody.innerHTML=''; empty.style.display='block'; return; }
    empty.style.display = 'none';

    tbody.innerHTML = filtered.map((d,i) => {
        // "My Role" column for User
        const myRoleHtml = ROLERANK===1
            ? `<span class="my-role-badge"><i class='bx bx-user'></i>${esc(d.direction==='Incoming'?'Recipient':'Sender')}</span><div style="font-size:11px;color:var(--text-3);margin-top:2px">${esc(d.assignedTo)}</div>`
            : `${esc(d.assignedTo)}<div style="font-size:11px;color:var(--text-3);margin-top:2px">${esc(d.sender)} → ${esc(d.recipient)}</div>`;

        // Action buttons per role
        let acts = '';
        if (ROLERANK >= 3) {
            acts += `<button class="btn btn-ghost btn-xs" onclick="openEdit('${d.docId}')"><i class='bx bx-edit'></i></button>`;
            acts += `<button class="btn btn-ghost btn-xs" onclick="openArchive('${d.docId}')"><i class='bx bx-archive'></i></button>`;
            if (ROLERANK >= 4) acts += `<button class="btn btn-ghost btn-xs" onclick="openDelete('${d.docId}')" style="color:var(--red)"><i class='bx bx-trash'></i></button>`;
        } else if (ROLERANK === 2) {
            acts += `<button class="btn btn-ghost btn-xs" onclick="openDetail('${d.docId}')"><i class='bx bx-show'></i> View</button>`;
            acts += `<button class="btn btn-amber btn-xs" onclick="quickFlagDelay('${d.docId}')"><i class='bx bx-flag'></i> Flag</button>`;
        } else {
            // User
            acts  = `<button class="btn btn-primary btn-xs" onclick="openDetail('${d.docId}')"><i class='bx bx-show'></i> View</button>`;
        }

        const cbCell = PERMS.showCheckboxes
            ? `<td onclick="event.stopPropagation()" class="cb-wrap"><input type="checkbox" ${selected.has(d.docId)?'checked':''} onchange="toggleSelect('${d.docId}',this)"></td>`
            : '';
        const actCell = ROLERANK >= 2
            ? `<td onclick="event.stopPropagation()"><div class="row-actions">${acts}</div></td>`
            : '';
        const viewBtn = ROLERANK >= 3
            ? `<button class="btn btn-ghost btn-xs" onclick="openDetail('${d.docId}')"><i class='bx bx-show'></i></button>` : '';

        return `<tr style="animation:rowIn .2s ${i*.015}s both" class="${selected.has(d.docId)?'selected-row':''}" onclick="openDetail('${d.docId}')">
          ${cbCell}
          <td><span class="doc-id">${esc(d.docId)}</span></td>
          <td>
            <div class="doc-name-cell">
              <div class="name">${esc(d.name)}</div>
              <div class="sub" style="display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap">
                <span class="type-badge" style="font-size:10px;padding:1px 6px;background:${typeColor(d.type,'.08')};color:${typeColor(d.type)};border-color:${typeColor(d.type,'.18')}">
                  <i class='bx ${typeIcon(d.type)}'></i>${esc(d.type)}
                </span>
                <span style="color:var(--text-3);font-size:11px">${esc(d.department)}</span>
                <span class="dir-badge ${d.direction==='Incoming'?'dir-in':'dir-out'}" style="font-size:9px;padding:1px 6px">
                  <i class='bx ${d.direction==='Incoming'?'bx-log-in':'bx-log-out'}'></i>${esc(d.direction)}
                </span>
                <span class="mode-badge ${d.mode==='Physical'?'mode-physical':'mode-digital'}" style="font-size:9px;padding:1px 6px">${esc(d.mode)}</span>
              </div>
            </div>
          </td>
          <td>
            <span class="status-chip ${statusClass(d.status)}">${esc(d.status)}</span>
            ${d.qrConfirmed ? `<div style="margin-top:4px"><span class="qr-chip qr-confirmed" style="font-size:10px;padding:1px 7px"><i class='bx bx-check-circle'></i> QR ✓</span></div>` : ''}
          </td>
          <td style="font-size:13px;font-weight:500;color:var(--text-1)">${myRoleHtml}</td>
          <td style="font-size:12px;color:var(--text-2);white-space:nowrap">${fmtD(d.dateRegistered)}</td>
          <td onclick="event.stopPropagation()">
            <div class="qr-thumb-wrap" id="qrthumb-${d.docId}" title="View QR — ${d.docId}" onclick="openQRView('${d.docId}')"></div>
          </td>
          ${actCell}
        </tr>`;
    }).join('');

    renderQRThumbs();
}

function onSort(col) {
    if (sortCol===col) sortDir=sortDir==='asc'?'desc':'asc'; else{sortCol=col;sortDir='asc';}
    renderTable();
}

// ── SELECTION (Admin+) ────────────────────────────────────────────────────────
function toggleSelect(id, cb) { if(cb.checked) selected.add(id); else selected.delete(id); updateBulkBar(); renderTable(); }
function toggleSelectAll(cb) { const f=getFiltered(); if(cb.checked) f.forEach(d=>selected.add(d.docId)); else f.forEach(d=>selected.delete(d.docId)); updateBulkBar(); renderTable(); }
function clearSelection() { selected.clear(); const sa=document.getElementById('selectAll'); if(sa) sa.checked=false; updateBulkBar(); renderTable(); }
function updateBulkBar() {
    const bar = document.getElementById('bulkBar'); if (!bar) return;
    document.getElementById('bulkCount').textContent = selected.size;
    bar.classList.toggle('hidden', selected.size === 0);
}

function bulkExport() {
    const rows = DOCS.filter(d => selected.has(d.docId));
    const cols = ['docId','name','type','category','department','direction','mode','sender','recipient','assignedTo','status','dateRegistered'];
    const hdrs = ['Doc ID','Name','Type','Category','Department','Direction','Mode','Sender','Recipient','Assigned To','Status','Registered'];
    const csv  = [hdrs.join(','), ...rows.map(d => cols.map(c => `"${String(d[c]||'').replace(/"/g,'""')}"`).join(','))];
    const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([csv.join('\n')],{type:'text/csv'}));
    a.download='dtrs_export.csv'; a.click();
    toast(`Exported ${rows.length} document(s)`,'success'); clearSelection();
}
async function bulkArchive() {
    if (!PERMS.bulkArchive) return toast('Permission denied','danger');
    const ids = DOCS.filter(d => selected.has(d.docId)).map(d => d.id);
    try { const r=await apiPost(API+'?api=bulk',{ids,action:'archive'}); toast(`${r.done} document(s) archived`,'success'); clearSelection(); await loadDocs(); }
    catch(e) { toast(e.message,'danger'); }
}
async function bulkDelete() {
    if (!PERMS.delete) return toast('Permission denied — Super Admin only','danger');
    const ids = DOCS.filter(d => selected.has(d.docId)).map(d => d.id);
    document.getElementById('confirmIcon').textContent='🗑️';
    document.getElementById('confirmTitle').textContent=`Delete ${ids.length} document(s)?`;
    document.getElementById('confirmBody').textContent='This permanently removes all selected documents. Super Admin only. Cannot be undone.';
    document.getElementById('confirmActionBtn').textContent='Delete All';
    document.getElementById('confirmActionBtn').className='btn btn-danger btn-sm';
    confirmCb=async()=>{ try{const r=await apiPost(API+'?api=bulk',{ids,action:'delete'});toast(`${r.done} deleted`,'danger');clearSelection();await loadDocs();}catch(e){toast(e.message,'danger');} };
    openOverlay('confirmOverlay');
}

// ── DETAIL ────────────────────────────────────────────────────────────────────
async function openDetail(docId) {
    const d = DOCS.find(x => x.docId === docId); if (!d) return;
    activeDoc = d;
    document.getElementById('d-id').textContent    = d.docId;
    document.getElementById('d-title').textContent = d.name;
    document.getElementById('d-chips').innerHTML = `
        <span class="type-badge" style="background:${typeColor(d.type,'.1')};color:${typeColor(d.type)};border-color:${typeColor(d.type,'.2')}"><i class='bx ${typeIcon(d.type)}'></i>${esc(d.type)}</span>
        <span class="status-chip ${statusClass(d.status)}">${esc(d.status)}</span>
        <span class="dir-badge ${d.direction==='Incoming'?'dir-in':'dir-out'}"><i class='bx ${d.direction==='Incoming'?'bx-log-in':'bx-log-out'}'></i>${esc(d.direction)}</span>
        <span class="mode-badge ${d.mode==='Physical'?'mode-physical':'mode-digital'}"><i class='bx ${d.mode==='Physical'?'bx-package':'bx-cloud'}'></i>${esc(d.mode)}</span>`;

    // Build meta grid — User sees a subset
    const allMeta = [
        {l:'Document ID',       v:d.docId,           mono:true},
        {l:'Document Name',     v:d.name},
        {l:'Type',              v:d.type},
        {l:'Category',          v:d.category},
        ...(ROLERANK >= 2 ? [{l:'Department', v:d.department}] : []),
        {l:'Date Registered',   v:fmtD(d.dateRegistered)},
        {l:'Status',            v:d.status},
        {l:'Assigned To',       v:d.assignedTo},
        {l:'Direction',         v:d.direction},
        {l:'Mode',              v:d.mode},
        {l:'Sender',            v:d.sender},
        {l:'Recipient',         v:d.recipient},
        {l:'Date & Time',       v:fmtDT(d.dateTime)},
        ...(ROLERANK >= 2 ? [{l:'Priority', v:d.priority}] : []),
        ...(ROLERANK >= 3 ? [{l:'Ref Number', v:d.refNumber||'—'}] : []),
        {l:'Notes',             v:d.notes||'—'},
    ];
    document.getElementById('d-meta').innerHTML = allMeta.map(m =>
        `<div class="meta-item"><div class="meta-label">${m.l}</div><div class="meta-value${m.mono?' mono':''}">${esc(String(m.v||''))}</div></div>`
    ).join('');

    // Pre-fill self-service box for User
    if (ROLERANK === 1) {
        const ssStatus = document.getElementById('ssStatus');
        const ssNotes  = document.getElementById('ssNotes');
        if (ssStatus) ssStatus.value = '';
        if (ssNotes)  ssNotes.value  = d.notes || '';
    }
    // Pre-fill flag note for Manager
    if (ROLERANK === 2) {
        const fn = document.getElementById('flagNote');
        if (fn) fn.value = '';
    }

    // QR
    const qrWrap = document.getElementById('detailQRCanvas'); qrWrap.innerHTML='';
    setTimeout(() => makeQRInEl(qrWrap, d.docId, 180), 50);

    // File viewer
    loadFileViewer(d);

    // Rebuild footer buttons
    buildDetailFooter(d);

    openOverlay('detailOverlay');

    // Load QR scan log
    document.getElementById('d-qrlog').innerHTML = '<div style="padding:20px 0;text-align:center;color:var(--text-3);font-size:13px">Loading…</div>';
    try {
        const logs = await apiGet(API + '?api=qrlogs&id=' + d.id);
        const qrl = document.getElementById('d-qrlog');
        if (!logs.length) {
            qrl.innerHTML = `<div style="padding:20px 0;text-align:center;color:var(--text-3);font-size:13px"><i class='bx bx-qr' style="font-size:32px;display:block;margin-bottom:8px;color:#C8E6C9"></i>No QR scans logged yet</div>`;
        } else {
            qrl.innerHTML = logs.map((log,i) => `
                <div class="qr-log-item" style="animation-delay:${i*.04}s">
                  <div class="qr-ic"><i class='bx bx-qr-scan'></i></div>
                  <div><div class="qr-log-user">${esc(log.user)}</div><div class="qr-log-note">${esc(log.note)}</div></div>
                  <span class="qr-log-ts">${fmtDT(log.ts)}</span>
                </div>`).join('');
        }
    } catch(e) { document.getElementById('d-qrlog').innerHTML=`<div style="color:var(--red);padding:12px;font-size:12px">Failed to load scan log.</div>`; }
}

function buildDetailFooter(d) {
    const footer = document.getElementById('detailFooter');
    footer.innerHTML = '';
    const mk=(cls,icon,lbl,cb)=>{const b=document.createElement('button');b.className=`btn ${cls} btn-sm`;b.innerHTML=`<i class='bx ${icon}'></i> ${lbl}`;b.addEventListener('click',cb);footer.appendChild(b);};

    if (ROLERANK >= 3) {
        mk('btn-ghost','bx-archive','Archive',archiveFromDetail);
        if (ROLERANK >= 4) mk('btn-danger','bx-trash','Delete',deleteFromDetail);
        mk('btn-teal','bx-edit','Edit',editFromDetail);
    }
    if (ROLERANK >= 2) {
        mk('btn-ghost','bx-qr-scan','Log QR Scan', () => logQRScanFromDetail());
    }
    mk('btn-primary','bx-check','Done', () => closeOverlay('detailOverlay'));
}

function archiveFromDetail() { closeOverlay('detailOverlay'); setTimeout(()=>openArchive(activeDoc.docId),200); }
function deleteFromDetail()  { closeOverlay('detailOverlay'); setTimeout(()=>openDelete(activeDoc.docId),200); }
function editFromDetail()    { closeOverlay('detailOverlay'); setTimeout(()=>openEdit(activeDoc.docId),200); }

async function logQRScanFromDetail() {
    if (!activeDoc) return;
    try {
        const log = await apiPost(API+'?api=qrscan',{id:activeDoc.id,note:'Manual QR log from detail view.'});
        activeDoc.qrConfirmed=true;
        const idx=DOCS.findIndex(x=>x.id===activeDoc.id); if(idx>-1) DOCS[idx].qrConfirmed=true;
        renderTable();
        toast('QR scan logged for '+activeDoc.docId,'success');
    } catch(e){ toast(e.message,'danger'); }
}

// ── USER: update own status/notes ─────────────────────────────────────────────
async function submitOwnUpdate() {
    const d   = activeDoc; if (!d) return;
    const status = document.getElementById('ssStatus')?.value;
    const notes  = document.getElementById('ssNotes')?.value?.trim();
    if (!status && !notes) return toast('Enter a status or note to update','warning');
    const btn = document.getElementById('ssBtn'); btn.disabled=true;
    try {
        const payload = {id: d.id};
        if (status) payload.status = status;
        if (notes !== undefined) payload.notes = notes;
        const updated = await apiPost(API+'?api=update_own', payload);
        const idx=DOCS.findIndex(x=>x.id===d.id); if(idx>-1) DOCS[idx]={...DOCS[idx],...updated};
        activeDoc=DOCS[idx]||updated;
        renderTable(); renderStats();
        toast(d.docId+' updated','success');
    } catch(e){ toast(e.message,'danger'); }
    finally{ btn.disabled=false; }
}

// ── MANAGER: flag delay ───────────────────────────────────────────────────────
async function submitFlagDelay() {
    const d    = activeDoc; if (!d) return;
    const note = document.getElementById('flagNote')?.value?.trim();
    if (!note) return toast('Please describe the delay','warning');
    const btn = document.getElementById('flagBtn'); btn.disabled=true;
    try {
        await apiPost(API+'?api=flag_delay',{id:d.id,note});
        toast('Delay flagged for '+d.docId,'warning');
        document.getElementById('flagNote').value='';
    } catch(e){ toast(e.message,'danger'); }
    finally{ btn.disabled=false; }
}

async function quickFlagDelay(docId) {
    const d = DOCS.find(x=>x.docId===docId); if(!d) return;
    const note = prompt('Describe the delay for '+docId+':'); if(!note?.trim()) return;
    try { await apiPost(API+'?api=flag_delay',{id:d.id,note:note.trim()}); toast('Delay flagged for '+docId,'warning'); }
    catch(e){ toast(e.message,'danger'); }
}

// ── EDIT (Admin+) ─────────────────────────────────────────────────────────────
function openEdit(docId) {
    if (!PERMS.edit) return toast('Permission denied','danger');
    const d = DOCS.find(x => x.docId === docId); if (!d) return; activeDoc=d;
    document.getElementById('ef-doc-id').innerHTML=`<i class='bx bx-file-blank'></i> ${esc(d.docId)}`;
    document.getElementById('ef-title').value       = d.name       || '';
    document.getElementById('ef-type').value        = d.type       || '';
    document.getElementById('ef-category').value    = d.category   || '';
    document.getElementById('ef-department').value  = d.department || '';
    document.getElementById('ef-direction').value   = d.direction  || 'Incoming';
    document.getElementById('ef-sender').value      = d.sender     || '';
    document.getElementById('ef-recipient').value   = d.recipient  || '';
    document.getElementById('ef-assigned-to').value = d.assignedTo || '';
    document.getElementById('ef-priority').value    = d.priority   || 'Normal';
    document.getElementById('ef-retention').value   = d.retention  || '1 Year';
    document.getElementById('ef-notes').value       = d.notes      || '';
    if (d.dateTime) {
        try {
            const dt=new Date(d.dateTime),pad=n=>String(n).padStart(2,'0');
            document.getElementById('ef-doc-date').value=`${dt.getFullYear()}-${pad(dt.getMonth()+1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
        } catch(e) { document.getElementById('ef-doc-date').value=''; }
    } else { document.getElementById('ef-doc-date').value=''; }
    const errEl=document.getElementById('editErr'); errEl.classList.remove('show'); errEl.textContent='';
    const btn=document.getElementById('editSaveBtn'); btn.disabled=false; btn.innerHTML=`<i class='bx bx-save'></i> Save Changes`;
    openOverlay('editOverlay');
}

async function submitEdit() {
    const d=activeDoc; if(!d) return;
    const btn=document.getElementById('editSaveBtn');
    const err=document.getElementById('editErr'); err.classList.remove('show');
    const title=document.getElementById('ef-title').value.trim();
    const docType=document.getElementById('ef-type').value;
    const category=document.getElementById('ef-category').value;
    const department=document.getElementById('ef-department').value;
    const direction=document.getElementById('ef-direction').value;
    const sender=document.getElementById('ef-sender').value.trim();
    const recipient=document.getElementById('ef-recipient').value.trim();
    const assignedTo=document.getElementById('ef-assigned-to').value.trim();
    const docDate=document.getElementById('ef-doc-date').value;
    const priority=document.getElementById('ef-priority').value;
    const retention=document.getElementById('ef-retention').value;
    const notes=document.getElementById('ef-notes').value.trim();
    if(!title){showEditErr('Document title is required');return;}
    if(!docType){showEditErr('Document type is required');return;}
    if(!category){showEditErr('Category is required');return;}
    if(!department){showEditErr('Department is required');return;}
    if(!sender){showEditErr('Sender is required');return;}
    if(!recipient){showEditErr('Recipient is required');return;}
    if(!assignedTo){showEditErr('Assigned To is required');return;}
    if(!docDate){showEditErr('Document date is required');return;}
    btn.disabled=true; btn.innerHTML=`<i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i> Saving…`;
    try {
        const updated=await apiPost(API+'?api=update',{id:d.id,title,docType,category,department,direction,sender,recipient,assignedTo,docDate,priority,retention,notes});
        const idx=DOCS.findIndex(x=>x.id===d.id); if(idx>-1) DOCS[idx]={...DOCS[idx],...updated}; activeDoc=DOCS[idx]||updated;
        renderTable();renderStats(); closeOverlay('editOverlay'); toast(d.docId+' updated','success');
    } catch(e){ showEditErr(e.message); btn.disabled=false; btn.innerHTML=`<i class='bx bx-save'></i> Save Changes`; }
}
function showEditErr(msg){ const el=document.getElementById('editErr'); el.textContent=msg; el.classList.add('show'); }

// ── ARCHIVE / DELETE ──────────────────────────────────────────────────────────
function openArchive(docId) {
    if (!PERMS.archive) return toast('Permission denied','danger');
    const d=DOCS.find(x=>x.docId===docId); if(!d) return; activeDoc=d;
    document.getElementById('confirmIcon').textContent='📦';
    document.getElementById('confirmTitle').textContent=`Archive "${d.name.substring(0,40)+(d.name.length>40?'…':'')}"?`;
    document.getElementById('confirmBody').textContent=d.docId+' will be marked as Archived. All data and QR logs are preserved.';
    document.getElementById('confirmActionBtn').textContent='Archive';
    document.getElementById('confirmActionBtn').className='btn btn-amber btn-sm';
    confirmCb=async()=>{try{await apiPost(API+'?api=archive',{id:d.id});const idx=DOCS.findIndex(x=>x.id===d.id);if(idx>-1)DOCS[idx].status='Archived';renderTable();renderStats();toast(d.docId+' archived','success');}catch(e){toast(e.message,'danger');}};
    openOverlay('confirmOverlay');
}

function openDelete(docId) {
    if (!PERMS.delete) return toast('Permission denied — Super Admin only','danger');
    const d=DOCS.find(x=>x.docId===docId); if(!d) return; activeDoc=d;
    document.getElementById('confirmIcon').textContent='🗑️';
    document.getElementById('confirmTitle').textContent='Permanently Delete?';
    document.getElementById('confirmBody').textContent='This will permanently delete "'+d.name+'" ('+d.docId+'). This action cannot be undone. Super Admin only.';
    document.getElementById('confirmActionBtn').textContent='Delete Permanently';
    document.getElementById('confirmActionBtn').className='btn btn-danger btn-sm';
    confirmCb=async()=>{try{await apiPost(API+'?api=delete',{id:d.id});DOCS=DOCS.filter(x=>x.id!==d.id);renderTable();renderStats();toast(d.docId+' permanently deleted','danger');}catch(e){toast(e.message,'danger');}};
    openOverlay('confirmOverlay');
}
async function confirmAction(){ if(confirmCb) await confirmCb(); confirmCb=null; closeOverlay('confirmOverlay'); }

// ── OVERLAYS ──────────────────────────────────────────────────────────────────
function openOverlay(id)  { document.getElementById(id)?.classList.add('open'); }
function closeOverlay(id) { document.getElementById(id)?.classList.remove('open'); }
function handleOverlayClick(e,id){ if(e.target===document.getElementById(id)) closeOverlay(id); }

// ── FILE VIEWER ───────────────────────────────────────────────────────────────
let currentFileUrl='';
async function loadFileViewer(doc) {
    const vb=document.getElementById('fileViewerBlock'),nb=document.getElementById('fileNoneBlock');
    const fvL=document.getElementById('fvLoading'),fvP=document.getElementById('fvPdf'),fvI=document.getElementById('fvImg'),fvU=document.getElementById('fvUnsupported');
    function reset(){ fvL.style.display='none';fvP.style.display='none';fvI.style.display='none';fvU.style.display='none';fvP.src='';fvI.src='';currentFileUrl=''; }
    vb.style.display='none'; nb.style.display='none'; reset();
    if(!doc.filePath){nb.style.display='block';return;}
    vb.style.display='block'; fvL.style.display='flex';
    document.getElementById('fvFileName').textContent=doc.fileName||doc.docId;
    document.getElementById('fvExtBadge').textContent=(doc.fileExt||'FILE').toUpperCase();
    try {
        const result=await apiGet(API+'?api=file&id='+doc.id);
        currentFileUrl=result.signedUrl||'';
        if(!currentFileUrl) throw new Error('No signed URL returned');
        document.getElementById('fvDownloadBtn').href=currentFileUrl;
        document.getElementById('fvDownloadBtn').setAttribute('download',result.fileName||doc.fileName||doc.docId);
        const dlb2=document.getElementById('fvDlBtn2'); if(dlb2){dlb2.href=currentFileUrl;dlb2.style.display='';}
        fvL.style.display='none';
        const ext=(result.fileExt||doc.fileExt||'').toLowerCase().replace('.','');
        const imgExts=['png','jpg','jpeg','gif','webp','bmp','svg'];
        if(ext==='pdf'){fvP.src=currentFileUrl;fvP.style.display='block';}
        else if(imgExts.includes(ext)){fvI.style.display='block';fvI.src=currentFileUrl;}
        else{fvU.style.display='flex';}
    } catch(e){
        fvL.style.display='none';fvU.style.display='flex';
        const dlb2=document.getElementById('fvDlBtn2'); if(dlb2) dlb2.style.display='none';
        const p=fvU.querySelector('p'); if(p) p.textContent='Could not load file: '+e.message;
    }
}
function openFileNewTab(){ if(currentFileUrl) window.open(currentFileUrl,'_blank'); }

async function handleAttachFile(event) {
    if (!PERMS.edit) return;
    const file=event.target.files[0]; if(!file) return;
    const doc=activeDoc; if(!doc) return;
    const maxMb=20;
    if(file.size>maxMb*1024*1024){showAttachErr('File too large. Maximum size is '+maxMb+' MB.');return;}
    const progressEl=document.getElementById('attachProgress'),progressTxt=document.getElementById('attachProgressTxt');
    const pickBtn=document.getElementById('attachPickBtn'),errEl=document.getElementById('attachErr');
    errEl.style.display='none'; progressEl.style.display='flex'; progressTxt.textContent='Reading file…'; pickBtn.disabled=true;
    try {
        const ext=file.name.split('.').pop().toLowerCase();
        progressTxt.textContent='Uploading to storage…';
        const b64=await fileToBase64Registry(file);
        const updated=await apiPost(API+'?api=attach',{id:doc.id,docId:doc.docId,fileName:file.name,fileExt:ext,fileBase64:b64});
        const idx=DOCS.findIndex(x=>x.id===doc.id); if(idx>-1) DOCS[idx]={...DOCS[idx],...updated}; activeDoc=DOCS[idx]||updated;
        progressEl.style.display='none'; pickBtn.disabled=false;
        document.getElementById('attachFileInput').value='';
        toast('File attached to '+doc.docId,'success'); loadFileViewer(activeDoc);
    } catch(e){ progressEl.style.display='none'; pickBtn.disabled=false; showAttachErr(e.message); }
}
function showAttachErr(msg){ const el=document.getElementById('attachErr'); if(!el) return; el.textContent=msg; el.style.display='block'; }
function fileToBase64Registry(file){ return new Promise((res,rej)=>{ const r=new FileReader(); r.onload=()=>res(r.result.split(',')[1]); r.onerror=()=>rej(new Error('Failed to read file')); r.readAsDataURL(file); }); }

// ── QR SCANNER ────────────────────────────────────────────────────────────────
let qrStream=null,qrScanning=false,qrTorchOn=false,qrScannedDoc=null,qrCooldown=false,qrLastScan=null,zxingReader=null;
function setQRStatus(msg,state='idle'){document.getElementById('qrStatusTxt').textContent=msg;const dot=document.getElementById('qrDot');dot.className='qr-status-dot'+(state==='scanning'?' scanning':state==='found'?' found':state==='error'?' error':'');}
async function openQRScanner(){document.getElementById('qrOverlay').classList.add('open');document.getElementById('qrResultCard').classList.remove('show');document.getElementById('qrManualInput').value='';document.getElementById('qrNoCamMsg').textContent='Requesting camera access…';qrScannedDoc=null;qrCooldown=false;qrLastScan=null;await initCamera();}
function closeQRScanner(){stopCamera();document.getElementById('qrOverlay').classList.remove('open');document.getElementById('qrResultCard').classList.remove('show');}
function handleQROverlayClick(e){if(e.target===document.getElementById('qrOverlay'))closeQRScanner();}
async function initCamera(){setQRStatus('Requesting camera access…','idle');try{const probe=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}});probe.getTracks().forEach(t=>t.stop());const devices=await navigator.mediaDevices.enumerateDevices();const cameras=devices.filter(d=>d.kind==='videoinput');const sel=document.getElementById('qrCamSelect');sel.innerHTML='';cameras.forEach((cam,i)=>{const op=document.createElement('option');op.value=cam.deviceId;op.textContent=cam.label||`Camera ${i+1}`;sel.appendChild(op);});const back=cameras.find(c=>/back|rear|environment/i.test(c.label));const chosen=back||cameras[cameras.length-1];if(chosen){sel.value=chosen.deviceId;document.getElementById('qrCamLabel').textContent=chosen.label||`Camera ${cameras.indexOf(chosen)+1}`;}await startCamera(chosen?chosen.deviceId:undefined);}catch(err){handleCameraError(err);}}
async function startCamera(deviceId){stopCamera();const video=document.getElementById('qrVideo');document.getElementById('qrNoCam').style.display='flex';document.getElementById('qrNoCamMsg').textContent='Starting camera…';try{const res={width:{min:640,ideal:1280},height:{min:480,ideal:720}};const vc=deviceId?{deviceId:{exact:deviceId},...res}:{facingMode:{ideal:'environment'},...res};let stream;try{stream=await navigator.mediaDevices.getUserMedia({video:vc});}catch(e){if(e.name==='OverconstrainedError'){stream=await navigator.mediaDevices.getUserMedia({video:deviceId?{deviceId:{exact:deviceId}}:{facingMode:{ideal:'environment'}}});}else throw e;}qrStream=stream;video.srcObject=stream;await video.play().catch(()=>{});document.getElementById('qrNoCam').style.display='none';qrScanning=true;setQRStatus('Scanning — aim camera at QR code…','scanning');const hints=new Map();hints.set(ZXing.DecodeHintType.POSSIBLE_FORMATS,[ZXing.BarcodeFormat.QR_CODE]);hints.set(ZXing.DecodeHintType.TRY_HARDER,true);zxingReader=new ZXing.BrowserMultiFormatReader(hints);zxingReader.decodeFromStream(stream,video,(result,err)=>{if(!qrScanning)return;if(result&&!qrCooldown)processQRValue(result.getText().trim());});}catch(err){handleCameraError(err);}}
function stopCamera(){qrScanning=false;if(zxingReader){try{zxingReader.reset();}catch(e){}zxingReader=null;}if(qrStream){qrStream.getTracks().forEach(t=>t.stop());qrStream=null;}const v=document.getElementById('qrVideo');v.srcObject=null;}
function handleCameraError(err){let msg='Camera unavailable. Use manual lookup below.';if(err.name==='NotAllowedError')msg='Camera permission denied.';if(err.name==='NotFoundError')msg='No camera found on this device.';if(err.name==='NotReadableError')msg='Camera in use by another app.';if(err.name==='SecurityError')msg='Camera blocked — page needs HTTPS.';setQRStatus(msg,'error');document.getElementById('qrNoCam').style.display='flex';document.getElementById('qrNoCamMsg').textContent=msg;}
function qrCamChanged(){const sel=document.getElementById('qrCamSelect');document.getElementById('qrCamLabel').textContent=sel.options[sel.selectedIndex]?.text||'Camera';document.getElementById('qrResultCard').classList.remove('show');qrScannedDoc=null;qrCooldown=false;qrLastScan=null;startCamera(sel.value);}
async function toggleTorch(){if(!qrStream)return;const track=qrStream.getVideoTracks()[0];if(!track)return;const caps=track.getCapabilities?track.getCapabilities():{};if(!caps.torch){toast('Torch not supported on this device','warning');return;}qrTorchOn=!qrTorchOn;try{await track.applyConstraints({advanced:[{torch:qrTorchOn}]});document.getElementById('torchBtn').classList.toggle('on',qrTorchOn);}catch(e){toast('Could not toggle torch','warning');}}
function processQRValue(raw){
    const match=raw.match(/DTRS-[\w-]+/i),docId=match?match[0].toUpperCase():raw.toUpperCase();
    if(docId===qrLastScan)return;
    // User: only show their assigned docs
    let doc=DOCS.find(d=>d.docId.toUpperCase()===docId);
    if(ROLERANK===1&&doc&&doc.assignedTo!==UNAME){doc=null;}
    if(doc){qrLastScan=docId;qrScannedDoc=doc;qrCooldown=true;showQRResult(doc);setTimeout(()=>{qrCooldown=false;qrLastScan=null;},4000);}
    else{showQRNotFound(docId+(ROLERANK===1?' (not assigned to you)':''));qrCooldown=true;setTimeout(()=>{qrCooldown=false;},2000);}
}
function showQRResult(doc){const card=document.getElementById('qrResultCard');document.getElementById('qrRcLabel').className='qr-rc-label';document.getElementById('qrRcLabel').textContent='✓ Document Found';document.getElementById('qrRcId').textContent=doc.docId;document.getElementById('qrRcName').textContent=doc.name;document.getElementById('qrRcMeta').innerHTML=`<span>${esc(doc.type)}</span><span>${esc(doc.department)}</span><span>${esc(doc.status)}</span><span>${esc(doc.mode)}</span>`;card.classList.remove('error-card');card.classList.add('show');setQRStatus('Document identified: '+doc.docId,'found');if(navigator.vibrate)navigator.vibrate([80,30,80]);}
function showQRNotFound(rawId){const card=document.getElementById('qrResultCard');document.getElementById('qrRcLabel').className='qr-rc-label err';document.getElementById('qrRcLabel').textContent='✗ Document Not Found';document.getElementById('qrRcId').textContent=rawId;document.getElementById('qrRcName').textContent='No matching document in the registry.';document.getElementById('qrRcMeta').innerHTML='<span style="color:rgba(255,255,255,.4)">Ensure the QR belongs to this system.</span>';card.classList.add('error-card');card.classList.add('show');setQRStatus('No match for: '+rawId,'error');toast('No document found for: '+rawId,'warning');}
async function confirmQRScan(){if(!qrScannedDoc)return;const doc=qrScannedDoc;try{await apiPost(API+'?api=qrscan',{id:doc.id,note:'Physical receipt confirmed via QR scanner.'});doc.qrConfirmed=true;const idx=DOCS.findIndex(x=>x.id===doc.id);if(idx>-1)DOCS[idx].qrConfirmed=true;renderTable();renderStats();toast('Scan confirmed & logged for '+doc.docId,'success');setQRStatus('Scan logged ✓ — ready for next scan','found');setTimeout(()=>{document.getElementById('qrResultCard').classList.remove('show');qrScannedDoc=null;qrCooldown=false;qrLastScan=null;setQRStatus('Scanning — aim at a QR code…','scanning');},1800);}catch(e){toast('Failed to log scan: '+e.message,'danger');}}
function viewScannedDoc(){if(!qrScannedDoc)return;const id=qrScannedDoc.docId;closeQRScanner();setTimeout(()=>openDetail(id),250);}
function lookupManual(){const raw=document.getElementById('qrManualInput').value.trim();if(!raw)return;processQRValue(raw);document.getElementById('qrManualInput').value='';}

// ── QR CODE GENERATION ────────────────────────────────────────────────────────
function makeQRInEl(el,docId,size){el.innerHTML='';new QRCode(el,{text:docId,width:size,height:size,colorDark:'#000000',colorLight:'#FFFFFF',correctLevel:QRCode.CorrectLevel.M});}
function getQRCanvas(c){return c.querySelector('canvas')||c.querySelector('img');}
function renderQRThumbs(){setTimeout(()=>{getFiltered().forEach(d=>{const w=document.getElementById('qrthumb-'+d.docId);if(w&&!w.dataset.rendered){w.dataset.rendered='1';makeQRInEl(w,d.docId,32);}});},60);}
let qrViewDocId=null;
function openQRView(docId){const d=DOCS.find(x=>x.docId===docId);if(!d)return;qrViewDocId=docId;document.getElementById('qvm-id').textContent=d.docId;document.getElementById('qvm-name').textContent=d.name;document.getElementById('qvm-sub').textContent=d.type+' · '+d.department+' · '+d.status;const wrap=document.getElementById('qvmQRWrap');wrap.innerHTML='';makeQRInEl(wrap,d.docId,220);document.getElementById('qvmInfo').innerHTML=[{l:'Document ID',v:d.docId},{l:'Type',v:d.type},{l:'Department',v:d.department},{l:'Status',v:d.status},{l:'Direction',v:d.direction},{l:'Mode',v:d.mode}].map(m=>`<div class="qvm-info-item"><div class="qi-label">${m.l}</div><div class="qi-val">${esc(String(m.v))}</div></div>`).join('');openOverlay('qrViewOverlay');}
function handleQRViewOverlayClick(e){if(e.target===document.getElementById('qrViewOverlay'))closeOverlay('qrViewOverlay');}
function _dlQR(wrap,filename){setTimeout(()=>{const c=getQRCanvas(wrap);if(!c)return toast('QR not ready','warning');const url=c.tagName==='CANVAS'?c.toDataURL('image/png'):c.src;const a=document.createElement('a');a.href=url;a.download=filename+'.png';a.click();toast('QR downloaded — '+filename,'success');},100);}
function downloadQR(){if(!qrViewDocId)return;_dlQR(document.getElementById('qvmQRWrap'),qrViewDocId);}
function downloadQRForDoc(id){_dlQR(document.getElementById('detailQRCanvas'),id);}
function _printQR(wrap,doc){setTimeout(()=>{const c=getQRCanvas(wrap);if(!c)return toast('QR not ready','warning');const src=c.tagName==='CANVAS'?c.toDataURL('image/png'):c.src;const w=window.open('','_blank','width=400,height=500');w.document.write(`<!DOCTYPE html><html><head><title>QR — ${doc.docId}</title><style>body{font-family:sans-serif;text-align:center;padding:30px}h2{font-size:16px;margin-bottom:4px}p{font-size:12px;color:#6B7280;margin-bottom:16px}img{border:2px solid #ddd;border-radius:8px;padding:10px}@media print{button{display:none}}</style></head><body><h2>${esc(doc.name)}</h2><p>${esc(doc.docId)} · ${esc(doc.type)}</p><img src="${src}" width="200" height="200"><br><br><button onclick="window.print()">🖨 Print</button></body></html>`);w.document.close();w.focus();setTimeout(()=>w.print(),400);},100);}
function printQR(){if(!qrViewDocId)return;const d=DOCS.find(x=>x.docId===qrViewDocId);if(d)_printQR(document.getElementById('qvmQRWrap'),d);}
function printQRForDoc(id){const d=DOCS.find(x=>x.docId===id);if(d)_printQR(document.getElementById('detailQRCanvas'),d);}
function openQRFullscreen(docId){const d=DOCS.find(x=>x.docId===docId);if(!d)return;document.getElementById('qfsId').textContent=d.docId;document.getElementById('qfsName').textContent=d.name;const wrap=document.getElementById('qfsCanvas');wrap.innerHTML='';const sz=Math.max(280,Math.min(Math.min(window.innerWidth,window.innerHeight)-120,600));new QRCode(wrap,{text:d.docId,width:sz,height:sz,colorDark:'#000000',colorLight:'#FFFFFF',correctLevel:QRCode.CorrectLevel.M});document.getElementById('qrFullscreen').classList.add('open');document.body.style.overflow='hidden';}
function closeQRFullscreen(){document.getElementById('qrFullscreen').classList.remove('open');document.body.style.overflow='';}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeQRFullscreen();});

// ── UTILS ─────────────────────────────────────────────────────────────────────
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function fmtD(d){return d?new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}):'—';}
function fmtDT(d){return d?new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'})+' '+new Date(d).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'}):'—';}
function statusClass(s){return{Active:'s-active',Archived:'s-archived','Pending Validation':'s-pending','For Disposal':'s-disposal'}[s]||'s-pending';}
function typeColor(t,alpha){const c=TYPE_COLORS[t]||'#6B7280';if(!alpha)return c;const r=parseInt(c.slice(1,3),16),g=parseInt(c.slice(3,5),16),b=parseInt(c.slice(5,7),16);return `rgba(${r},${g},${b},${alpha})`;}
function typeIcon(t){return TYPE_ICONS[t]||'bx-file';}
function toast(msg,type='success'){const icons={success:'bx-check-circle',warning:'bx-error',danger:'bx-error-circle'};const el=document.createElement('div');el.className=`toast t-${type}`;el.innerHTML=`<i class='bx ${icons[type]||'bx-info-circle'}' style="font-size:17px;flex-shrink:0"></i>${esc(msg)}`;document.getElementById('toastWrap').appendChild(el);setTimeout(()=>{el.classList.add('t-out');setTimeout(()=>el.remove(),300);},3200);}
</script>
</body>
</html>