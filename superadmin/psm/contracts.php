<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE RESOLUTION (mirrors superadmin_sidebar.php) ─────────────────────────
function _cm_resolve_role(): string {
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

$cmRoleName = _cm_resolve_role();
$cmRoleRank = match($cmRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,   // User — no access
};
$cmUserZone = $_SESSION['zone']      ?? '';
$cmUserName = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'User');

// Block Users entirely
if ($cmRoleRank < 2) {
    http_response_code(403);
    include $root . '/includes/superadmin_sidebar.php';
    include $root . '/includes/header.php';
    echo '<main class="main-content"><div style="max-width:600px;margin:80px auto;text-align:center;font-family:sans-serif"><h2 style="color:#DC2626">Access Denied</h2><p style="color:#6B7280;margin-top:8px">You do not have permission to access Contract Management.</p></div></main>';
    exit;
}

// ── PERMISSION GATES ──────────────────────────────────────────────────────────
// Super Admin (4): full access — create, edit, terminate, archive, upload docs, delete docs, send to legal, flag renewal, view SA notes
// Admin (3):       view, send to legal, flag renewal — NO create/edit/terminate/archive/upload/delete docs
// Manager (2):     view only (Active + Expiring Soon) — NO edit, legal, terminate, archive, renewal flag
// User (1):        blocked at top

$cmCan = [
    'create'          => $cmRoleRank >= 4,   // SA only
    'edit'            => $cmRoleRank >= 4,   // SA only
    'terminate'       => $cmRoleRank >= 4,   // SA only
    'archive'         => $cmRoleRank >= 4,   // SA only (system-wide archive)
    'uploadDoc'       => $cmRoleRank >= 4,   // SA only
    'deleteDoc'       => $cmRoleRank >= 4,   // SA only
    'sendLegal'       => $cmRoleRank >= 3,   // SA, Admin
    'flagRenewal'     => $cmRoleRank >= 3,   // SA, Admin
    'viewSaNotes'     => $cmRoleRank >= 4,   // SA only
    'viewExpired'     => $cmRoleRank >= 3,   // SA, Admin (Manager: Active + Expiring only)
    'viewTerminated'  => $cmRoleRank >= 3,   // SA, Admin
    'viewAllZones'    => $cmRoleRank >= 4,   // SA only
    'exportData'      => $cmRoleRank >= 3,   // SA, Admin
    'viewBanner'      => $cmRoleRank >= 3,   // SA, Admin
];

$jsPerms    = json_encode($cmCan);
$jsRole     = json_encode($cmRoleName);
$jsRoleRank = (int)$cmRoleRank;
$jsZone     = json_encode($cmUserZone);

$tagCls  = match($cmRoleName) { 'Super Admin'=>'sa','Admin'=>'admin',default=>'mgr' };
$tagIcon = match($cmRoleName) { 'Super Admin'=>'bx-shield-quarter','Admin'=>'bx-user-check',default=>'bx-show' };

// ── HELPERS ──────────────────────────────────────────────────────────────────
function cm_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function cm_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function cm_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    if ($d === null && json_last_error() !== JSON_ERROR_NONE) cm_err('Invalid JSON', 400);
    return is_array($d) ? $d : [];
}
function cm_sb(string $table, string $method = 'GET', array $query = [], $body = null, array $extra_headers = []): array {
    $url = SUPABASE_URL . '/rest/v1/' . $table;
    if ($query) $url .= '?' . http_build_query($query);
    $headers = array_merge([
        'Content-Type: application/json',
        'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Prefer: return=representation',
    ], $extra_headers);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $res === '') { if ($code >= 400) cm_err('Supabase request failed', 500); return []; }
    $data = json_decode($res, true);
    if ($code >= 400) { $msg = is_array($data) ? ($data['message'] ?? $data['hint'] ?? $res) : $res; cm_err('Supabase: ' . $msg, 400); }
    return is_array($data) ? $data : [];
}

function cm_compute_status(array $c): string {
    if (in_array($c['status'], ['Terminated', 'Archived'], true)) return $c['status'];
    $days = (int) ceil((strtotime($c['expiry_date']) - strtotime('today')) / 86400);
    if ($days < 0) return 'Expired';
    if ($days <= 30) return 'Expiring Soon';
    if (in_array($c['legal_status'], ['Under Review', 'Pending Review'], true)) return 'Under Review';
    return 'Active';
}

function cm_build_full(array $row, bool $showSaNotes = true): array {
    $id = (int) $row['id'];
    $docRows = cm_sb('psm_contract_documents', 'GET', [
        'contract_id' => 'eq.' . $id,
        'select'      => 'id,file_name,file_size,file_type,file_path,uploaded_by,uploaded_at',
        'order'       => 'uploaded_at.asc',
    ]);
    $docs = array_map(fn($d) => [
        'id'         => (int)$d['id'],
        'name'       => $d['file_name']   ?? '',
        'size'       => $d['file_size']   ?? '',
        'type'       => $d['file_type']   ?? 'pdf',
        'filePath'   => $d['file_path']   ?? '',
        'uploadedBy' => $d['uploaded_by'] ?? '',
        'uploadedAt' => $d['uploaded_at'] ?? '',
    ], $docRows);
    $auditRows = cm_sb('psm_contract_audit_log', 'GET', [
        'contract_id' => 'eq.' . $id,
        'select'      => 'action_label,actor_name,dot_class,occurred_at',
        'order'       => 'occurred_at.desc,id.desc',
    ]);
    $audit = array_map(fn($a) => [
        't'   => $a['dot_class']    ?? 'dot-b',
        'msg' => $a['action_label'] ?? '',
        'by'  => $a['actor_name']   ?? '',
        'ts'  => $a['occurred_at']  ?? '',
    ], $auditRows);
    return [
        'id'             => $id,
        'contractNo'     => $row['contract_no']  ?? '',
        'poRef'          => $row['po_ref']        ?? '',
        'supplier'       => $row['supplier']      ?? '',
        'type'           => $row['contract_type'] ?? '',
        'value'          => (float)($row['value'] ?? 0),
        'startDate'      => $row['start_date']    ?? '',
        'expiryDate'     => $row['expiry_date']   ?? '',
        'legalStatus'    => $row['legal_status']  ?? 'Pending Review',
        'status'         => $row['status']        ?? 'Active',
        'computedStatus' => cm_compute_status($row),
        'renewal'        => (int)($row['renewal'] ?? 0),
        'notes'          => $row['notes']         ?? '',
        'saNotes'        => $showSaNotes ? ($row['sa_notes'] ?? '') : '',
        'createdBy'      => $row['created_by']    ?? '',
        'createdAt'      => $row['created_at']    ?? '',
        'updatedAt'      => $row['updated_at']    ?? '',
        'docs'           => $docs,
        'audit'          => $audit,
    ];
}

function cm_next_number(): string {
    $rows = cm_sb('psm_contracts', 'GET', ['select'=>'contract_no','order'=>'id.desc','limit'=>1]);
    $next = 1;
    if (!empty($rows) && preg_match('/CTR-\d{4}-(\d+)/', $rows[0]['contract_no'] ?? '', $m)) $next = ((int)$m[1]) + 1;
    return sprintf('CTR-%s-%04d', date('Y'), $next);
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $cmUserName;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
    $showSa = $cmCan['viewSaNotes'];

    try {

        if ($api === 'next_no' && $method === 'GET') {
            if (!$cmCan['create']) cm_err('Permission denied', 403);
            cm_ok(['contractNo' => cm_next_number()]);
        }

        if ($api === 'suppliers' && $method === 'GET') {
            $rows = cm_sb('psm_suppliers', 'GET', ['select'=>'id,name,category','status'=>'eq.Active','order'=>'name.asc']);
            cm_ok(array_map(fn($r) => ['id'=>(int)$r['id'],'name'=>$r['name']??'','cat'=>$r['category']??''], $rows));
        }

        if ($api === 'pos' && $method === 'GET') {
            if (!$cmCan['create']) cm_err('Permission denied', 403);
            $rows = cm_sb('psm_purchase_orders', 'GET', ['select'=>'po_number,supplier_name,total_amount','status'=>'in.(Confirmed,Sent,Partially Fulfilled,Fulfilled)','order'=>'po_number.desc']);
            cm_ok($rows);
        }

        if ($api === 'list' && $method === 'GET') {
            $q = ['select'=>'id,contract_no,po_ref,supplier,contract_type,value,start_date,expiry_date,legal_status,status,renewal,notes,sa_notes,created_by,created_at,updated_at','order'=>'created_at.desc,id.desc'];
            // Admin/Manager: filter by zone (branch)
            if (!$cmCan['viewAllZones'] && $cmUserZone !== '') {
                $q['branch'] = 'eq.' . $cmUserZone;
            }
            $rows = cm_sb('psm_contracts', 'GET', $q);
            // Manager: only Active + Expiring Soon
            if ($cmRoleRank === 2) {
                $rows = array_values(array_filter($rows, function($r) {
                    $cs = cm_compute_status($r);
                    return in_array($cs, ['Active', 'Expiring Soon']);
                }));
            }
            cm_ok(array_map(fn($r) => cm_build_full($r, $showSa), $rows));
        }

        if ($api === 'get' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) cm_err('Missing id', 400);
            $rows = cm_sb('psm_contracts', 'GET', ['select'=>'id,contract_no,po_ref,supplier,contract_type,value,start_date,expiry_date,legal_status,status,renewal,notes,sa_notes,created_by,created_at,updated_at','id'=>'eq.'.$id,'limit'=>1]);
            if (empty($rows)) cm_err('Contract not found', 404);
            // Manager zone check
            if ($cmRoleRank === 2 && $cmUserZone !== '' && ($rows[0]['branch'] ?? '') !== $cmUserZone) {
                cm_err('Access denied', 403);
            }
            cm_ok(cm_build_full($rows[0], $showSa));
        }

        if ($api === 'save' && $method === 'POST') {
            if (!$cmCan['create']) cm_err('Permission denied: only Super Admin can create or edit contracts', 403);
            $b = cm_body();
            $contractNo  = trim($b['contractNo']  ?? '');
            $poRef       = trim($b['poRef']        ?? '');
            $supplier    = trim($b['supplier']     ?? '');
            $type        = trim($b['type']         ?? 'Supply Agreement');
            $value       = (float)($b['value']     ?? 0);
            $startDate   = trim($b['startDate']    ?? '');
            $expiryDate  = trim($b['expiryDate']   ?? '');
            $legalStatus = trim($b['legalStatus']  ?? 'Pending Review');
            $status      = trim($b['status']       ?? 'Active');
            $renewal     = (int)($b['renewal']     ?? 0);
            $notes       = trim($b['notes']        ?? '');
            $saNotes     = trim($b['saNotes']      ?? '');
            if ($contractNo === '') cm_err('Contract number is required', 400);
            if ($poRef      === '') cm_err('PO Reference is required', 400);
            if ($supplier   === '') cm_err('Supplier is required', 400);
            if ($startDate  === '') cm_err('Start date is required', 400);
            if ($expiryDate === '') cm_err('Expiry date is required', 400);
            if ($value <= 0)        cm_err('Contract value must be greater than 0', 400);
            $allowedStatus = ['Active','Under Review','Expiring Soon','Expired','Terminated','Archived'];
            if (!in_array($status, $allowedStatus, true)) $status = 'Active';
            $allowedLegal = ['Pending Review','Under Review','Approved','Rejected'];
            if (!in_array($legalStatus, $allowedLegal, true)) $legalStatus = 'Pending Review';
            $editId = (int)($b['id'] ?? 0);
            $now    = date('Y-m-d H:i:s');
            $SELECT = 'id,contract_no,po_ref,supplier,contract_type,value,start_date,expiry_date,legal_status,status,renewal,notes,sa_notes,created_by,created_at,updated_at';
            if ($editId) {
                cm_sb('psm_contracts', 'PATCH', ['id'=>'eq.'.$editId], ['contract_no'=>$contractNo,'po_ref'=>$poRef,'supplier'=>$supplier,'contract_type'=>$type,'value'=>$value,'start_date'=>$startDate,'expiry_date'=>$expiryDate,'legal_status'=>$legalStatus,'status'=>$status,'renewal'=>$renewal,'notes'=>$notes,'sa_notes'=>$saNotes,'updated_at'=>$now]);
                cm_sb('psm_contract_audit_log', 'POST', [], [['contract_id'=>$editId,'action_label'=>'Contract Edited','actor_name'=>$actor,'dot_class'=>'dot-b','ip_address'=>$ip,'occurred_at'=>$now]]);
                $rows = cm_sb('psm_contracts', 'GET', ['select'=>$SELECT,'id'=>'eq.'.$editId,'limit'=>1]);
                cm_ok(cm_build_full($rows[0], $showSa));
            }
            $inserted = cm_sb('psm_contracts', 'POST', [], [['contract_no'=>$contractNo,'po_ref'=>$poRef,'supplier'=>$supplier,'contract_type'=>$type,'value'=>$value,'start_date'=>$startDate,'expiry_date'=>$expiryDate,'legal_status'=>$legalStatus,'status'=>$status,'renewal'=>$renewal,'notes'=>$notes,'sa_notes'=>$saNotes,'created_by'=>$actor,'created_user_id'=>$_SESSION['user_id']??null,'created_at'=>$now,'updated_at'=>$now]]);
            if (empty($inserted)) cm_err('Failed to create contract', 500);
            $newId = (int)$inserted[0]['id'];
            cm_sb('psm_contract_audit_log', 'POST', [], [['contract_id'=>$newId,'action_label'=>'Contract Created','actor_name'=>$actor,'dot_class'=>'dot-g','ip_address'=>$ip,'occurred_at'=>$now]]);
            $rows = cm_sb('psm_contracts', 'GET', ['select'=>$SELECT,'id'=>'eq.'.$newId,'limit'=>1]);
            cm_ok(cm_build_full($rows[0], $showSa));
        }

        if ($api === 'action' && $method === 'POST') {
            $b      = cm_body();
            $id     = (int)trim($b['id']   ?? 0);
            $type   = trim($b['type']      ?? '');
            $reason = trim($b['reason']    ?? '');
            $now    = date('Y-m-d H:i:s');
            $SELECT = 'id,contract_no,po_ref,supplier,contract_type,value,start_date,expiry_date,legal_status,status,renewal,notes,sa_notes,created_by,created_at,updated_at';
            if (!$id)     cm_err('Missing id', 400);
            if ($type === '') cm_err('Missing type', 400);

            // Permission gates per action
            $permMap = [
                'terminate'      => 'terminate',
                'archive'        => 'archive',
                'send_legal'     => 'sendLegal',
                'legal_approve'  => 'terminate',   // SA only — reuse gate
                'legal_reject'   => 'terminate',   // SA only
                'toggle_renewal' => 'flagRenewal',
            ];
            if (isset($permMap[$type]) && !$cmCan[$permMap[$type]]) {
                cm_err('Permission denied: your role cannot perform this action', 403);
            }

            $rows = cm_sb('psm_contracts', 'GET', ['select'=>'id,contract_no,status,legal_status','id'=>'eq.'.$id,'limit'=>1]);
            if (empty($rows)) cm_err('Contract not found', 404);
            $contract = $rows[0];

            if ($type === 'terminate') {
                if ($reason === '') cm_err('Reason is required to terminate', 400);
                $termNotes = 'Terminated: ' . $reason . (isset($b['notes']) ? ' — ' . $b['notes'] : '');
                cm_sb('psm_contracts', 'PATCH', ['id'=>'eq.'.$id], ['status'=>'Terminated','sa_notes'=>$termNotes,'updated_at'=>$now]);
                cm_sb('psm_contract_audit_log', 'POST', [], [['contract_id'=>$id,'action_label'=>'Contract Terminated — '.$reason,'actor_name'=>$actor,'dot_class'=>'dot-r','ip_address'=>$ip,'occurred_at'=>$now]]);
            } elseif ($type === 'archive') {
                cm_sb('psm_contracts', 'PATCH', ['id'=>'eq.'.$id], ['status'=>'Archived','updated_at'=>$now]);
                cm_sb('psm_contract_audit_log', 'POST', [], [['contract_id'=>$id,'action_label'=>'Contract Archived'.($reason?' — '.$reason:''),'actor_name'=>$actor,'dot_class'=>'dot-gy','ip_address'=>$ip,'occurred_at'=>$now]]);
            } elseif ($type === 'send_legal') {
                $officer  = trim($b['officer']  ?? '');
                $priority = trim($b['priority'] ?? 'Normal');
                $lNotes   = trim($b['notes']    ?? '');
                $newStat  = in_array($contract['status'], ['Terminated','Archived'], true) ? $contract['status'] : 'Under Review';
                cm_sb('psm_contracts', 'PATCH', ['id'=>'eq.'.$id], ['legal_status'=>'Under Review','status'=>$newStat,'updated_at'=>$now]);
                cm_sb('psm_contract_audit_log', 'POST', [], [['contract_id'=>$id,'action_label'=>'Sent to Legal — '.$officer.' ('.$priority.')'.($lNotes?': '.$lNotes:''),'actor_name'=>$actor,'dot_class'=>'dot-pu','ip_address'=>$ip,'occurred_at'=>$now]]);
            } elseif ($type === 'legal_approve') {
                cm_sb('psm_contracts', 'PATCH', ['id'=>'eq.'.$id], ['legal_status'=>'Approved','updated_at'=>$now]);
                cm_sb('psm_contract_audit_log', 'POST', [], [['contract_id'=>$id,'action_label'=>'Legal Status set to Approved'.($reason?' — '.$reason:''),'actor_name'=>$actor,'dot_class'=>'dot-g','ip_address'=>$ip,'occurred_at'=>$now]]);
            } elseif ($type === 'legal_reject') {
                if ($reason === '') cm_err('Reason is required to reject', 400);
                cm_sb('psm_contracts', 'PATCH', ['id'=>'eq.'.$id], ['legal_status'=>'Rejected','updated_at'=>$now]);
                cm_sb('psm_contract_audit_log', 'POST', [], [['contract_id'=>$id,'action_label'=>'Legal Status rejected — '.$reason,'actor_name'=>$actor,'dot_class'=>'dot-r','ip_address'=>$ip,'occurred_at'=>$now]]);
            } elseif ($type === 'toggle_renewal') {
                $newRenewal = (int)($b['currentRenewal'] ?? 0) ? 0 : 1;
                cm_sb('psm_contracts', 'PATCH', ['id'=>'eq.'.$id], ['renewal'=>$newRenewal,'updated_at'=>$now]);
                cm_sb('psm_contract_audit_log', 'POST', [], [['contract_id'=>$id,'action_label'=>$newRenewal?'Flagged for Renewal':'Renewal flag removed','actor_name'=>$actor,'dot_class'=>$newRenewal?'dot-b':'dot-gy','ip_address'=>$ip,'occurred_at'=>$now]]);
            } else {
                cm_err('Unsupported action type', 400);
            }

            $rows = cm_sb('psm_contracts', 'GET', ['select'=>$SELECT,'id'=>'eq.'.$id,'limit'=>1]);
            cm_ok(cm_build_full($rows[0], $showSa));
        }

        if ($api === 'upload_doc' && $method === 'POST') {
            if (!$cmCan['uploadDoc']) cm_err('Permission denied: only Super Admin can upload contract documents', 403);
            $contractId = (int)($_POST['contractId'] ?? 0);
            if (!$contractId)           cm_err('Missing contractId', 400);
            if (empty($_FILES['file'])) cm_err('No file received', 400);
            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) cm_err('PHP upload error code: ' . $file['error'], 400);
            $origName = basename($file['name']);
            $bytes    = $file['size'];
            $fileSize = $bytes > 1048576 ? round($bytes/1048576,1).' MB' : round($bytes/1024).' KB';
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $fileType = ($ext === 'docx') ? 'docx' : 'pdf';
            $mimeType = ($fileType === 'docx') ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf';
            $bucket       = 'contract-docs';
            $pathInBucket = $contractId . '/' . date('YmdHis') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
            $storageUrl   = SUPABASE_URL . '/storage/v1/object/' . $bucket . '/' . $pathInBucket;
            $now          = date('Y-m-d H:i:s');
            $crt = curl_init(SUPABASE_URL . '/storage/v1/bucket');
            curl_setopt_array($crt, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_HTTPHEADER=>['Content-Type: application/json','apikey: '.SUPABASE_SERVICE_ROLE_KEY,'Authorization: Bearer '.SUPABASE_SERVICE_ROLE_KEY],CURLOPT_POSTFIELDS=>json_encode(['id'=>$bucket,'name'=>$bucket,'public'=>true])]);
            curl_exec($crt); curl_close($crt);
            $fp = fopen($file['tmp_name'], 'rb');
            $ch = curl_init($storageUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_PUT=>true,CURLOPT_INFILE=>$fp,CURLOPT_INFILESIZE=>$bytes,CURLOPT_HTTPHEADER=>['Content-Type: '.$mimeType,'apikey: '.SUPABASE_SERVICE_ROLE_KEY,'Authorization: Bearer '.SUPABASE_SERVICE_ROLE_KEY,'x-upsert: true']]);
            $storageRes  = curl_exec($ch);
            $storageCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch); fclose($fp);
            if ($storageCode >= 400) { $sd = json_decode($storageRes, true); cm_err('Storage upload failed ('.$storageCode.'): '.($sd['message']??$storageRes), 500); }
            $filePath = $bucket . '/' . $pathInBucket;
            $inserted = cm_sb('psm_contract_documents', 'POST', [], [['contract_id'=>$contractId,'file_name'=>$origName,'file_size'=>$fileSize,'file_type'=>$fileType,'file_path'=>$filePath,'uploaded_by'=>$actor,'uploaded_at'=>$now]]);
            if (empty($inserted)) cm_err('Failed to save document record', 500);
            cm_sb('psm_contract_audit_log', 'POST', [], [['contract_id'=>$contractId,'action_label'=>'Document uploaded: '.$origName,'actor_name'=>$actor,'dot_class'=>'dot-b','ip_address'=>$ip,'occurred_at'=>$now]]);
            cm_ok(['id'=>(int)$inserted[0]['id'],'name'=>$origName,'size'=>$fileSize,'type'=>$fileType,'filePath'=>$filePath,'uploadedBy'=>$actor,'uploadedAt'=>$now]);
        }

        if ($api === 'delete_doc' && $method === 'POST') {
            if (!$cmCan['deleteDoc']) cm_err('Permission denied: only Super Admin can delete contract documents', 403);
            $b          = cm_body();
            $docId      = (int)($b['docId']      ?? 0);
            $contractId = (int)($b['contractId'] ?? 0);
            $now        = date('Y-m-d H:i:s');
            if (!$docId || !$contractId) cm_err('Missing docId or contractId', 400);
            $docRows = cm_sb('psm_contract_documents', 'GET', ['select'=>'file_name,file_path','id'=>'eq.'.$docId,'limit'=>1]);
            if (empty($docRows)) cm_err('Document not found', 404);
            $docName  = $docRows[0]['file_name'] ?? '';
            $filePath = $docRows[0]['file_path'] ?? '';
            if ($filePath !== '') {
                $slashPos   = strpos($filePath, '/');
                $bucket     = $slashPos !== false ? substr($filePath, 0, $slashPos) : 'contract-docs';
                $objectPath = $slashPos !== false ? substr($filePath, $slashPos + 1) : $filePath;
                $delUrl = SUPABASE_URL . '/storage/v1/object/' . $bucket;
                $dch = curl_init($delUrl);
                curl_setopt_array($dch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'DELETE',CURLOPT_HTTPHEADER=>['Content-Type: application/json','apikey: '.SUPABASE_SERVICE_ROLE_KEY,'Authorization: Bearer '.SUPABASE_SERVICE_ROLE_KEY],CURLOPT_POSTFIELDS=>json_encode(['prefixes'=>[$objectPath]])]);
                curl_exec($dch); curl_close($dch);
            }
            cm_sb('psm_contract_documents', 'DELETE', ['id'=>'eq.'.$docId]);
            cm_sb('psm_contract_audit_log', 'POST', [], [['contract_id'=>$contractId,'action_label'=>'Document deleted: '.$docName,'actor_name'=>$actor,'dot_class'=>'dot-r','ip_address'=>$ip,'occurred_at'=>$now]]);
            cm_ok(['deleted' => true]);
        }

        cm_err('Unsupported API route', 404);
    } catch (Throwable $e) {
        cm_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── HTML PAGE RENDER ──────────────────────────────────────────────────────────
$root_include = dirname(__DIR__, 2);
include $root_include . '/includes/superadmin_sidebar.php';
include $root_include . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contract Management — PSM</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
    <link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
    <link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
#mainContent,#panel,#modal,#termModal,#legalModal,#archiveModal,#delDocModal,#tw{
    --surface:#FFFFFF;--border:rgba(46,125,50,.14);--border-mid:rgba(46,125,50,.22);
    --text-1:var(--text-primary);--text-2:var(--text-secondary);--text-3:#9EB0A2;
    --hover-s:var(--hover-bg-light);--shadow-sm:var(--shadow-light);
    --shadow-md:0 4px 16px rgba(46,125,50,.12);--shadow-xl:0 20px 60px rgba(0,0,0,.22);
    --radius:12px;--tr:var(--transition);--danger:#DC2626;--warning:#D97706;
    --info:#2563EB;--purple:#7C3AED;--bg:var(--bg-color);
    --primary:var(--primary-color);--prim-dark:var(--primary-dark);
}
#mainContent *,#panel *,#modal *,#termModal *,#legalModal *,#archiveModal *,#delDocModal *,#tw *{box-sizing:border-box;}
.sa-badge,.role-badge,.user-role-badge,.header-role,.badge-superadmin,[class*="role-badge"],.header-user-role{display:none!important;}
.cm-page{max-width:100%;margin:0 auto;padding:0 0 3rem;overflow-x:hidden;}
#mainContent{overflow-x:hidden;min-width:0;}

/* ── ROLE BADGE ── */
.role-tag{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;letter-spacing:.04em;vertical-align:middle;margin-left:10px;}
.role-tag.sa   {background:#E8F5E9;color:var(--primary); border:1px solid rgba(46,125,50,.25);}
.role-tag.admin{background:#EFF6FF;color:var(--info);    border:1px solid rgba(37,99,235,.2);}
.role-tag.mgr  {background:#FEF3C7;color:var(--warning); border:1px solid rgba(217,119,6,.2);}

/* ── ACCESS NOTICES ── */
.access-notice{display:flex;align-items:flex-start;gap:10px;border-radius:12px;padding:12px 16px;margin-bottom:20px;animation:cmFU .4s .05s both;font-size:12.5px;line-height:1.55;}
.access-notice i{font-size:18px;flex-shrink:0;margin-top:1px;}
.access-notice.amber{background:linear-gradient(135deg,#FEF3C7,#FFFBEB);border:1px solid rgba(217,119,6,.3);color:#92400E;}
.access-notice.amber i{color:var(--warning);}
.access-notice.amber strong{color:#78350F;}
.access-notice.blue {background:linear-gradient(135deg,#EFF6FF,#F0F9FF);border:1px solid rgba(37,99,235,.2);color:#1E3A5F;}
.access-notice.blue  i{color:var(--info);}
.access-notice.blue strong{color:#1E40AF;}

.cm-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px;animation:cmFU .4s both;}
.cm-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--primary);margin-bottom:4px;}
.cm-ph h1{font-size:26px;font-weight:800;color:var(--text-1);line-height:1.15;display:flex;align-items:center;flex-wrap:wrap;}
.cm-acts{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-p{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3);}
.btn-p:hover{background:var(--prim-dark);transform:translateY(-1px);}
.btn-g{background:var(--surface);color:var(--text-2);border:1px solid var(--border-mid);}
.btn-g:hover{background:var(--hover-s);color:var(--text-1);}
.btn-s{font-size:12px;padding:6px 13px;}
.btn-danger{background:var(--danger);color:#fff;box-shadow:0 2px 8px rgba(220,38,38,.3);}
.btn-danger:hover{background:#B91C1C;transform:translateY(-1px);}
.btn-purple{background:var(--purple);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.3);}
.btn-purple:hover{background:#6D28D9;transform:translateY(-1px);}
.btn-warn{background:var(--warning);color:#fff;}
.btn-warn:hover{background:#B45309;transform:translateY(-1px);}
.btn:disabled{opacity:.45;pointer-events:none;}
.cm-banner{background:linear-gradient(135deg,#FFFBEB,#FEF3C7);border:1px solid #FDE68A;border-radius:12px;padding:12px 18px;margin-bottom:18px;display:flex;align-items:center;gap:12px;animation:cmFU .4s .05s both;}
.cm-banner.hidden{display:none;}
.cm-banner i{font-size:20px;color:var(--warning);flex-shrink:0;}
.cm-banner-txt{flex:1;font-size:13px;color:#78350F;}
.cm-banner-txt strong{color:var(--warning);}
.cm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:22px;}
.cm-stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;box-shadow:var(--shadow-sm);display:flex;align-items:center;gap:10px;animation:cmFU .4s both;}
.cm-stat:nth-child(1){animation-delay:.04s}.cm-stat:nth-child(2){animation-delay:.08s}.cm-stat:nth-child(3){animation-delay:.12s}.cm-stat:nth-child(4){animation-delay:.16s}.cm-stat:nth-child(5){animation-delay:.2s}
.st-ic{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.ic-g{background:#E8F5E9;color:var(--primary);}.ic-o{background:#FEF3C7;color:var(--warning);}.ic-r{background:#FEE2E2;color:var(--danger);}.ic-b{background:#EFF6FF;color:var(--info);}.ic-pu{background:#EDE9FE;color:var(--purple);}
.st-v{font-size:21px;font-weight:800;line-height:1;}.st-l{font-size:11px;color:var(--text-2);margin-top:2px;}
.cm-toolbar{display:flex;align-items:center;gap:8px;margin-bottom:16px;animation:cmFU .4s .1s both;flex-wrap:nowrap;overflow-x:auto;overflow-y:visible;padding-bottom:2px;min-width:0;}
.cm-toolbar::-webkit-scrollbar{height:3px;}.cm-toolbar::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px;}
.cm-sw{position:relative;flex:0 0 320px;min-width:0;}
.cm-sw i{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--text-3);pointer-events:none;}
.cm-sin{width:100%;height:34px;padding:0 10px 0 30px;font-family:'Inter',sans-serif;font-size:12px;border:1px solid var(--border-mid);border-radius:9px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);}
.cm-sin:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12);}
.cm-sin::placeholder{color:var(--text-3);}
.fsel{font-family:'Inter',sans-serif;font-size:12px;height:34px;padding:0 26px 0 10px;border:1px solid var(--border-mid);border-radius:9px;background:var(--surface);color:var(--text-1);cursor:pointer;outline:none;transition:var(--tr);appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;flex-shrink:0;white-space:nowrap;}
.fsel:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12);}
.filter-pill{display:inline-flex;flex-direction:row;flex-wrap:nowrap;align-items:center;background:var(--surface);border:1px solid var(--border-mid);border-radius:9px;flex-shrink:0;height:34px;overflow:hidden;width:max-content;}
.filter-pill .pill-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);padding:0 8px;background:var(--bg);border-right:1px solid var(--border-mid);height:34px;display:flex;align-items:center;white-space:nowrap;flex-shrink:0;}
.filter-pill input[type=date]{font-family:'Inter',sans-serif;font-size:11px;border:none;border-radius:0;outline:none;background:transparent;color:var(--text-1);padding:0 4px;height:34px;width:120px;min-width:120px;max-width:120px;flex:0 0 120px;cursor:pointer;box-sizing:border-box;}
.filter-pill .pill-sep{font-size:11px;color:var(--text-3);padding:0 2px;flex-shrink:0;white-space:nowrap;}
.filter-pill input[type=date]:focus{background:rgba(46,125,50,.06);}
.clear-btn{font-size:11px;font-weight:600;color:var(--text-3);background:none;border:1px solid var(--border-mid);cursor:pointer;padding:0 10px;border-radius:9px;transition:var(--tr);white-space:nowrap;display:inline-flex;align-items:center;gap:4px;flex-shrink:0;height:34px;}
.clear-btn:hover{color:var(--danger);background:#FEE2E2;border-color:#FECACA;}
.cm-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-md);animation:cmFU .4s .15s both;}
.cm-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
.cm-table{width:100%;min-width:760px;border-collapse:collapse;font-size:12px;}
.cm-table thead th{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-2);padding:11px 12px;text-align:left;background:var(--bg);border-bottom:1px solid var(--border);white-space:nowrap;user-select:none;cursor:pointer;}
.cm-table thead th:hover{color:var(--primary);}.cm-table thead th.sorted{color:var(--primary);}
.cm-table thead th:first-child{padding-left:16px;}.cm-table thead th:last-child{padding-right:12px;}
.cm-table tbody tr{border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;}
.cm-table tbody tr:last-child{border-bottom:none;}.cm-table tbody tr:hover{background:var(--hover-s);}
.cm-table tbody tr.row-expiring{background:rgba(217,119,6,.03);}.cm-table tbody tr.row-expiring:hover{background:rgba(217,119,6,.07);}
.cm-table tbody tr.row-expired{background:rgba(220,38,38,.03);}.cm-table tbody tr.row-expired:hover{background:rgba(220,38,38,.07);}
.cm-table tbody tr.row-archived{opacity:.65;}
.cm-table tbody td{padding:11px 12px;vertical-align:middle;}
.cm-table tbody td:first-child{padding-left:16px;}.cm-table tbody td:last-child{padding-right:12px;white-space:nowrap;}
.cn-val{font-family:'DM Mono',monospace;font-size:11px;font-weight:600;color:var(--primary);}.cn-type{font-size:10px;color:var(--text-3);margin-top:2px;}
.sup-name{font-size:12px;font-weight:600;color:var(--text-1);}
.po-ref{font-family:'DM Mono',monospace;font-size:11px;font-weight:600;color:var(--info);}
.val-cell{font-size:12px;font-weight:700;font-family:'DM Mono',monospace;}
.date-val{font-size:11px;color:var(--text-2);}
.chip{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;}
.chip::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0;}
.cc-active{background:#E8F5E9;color:#166534;}.cc-expiring{background:#FEF3C7;color:var(--warning);}
.cc-expired{background:#FEE2E2;color:var(--danger);}.cc-terminated{background:#1F2937;color:#D1D5DB;}
.cc-review{background:#EDE9FE;color:var(--purple);}.cc-archived{background:#F3F4F6;color:#6B7280;}
.cc-l-ok{background:#E8F5E9;color:var(--primary);}.cc-l-pend{background:#EDE9FE;color:var(--purple);}
.cc-l-rev{background:#FEF3C7;color:var(--warning);}.cc-l-rej{background:#FEE2E2;color:var(--danger);}
.days-badge{display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:700;padding:2px 7px;border-radius:7px;}
.db-ok{background:#E8F5E9;color:var(--primary);}.db-warn{background:#FEF3C7;color:var(--warning);}.db-crit{background:#FEE2E2;color:var(--danger);}
.row-acts{display:flex;align-items:center;gap:3px;}
.icon-btn{width:26px;height:26px;border-radius:7px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:13px;color:var(--text-2);transition:var(--tr);}
.icon-btn:hover{background:var(--hover-s);border-color:var(--primary);color:var(--primary);}
.icon-btn.danger:hover{background:#FEE2E2;border-color:#FECACA;color:var(--danger);}
.icon-btn.purple:hover{background:#EDE9FE;border-color:#DDD6FE;color:var(--purple);}
.icon-btn.warn:hover{background:#FEF3C7;border-color:#FDE68A;color:var(--warning);}
.cm-card-ft{padding:12px 20px;border-top:1px solid var(--border);background:linear-gradient(135deg,rgba(46,125,50,.03),var(--bg));display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.ft-info{font-size:12px;color:var(--text-2);}
.pbtns{display:flex;gap:5px;}
.pb{width:30px;height:30px;border-radius:7px;border:1px solid var(--border-mid);background:var(--surface);font-family:'Inter',sans-serif;font-size:12px;font-weight:500;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--text-1);}
.pb:hover{background:var(--hover-s);border-color:var(--primary);color:var(--primary);}.pb.active{background:var(--primary);border-color:var(--primary);color:#fff;}.pb:disabled{opacity:.4;pointer-events:none;}
.cm-empty{padding:60px 20px;text-align:center;color:var(--text-3);}
.cm-empty i{font-size:44px;display:block;margin-bottom:10px;color:#C8E6C9;}.cm-empty p{font-size:14px;}
.cm-ov{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1100;opacity:0;pointer-events:none;transition:opacity .25s;}
.cm-ov.show{opacity:1;pointer-events:all;}
#panel{position:fixed;top:0;right:0;bottom:0;width:520px;max-width:92vw;background:var(--surface);box-shadow:-4px 0 40px rgba(0,0,0,.18);z-index:1101;transform:translateX(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;}
#panel.open{transform:translateX(0);}
.p-hd{display:flex;align-items:center;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--border);background:var(--bg);flex-shrink:0;}
.p-title{font-size:17px;font-weight:700;color:var(--text-1);}.p-sub{font-size:12px;color:var(--text-2);margin-top:2px;}
.p-cl{width:34px;height:34px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:19px;color:var(--text-2);transition:var(--tr);}
.p-cl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA;}
.p-body{flex:1;overflow-y:auto;padding:22px 24px;display:flex;flex-direction:column;gap:16px;}
.p-body::-webkit-scrollbar{width:4px;}.p-body::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px;}
.p-ft{padding:14px 24px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;}
.fg{display:flex;flex-direction:column;gap:5px;}.fr{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fl{font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.06em;}.fl span{color:var(--danger);margin-left:2px;}
.fi,.fs,.fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);width:100%;}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12);}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:32px;}
.fta{resize:vertical;min-height:72px;}
.sdv{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);display:flex;align-items:center;gap:10px;margin:4px 0;}
.sdv::after{content:'';flex:1;height:1px;background:var(--border);}
.sa-section{background:linear-gradient(135deg,rgba(27,94,32,.04),rgba(46,125,50,.06));border:1px solid rgba(46,125,50,.2);border-radius:12px;padding:14px;}
.sa-hd{display:flex;align-items:center;gap:8px;margin-bottom:12px;}.sa-hd i{color:var(--primary);font-size:15px;}.sa-hd span{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--primary);}
.upload-zone{border:2px dashed var(--border-mid);border-radius:12px;padding:22px;text-align:center;cursor:pointer;transition:var(--tr);background:var(--bg);}
.upload-zone:hover,.upload-zone.dragover{border-color:var(--primary);background:#F0FBF1;}
.upload-zone i{font-size:30px;color:var(--primary);display:block;margin-bottom:8px;}
.upload-zone p{font-size:13px;color:var(--text-2);}.upload-zone small{font-size:11px;color:var(--text-3);}
.upload-fn{margin-top:8px;font-size:12px;font-weight:600;color:var(--primary);display:none;}.upload-fn.show{display:block;}
#modal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;}
#modal.show{opacity:1;pointer-events:all;}
.mbox{background:var(--surface);border-radius:20px;width:820px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:var(--shadow-xl);transform:scale(.96);transition:transform .25s;overflow:hidden;}
#modal.show .mbox{transform:scale(1);}
.m-hd{padding:22px 26px 0;border-bottom:1px solid var(--border);background:var(--bg);flex-shrink:0;}
.m-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;}
.m-ic{width:48px;height:48px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;background:#E8F5E9;color:var(--primary);flex-shrink:0;}
.m-nm{font-size:18px;font-weight:800;color:var(--text-1);font-family:'DM Mono',monospace;}.m-id{font-size:12px;color:var(--text-2);margin-top:3px;}
.m-cl{width:34px;height:34px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:19px;color:var(--text-2);transition:var(--tr);}
.m-cl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA;}
.m-meta{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;}
.m-mc{display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text-2);background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:4px 10px;}.m-mc i{font-size:13px;color:var(--primary);}
.m-tabs{display:flex;gap:4px;}
.m-tab{font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px 8px 0 0;cursor:pointer;transition:var(--tr);color:var(--text-2);border:none;background:transparent;}
.m-tab:hover{background:var(--hover-s);}.m-tab.active{background:var(--primary);color:#fff;}
.m-body{flex:1;overflow-y:auto;padding:22px 26px;}
.m-body::-webkit-scrollbar{width:4px;}.m-body::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px;}
.m-ft{padding:14px 26px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap;}
.m-tp{display:none;}.m-tp.active{display:block;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
.info-item label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);display:block;margin-bottom:3px;}
.info-item .v{font-size:13px;font-weight:500;color:var(--text-1);}.info-full{grid-column:1/-1;}
.sbs{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px;}
.sb{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px 14px;}
.sb .sbv{font-size:17px;font-weight:800;color:var(--text-1);}.sb .sbl{font-size:11px;color:var(--text-2);margin-top:2px;}
.bar-wrap{width:100%;height:5px;background:var(--border);border-radius:4px;overflow:hidden;margin-top:5px;}
.bar-fill{height:100%;border-radius:4px;transition:width .6s;}.bar-g{background:var(--primary);}.bar-o{background:var(--warning);}.bar-r{background:var(--danger);}
.doc-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg);margin-bottom:8px;transition:var(--tr);}
.doc-item:hover{border-color:var(--border-mid);background:var(--hover-s);}
.doc-ic{width:34px;height:34px;border-radius:8px;background:#EFF6FF;color:var(--info);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.doc-name{font-size:13px;font-weight:600;color:var(--text-1);}.doc-meta{font-size:11px;color:var(--text-3);margin-top:1px;}
.doc-acts{margin-left:auto;display:flex;gap:5px;}
.tl-item{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);}
.tl-item:last-child{border-bottom:none;}
.tl-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px;}
.dot-g{background:var(--primary)}.dot-r{background:var(--danger)}.dot-o{background:var(--warning)}.dot-b{background:var(--info)}.dot-pu{background:var(--purple)}.dot-gy{background:#9CA3AF;}
.tl-act{font-size:13px;font-weight:500;color:var(--text-1);}.tl-ts{font-size:11px;color:var(--text-3);font-family:'DM Mono',monospace;margin-top:2px;}

/* Modals */
#termModal,#legalModal,#archiveModal,#delDocModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1300;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s;}
#termModal.show,#legalModal.show,#archiveModal.show,#delDocModal.show{opacity:1;pointer-events:all;}
.term-box,.legal-box,.archive-box,.del-doc-box{background:var(--surface);border-radius:20px;width:460px;max-width:100%;box-shadow:var(--shadow-xl);transform:scale(.95);transition:transform .2s;overflow:hidden;}
#termModal.show .term-box,#legalModal.show .legal-box,#archiveModal.show .archive-box,#delDocModal.show .del-doc-box{transform:scale(1);}
.del-doc-box{width:420px;}
.term-hd,.legal-hd,.archive-hd,.del-doc-hd{padding:20px 24px 16px;border-bottom:1px solid var(--border);display:flex;gap:12px;align-items:flex-start;background:var(--bg);}
.term-ic{width:42px;height:42px;border-radius:11px;background:#FEE2E2;color:var(--danger);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.legal-ic{width:42px;height:42px;border-radius:11px;background:#EDE9FE;color:var(--purple);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.archive-ic{width:42px;height:42px;border-radius:11px;background:#F3F4F6;color:#6B7280;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.del-doc-ic{width:42px;height:42px;border-radius:11px;background:#FEE2E2;color:var(--danger);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.term-title,.legal-title{font-size:15px;font-weight:700;color:var(--text-1);}
.term-sub,.legal-sub{font-size:12px;color:var(--text-2);margin-top:3px;}
.term-body,.legal-body,.archive-body,.del-doc-body{padding:18px 24px;display:flex;flex-direction:column;gap:12px;}
.term-warn,.del-doc-warn{background:#FFF5F5;border:1px solid #FECACA;border-radius:10px;padding:12px;font-size:12px;color:#7F1D1D;display:flex;gap:8px;}
.term-warn i,.del-doc-warn i{font-size:15px;flex-shrink:0;color:var(--danger);}
.term-ft,.legal-ft,.archive-ft,.del-doc-ft{padding:12px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:var(--bg);}
.del-doc-fname{font-size:13px;font-weight:600;color:var(--text-1);padding:10px 12px;background:var(--bg);border:1px solid var(--border);border-radius:8px;word-break:break-all;}

#tw{position:fixed;bottom:28px;right:28px;display:flex;flex-direction:column;gap:10px;z-index:9999;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:11px 16px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow-xl);pointer-events:all;min-width:220px;animation:toastIn .3s ease;}
.toast.success{background:var(--primary);}.toast.warning{background:var(--warning);}.toast.danger{background:var(--danger);}.toast.info{background:var(--info);}.toast.purple{background:var(--purple);}
.toast.out{animation:toastOut .3s ease forwards;}
@keyframes cmFU{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastOut{from{opacity:1}to{opacity:0;transform:translateY(12px)}}
@keyframes shake{0%,100%{transform:translateX(0)}25%,75%{transform:translateX(-5px)}50%{transform:translateX(5px)}}
@media(max-width:768px){#panel{width:100vw;max-width:100vw;}.fr,.info-grid{grid-template-columns:1fr;}.sbs{grid-template-columns:1fr 1fr;}.cm-stats{grid-template-columns:1fr 1fr;}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="cm-page">

    <div class="cm-ph">
        <div>
            <p class="ey">PSM · Procurement &amp; Sourcing Management</p>
            <h1>Contract Management
                <span class="role-tag <?= $tagCls ?>"><i class='bx <?= $tagIcon ?>'></i><?= htmlspecialchars($cmRoleName) ?></span>
            </h1>
        </div>
        <div class="cm-acts">
            <?php if ($cmCan['viewBanner']): ?>
            <button class="btn btn-g" id="expiringBtn"><i class='bx bx-time-five'></i> Expiring Soon</button>
            <?php endif; ?>
            <?php if ($cmCan['exportData']): ?>
            <button class="btn btn-g" id="exportBtn"><i class='bx bx-export'></i> Export</button>
            <?php endif; ?>
            <?php if ($cmCan['create']): ?>
            <button class="btn btn-p" id="addBtn"><i class='bx bx-plus'></i> New Contract</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($cmRoleRank === 2): ?>
    <div class="access-notice amber">
        <i class='bx bx-info-circle'></i>
        <p><strong>Manager View:</strong> You can monitor Active and Expiring Soon contracts in your zone (<strong><?= htmlspecialchars($cmUserZone ?: 'All') ?></strong>). Editing, sending to Legal, terminating, and archiving contracts require Admin or Super Admin access.</p>
    </div>
    <?php elseif ($cmRoleRank === 3): ?>
    <div class="access-notice blue">
        <i class='bx bx-info-circle'></i>
        <p><strong>Admin View:</strong> You can view contracts, send to Legal, and flag for renewal in zone <strong><?= htmlspecialchars($cmUserZone ?: 'All') ?></strong>. Creating, editing, terminating, archiving, and document management require Super Admin access.</p>
    </div>
    <?php endif; ?>

    <?php if ($cmCan['viewBanner']): ?>
    <div class="cm-banner hidden" id="expiryBanner">
        <i class='bx bx-error'></i>
        <div class="cm-banner-txt"><strong>Expiry Alert:</strong> <span id="bannerTxt"></span> — Review and flag for renewal immediately.</div>
        <button class="btn btn-warn btn-s" id="bannerViewBtn">View Now</button>
    </div>
    <?php endif; ?>

    <div class="cm-stats" id="statsRow"></div>

    <div class="cm-toolbar">
        <div class="cm-sw">
            <i class='bx bx-search'></i>
            <input type="text" class="cm-sin" id="srch" placeholder="Search contract no., supplier…">
        </div>
        <select class="fsel" id="fStatus">
            <option value="">All Statuses</option>
            <option>Active</option>
            <option>Expiring Soon</option>
            <?php if ($cmCan['viewExpired']): ?>
            <option>Expired</option>
            <option>Under Review</option>
            <option>Terminated</option>
            <option>Archived</option>
            <?php endif; ?>
        </select>
        <select class="fsel" id="fSupplier"><option value="">All Suppliers</option></select>
        <div class="filter-pill">
            <span class="pill-lbl">EXPIRY</span>
            <input type="date" id="fDateFrom" title="Expiry from">
            <span class="pill-sep">—</span>
            <input type="date" id="fDateTo" title="Expiry to" style="border-left:1px solid var(--border-mid);">
        </div>
        <button class="clear-btn" id="clearFilters"><i class='bx bx-x'></i> Clear</button>
    </div>

    <div class="cm-card">
        <div class="cm-wrap">
            <table class="cm-table">
                <thead>
                    <tr>
                        <th data-col="contractNo">Contract No.</th>
                        <th data-col="supplier">Supplier</th>
                        <?php if ($cmCan['viewExpired']): // Admin+ sees PO Ref ?>
                        <th data-col="poRef">PO Reference</th>
                        <?php endif; ?>
                        <th data-col="value">Contract Value</th>
                        <th data-col="startDate">Start Date</th>
                        <th data-col="expiryDate">Expiry Date</th>
                        <?php if ($cmCan['viewExpired']): ?>
                        <th data-col="legalStatus">Legal Status</th>
                        <?php endif; ?>
                        <th data-col="computedStatus">Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tb"></tbody>
            </table>
            <div id="cmEmpty" class="cm-empty" style="display:none">
                <i class='bx bx-file-blank'></i><p>No contracts match your filters.</p>
            </div>
        </div>
        <div class="cm-card-ft">
            <div class="ft-info" id="ftInfo"></div>
            <div class="pbtns" id="pagBtns"></div>
        </div>
    </div>
</div>
</main>

<div class="cm-ov" id="mainOv"></div>

<?php if ($cmCan['create']): ?>
<!-- SIDE PANEL (SA only) -->
<div id="panel">
    <div class="p-hd">
        <div><div class="p-title" id="pTitle">New Contract</div><div class="p-sub" id="pSub">Link a contract to an approved purchase order</div></div>
        <button class="p-cl" id="pCl"><i class='bx bx-x'></i></button>
    </div>
    <div class="p-body">
        <div class="sdv">Contract Identity</div>
        <div class="fr">
            <div class="fg"><label class="fl">Contract No. <span>*</span></label><input type="text" class="fi" id="fCN" placeholder="Auto-generated"></div>
            <div class="fg"><label class="fl">PO Reference <span>*</span></label><input type="text" class="fi" id="fPO" list="poList" placeholder="Search PO…"><datalist id="poList"></datalist></div>
        </div>
        <div class="fg"><label class="fl">Supplier <span>*</span></label><select class="fs" id="fSup"><option value="">Select supplier…</option></select></div>
        <div class="fr">
            <div class="fg"><label class="fl">Contract Type</label><select class="fs" id="fType"><option>Supply Agreement</option><option>Service Agreement</option><option>Framework Agreement</option><option>Blanket Order</option><option>One-Time Purchase</option></select></div>
            <div class="fg"><label class="fl">Contract Value (₱) <span>*</span></label><input type="number" class="fi" id="fVal" placeholder="0.00" min="0" step="0.01"></div>
        </div>
        <div class="sdv">Dates</div>
        <div class="fr">
            <div class="fg"><label class="fl">Start Date <span>*</span></label><input type="date" class="fi" id="fSD"></div>
            <div class="fg"><label class="fl">Expiry Date <span>*</span></label><input type="date" class="fi" id="fED"></div>
        </div>
        <div class="sdv">Status &amp; Legal</div>
        <div class="fr">
            <div class="fg"><label class="fl">Contract Status</label><select class="fs" id="fSt"><option>Active</option><option>Under Review</option><option>Expiring Soon</option><option>Expired</option></select></div>
            <div class="fg"><label class="fl">Legal Status</label><select class="fs" id="fLS"><option>Pending Review</option><option>Under Review</option><option>Approved</option><option>Rejected</option></select></div>
        </div>
        <div class="sdv">Document Upload</div>
        <div class="upload-zone" id="uploadZone"><i class='bx bx-cloud-upload'></i><p>Drag &amp; drop contract document here</p><small>PDF, DOCX — max 20 MB</small><div class="upload-fn" id="uploadFn"></div><input type="file" id="fileInput" accept=".pdf,.docx" style="display:none"></div>
        <div class="fg"><label class="fl">Notes / Remarks</label><textarea class="fta" id="fNo" placeholder="Special terms, conditions, or remarks…"></textarea></div>
        <div class="sa-section">
            <div class="sa-hd"><i class='bx bx-shield-quarter'></i><span>Super Admin Controls</span></div>
            <div class="fg" style="margin-bottom:10px"><label class="fl">Flag for Renewal</label><select class="fs" id="fRen"><option value="0">No Flag</option><option value="1">Flag for Renewal</option></select></div>
            <div class="fg"><label class="fl">SA Internal Notes</label><textarea class="fta" id="fSaN" placeholder="Internal notes — SA eyes only…" style="min-height:56px"></textarea></div>
        </div>
    </div>
    <div class="p-ft">
        <button class="btn btn-g btn-s" id="pCa">Cancel</button>
        <button class="btn btn-p btn-s" id="pSv"><i class='bx bx-check'></i> Save Contract</button>
    </div>
</div>
<?php endif; ?>

<!-- VIEW MODAL -->
<div id="modal">
    <div class="mbox">
        <div class="m-hd">
            <div class="m-top">
                <div style="display:flex;align-items:center;gap:14px">
                    <div class="m-ic"><i class='bx bx-file-blank'></i></div>
                    <div><div class="m-nm" id="mNm"></div><div class="m-id" id="mId"></div></div>
                </div>
                <button class="m-cl" id="mCl"><i class='bx bx-x'></i></button>
            </div>
            <div class="m-meta" id="mMeta"></div>
            <div class="m-tabs">
                <button class="m-tab active" data-t="ov">Overview</button>
                <button class="m-tab" data-t="docs">Documents</button>
                <button class="m-tab" data-t="hist">Audit Log</button>
            </div>
        </div>
        <div class="m-body">
            <div class="m-tp active" id="tp-ov"><div class="sbs" id="mSbs"></div><div class="info-grid" id="mInfo"></div></div>
            <div class="m-tp" id="tp-docs"><div id="mDocs"></div></div>
            <div class="m-tp" id="tp-hist"><div id="mHist"></div></div>
        </div>
        <div class="m-ft" id="mFt"></div>
    </div>
</div>

<?php if ($cmCan['terminate']): ?>
<!-- TERMINATE MODAL (SA only) -->
<div id="termModal">
    <div class="term-box">
        <div class="term-hd"><div class="term-ic"><i class='bx bx-x-circle'></i></div><div><div class="term-title">Terminate Contract</div><div class="term-sub" id="termSub"></div></div></div>
        <div class="term-body">
            <div class="term-warn"><i class='bx bx-error-circle'></i><div><strong>Super Admin Action:</strong> Terminating a contract is permanent.</div></div>
            <div class="fg"><label class="fl">Reason <span>*</span></label><select class="fs" id="termReason"><option value="">Select reason…</option><option>Supplier non-compliance</option><option>Contract breach</option><option>Budget reallocation</option><option>Project cancelled</option><option>Legal dispute</option><option>Other</option></select></div>
            <div class="fg"><label class="fl">Termination Notes</label><textarea class="fta" id="termNotes" placeholder="Provide context for this decision…" style="min-height:64px"></textarea></div>
        </div>
        <div class="term-ft">
            <button class="btn btn-g btn-s" id="termCancel">Cancel</button>
            <button class="btn btn-danger btn-s" id="termConfirm"><i class='bx bx-x-circle'></i> Confirm Termination</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($cmCan['sendLegal']): ?>
<!-- SEND TO LEGAL MODAL (SA + Admin) -->
<div id="legalModal">
    <div class="legal-box">
        <div class="legal-hd"><div class="legal-ic"><i class='bx bx-shield-quarter'></i></div><div><div class="legal-title">Send to Legal Review</div><div class="legal-sub" id="legalSub"></div></div></div>
        <div class="legal-body">
            <div class="fg"><label class="fl">Assign to Legal Officer</label><select class="fs" id="legalOfficer"><option>Atty. Maria Cruz — Corporate Law</option><option>Atty. Jose Reyes — Contracts Division</option><option>Atty. Ana Santos — Compliance</option></select></div>
            <div class="fg"><label class="fl">Review Priority</label><select class="fs" id="legalPriority"><option>Normal</option><option>Urgent</option><option>Critical</option></select></div>
            <div class="fg"><label class="fl">Notes for Legal</label><textarea class="fta" id="legalNotes" placeholder="Special instructions for the legal team…" style="min-height:64px"></textarea></div>
        </div>
        <div class="legal-ft">
            <button class="btn btn-g btn-s" id="legalCancel">Cancel</button>
            <button class="btn btn-purple btn-s" id="legalConfirm"><i class='bx bx-send'></i> Send for Review</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($cmCan['archive']): ?>
<!-- ARCHIVE MODAL (SA only) -->
<div id="archiveModal">
    <div class="archive-box">
        <div class="archive-hd"><div class="archive-ic"><i class='bx bx-archive'></i></div><div><div style="font-size:15px;font-weight:700;color:var(--text-1)">Archive Contract</div><div style="font-size:12px;color:var(--text-2);margin-top:3px" id="archiveSub"></div></div></div>
        <div class="archive-body">
            <p style="font-size:13px;color:var(--text-2)">Archived contracts are retained for reference but are no longer active. This can be reversed by a Super Admin.</p>
            <div class="fg"><label class="fl">Archive Reason</label><textarea class="fta" id="archiveReason" placeholder="Reason for archiving (optional)…" style="min-height:60px"></textarea></div>
        </div>
        <div class="archive-ft">
            <button class="btn btn-g btn-s" id="archiveCancel">Cancel</button>
            <button class="btn btn-s" style="background:#6B7280;color:#fff" id="archiveConfirm"><i class='bx bx-archive'></i> Archive</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($cmCan['deleteDoc']): ?>
<!-- DELETE DOCUMENT MODAL (SA only) -->
<div id="delDocModal">
    <div class="del-doc-box">
        <div class="del-doc-hd"><div class="del-doc-ic"><i class='bx bx-trash'></i></div><div><div style="font-size:15px;font-weight:700;color:var(--text-1)">Delete Document</div><div style="font-size:12px;color:var(--text-2);margin-top:2px" id="delDocSub">This cannot be undone.</div></div></div>
        <div class="del-doc-body">
            <div class="del-doc-warn"><i class='bx bx-error-circle'></i><div>The file will be permanently removed from storage.</div></div>
            <div class="del-doc-fname" id="delDocFname"></div>
        </div>
        <div class="del-doc-ft">
            <button class="btn btn-g btn-s" id="delDocCancel">Cancel</button>
            <button class="btn btn-danger btn-s" id="delDocConfirm"><i class='bx bx-trash'></i> Delete</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="tw"></div>

<script>
const API      = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';
const PERMS    = <?= $jsPerms ?>;
const ROLE     = <?= $jsRole ?>;
const ROLERANK = <?= $jsRoleRank ?>;
const UZONE    = <?= $jsZone ?>;
const STORAGE  = '<?= SUPABASE_URL ?>/storage/v1/object/public/';

// ── UTILS ─────────────────────────────────────────────────────────────────────
const esc      = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmtDate  = d => d ? new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'}) : '—';
const fmtMoney = v => '₱' + Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2});
const todayStr = () => new Date().toISOString().split('T')[0];
function daysUntil(d){ const n=new Date();n.setHours(0,0,0,0);const e=new Date(d);e.setHours(0,0,0,0);return Math.round((e-n)/86400000); }
function expiryBadge(d){
    const v=daysUntil(d);
    if(v<0)   return`<span class="days-badge db-crit"><i class='bx bx-error-circle' style="font-size:10px"></i> Expired</span>`;
    if(v<=30) return`<span class="days-badge db-warn"><i class='bx bx-time-five' style="font-size:10px"></i> ${v}d left</span>`;
    return`<span class="days-badge db-ok"><i class='bx bx-calendar-check' style="font-size:10px"></i> ${v}d left</span>`;
}
function expiryPct(s,e){ const st=new Date(s),en=new Date(e),n=new Date();return Math.max(0,Math.min(100,Math.round((n-st)/(en-st)*100))); }

// ── API ───────────────────────────────────────────────────────────────────────
async function apiFetch(path, opts={}) {
    const r = await fetch(path, {headers:{'Content-Type':'application/json'}, ...opts});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p => apiFetch(p);
const apiPost = (p,b) => apiFetch(p,{method:'POST',body:JSON.stringify(b)});

// ── STATE ─────────────────────────────────────────────────────────────────────
let D=[], SUPPLIERS=[], POS=[];
let sc='expiryDate', sd='asc', pg=1, PG=10;
let editId=null, viewId=null, termId=null, legalId=null, archiveId=null;
let pendingFile=null;

// ── LOAD ──────────────────────────────────────────────────────────────────────
async function loadAll() {
    try {
        const tasks = [apiGet(API+'?api=suppliers'), apiGet(API+'?api=list')];
        if (PERMS.create) tasks.push(apiGet(API+'?api=pos'));
        const res = await Promise.all(tasks);
        SUPPLIERS = res[0]; D = res[1]; POS = res[2] || [];
    } catch(e) { toast('Failed to load data: '+e.message,'danger'); }
    rSupplierDropdowns();
    if (PERMS.create) rPoList();
    render();
}

function rSupplierDropdowns() {
    const fSup = document.getElementById('fSupplier');
    const prev = fSup.value;
    fSup.innerHTML = '<option value="">All Suppliers</option>' +
        [...new Set(D.map(c=>c.supplier))].sort().map(s=>`<option${s===prev?' selected':''}>${esc(s)}</option>`).join('');
    const pSup = document.getElementById('fSup');
    if (pSup) pSup.innerHTML = '<option value="">Select supplier…</option>' +
        SUPPLIERS.map(s=>`<option value="${esc(s.name)}">${esc(s.name)} — ${esc(s.cat)}</option>`).join('');
}
function rPoList() {
    const dl = document.getElementById('poList'); if(!dl) return;
    dl.innerHTML = POS.map(p=>`<option value="${esc(p.po_number)}">${esc(p.po_number)} — ${esc(p.supplier_name)}</option>`).join('');
}

// Auto-fill supplier on PO select
const poInput = document.getElementById('fPO');
if (poInput) poInput.addEventListener('input', function(){
    const po = POS.find(p=>p.po_number===this.value.trim());
    if (!po) return;
    const supEl = document.getElementById('fSup'); if (!supEl) return;
    const opt = Array.from(supEl.options).find(o=>o.value===po.supplier_name);
    if (opt) supEl.value = po.supplier_name;
    if (po.total_amount && parseFloat(po.total_amount)>0) document.getElementById('fVal').value=parseFloat(po.total_amount);
});

// ── CHIPS ─────────────────────────────────────────────────────────────────────
const statusChip = s => {
    const m={'Active':'cc-active','Expiring Soon':'cc-expiring','Expired':'cc-expired','Terminated':'cc-terminated','Under Review':'cc-review','Archived':'cc-archived'};
    return `<span class="chip ${m[s]||'cc-review'}">${esc(s)}</span>`;
};
const legalChip = s => {
    const m={'Approved':'cc-l-ok','Pending Review':'cc-l-pend','Under Review':'cc-l-rev','Rejected':'cc-l-rej'};
    return `<span class="chip ${m[s]||'cc-l-pend'}">${esc(s)}</span>`;
};

// ── FILTER / SORT ─────────────────────────────────────────────────────────────
function getFiltered() {
    const q  = document.getElementById('srch').value.trim().toLowerCase();
    const st = document.getElementById('fStatus').value;
    const sp = document.getElementById('fSupplier').value;
    const df = document.getElementById('fDateFrom').value;
    const dt = document.getElementById('fDateTo').value;
    return D.filter(c => {
        const cs = c.computedStatus;
        if (q && !c.contractNo.toLowerCase().includes(q) && !c.supplier.toLowerCase().includes(q) && !(c.poRef||'').toLowerCase().includes(q)) return false;
        if (st && cs !== st) return false;
        if (sp && c.supplier !== sp) return false;
        if (df && c.expiryDate < df) return false;
        if (dt && c.expiryDate > dt) return false;
        return true;
    });
}
function getSorted(list) {
    return [...list].sort((a,b)=>{
        let va=a[sc]??'',vb=b[sc]??'';
        if(sc==='value'){va=Number(va);vb=Number(vb);}
        if(typeof va==='number') return sd==='asc'?va-vb:vb-va;
        return sd==='asc'?String(va).localeCompare(String(vb)):String(vb).localeCompare(String(va));
    });
}

// ── RENDER ────────────────────────────────────────────────────────────────────
function render() { rStats(); rSupplierDropdowns(); rTable(); if(PERMS.viewBanner) rBanner(); }

function rStats() {
    const active  = D.filter(c=>c.computedStatus==='Active').length;
    const expiring= D.filter(c=>c.computedStatus==='Expiring Soon').length;
    let html = `
        <div class="cm-stat"><div class="st-ic ic-g"><i class='bx bx-check-circle'></i></div><div><div class="st-v">${active}</div><div class="st-l">Active</div></div></div>
        <div class="cm-stat"><div class="st-ic ic-o"><i class='bx bx-time-five'></i></div><div><div class="st-v">${expiring}</div><div class="st-l">Expiring Soon</div></div></div>`;
    if (PERMS.viewExpired) {
        const expired = D.filter(c=>c.computedStatus==='Expired').length;
        const review  = D.filter(c=>c.computedStatus==='Under Review').length;
        const term    = D.filter(c=>c.status==='Terminated').length;
        html += `
            <div class="cm-stat"><div class="st-ic ic-r"><i class='bx bx-error-circle'></i></div><div><div class="st-v">${expired}</div><div class="st-l">Expired</div></div></div>
            <div class="cm-stat"><div class="st-ic ic-pu"><i class='bx bx-shield-quarter'></i></div><div><div class="st-v">${review}</div><div class="st-l">Under Review</div></div></div>
            <div class="cm-stat"><div class="st-ic ic-r"><i class='bx bx-x-circle'></i></div><div><div class="st-v">${term}</div><div class="st-l">Terminated</div></div></div>`;
    }
    document.getElementById('statsRow').innerHTML = html;
}

function rBanner() {
    const b = document.getElementById('expiryBanner'); if (!b) return;
    const exp = D.filter(c=>{ const d=daysUntil(c.expiryDate); return d>=0&&d<=30&&!['Terminated','Archived'].includes(c.status); });
    if (!exp.length) { b.classList.add('hidden'); return; }
    b.classList.remove('hidden');
    document.getElementById('bannerTxt').innerHTML = `<strong>${exp.length} contract${exp.length>1?'s':''}</strong> expiring within 30 days: ${exp.slice(0,3).map(c=>`<strong>${esc(c.contractNo)}</strong>`).join(', ')}${exp.length>3?` +${exp.length-3} more`:''}`;
}

function rTable() {
    const list=getSorted(getFiltered()), total=list.length, pages=Math.max(1,Math.ceil(total/PG));
    if (pg>pages) pg=pages;
    const sl = list.slice((pg-1)*PG, pg*PG);
    const tb = document.getElementById('tb');
    const empty = document.getElementById('cmEmpty');
    if (!sl.length) { tb.innerHTML=''; empty.style.display='block'; document.getElementById('ftInfo').textContent=''; document.getElementById('pagBtns').innerHTML=''; return; }
    empty.style.display='none';
    document.querySelectorAll('.cm-table thead th[data-col]').forEach(th=>th.classList.toggle('sorted', th.dataset.col===sc));

    tb.innerHTML = sl.map(c => {
        const cs = c.computedStatus;
        const rowCls = cs==='Expiring Soon'?'row-expiring':cs==='Expired'?'row-expired':c.status==='Archived'?'row-archived':'';
        const renewIco = c.renewal ? `<i class='bx bx-refresh' style="color:var(--info);font-size:12px;margin-left:3px" title="Flagged for Renewal"></i>` : '';

        // Build action buttons based on permissions
        let acts = `<button class="icon-btn" onclick="openView(${c.id})" title="View"><i class='bx bx-show'></i></button>`;
        if (PERMS.create)       acts += `<button class="icon-btn" onclick="openEdit(${c.id})" title="Edit"><i class='bx bx-edit-alt'></i></button>`;
        if (PERMS.sendLegal)    acts += `<button class="icon-btn purple" onclick="openLegal(${c.id})" title="Send to Legal"><i class='bx bx-send'></i></button>`;
        if (PERMS.flagRenewal)  acts += `<button class="icon-btn warn" onclick="doToggleRenewal(${c.id})" title="Flag for Renewal"><i class='bx bx-refresh'></i></button>`;
        if (PERMS.archive)      acts += `<button class="icon-btn" onclick="openArchive(${c.id})" title="Archive"><i class='bx bx-archive'></i></button>`;
        if (PERMS.terminate && !['Terminated','Archived'].includes(c.status))
            acts += `<button class="icon-btn danger" onclick="openTerm(${c.id})" title="Terminate"><i class='bx bx-x-circle'></i></button>`;

        const poRefCell = PERMS.viewExpired ? `<td><span class="po-ref">${esc(c.poRef)}</span></td>` : '';
        const legalCell = PERMS.viewExpired ? `<td>${legalChip(c.legalStatus)}</td>` : '';

        return `<tr data-id="${c.id}" class="${rowCls}">
            <td><div class="cn-val">${esc(c.contractNo)}</div><div class="cn-type">${esc(c.type)}</div></td>
            <td><div class="sup-name">${esc(c.supplier)}</div></td>
            ${poRefCell}
            <td><span class="val-cell">${fmtMoney(c.value)}</span></td>
            <td><span class="date-val">${fmtDate(c.startDate)}</span></td>
            <td><div class="date-val">${fmtDate(c.expiryDate)}</div><div style="margin-top:3px">${expiryBadge(c.expiryDate)}</div></td>
            ${legalCell}
            <td><div style="display:flex;align-items:center;flex-wrap:nowrap">${statusChip(cs)}${renewIco}</div></td>
            <td onclick="event.stopPropagation()"><div class="row-acts">${acts}</div></td>
        </tr>`;
    }).join('');

    const s=(pg-1)*PG+1, e2=Math.min(pg*PG,total);
    document.getElementById('ftInfo').textContent = `Showing ${s}–${e2} of ${total} contracts`;
    rPag(pages);
}

function rPag(pages) {
    let btns='';
    for(let i=1;i<=pages;i++){
        if(i===1||i===pages||(i>=pg-1&&i<=pg+1))btns+=`<button class="pb${i===pg?' active':''}" onclick="goPg(${i})">${i}</button>`;
        else if(i===pg-2||i===pg+2)btns+=`<button class="pb" disabled>…</button>`;
    }
    document.getElementById('pagBtns').innerHTML=`
        <button class="pb" onclick="goPg(${pg-1})" ${pg<=1?'disabled':''}><i class='bx bx-chevron-left'></i></button>
        ${btns}
        <button class="pb" onclick="goPg(${pg+1})" ${pg>=pages?'disabled':''}><i class='bx bx-chevron-right'></i></button>`;
}
window.goPg = p => { pg=p; rTable(); };

// Sort
document.querySelectorAll('.cm-table thead th[data-col]').forEach(th => {
    th.addEventListener('click', () => {
        const col=th.dataset.col; sc===col?(sd=sd==='asc'?'desc':'asc'):(sc=col,sd='asc'); pg=1; rTable();
    });
});

// Filters
['srch','fStatus','fSupplier','fDateFrom','fDateTo'].forEach(id =>
    document.getElementById(id)?.addEventListener('input', () => { pg=1; rTable(); })
);
document.getElementById('clearFilters').addEventListener('click', () => {
    ['srch','fDateFrom','fDateTo'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
    ['fStatus','fSupplier'].forEach(id => { const el=document.getElementById(id); if(el) el.selectedIndex=0; });
    pg=1; render();
});

// Row click → view
document.getElementById('tb').addEventListener('click', function(e) {
    const tr=e.target.closest('tr[data-id]'); if(!tr||e.target.closest('button')) return;
    openView(parseInt(tr.dataset.id));
});

// Toolbar buttons
const expiringBtn = document.getElementById('expiringBtn');
if (expiringBtn) expiringBtn.addEventListener('click', () => {
    document.getElementById('fStatus').value='Expiring Soon'; pg=1; rTable();
    const c=D.filter(x=>x.computedStatus==='Expiring Soon').length;
    toast(`Showing ${c} expiring contract${c!==1?'s':''}`, 'warning');
});
const bannerViewBtn = document.getElementById('bannerViewBtn');
if (bannerViewBtn) bannerViewBtn.addEventListener('click', () => {
    document.getElementById('fStatus').value='Expiring Soon'; pg=1; rTable();
});
const exportBtn = document.getElementById('exportBtn');
if (exportBtn) exportBtn.addEventListener('click', () => {
    const cols=['contractNo','supplier','poRef','value','startDate','expiryDate','legalStatus','computedStatus','type'];
    const rows=[cols.join(','),...D.map(c=>cols.map(k=>`"${String(c[k]??'').replace(/"/g,'""')}"`).join(','))];
    const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
    a.download='contracts_export.csv'; a.click(); toast('Contract data exported','success');
});

// ── SIDE PANEL (SA only) ──────────────────────────────────────────────────────
function openPanel()  { document.getElementById('panel')?.classList.add('open');    document.getElementById('mainOv').classList.add('show'); }
function closePanel() { document.getElementById('panel')?.classList.remove('open'); document.getElementById('mainOv').classList.remove('show'); editId=null; pendingFile=null; }
document.getElementById('mainOv').addEventListener('click', closePanel);
document.getElementById('pCl')?.addEventListener('click', closePanel);
document.getElementById('pCa')?.addEventListener('click', closePanel);

function clearForm() {
    ['fCN','fPO','fVal','fNo','fSD','fED','fSaN'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    const fSup=document.getElementById('fSup');if(fSup)fSup.value='';
    const fType=document.getElementById('fType');if(fType)fType.value='Supply Agreement';
    const fSt=document.getElementById('fSt');if(fSt)fSt.value='Active';
    const fLS=document.getElementById('fLS');if(fLS)fLS.value='Pending Review';
    const fRen=document.getElementById('fRen');if(fRen)fRen.value='0';
    const fn=document.getElementById('uploadFn');if(fn){fn.textContent='';fn.classList.remove('show');}
    const fi=document.getElementById('fileInput');if(fi)fi.value='';
    pendingFile=null;
}

const addBtn=document.getElementById('addBtn');
if(addBtn) addBtn.addEventListener('click', async() => {
    editId=null; clearForm();
    document.getElementById('pTitle').textContent='New Contract';
    document.getElementById('pSub').textContent='Link a contract to an approved purchase order';
    document.getElementById('pSv').innerHTML='<i class="bx bx-plus"></i> Add Contract';
    document.getElementById('fCN').value='Loading…';
    document.getElementById('fSD').value=todayStr();
    openPanel(); document.getElementById('fPO').focus();
    try { const d=await apiGet(API+'?api=next_no'); document.getElementById('fCN').value=d.contractNo; }
    catch(e) { document.getElementById('fCN').value='CTR-????'; }
});

function openEdit(id) {
    if (!PERMS.create) return;
    const c=D.find(x=>x.id===id); if(!c)return;
    editId=id; clearForm();
    document.getElementById('fCN').value=c.contractNo;
    document.getElementById('fPO').value=c.poRef;
    document.getElementById('fSup').value=c.supplier;
    document.getElementById('fType').value=c.type;
    document.getElementById('fVal').value=c.value;
    document.getElementById('fSD').value=c.startDate;
    document.getElementById('fED').value=c.expiryDate;
    document.getElementById('fSt').value=['Active','Under Review','Expiring Soon','Expired'].includes(c.status)?c.status:'Active';
    document.getElementById('fLS').value=c.legalStatus;
    document.getElementById('fNo').value=c.notes||'';
    document.getElementById('fRen').value=String(c.renewal||0);
    document.getElementById('fSaN').value=c.saNotes||'';
    document.getElementById('pTitle').textContent='Edit Contract';
    document.getElementById('pSub').textContent=c.contractNo;
    document.getElementById('pSv').innerHTML='<i class="bx bx-check"></i> Save Changes';
    openPanel();
}
window.openEdit=openEdit;

document.getElementById('pSv')?.addEventListener('click', async() => {
    const cn=document.getElementById('fCN').value.trim();
    const po=document.getElementById('fPO').value.trim();
    const sup=document.getElementById('fSup').value;
    const val=document.getElementById('fVal').value;
    const sd2=document.getElementById('fSD').value;
    const ed=document.getElementById('fED').value;
    if(!cn){shk('fCN');return toast('Contract number is required','danger');}
    if(!po){shk('fPO');return toast('PO reference is required','danger');}
    if(!sup){shk('fSup');return toast('Please select a supplier','danger');}
    if(!val){shk('fVal');return toast('Contract value is required','danger');}
    if(!sd2){shk('fSD');return toast('Start date is required','danger');}
    if(!ed){shk('fED');return toast('Expiry date is required','danger');}
    const btn=document.getElementById('pSv'); btn.disabled=true;
    const payload={contractNo:cn,poRef:po,supplier:sup,type:document.getElementById('fType').value,value:parseFloat(val)||0,startDate:sd2,expiryDate:ed,status:document.getElementById('fSt').value,legalStatus:document.getElementById('fLS').value,notes:document.getElementById('fNo').value.trim(),renewal:parseInt(document.getElementById('fRen').value)||0,saNotes:document.getElementById('fSaN').value.trim()};
    if(editId) payload.id=editId;
    try {
        const saved=await apiPost(API+'?api=save',payload);
        if(pendingFile&&saved.id) await uploadFile(pendingFile,saved.id);
        toast(`"${cn}" ${editId?'updated':'created'}`,'success');
        closePanel(); D=await apiGet(API+'?api=list'); render();
    } catch(e){toast(e.message,'danger');}
    finally{btn.disabled=false;}
});

// File upload (SA panel)
const uz=document.getElementById('uploadZone'), fi=document.getElementById('fileInput');
if(uz&&fi){
    uz.addEventListener('click',()=>fi.click());
    uz.addEventListener('dragover',e=>{e.preventDefault();uz.classList.add('dragover');});
    uz.addEventListener('dragleave',()=>uz.classList.remove('dragover'));
    uz.addEventListener('drop',e=>{e.preventDefault();uz.classList.remove('dragover');if(e.dataTransfer.files[0])setFile(e.dataTransfer.files[0]);});
    fi.addEventListener('change',()=>{if(fi.files[0])setFile(fi.files[0]);});
}
function setFile(f){pendingFile=f;const fn=document.getElementById('uploadFn');fn.textContent='📎 '+f.name;fn.classList.add('show');toast('File attached: '+f.name,'info');}
async function uploadFile(file,contractId){
    const form=new FormData();form.append('contractId',contractId);form.append('file',file,file.name);
    const r=await fetch(API+'?api=upload_doc',{method:'POST',body:form});
    const j=await r.json(); if(!j.success) throw new Error(j.error||'Upload failed'); return j.data;
}

// ── VIEW MODAL ────────────────────────────────────────────────────────────────
function openView(id) {
    const c=D.find(x=>x.id===id); if(!c)return;
    viewId=id;
    const cs=c.computedStatus;
    document.getElementById('mNm').textContent=c.contractNo;
    document.getElementById('mId').innerHTML=`${esc(c.supplier)} &nbsp;·&nbsp; ${esc(c.poRef)} &nbsp;${statusChip(cs)}&nbsp;${legalChip(c.legalStatus)}`;
    const pct=expiryPct(c.startDate,c.expiryDate);
    const d=daysUntil(c.expiryDate);
    const barCls=d<0?'bar-r':d<=30?'bar-o':'bar-g';
    document.getElementById('mMeta').innerHTML=`
        <div class="m-mc"><i class='bx bx-buildings'></i>${esc(c.supplier)}</div>
        <div class="m-mc"><i class='bx bx-tag'></i>${esc(c.type)}</div>
        <div class="m-mc"><i class='bx bx-money'></i>${fmtMoney(c.value)}</div>
        <div class="m-mc" style="flex-direction:column;align-items:flex-start;gap:3px;min-width:160px">
            <span style="font-size:10px;color:var(--text-3)">Contract Progress (${pct}%)</span>
            <div class="bar-wrap"><div class="bar-fill ${barCls}" style="width:${pct}%"></div></div>
        </div>`;
    document.getElementById('mSbs').innerHTML=`
        <div class="sb"><div class="sbv">${fmtMoney(c.value)}</div><div class="sbl">Contract Value</div></div>
        <div class="sb"><div class="sbv">${expiryBadge(c.expiryDate)}</div><div class="sbl">Time Remaining</div></div>
        <div class="sb"><div class="sbv">${c.docs.length}</div><div class="sbl">Documents</div></div>
        <div class="sb"><div class="sbv">${pct}%</div><div class="sbl">Duration Elapsed</div></div>`;

    // Info grid — SA sees all, Admin/Manager see subset
    let infoHtml = `
        <div class="info-item"><label>Contract No.</label><div class="v" style="font-family:'DM Mono',monospace;color:var(--primary);font-weight:700">${esc(c.contractNo)}</div></div>
        <div class="info-item"><label>PO Reference</label><div class="v" style="font-family:'DM Mono',monospace;color:var(--info);font-weight:700">${esc(c.poRef)}</div></div>
        <div class="info-item"><label>Supplier</label><div class="v">${esc(c.supplier)}</div></div>
        <div class="info-item"><label>Contract Type</label><div class="v">${esc(c.type)}</div></div>
        <div class="info-item"><label>Contract Value</label><div class="v" style="font-weight:700">${fmtMoney(c.value)}</div></div>
        <div class="info-item"><label>Legal Status</label><div class="v">${legalChip(c.legalStatus)}</div></div>
        <div class="info-item"><label>Status</label><div class="v">${statusChip(cs)}</div></div>
        <div class="info-item"><label>Start Date</label><div class="v">${fmtDate(c.startDate)}</div></div>
        <div class="info-item"><label>Expiry Date</label><div class="v">${fmtDate(c.expiryDate)} &nbsp;${expiryBadge(c.expiryDate)}</div></div>
        <div class="info-item"><label>Flagged for Renewal</label><div class="v">${c.renewal?'<span style="color:var(--info);font-weight:700">Yes</span>':'<span style="color:#6B7280">No</span>'}</div></div>
        ${c.notes?`<div class="info-item info-full"><label>Notes</label><div class="v">${esc(c.notes)}</div></div>`:''}`;
    // SA notes visible only to SA
    if (PERMS.viewSaNotes && c.saNotes) {
        infoHtml += `<div class="info-item info-full" style="background:rgba(46,125,50,.04);border:1px solid rgba(46,125,50,.12);border-radius:10px;padding:12px"><label style="color:var(--primary)">SA Notes</label><div class="v" style="color:var(--primary)">${esc(c.saNotes)}</div></div>`;
    }
    document.getElementById('mInfo').innerHTML = infoHtml;

    // Documents tab
    rModalDocs(c);

    // Audit log
    const logs=c.audit||[];
    document.getElementById('mHist').innerHTML = logs.length
        ? logs.map(l=>`<div class="tl-item"><div class="tl-dot ${l.t}"></div><div><div class="tl-act">${esc(l.msg)}</div><div class="tl-ts">${l.by?`By ${esc(l.by)} · `:''}${esc(l.ts)}</div></div></div>`).join('')
        : '<div style="padding:16px;text-align:center;font-size:12px;color:var(--text-3)">No audit entries yet.</div>';

    // Footer buttons — role-aware
    const mFt = document.getElementById('mFt');
    mFt.innerHTML = '';
    const mk=(cls,icon,lbl,cb)=>{const b=document.createElement('button');b.className=`btn ${cls} btn-s`;b.innerHTML=`<i class='bx ${icon}'></i> ${lbl}`;b.addEventListener('click',cb);mFt.appendChild(b);};
    mk('btn-g','bx-x','Close',closeView);
    if (PERMS.create)      mk('btn-g','bx-edit-alt','Edit',()=>{closeView();openEdit(c.id);});
    if (PERMS.sendLegal)   mk('btn-purple','bx-send','Send to Legal',()=>openLegal(c.id));
    if (PERMS.flagRenewal) mk('btn-g','bx-refresh',c.renewal?'Unflag Renewal':'Flag Renewal',()=>doToggleRenewal(c.id));
    if (PERMS.archive)     mk('btn-g','bx-archive','Archive',()=>{closeView();openArchive(c.id);});
    if (PERMS.terminate && !['Terminated','Archived'].includes(c.status))
        mk('btn-danger','bx-x-circle','Terminate',()=>{closeView();openTerm(c.id);});

    // Tabs reset
    document.querySelectorAll('.m-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.m-tp').forEach(p=>p.classList.remove('active'));
    document.querySelector('.m-tab[data-t="ov"]').classList.add('active');
    document.getElementById('tp-ov').classList.add('active');
    document.getElementById('modal').classList.add('show');
}

function rModalDocs(c) {
    const docs=c.docs||[];
    if (!docs.length) {
        document.getElementById('mDocs').innerHTML =
            '<div style="padding:30px;text-align:center;font-size:12px;color:var(--text-3)"><i class="bx bx-file-blank" style="font-size:32px;display:block;margin-bottom:8px;color:#C8E6C9"></i>No documents uploaded yet.' +
            (PERMS.uploadDoc ? ' <br>Upload via <strong>Edit Contract</strong>.' : '') + '</div>';
        return;
    }
    document.getElementById('mDocs').innerHTML = docs.map(d=>{
        const icon=d.type==='docx'?'bx-file':'bx-file-pdf';
        const iconBg=d.type==='docx'?'#EDE9FE':'#EFF6FF';
        const iconCl=d.type==='docx'?'var(--purple)':'var(--info)';
        const ts=d.uploadedAt?new Date(d.uploadedAt).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'}):'';
        const meta=[d.size,d.uploadedBy,ts].filter(Boolean).join(' · ');
        const fileUrl=d.filePath?STORAGE+d.filePath:'';
        const deleteBtn = PERMS.deleteDoc
            ? `<button class="btn btn-danger btn-s" onclick="doDeleteDoc(${d.id},${c.id},'${(d.filePath||'').replace(/'/g,'')}')" title="Delete"><i class='bx bx-trash'></i></button>`
            : '';
        return `<div class="doc-item">
            <div class="doc-ic" style="background:${iconBg};color:${iconCl}"><i class='bx ${icon}'></i></div>
            <div style="flex:1;min-width:0"><div class="doc-name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(d.name)}</div><div class="doc-meta">${esc(meta)}</div></div>
            <div class="doc-acts">
                ${fileUrl?`<a href="${fileUrl}" target="_blank" class="btn btn-g btn-s" title="Download"><i class='bx bx-download'></i></a>`:`<button class="btn btn-g btn-s" disabled><i class='bx bx-download'></i></button>`}
                ${deleteBtn}
            </div>
        </div>`;
    }).join('');
}

// Tabs
document.querySelectorAll('.m-tab').forEach(t=>t.addEventListener('click',()=>{
    document.querySelectorAll('.m-tab').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.m-tp').forEach(x=>x.classList.remove('active'));
    t.classList.add('active'); document.getElementById('tp-'+t.dataset.t).classList.add('active');
}));
function closeView(){document.getElementById('modal').classList.remove('show');viewId=null;}
document.getElementById('mCl').addEventListener('click',closeView);
document.getElementById('modal').addEventListener('click',function(e){if(e.target===this)closeView();});

// ── DELETE DOC MODAL (SA only) ────────────────────────────────────────────────
let _delDocId=null,_delDocContractId=null,_delDocFilePath=null;
window.doDeleteDoc=function(docId,contractId,filePath){
    if(!PERMS.deleteDoc)return;
    const c=D.find(x=>x.id===contractId);
    const doc=c?(c.docs||[]).find(d=>d.id===docId):null;
    _delDocId=docId;_delDocContractId=contractId;_delDocFilePath=filePath||'';
    document.getElementById('delDocSub').textContent='Deleting from contract record';
    document.getElementById('delDocFname').textContent=doc?doc.name:'this document';
    document.getElementById('delDocModal').classList.add('show');
};
document.getElementById('delDocCancel')?.addEventListener('click',()=>document.getElementById('delDocModal').classList.remove('show'));
document.getElementById('delDocModal')?.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');});
document.getElementById('delDocConfirm')?.addEventListener('click',async()=>{
    const btn=document.getElementById('delDocConfirm'); btn.disabled=true;
    try{
        await apiPost(API+'?api=delete_doc',{docId:_delDocId,contractId:_delDocContractId,filePath:_delDocFilePath});
        document.getElementById('delDocModal').classList.remove('show');
        toast('Document deleted','danger');
        D=await apiGet(API+'?api=list');
        if(viewId===_delDocContractId){const up=D.find(x=>x.id===_delDocContractId);if(up)openView(_delDocContractId);}
        render();
    }catch(e){toast(e.message,'danger');}
    finally{btn.disabled=false;}
});

// ── TERMINATE (SA only) ───────────────────────────────────────────────────────
function openTerm(id){
    if(!PERMS.terminate)return;
    const c=D.find(x=>x.id===id);if(!c)return;termId=id;
    document.getElementById('termSub').textContent=`${c.contractNo} — ${c.supplier}`;
    document.getElementById('termReason').value='';document.getElementById('termNotes').value='';
    document.getElementById('termModal').classList.add('show');
}
window.openTerm=openTerm;
function closeTerm(){document.getElementById('termModal').classList.remove('show');termId=null;}
document.getElementById('termCancel')?.addEventListener('click',closeTerm);
document.getElementById('termModal')?.addEventListener('click',function(e){if(e.target===this)closeTerm();});
document.getElementById('termConfirm')?.addEventListener('click',async()=>{
    const reason=document.getElementById('termReason').value;
    if(!reason){shk('termReason');return toast('Please select a termination reason','danger');}
    const btn=document.getElementById('termConfirm');btn.disabled=true;
    try{
        await apiPost(API+'?api=action',{id:termId,type:'terminate',reason,notes:document.getElementById('termNotes').value.trim()});
        const c=D.find(x=>x.id===termId);
        toast(`"${c?.contractNo}" terminated`,'danger');
        closeTerm();closeView();D=await apiGet(API+'?api=list');render();
    }catch(e){toast(e.message,'danger');}
    finally{btn.disabled=false;}
});

// ── SEND TO LEGAL (SA + Admin) ────────────────────────────────────────────────
function openLegal(id){
    if(!PERMS.sendLegal)return;
    const c=D.find(x=>x.id===id);if(!c)return;legalId=id;
    document.getElementById('legalSub').textContent=`${c.contractNo} — ${c.supplier}`;
    document.getElementById('legalNotes').value='';
    document.getElementById('legalModal').classList.add('show');
}
window.openLegal=openLegal;
function closeLegal(){document.getElementById('legalModal').classList.remove('show');legalId=null;}
document.getElementById('legalCancel')?.addEventListener('click',closeLegal);
document.getElementById('legalModal')?.addEventListener('click',function(e){if(e.target===this)closeLegal();});
document.getElementById('legalConfirm')?.addEventListener('click',async()=>{
    const officer=document.getElementById('legalOfficer').value;
    const priority=document.getElementById('legalPriority').value;
    const notes=document.getElementById('legalNotes').value.trim();
    const btn=document.getElementById('legalConfirm');btn.disabled=true;
    try{
        await apiPost(API+'?api=action',{id:legalId,type:'send_legal',officer,priority,notes});
        const c=D.find(x=>x.id===legalId);
        toast(`"${c?.contractNo}" sent to ${officer.split('—')[0].trim()} (${priority})`,'purple');
        closeLegal();D=await apiGet(API+'?api=list');
        if(viewId===legalId){const up=D.find(x=>x.id===viewId);if(up)openView(viewId);}
        render();
    }catch(e){toast(e.message,'danger');}
    finally{btn.disabled=false;}
});

// ── ARCHIVE (SA only) ─────────────────────────────────────────────────────────
function openArchive(id){
    if(!PERMS.archive)return;
    const c=D.find(x=>x.id===id);if(!c)return;archiveId=id;
    document.getElementById('archiveSub').textContent=`${c.contractNo} — ${c.supplier}`;
    document.getElementById('archiveReason').value='';
    document.getElementById('archiveModal').classList.add('show');
}
window.openArchive=openArchive;
function closeArchive(){document.getElementById('archiveModal').classList.remove('show');archiveId=null;}
document.getElementById('archiveCancel')?.addEventListener('click',closeArchive);
document.getElementById('archiveModal')?.addEventListener('click',function(e){if(e.target===this)closeArchive();});
document.getElementById('archiveConfirm')?.addEventListener('click',async()=>{
    const btn=document.getElementById('archiveConfirm');btn.disabled=true;
    try{
        await apiPost(API+'?api=action',{id:archiveId,type:'archive',reason:document.getElementById('archiveReason').value.trim()});
        const c=D.find(x=>x.id===archiveId);
        toast(`"${c?.contractNo}" archived`,'info');
        closeArchive();closeView();D=await apiGet(API+'?api=list');render();
    }catch(e){toast(e.message,'danger');}
    finally{btn.disabled=false;}
});

// ── TOGGLE RENEWAL (SA + Admin) ───────────────────────────────────────────────
window.doToggleRenewal=async function(id){
    if(!PERMS.flagRenewal)return;
    const c=D.find(x=>x.id===id);if(!c)return;
    try{
        const updated=await apiPost(API+'?api=action',{id,type:'toggle_renewal',currentRenewal:c.renewal});
        const idx=D.findIndex(x=>x.id===id);if(idx>-1)D[idx]=updated;
        toast(updated.renewal?`"${c.contractNo}" flagged for renewal`:`Renewal flag removed`,'info');
        if(viewId===id)openView(id);
        render();
    }catch(e){toast(e.message,'danger');}
};

// ── TOAST & SHAKE ─────────────────────────────────────────────────────────────
function toast(msg,type='success'){
    const icons={success:'bx-check-circle',danger:'bx-error-circle',warning:'bx-error',info:'bx-info-circle',purple:'bx-shield-quarter'};
    const el=document.createElement('div');el.className=`toast ${type}`;
    el.innerHTML=`<i class='bx ${icons[type]||"bx-check-circle"}' style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('tw').appendChild(el);
    setTimeout(()=>{el.classList.add('out');setTimeout(()=>el.remove(),300);},3200);
}
function shk(id){
    const el=document.getElementById(id);if(!el)return;
    el.style.borderColor='#DC2626';el.offsetHeight;el.style.animation='shake .3s ease';
    setTimeout(()=>{el.style.borderColor='';el.style.animation='';},600);
}

// ── INIT ──────────────────────────────────────────────────────────────────────
loadAll();
</script>
</body>
</html>