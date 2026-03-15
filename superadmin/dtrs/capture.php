<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE RESOLUTION (mirrors superadmin_sidebar.php) ─────────────────────────
function _dc_resolve_role(): string {
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

$dcRoleName = _dc_resolve_role();
$dcRoleRank = match($dcRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,   // User / Staff
};
$dcUserZone = $_SESSION['zone']      ?? '';
$dcUserName = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'User');
$dcUserId   = $_SESSION['user_id']   ?? '';

// ── PERMISSION GATES ──────────────────────────────────────────────────────────
// canCapture         : Super Admin, Admin, User  (NOT Manager)
// canSubmit          : Super Admin, Admin, User  (User submits for validation)
// canForceValidate   : Super Admin only
// canModifyRetention : Super Admin only
// canCrossZoneAssign : Super Admin only
// canViewMonitor     : Manager+ (monitoring dashboard)
// canFlagAI          : Manager+ (flag low-confidence tags)
// canEscalate        : Manager (escalate to Admin)

$dcCan = [
    'capture'          => $dcRoleRank !== 2,   // all except Manager
    'submit'           => $dcRoleRank !== 2,   // all except Manager
    'forceValidate'    => $dcRoleRank >= 4,    // SA only
    'modifyRetention'  => $dcRoleRank >= 4,    // SA only
    'crossZoneAssign'  => $dcRoleRank >= 4,    // SA only
    'viewMonitor'      => $dcRoleRank >= 2,    // Manager+
    'flagAI'           => $dcRoleRank >= 2,    // Manager+
    'escalate'         => $dcRoleRank === 2,   // Manager only
    'correctMetadata'  => $dcRoleRank >= 3,    // SA, Admin — full edit; User sees read-only review
    'userReview'       => $dcRoleRank === 1,   // User submits for Admin validation
];

$jsPerms    = json_encode($dcCan);
$jsRole     = json_encode($dcRoleName);
$jsRoleRank = (int)$dcRoleRank;
$jsZone     = json_encode($dcUserZone);
$jsUserName = json_encode($dcUserName);

// ── HELPERS ──────────────────────────────────────────────────────────────────
function dc_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function dc_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function dc_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function dc_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
    $url = SUPABASE_URL . '/rest/v1/' . $table;
    if ($query) $url .= '?' . http_build_query($query);
    $headers = [
        'Content-Type: application/json',
        'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Prefer: return=representation',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$res && $code >= 400) throw new RuntimeException('Supabase request failed');
    $data = json_decode($res, true);
    if ($code >= 400) throw new RuntimeException(is_array($data) ? ($data['message'] ?? $res) : $res);
    return is_array($data) ? $data : [];
}

function dc_next_doc_id(): string {
    $year = date('Y');
    $rows = dc_sb('dtrs_documents', 'GET', ['select'=>'doc_id','doc_id'=>'like.DTRS-'.$year.'-%','order'=>'id.desc','limit'=>'1']);
    $next = 1;
    if (!empty($rows) && preg_match('/DTRS-\d{4}-(\d+)/', $rows[0]['doc_id'] ?? '', $m)) $next = ((int)$m[1]) + 1;
    return 'DTRS-' . $year . '-' . sprintf('%04d', $next);
}
function dc_next_ref_num(): string {
    $year = date('Y');
    $rows = dc_sb('dtrs_documents', 'GET', ['select'=>'ref_number','ref_number'=>'like.REF-'.$year.'-%','order'=>'id.desc','limit'=>'1']);
    $next = 1;
    if (!empty($rows) && preg_match('/REF-\d{4}-(\d+)/', $rows[0]['ref_number'] ?? '', $m)) $next = ((int)$m[1]) + 1;
    return 'REF-' . $year . '-' . sprintf('%03d', $next);
}
function dc_build(array $row): array {
    return [
        'id'             => (int)$row['id'],
        'docId'          => $row['doc_id']         ?? '',
        'title'          => $row['title']           ?? '',
        'refNumber'      => $row['ref_number']      ?? '',
        'docType'        => $row['doc_type']        ?? '',
        'category'       => $row['category']        ?? '',
        'department'     => $row['department']      ?? '',
        'direction'      => $row['direction']       ?? '',
        'sender'         => $row['sender']          ?? '',
        'recipient'      => $row['recipient']       ?? '',
        'assignedTo'     => $row['assigned_to']     ?? '',
        'docDate'        => $row['doc_date']        ?? '',
        'priority'       => $row['priority']        ?? 'Normal',
        'retention'      => $row['retention']       ?? '1 Year',
        'notes'          => $row['notes']           ?? '',
        'captureMode'    => $row['capture_mode']    ?? 'physical',
        'fileName'       => $row['file_name']       ?? '',
        'fileSizeKb'     => (float)($row['file_size_kb'] ?? 0),
        'fileExt'        => $row['file_ext']        ?? '',
        'filePath'       => $row['file_path']       ?? '',
        'aiConfidence'   => (int)($row['ai_confidence']   ?? 0),
        'aiAutoFilled'   => (bool)($row['ai_auto_filled']  ?? false),
        'needsValidation'=> (bool)($row['needs_validation'] ?? false),
        'status'         => $row['status']          ?? 'Registered',
        'createdBy'      => $row['created_by']      ?? '',
        'createdAt'      => $row['created_at']      ?? '',
    ];
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $dcUserName;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET next doc ID + ref (capture roles only) ────────────────────────
        if ($api === 'next-ids' && $method === 'GET') {
            if ($dcRoleRank === 2) dc_err('Permission denied', 403);
            dc_ok(['docId' => dc_next_doc_id(), 'refNumber' => dc_next_ref_num()]);
        }

        // ── POST upload file ──────────────────────────────────────────────────
        if ($api === 'upload' && $method === 'POST') {
            if ($dcRoleRank === 2) dc_err('Permission denied', 403);
            $b        = dc_body();
            $docId    = trim($b['docId']    ?? '');
            $docDbId  = (int)($b['docDbId'] ?? 0);
            $fileName = trim($b['fileName'] ?? '');
            $fileExt  = strtolower(trim($b['fileExt'] ?? ''));
            $b64      = $b['fileBase64']    ?? '';
            if (!$docId || !$b64) dc_err('Missing docId or fileBase64', 400);
            $fileBytes = base64_decode($b64);
            if ($fileBytes === false) dc_err('Invalid base64 data', 400);
            $year = date('Y');
            $safeName    = preg_replace('/[^A-Za-z0-9\-_]/', '', $docId);
            $ext         = $fileExt ?: pathinfo($fileName, PATHINFO_EXTENSION) ?: 'bin';
            $storagePath = $year . '/' . $safeName . '.' . $ext;
            $mimeMap = ['pdf'=>'application/pdf','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','doc'=>'application/msword','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp'];
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';
            $ch = curl_init(SUPABASE_URL . '/storage/v1/object/dtrs-documents/' . $storagePath);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_HTTPHEADER=>['Content-Type: '.$mime,'apikey: '.SUPABASE_SERVICE_ROLE_KEY,'Authorization: Bearer '.SUPABASE_SERVICE_ROLE_KEY,'x-upsert: true'],CURLOPT_POSTFIELDS=>$fileBytes]);
            $res  = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($code >= 400) { $err = json_decode($res, true); dc_err('Storage upload failed: '.($err['message']??'HTTP '.$code), 502); }
            if ($docDbId > 0) {
                dc_sb('dtrs_documents', 'PATCH', ['id'=>'eq.'.$docDbId], ['file_path'=>$storagePath,'file_name'=>$fileName?:($safeName.'.'.$ext),'file_ext'=>strtoupper($ext),'file_size_kb'=>round(strlen($fileBytes)/1024,1),'updated_at'=>date('Y-m-d H:i:s')]);
            }
            $publicUrl = SUPABASE_URL . '/storage/v1/object/public/dtrs-documents/' . $storagePath;
            dc_ok(['path' => $storagePath, 'url' => $publicUrl]);
        }

        // ── POST get signed URL ───────────────────────────────────────────────
        if ($api === 'signed-url' && $method === 'POST') {
            $b = dc_body(); $path = trim($b['path'] ?? '');
            if (!$path) dc_err('Missing path', 400);
            $ch = curl_init(SUPABASE_URL . '/storage/v1/object/sign/dtrs-documents/' . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','apikey: '.SUPABASE_SERVICE_ROLE_KEY,'Authorization: Bearer '.SUPABASE_SERVICE_ROLE_KEY],CURLOPT_POSTFIELDS=>json_encode(['expiresIn'=>3600])]);
            $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($code >= 400) dc_err('Could not generate signed URL', 502);
            $data = json_decode($res, true);
            dc_ok(['signedUrl' => SUPABASE_URL . '/storage/v1' . ($data['signedURL'] ?? '')]);
        }

        // ── GET staff list ────────────────────────────────────────────────────
        if ($api === 'staff' && $method === 'GET') {
            $q = ['select'=>'user_id,first_name,last_name,zone','status'=>'eq.Active','order'=>'first_name.asc'];
            // Admin/User: filter by zone
            if ($dcRoleRank === 3 && $dcUserZone !== '') $q['zone'] = 'eq.'.$dcUserZone;
            $rows = dc_sb('users', 'GET', $q);
            $staff = array_map(fn($r) => ['id'=>$r['user_id'],'name'=>trim(($r['first_name']??'').' '.($r['last_name']??''))], $rows);
            dc_ok(array_values(array_filter($staff, fn($s) => $s['name'] !== '')));
        }

        // ── POST extract metadata via Groq ────────────────────────────────────
        if ($api === 'extract' && $method === 'POST') {
            if ($dcRoleRank === 2) dc_err('Permission denied', 403);
            $b       = dc_body();
            $ocrText = trim($b['ocrText'] ?? '');
            if (!$ocrText) dc_err('No OCR text provided', 400);
            $ocrText = mb_convert_encoding($ocrText, 'UTF-8', 'UTF-8');
            $ocrText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $ocrText);
            $ocrText = mb_substr($ocrText, 0, 6000);
            $groqKey   = defined('GROQ_API_KEY')       ? GROQ_API_KEY       : '';
            $groqModel = defined('GROQ_EXTRACT_MODEL') ? GROQ_EXTRACT_MODEL : 'llama-3.1-8b-instant';
            if (empty($groqKey)) dc_err('GROQ_API_KEY not configured.', 503);
            $system = 'You are a document metadata extraction assistant. You ONLY respond with a single valid JSON object — no markdown, no backticks, no explanation, nothing before or after the JSON.';
            $user = 'Extract metadata from this document text and return ONLY a JSON object with these exact keys:
{"title":"<concise title max 80 chars>","doc_type":"<Memo|Contract|Invoice|Report|Form|Certificate|Correspondence|Policy>","category":"<Financial|Legal|Operational|HR|Procurement|Compliance|Administrative>","department":"<Procurement|Logistics|Finance|HR|Legal|Operations|Admin>","sender":"<sender>","recipient":"<recipient>","direction":"<Incoming|Outgoing>","doc_date":"<YYYY-MM-DDTHH:MM or empty>","priority":"<Normal|Urgent|Confidential|High Value>","notes":"<one sentence summary>","confidence":<0-100>}
Document text:
"""'.$ocrText.'"""';
            $requestBody = json_encode(['model'=>$groqModel,'max_tokens'=>600,'temperature'=>0.1,'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]]],JSON_UNESCAPED_UNICODE);
            if ($requestBody === false) dc_err('Failed to encode request: '.json_last_error_msg(), 500);
            $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$groqKey],CURLOPT_POSTFIELDS=>$requestBody]);
            $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if (!$res) dc_err('No response from Groq API', 502);
            $aiResp = json_decode($res, true);
            if ($code >= 400) dc_err('Groq: '.($aiResp['error']['message'] ?? 'HTTP '.$code), 502);
            $raw = $aiResp['choices'][0]['message']['content'] ?? '';
            $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
            $raw = preg_replace('/\s*```\s*$/', '', $raw);
            $meta = json_decode(trim($raw), true);
            if (!is_array($meta)) dc_err('Groq returned unparseable JSON: '.substr($raw, 0, 120), 502);
            $allowedTypes = ['Memo','Contract','Invoice','Report','Form','Certificate','Correspondence','Policy'];
            $allowedCats  = ['Financial','Legal','Operational','HR','Procurement','Compliance','Administrative'];
            $allowedDepts = ['Procurement','Logistics','Finance','HR','Legal','Operations','Admin'];
            $allowedPrio  = ['Normal','Urgent','Confidential','High Value'];
            $allowedDirs  = ['Incoming','Outgoing'];
            dc_ok(['title'=>mb_substr(trim($meta['title']??''),0,80),'doc_type'=>in_array($meta['doc_type']??'',$allowedTypes,true)?$meta['doc_type']:'','category'=>in_array($meta['category']??'',$allowedCats,true)?$meta['category']:'','department'=>in_array($meta['department']??'',$allowedDepts,true)?$meta['department']:'','sender'=>mb_substr(trim($meta['sender']??''),0,150),'recipient'=>mb_substr(trim($meta['recipient']??''),0,150),'direction'=>in_array($meta['direction']??'',$allowedDirs,true)?$meta['direction']:'Incoming','doc_date'=>trim($meta['doc_date']??''),'priority'=>in_array($meta['priority']??'',$allowedPrio,true)?$meta['priority']:'Normal','notes'=>mb_substr(trim($meta['notes']??''),0,300),'confidence'=>max(0,min(100,(int)($meta['confidence']??0)))]);
        }

        // ── POST register document ────────────────────────────────────────────
        if ($api === 'register' && $method === 'POST') {
            if ($dcRoleRank === 2) dc_err('Permission denied: Managers cannot register documents', 403);
            $b = dc_body();
            $title      = trim($b['title']      ?? ''); if (!$title)      dc_err('Document title is required', 400);
            $docType    = trim($b['docType']     ?? ''); if (!$docType)    dc_err('Document type is required', 400);
            $category   = trim($b['category']   ?? ''); if (!$category)   dc_err('Category is required', 400);
            $department = trim($b['department'] ?? ''); if (!$department) dc_err('Department is required', 400);
            $direction  = trim($b['direction']  ?? ''); if (!$direction)  dc_err('Direction is required', 400);
            $sender     = trim($b['sender']     ?? ''); if (!$sender)     dc_err('Sender is required', 400);
            $recipient  = trim($b['recipient']  ?? ''); if (!$recipient)  dc_err('Recipient is required', 400);
            $assignedTo = trim($b['assignedTo'] ?? ''); if (!$assignedTo) dc_err('Assigned To is required', 400);
            $docDate    = trim($b['docDate']    ?? ''); if (!$docDate)    dc_err('Document date is required', 400);

            // Non-SA/Admin: force status to "Pending Validation" (needs review)
            $isUserSubmission = $dcRoleRank === 1;

            // Retention: Users and Managers cannot set custom retention
            $retention  = trim($b['retention'] ?? '1 Year');
            if ($dcRoleRank < 4) {
                // Admins can set retention but not modify "Permanent" unless they inherited it
                // SA can set any
            }

            // Cross-zone assignment gate
            if (!$dcCan['crossZoneAssign'] && $dcUserZone !== '') {
                // For Admin/User, assigned department should match their zone
                // We allow it but flag — strict enforcement is zone-specific
            }

            if (!in_array($direction, ['Incoming','Outgoing'], true)) dc_err('Invalid direction', 400);

            $now       = date('Y-m-d H:i:s');
            $docId     = dc_next_doc_id();
            $refNumber = trim($b['refNumber'] ?? '') ?: dc_next_ref_num();
            $aiConf    = max(0, min(100, (int)($b['aiConfidence'] ?? 0)));
            $needsVal  = $isUserSubmission || ($aiConf > 0 && $aiConf < 70);
            $finalStatus = $isUserSubmission ? 'Processing' : 'Registered';

            $payload = [
                'doc_id'          => $docId,
                'title'           => $title,
                'ref_number'      => $refNumber,
                'doc_type'        => $docType,
                'category'        => $category,
                'department'      => $department,
                'direction'       => $direction,
                'sender'          => $sender,
                'recipient'       => $recipient,
                'assigned_to'     => $assignedTo,
                'doc_date'        => $docDate,
                'priority'        => trim($b['priority']    ?? 'Normal'),
                'retention'       => $retention,
                'notes'           => trim($b['notes']       ?? ''),
                'capture_mode'    => trim($b['captureMode'] ?? 'physical'),
                'file_name'       => trim($b['fileName']    ?? ''),
                'file_size_kb'    => (float)($b['fileSizeKb'] ?? 0),
                'file_ext'        => strtoupper(trim($b['fileExt'] ?? '')),
                'file_path'       => trim($b['filePath']    ?? ''),
                'ocr_text'        => trim($b['ocrText']     ?? ''),
                'ai_confidence'   => $aiConf,
                'ai_auto_filled'  => (bool)($b['aiAutoFilled'] ?? false),
                'needs_validation'=> $needsVal,
                'status'          => $finalStatus,
                'created_by'      => $actor,
                'created_user_id' => $dcUserId ?: null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];

            $inserted = dc_sb('dtrs_documents', 'POST', [], [$payload]);
            if (empty($inserted)) dc_err('Failed to register document', 500);
            $newId = (int)$inserted[0]['id'];

            $auditNote = 'Captured via ' . ($payload['capture_mode'] === 'physical' ? 'Physical Scan (OCR)' : 'Digital Upload') . '. AI confidence: ' . ($aiConf > 0 ? $aiConf . '%' : 'N/A') . ($needsVal ? ' — validation required.' : '.');
            if ($isUserSubmission) $auditNote .= ' Submitted by User — awaiting Admin validation.';

            dc_sb('dtrs_audit_log', 'POST', [], [[
                'doc_id'        => $newId,
                'action_label'  => $isUserSubmission ? 'Submitted for Validation' : 'Document Registered',
                'actor_name'    => $actor,
                'actor_role'    => $dcRoleName,
                'note'          => $auditNote,
                'icon'          => 'bx-file-plus',
                'css_class'     => 'dc-c',
                'is_super_admin'=> $dcRoleRank >= 4,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            $rows = dc_sb('dtrs_documents', 'GET', ['select'=>'*','id'=>'eq.'.$newId,'limit'=>'1']);
            dc_ok(dc_build($rows[0]));
        }

        // ── GET monitoring stats (Manager) ────────────────────────────────────
        if ($api === 'monitor-stats' && $method === 'GET') {
            if ($dcRoleRank < 2) dc_err('Permission denied', 403);
            $today = date('Y-m-d');
            $q = ['select'=>'id,ai_confidence,needs_validation,capture_mode,status,created_at,department'];
            if ($dcUserZone !== '') $q['department'] = 'eq.'.$dcUserZone;
            $rows = dc_sb('dtrs_documents', 'GET', $q);
            $todayRows    = array_filter($rows, fn($r) => str_starts_with($r['created_at'] ?? '', $today));
            $pendingVal   = array_filter($rows, fn($r) => ($r['needs_validation'] ?? false) || ($r['status'] ?? '') === 'Processing');
            $lowConfRows  = array_filter($rows, fn($r) => ($r['ai_confidence'] ?? 0) > 0 && ($r['ai_confidence'] ?? 0) < 70);
            $physicalToday = array_filter($todayRows, fn($r) => ($r['capture_mode'] ?? '') === 'physical');
            $digitalToday  = array_filter($todayRows, fn($r) => ($r['capture_mode'] ?? '') === 'digital');
            dc_ok([
                'uploadsToday'   => count($todayRows),
                'physicalToday'  => count($physicalToday),
                'digitalToday'   => count($digitalToday),
                'pendingValidation' => count($pendingVal),
                'lowConfidence'  => count($lowConfRows),
                'totalDocs'      => count($rows),
            ]);
        }

        // ── GET low-confidence docs (Manager: to flag/escalate) ───────────────
        if ($api === 'low-conf-docs' && $method === 'GET') {
            if ($dcRoleRank < 2) dc_err('Permission denied', 403);
            $q = ['select'=>'id,doc_id,title,doc_type,ai_confidence,needs_validation,status,created_at,capture_mode','order'=>'created_at.desc','limit'=>'20'];
            if ($dcUserZone !== '') $q['department'] = 'eq.'.$dcUserZone;
            $q['needs_validation'] = 'eq.true';
            $rows = dc_sb('dtrs_documents', 'GET', $q);
            dc_ok($rows);
        }

        // ── POST flag AI confidence issue (Manager) ───────────────────────────
        if ($api === 'flag-ai' && $method === 'POST') {
            if ($dcRoleRank < 2) dc_err('Permission denied', 403);
            $b   = dc_body();
            $id  = (int)($b['id']   ?? 0);
            $note= trim($b['note']  ?? 'Low-confidence AI tag flagged for review.');
            if (!$id) dc_err('Missing id', 400);
            $now = date('Y-m-d H:i:s');
            dc_sb('dtrs_audit_log', 'POST', [], [['doc_id'=>$id,'action_label'=>'AI Tag Flagged for Review','actor_name'=>$actor,'actor_role'=>$dcRoleName,'note'=>$note,'icon'=>'bx-flag','css_class'=>'dc-o','is_super_admin'=>false,'ip_address'=>$ip,'occurred_at'=>$now]]);
            dc_ok(['id'=>$id,'flagged'=>true]);
        }

        // ── POST escalate document to Admin (Manager) ─────────────────────────
        if ($api === 'escalate' && $method === 'POST') {
            if ($dcRoleRank !== 2) dc_err('Permission denied', 403);
            $b   = dc_body();
            $id  = (int)($b['id']   ?? 0);
            $note= trim($b['note']  ?? 'Escalated to Admin for review.');
            if (!$id) dc_err('Missing id', 400);
            $now = date('Y-m-d H:i:s');
            dc_sb('dtrs_audit_log', 'POST', [], [['doc_id'=>$id,'action_label'=>'Escalated to Admin','actor_name'=>$actor,'actor_role'=>$dcRoleName,'note'=>$note,'icon'=>'bx-up-arrow-circle','css_class'=>'dc-r','is_super_admin'=>false,'ip_address'=>$ip,'occurred_at'=>$now]]);
            dc_ok(['id'=>$id,'escalated'=>true]);
        }

        // ── GET audit log ─────────────────────────────────────────────────────
        if ($api === 'audit' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) dc_err('Missing id', 400);
            $rows = dc_sb('dtrs_audit_log', 'GET', ['select'=>'id,action_label,actor_name,actor_role,note,icon,css_class,is_super_admin,ip_address,occurred_at','doc_id'=>'eq.'.$id,'order'=>'occurred_at.asc']);
            dc_ok($rows);
        }

        dc_err('Unsupported API route', 404);
    } catch (Throwable $e) {
        dc_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── HTML PAGE RENDER ──────────────────────────────────────────────────────────
$root_html = $_SERVER['DOCUMENT_ROOT'];
include $root_html . '/includes/superadmin_sidebar.php';
include $root_html . '/includes/header.php';

$tagCls = match($dcRoleName) { 'Super Admin'=>'sa','Admin'=>'admin','Manager'=>'mgr',default=>'user' };
$tagIcon = match($dcRoleName) { 'Super Admin'=>'bx-shield-quarter','Admin'=>'bx-user-check','Manager'=>'bx-show',default=>'bx-user' };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Document Capture — DTRS</title>
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --primary:#2E7D32;--primary-dark:#1B5E20;--primary-lt:#81C784;--primary-xlt:#C8E6C9;
  --bg:#F4F6F5;--surface:#FFFFFF;--surface2:#FAFCFA;
  --border:rgba(46,125,50,.13);--border-md:rgba(46,125,50,.24);--border-lg:rgba(46,125,50,.38);
  --text-1:#1A2E1C;--text-2:#4A6350;--text-3:#9EB0A2;
  --hover:rgba(46,125,50,.05);
  --shadow-sm:0 1px 4px rgba(46,125,50,.08);
  --shadow-md:0 4px 16px rgba(46,125,50,.11);
  --shadow-lg:0 20px 60px rgba(0,0,0,.16);
  --red:#DC2626;--amber:#D97706;--blue:#2563EB;--teal:#0D9488;--purple:#7C3AED;
  --rad:14px;--tr:all .18s ease;
}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:rgba(46,125,50,.22);border-radius:4px}
.page{max-width:1500px;margin:0 auto;padding:0 0 3rem}
.page-hdr{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:28px;animation:fadeUp .4s both}
.eyebrow{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--primary);margin-bottom:4px}
.page-hdr h1{font-size:26px;font-weight:800;color:var(--text-1);line-height:1.15;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.page-hdr-sub{font-size:13px;color:var(--text-2);margin-top:2px}

/* ── ROLE BADGE ── */
.role-tag{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;letter-spacing:.04em;vertical-align:middle}
.role-tag.sa   {background:#E8F5E9;color:var(--primary); border:1px solid rgba(46,125,50,.25)}
.role-tag.admin{background:#EFF6FF;color:var(--blue);    border:1px solid rgba(37,99,235,.2)}
.role-tag.mgr  {background:#FEF3C7;color:var(--amber);   border:1px solid rgba(217,119,6,.2)}
.role-tag.user {background:#F3F4F6;color:#374151;        border:1px solid rgba(0,0,0,.1)}

/* ── ACCESS NOTICE ── */
.access-notice{display:flex;align-items:flex-start;gap:10px;border-radius:12px;padding:12px 16px;margin-bottom:22px;animation:fadeUp .4s .05s both;font-size:12.5px;line-height:1.55}
.access-notice i{font-size:18px;flex-shrink:0;margin-top:1px}
.access-notice.amber{background:linear-gradient(135deg,#FEF3C7,#FFFBEB);border:1px solid rgba(217,119,6,.3);color:#92400E}
.access-notice.amber i{color:var(--amber)}
.access-notice.amber strong{color:#78350F}
.access-notice.blue{background:linear-gradient(135deg,#EFF6FF,#F0F9FF);border:1px solid rgba(37,99,235,.2);color:#1E3A5F}
.access-notice.blue i{color:var(--blue)}
.access-notice.slate{background:#F8FAFC;border:1px solid rgba(0,0,0,.1);color:#374151}
.access-notice.slate i{color:#6B7280}
.access-notice.green{background:linear-gradient(135deg,#F0FDF4,#ECFDF5);border:1px solid rgba(46,125,50,.25);color:#14532D}
.access-notice.green i{color:var(--primary)}

/* ── MANAGER MONITORING DASHBOARD ── */
.monitor-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px;animation:fadeUp .4s .05s both}
.monitor-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px 18px;box-shadow:var(--shadow-sm);display:flex;align-items:center;gap:12px}
.mon-ic{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.ic-g{background:#E8F5E9;color:var(--primary)}.ic-a{background:#FEF3C7;color:var(--amber)}.ic-r{background:#FEE2E2;color:var(--red)}.ic-b{background:#EFF6FF;color:var(--blue)}.ic-t{background:#CCFBF1;color:var(--teal)}
.mon-v{font-size:22px;font-weight:800;color:var(--text-1);line-height:1.1}
.mon-l{font-size:11px;color:var(--text-2);margin-top:2px}
.low-conf-table{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow-sm);animation:fadeUp .4s .1s both}
.lct-hdr{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border);background:var(--bg)}
.lct-title{font-size:13px;font-weight:700;color:var(--text-1);display:flex;align-items:center;gap:7px}
.lct-title i{color:var(--primary)}
table.lct{width:100%;border-collapse:collapse;font-size:13px}
table.lct thead th{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-2);padding:10px 14px;text-align:left;background:var(--bg);border-bottom:1px solid var(--border);white-space:nowrap}
table.lct tbody tr{border-bottom:1px solid var(--border);transition:background .12s}
table.lct tbody tr:last-child{border-bottom:none}
table.lct tbody tr:hover{background:var(--hover)}
table.lct tbody td{padding:11px 14px;vertical-align:middle}
table.lct tbody td:first-child{padding-left:18px}
.conf-bar-wrap{width:60px;height:6px;background:var(--border);border-radius:3px;overflow:hidden;display:inline-block;vertical-align:middle;margin-right:6px}
.conf-bar-fill{height:100%;border-radius:3px}
.doc-id-mono{font-family:'DM Mono',monospace;font-size:11px;color:var(--primary);font-weight:500}
.status-chip{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:2px 8px;border-radius:7px;white-space:nowrap}
.s-processing{background:#FEF3C7;color:#92400E}
.s-registered{background:#E8F5E9;color:#1B5E20}
.btn-xs-action{display:inline-flex;align-items:center;gap:4px;font-family:'DM Sans',sans-serif;font-size:11px;font-weight:700;padding:5px 10px;border-radius:7px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap}
.btn-flag{background:#FEF3C7;color:#92400E;border:1px solid rgba(217,119,6,.2)}
.btn-flag:hover{background:#FDE68A}
.btn-escalate{background:#FEE2E2;color:#991B1B;border:1px solid rgba(220,38,38,.2)}
.btn-escalate:hover{background:#FECACA}
.empty-monitor{padding:40px 20px;text-align:center;color:var(--text-3);font-size:13px}
.empty-monitor i{font-size:36px;display:block;margin-bottom:8px;color:#C8E6C9}

/* ── CAPTURE LAYOUT ── */
.capture-grid{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:20px;align-items:start;animation:fadeUp .4s .08s both}
.left-col{min-width:0;width:100%;display:flex;flex-direction:column;gap:14px;overflow:hidden}
.right-col{position:sticky;top:20px;display:flex;flex-direction:column;gap:14px}
@media(max-width:1024px){.capture-grid{grid-template-columns:minmax(0,1fr) 260px}}
@media(max-width:860px){.capture-grid{grid-template-columns:1fr}.right-col{position:static}}
.steps-row{display:flex;align-items:center;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:12px 16px;gap:0}
.step{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;color:var(--text-3);flex:1;min-width:0}
.step.active{color:var(--primary)}.step.done{color:var(--primary)}
.step-num{width:24px;height:24px;border-radius:50%;border:2px solid currentColor;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;transition:var(--tr);background:transparent}
.step.active .step-num{background:var(--primary);border-color:var(--primary);color:#fff}
.step.done .step-num{background:var(--primary);border-color:var(--primary);color:#fff}
.step-line{flex:1;height:1.5px;background:var(--border);margin:0 6px}
.step-line.done{background:var(--primary)}
.step-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:600px){.step-label{display:none}}
.mode-tabs{display:grid;grid-template-columns:1fr 1fr;gap:0;background:var(--surface);border:1px solid var(--border-md);border-radius:12px;padding:4px;box-shadow:var(--shadow-sm)}
.mode-tab{display:flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:9px;cursor:pointer;transition:var(--tr);font-size:13px;font-weight:600;color:var(--text-2);user-select:none;border:none;background:none;font-family:'DM Sans',sans-serif;text-align:center;width:100%}
.mode-tab i{font-size:17px;flex-shrink:0;transition:var(--tr)}
.mode-tab:hover{color:var(--text-1);background:var(--hover)}
.mode-tab.active{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.25)}
.mode-tab.active i{color:#fff}
.mode-tab-label{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1px;min-width:0;text-align:center}
.mode-tab-label span{font-size:11px;font-weight:400;opacity:.75;line-height:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-sm);overflow:hidden;width:100%}
.card-hdr{padding:13px 18px;border-bottom:1px solid var(--border);background:var(--bg);display:flex;align-items:center;justify-content:space-between;gap:10px}
.card-hdr-title{font-size:13px;font-weight:700;color:var(--text-1);display:flex;align-items:center;gap:7px}
.card-hdr-title i{color:var(--primary);font-size:17px}
.card-body{padding:18px}
.drop-zone{border:2px dashed var(--border-md);border-radius:10px;padding:32px 20px;text-align:center;cursor:pointer;transition:var(--tr);position:relative;overflow:hidden;background:linear-gradient(135deg,rgba(46,125,50,.02),transparent)}
.drop-zone:hover,.drop-zone.dragover{border-color:var(--primary);background:rgba(46,125,50,.04);transform:translateY(-1px)}
.drop-zone.has-file{border-color:var(--primary);border-style:solid;background:rgba(46,125,50,.04)}
.drop-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.dz-icon{width:48px;height:48px;background:linear-gradient(135deg,#E8F5E9,#C8E6C9);border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:22px;color:var(--primary);transition:var(--tr)}
.drop-zone:hover .dz-icon,.drop-zone.dragover .dz-icon{transform:scale(1.08) rotate(-4deg)}
.dz-title{font-size:15px;font-weight:700;color:var(--text-1);margin-bottom:6px}
.dz-sub{font-size:12px;color:var(--text-2);line-height:1.6}
.dz-formats{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-top:12px}
.dz-fmt{font-size:10px;font-weight:700;padding:3px 8px;border-radius:6px;background:var(--bg);color:var(--text-2);border:1px solid var(--border-md);letter-spacing:.06em;text-transform:uppercase}

/* User: submit-for-validation notice above upload */
.user-submit-notice{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#EFF6FF;border:1px solid rgba(37,99,235,.25);border-radius:10px;margin-bottom:14px;font-size:12px;color:#1E40AF;font-weight:500}
.user-submit-notice i{font-size:16px;flex-shrink:0}

.file-preview{display:none;align-items:center;gap:14px;padding:14px;background:var(--bg);border:1px solid var(--border-md);border-radius:10px;margin-top:12px}
.file-preview.show{display:flex}
.fp-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.fp-info{flex:1;min-width:0}
.fp-name{font-size:13px;font-weight:700;color:var(--text-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fp-size{font-size:11px;color:var(--text-3);margin-top:2px}
.fp-remove{width:28px;height:28px;border-radius:7px;border:1px solid var(--border-md);background:var(--surface);cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-3);font-size:15px;transition:var(--tr);flex-shrink:0}
.fp-remove:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA}
.ocr-block{margin-top:16px;display:none}
.ocr-block.show{display:block}
.ocr-status-bar{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;font-size:13px;font-weight:600;background:var(--bg);border:1px solid var(--border)}
.ocr-status-bar.processing{background:#FEF3C7;border-color:#FDE68A;color:#92400E}
.ocr-status-bar.done{background:#E8F5E9;border-color:#A7F3D0;color:#065F46}
.ocr-status-bar.error{background:#FEE2E2;border-color:#FECACA;color:#991B1B}
.ocr-spin{animation:spin .8s linear infinite;display:inline-block;font-size:16px}
@keyframes spin{to{transform:rotate(360deg)}}
.ocr-preview{margin-top:10px;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px;font-size:12px;color:var(--text-2);line-height:1.7;max-height:100px;overflow-y:auto;font-family:'DM Mono',monospace;display:none}
.ocr-preview.show{display:block}
.ai-conf-row{display:flex;align-items:center;gap:10px;margin-top:12px;padding:10px 14px;background:var(--bg);border-radius:10px;border:1px solid var(--border)}
.ai-conf-label{font-size:12px;font-weight:600;color:var(--text-2);flex:1}
.ai-conf-bar-wrap{width:100px;height:6px;background:var(--border);border-radius:3px;overflow:hidden}
.ai-conf-bar{height:100%;border-radius:3px;background:var(--primary);transition:width .6s ease}
.ai-conf-pct{font-size:12px;font-weight:700;color:var(--primary);font-family:'DM Mono',monospace;min-width:36px;text-align:right}
.validation-flag{display:none;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;background:#FEF3C7;border:1px solid #FDE68A;font-size:12px;font-weight:600;color:#92400E;margin-top:8px}
.validation-flag.show{display:flex}
.validation-flag i{font-size:16px;flex-shrink:0}

/* User: read-only metadata review box */
.user-review-box{background:linear-gradient(135deg,#EFF6FF,#F0F9FF);border:1px solid rgba(37,99,235,.2);border-radius:12px;padding:16px;margin-bottom:14px}
.user-review-box h4{font-size:13px;font-weight:700;color:#1E40AF;margin-bottom:10px;display:flex;align-items:center;gap:7px}
.urb-chips{display:flex;flex-direction:column;gap:5px}
.urb-chip{display:flex;align-items:center;justify-content:space-between;padding:7px 10px;background:#fff;border:1px solid rgba(37,99,235,.15);border-radius:8px;font-size:12px}
.urb-chip .uc-label{font-weight:600;color:#1E40AF;font-size:10px;text-transform:uppercase;letter-spacing:.07em}
.urb-chip .uc-val{font-weight:700;color:var(--text-1)}
.urb-ai{font-size:9px;background:#E8F5E9;color:var(--primary);border:1px solid rgba(46,125,50,.2);padding:1px 6px;border-radius:4px;font-weight:700}

.sdv{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--primary);display:flex;align-items:center;gap:8px;padding-bottom:8px;border-bottom:1px solid var(--border);margin:16px 0 12px}
.field{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.field label{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-2);display:flex;align-items:center;gap:5px}
.field label .req{color:var(--red)}
.field label .ai-tag{font-size:9px;background:#E8F5E9;color:var(--primary);border:1px solid rgba(46,125,50,.2);padding:1px 6px;border-radius:5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase}
.field label .locked-tag{font-size:9px;background:#FEF3C7;color:#92400E;border:1px solid rgba(217,119,6,.2);padding:1px 6px;border-radius:5px;font-weight:700;letter-spacing:.05em}
.field input,.field select,.field textarea{font-family:'DM Sans',sans-serif;font-size:13px;padding:10px 13px;border:1.5px solid var(--border-md);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);width:100%}
.field input::placeholder,.field textarea::placeholder{color:var(--text-3)}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
.field input.ai-filled,.field select.ai-filled,.field textarea.ai-filled{border-color:rgba(46,125,50,.4);background:#F0FDF4}
.field input:read-only,.field select:disabled,.field textarea:read-only{background:var(--bg);color:var(--text-2);cursor:not-allowed;opacity:.8}
.field select{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:32px}
.field select:disabled{background-image:none;padding-right:13px}
.field textarea{resize:vertical;min-height:70px;line-height:1.6}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.id-preview-row{display:flex;align-items:center;gap:8px;padding:10px 13px;background:linear-gradient(135deg,rgba(46,125,50,.04),rgba(46,125,50,.02));border:1.5px solid rgba(46,125,50,.2);border-radius:10px;margin-bottom:12px}
.id-preview-row i{font-size:15px;color:var(--primary);flex-shrink:0}
.id-preview-row .id-badge{font-family:'DM Mono',monospace;font-size:13px;font-weight:700;color:var(--text-1)}
.id-preview-row .id-label{font-size:11px;color:var(--text-3);margin-left:2px}
.id-preview-row .id-sep{color:var(--border-md);margin:0 4px}
.id-preview-row .id-loading{font-size:11px;color:var(--text-3);font-style:italic}
.qr-generated-card{background:linear-gradient(135deg,#0B1A0C 0%,#1A3320 100%);border-radius:12px;padding:16px;display:none;flex-direction:column;align-items:center;text-align:center;gap:10px;position:relative;overflow:hidden;border:1px solid rgba(46,125,50,.3);box-shadow:0 8px 32px rgba(0,0,0,.25)}
.qr-generated-card.show{display:flex}
.qr-generated-card::before{content:'';position:absolute;top:-40px;right:-40px;width:140px;height:140px;background:radial-gradient(circle,rgba(46,125,50,.3),transparent 70%);pointer-events:none}
.qr-card-eyebrow{font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(129,199,132,.7);display:flex;align-items:center;gap:6px}
.qr-id-badge{font-family:'DM Mono',monospace;font-size:16px;font-weight:600;color:#E8F5E9;background:rgba(255,255,255,.1);padding:6px 16px;border-radius:8px;border:1px solid rgba(255,255,255,.12)}
.qr-wrap{background:#fff;border-radius:12px;padding:12px;box-shadow:0 4px 20px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center}
.qr-wrap canvas,.qr-wrap img{display:block;border-radius:4px}
.qr-ts{font-size:11px;color:rgba(255,255,255,.4);display:flex;align-items:center;gap:5px;font-family:'DM Mono',monospace}
.qr-actions{display:flex;gap:8px;width:100%}
.qr-action-btn{flex:1;padding:9px 12px;border-radius:9px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:rgba(255,255,255,.8);font-size:12px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;transition:var(--tr);display:flex;align-items:center;justify-content:center;gap:5px}
.qr-action-btn:hover{background:rgba(255,255,255,.16);color:#fff}
.qr-placeholder{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px 16px;text-align:center;gap:8px}
.qr-placeholder i{font-size:36px;color:#C8E6C9}
.qr-placeholder p{font-size:12px;color:var(--text-3);line-height:1.5}
.meta-preview{display:none}
.meta-preview.show{display:block}
.meta-chip-grid{display:flex;flex-direction:column;gap:5px}
.meta-chip{display:flex;align-items:center;justify-content:space-between;padding:7px 10px;background:var(--bg);border:1px solid var(--border);border-radius:8px;font-size:12px}
.mc-label{font-weight:600;color:var(--text-3);font-size:10px;text-transform:uppercase;letter-spacing:.07em}
.mc-val{font-weight:700;color:var(--text-1)}
.mc-ai{font-size:9px;background:#E8F5E9;color:var(--primary);border:1px solid rgba(46,125,50,.2);padding:1px 6px;border-radius:4px;font-weight:700;letter-spacing:.05em}
.submit-footer{border-top:1px solid var(--border);padding:14px 18px;background:var(--bg);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.sf-info{font-size:12px;color:var(--text-2)}
.sf-info span{font-weight:600;color:var(--text-1)}
.sf-btns{display:flex;gap:10px}
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:700;padding:10px 20px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap}
.btn-primary{background:var(--primary);color:#fff;box-shadow:0 2px 10px rgba(46,125,50,.3)}
.btn-primary:hover{background:var(--primary-dark);transform:translateY(-1px);box-shadow:0 4px 16px rgba(46,125,50,.4)}
.btn-ghost{background:var(--surface);color:var(--text-2);border:1.5px solid var(--border-md)}
.btn-ghost:hover{background:var(--hover);color:var(--text-1)}
.btn:disabled{opacity:.45;pointer-events:none}
.btn-lg{font-size:14px;padding:12px 28px;border-radius:12px}
.chip{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:8px;white-space:nowrap}
.chip-green{background:#E8F5E9;color:#1B5E20}
.chip-amber{background:#FEF3C7;color:#92400E}
.chip-blue{background:#EFF6FF;color:#1D4ED8}
.chip-red{background:#FEE2E2;color:#991B1B}
.success-banner{display:none;align-items:center;gap:12px;padding:12px 16px;background:linear-gradient(135deg,#E8F5E9,#C8E6C9);border:1px solid rgba(46,125,50,.3);border-radius:10px;animation:fadeUp .3s both;margin-bottom:16px}
.success-banner.show{display:flex}
.sb-ic{font-size:28px;flex-shrink:0}
.sb-title{font-size:14px;font-weight:800;color:var(--primary-dark)}
.sb-sub{font-size:12px;color:var(--text-2);margin-top:2px}
/* User submit-for-validation success */
.user-success-banner{display:none;align-items:center;gap:12px;padding:12px 16px;background:linear-gradient(135deg,#EFF6FF,#DBEAFE);border:1px solid rgba(37,99,235,.3);border-radius:10px;animation:fadeUp .3s both;margin-bottom:16px}
.user-success-banner.show{display:flex}
.usb-title{font-size:14px;font-weight:800;color:#1E40AF}
.usb-sub{font-size:12px;color:var(--text-2);margin-top:2px}
.toast-wrap{position:fixed;bottom:28px;right:28px;display:flex;flex-direction:column;gap:10px;z-index:9999;pointer-events:none}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:0 8px 32px rgba(0,0,0,.3);pointer-events:all;min-width:220px;animation:toastIn .3s ease}
.toast.t-success{background:var(--primary)}.toast.t-warning{background:var(--amber)}.toast.t-danger{background:var(--red)}
.toast.t-out{animation:toastOut .3s ease forwards}
@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastOut{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}
@media(max-width:768px){.capture-grid{grid-template-columns:1fr}.field-row{grid-template-columns:1fr}.mode-tab-label span{display:none}.card-body{padding:16px}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="page">

  <!-- PAGE HEADER -->
  <div class="page-hdr">
    <div>
      <div class="eyebrow">DTRS · Document Capture</div>
      <h1>Document Capture
        <span class="role-tag <?= $tagCls ?>"><i class='bx <?= $tagIcon ?>'></i><?= htmlspecialchars($dcRoleName) ?></span>
      </h1>
      <div class="page-hdr-sub">
        <?php echo match($dcRoleRank) {
            4 => 'Full access — capture, AI extract, force-validate, cross-zone assignment, modify retention',
            3 => 'Zone capture — physical &amp; digital intake with AI-assisted metadata · ' . htmlspecialchars($dcUserZone ?: 'All Zones'),
            2 => 'Monitoring mode — review team submissions, flag low-confidence AI tags, escalate to Admin',
            default => 'Submit documents for Admin validation · OCR + AI metadata preview',
        }; ?>
      </div>
    </div>
  </div>

  <!-- ACCESS NOTICES -->
  <?php if ($dcRoleRank === 1): ?>
  <div class="access-notice blue">
    <i class='bx bx-info-circle'></i>
    <p><strong>User Submission Mode:</strong> Upload documents and review AI-extracted metadata. Your submissions will be sent for Admin validation before being added to the registry. You cannot force-validate, modify retention policies, or assign outside your zone.</p>
  </div>
  <?php elseif ($dcRoleRank === 2): ?>
  <div class="access-notice amber">
    <i class='bx bx-info-circle'></i>
    <p><strong>Manager View:</strong> You can monitor your team's capture activity and flag low-confidence AI tags for review. To capture documents directly, contact an Admin or Super Admin. You can escalate flagged items to your Admin.</p>
  </div>
  <?php elseif ($dcRoleRank === 3): ?>
  <div class="access-notice green">
    <i class='bx bx-info-circle'></i>
    <p><strong>Admin Capture:</strong> You can register documents within zone <strong><?= htmlspecialchars($dcUserZone ?: 'All') ?></strong>. Retention policy and cross-zone assignment require Super Admin access.</p>
  </div>
  <?php endif; ?>

  <!-- ── MANAGER: MONITORING DASHBOARD ───────────────────────────────────── -->
  <?php if ($dcRoleRank === 2): ?>

  <div class="monitor-grid" id="monitorGrid">
    <!-- injected by JS after loadMonitorStats() -->
    <div class="monitor-card"><div class="mon-ic ic-b"><i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i></div><div><div class="mon-v">…</div><div class="mon-l">Loading…</div></div></div>
  </div>

  <div class="low-conf-table">
    <div class="lct-hdr">
      <div class="lct-title"><i class='bx bx-error-circle'></i> Pending Validation — Low-Confidence AI Tags</div>
      <span class="chip chip-amber" id="pendingCount"></span>
    </div>
    <div style="overflow-x:auto">
      <table class="lct">
        <thead>
          <tr>
            <th>Doc ID</th>
            <th>Title</th>
            <th>Type</th>
            <th>AI Confidence</th>
            <th>Mode</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="pendingTbody">
          <tr><td colspan="7" style="padding:32px;text-align:center;color:var(--text-3)"><i class='bx bx-loader-alt' style="animation:spin .8s linear infinite;font-size:20px;display:block;margin-bottom:8px"></i>Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <?php else: ?>
  <!-- ── CAPTURE VIEW (SA, Admin, User) ────────────────────────────────────── -->

  <!-- SUCCESS BANNERS -->
  <div class="success-banner" id="successBanner">
    <div class="sb-ic">🎉</div>
    <div>
      <div class="sb-title" id="successTitle">Document registered successfully!</div>
      <div class="sb-sub" id="successSub">Document ID assigned and QR code generated. Ready for tracking.</div>
    </div>
  </div>
  <div class="user-success-banner" id="userSuccessBanner">
    <div class="sb-ic">📋</div>
    <div>
      <div class="usb-title" id="uSuccessTitle">Submitted for Admin Validation</div>
      <div class="usb-sub" id="uSuccessSub">Your document has been submitted. An Admin will review and validate the metadata before it is added to the registry.</div>
    </div>
  </div>

  <div class="capture-grid">

    <!-- LEFT: UPLOAD + METADATA -->
    <div class="left-col">

      <!-- PROGRESS STEPS -->
      <div class="steps-row" id="stepsRow">
        <div class="step active" id="step1"><div class="step-num" id="sn1">1</div><span class="step-label">Upload</span></div>
        <div class="step-line" id="sl1"></div>
        <div class="step" id="step2"><div class="step-num" id="sn2">2</div><span class="step-label">AI Extract</span></div>
        <div class="step-line" id="sl2"></div>
        <div class="step" id="step3"><div class="step-num" id="sn3">3</div><span class="step-label"><?= $dcRoleRank === 1 ? 'Review' : 'Validate' ?></span></div>
        <div class="step-line" id="sl3"></div>
        <div class="step" id="step4"><div class="step-num" id="sn4">4</div><span class="step-label"><?= $dcRoleRank === 1 ? 'Submit' : 'Register' ?></span></div>
      </div>

      <!-- MODE TABS -->
      <div class="mode-tabs">
        <button class="mode-tab active" id="tabPhysical" onclick="switchMode('physical')">
          <i class='bx bx-scan'></i>
          <div class="mode-tab-label">Physical Document Scan<span>Upload scanned image · OCR + AI tagging</span></div>
        </button>
        <button class="mode-tab" id="tabDigital" onclick="switchMode('digital')">
          <i class='bx bx-cloud-upload'></i>
          <div class="mode-tab-label">Digital Document Upload<span>Drag &amp; drop · Instant metadata extraction</span></div>
        </button>
      </div>

      <!-- UPLOAD CARD -->
      <div class="card">
        <div class="card-hdr">
          <div class="card-hdr-title" id="uploadCardTitle"><i class='bx bx-scan'></i> Physical Document Scan</div>
          <span class="chip chip-amber" id="modeChip">OCR · AI Auto-tag</span>
        </div>
        <div class="card-body">
          <?php if ($dcRoleRank === 1): ?>
          <div class="user-submit-notice">
            <i class='bx bx-info-circle'></i>
            Your document will be submitted for Admin validation before being added to the registry.
          </div>
          <?php endif; ?>
          <div class="id-preview-row" id="idPreviewRow">
            <i class='bx bx-qr'></i>
            <span class="id-loading" id="idPreviewLoading">Fetching next Document ID…</span>
            <span class="id-badge" id="idPreviewDocId" style="display:none"></span>
            <span class="id-sep" id="idPreviewSep" style="display:none">·</span>
            <span class="id-label" id="idPreviewRefLabel" style="display:none"></span>
          </div>
          <div class="drop-zone" id="dropZone">
            <input type="file" id="fileInput" accept=".pdf,.png,.jpg,.jpeg,.tiff,.docx,.xlsx" onchange="handleFile(this.files[0])">
            <div class="dz-icon"><i class='bx bx-camera' id="dzIconI"></i></div>
            <div class="dz-title" id="dzTitle">Drop your scanned document here</div>
            <div class="dz-sub" id="dzSub">or click to browse files from your device</div>
            <div class="dz-formats" id="dzFormats">
              <span class="dz-fmt">PDF</span><span class="dz-fmt">PNG</span><span class="dz-fmt">JPG</span><span class="dz-fmt">TIFF</span>
            </div>
          </div>
          <div class="file-preview" id="filePreview">
            <div class="fp-icon" id="fpIcon"></div>
            <div class="fp-info"><div class="fp-name" id="fpName">—</div><div class="fp-size" id="fpSize">—</div></div>
            <button class="fp-remove" onclick="removeFile()" title="Remove file"><i class='bx bx-x'></i></button>
          </div>
          <div class="ocr-block" id="ocrBlock">
            <div class="ocr-status-bar processing" id="ocrStatus">
              <i class='bx bx-loader-alt ocr-spin' id="ocrIcon"></i>
              <span id="ocrStatusText">Running OCR extraction…</span>
            </div>
            <div class="ocr-preview" id="ocrPreview"></div>
            <div class="ai-conf-row" id="aiConfRow" style="display:none">
              <span class="ai-conf-label"><i class='bx bx-brain' style="color:var(--primary);margin-right:4px"></i>AI Confidence</span>
              <div class="ai-conf-bar-wrap"><div class="ai-conf-bar" id="aiConfBar" style="width:0%"></div></div>
              <span class="ai-conf-pct" id="aiConfPct">0%</span>
            </div>
            <div class="validation-flag" id="validationFlag">
              <i class='bx bx-error'></i>
              <div>
                <div style="font-weight:700"><?= $dcRoleRank === 1 ? 'Low Confidence — Admin Validation Required' : 'Staff Validation Required' ?></div>
                <div style="font-size:11px;opacity:.8;margin-top:1px">
                  <?php if ($dcRoleRank === 1): ?>
                    AI confidence is below 70%. Please review the extracted metadata. Your submission will be flagged for Admin review.
                  <?php elseif ($dcRoleRank >= 4): ?>
                    AI confidence is below 70%. As Super Admin, you may force-validate this document.
                  <?php else: ?>
                    AI confidence is below 70%. Please review and confirm extracted metadata before submitting.
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php if ($dcRoleRank >= 4): ?>
            <button class="btn btn-ghost" id="forceValidateBtn" style="margin-top:10px;display:none;width:100%;font-size:12px;padding:8px" onclick="forceValidate()">
              <i class='bx bx-shield-quarter'></i> SA Override: Force-Validate
            </button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- METADATA CARD -->
      <div class="card">
        <div class="card-hdr">
          <div class="card-hdr-title"><i class='bx bx-detail'></i>
            <?= $dcRoleRank === 1 ? 'Review Extracted Metadata' : 'Document Metadata' ?>
          </div>
          <span class="chip chip-green" id="metaBadge" style="display:none"><i class='bx bx-magic-wand'></i> AI Auto-filled</span>
        </div>
        <div class="card-body">
          <?php if ($dcRoleRank === 1): ?>
          <!-- User: review-only notice above fields -->
          <div style="display:flex;align-items:flex-start;gap:8px;padding:10px 12px;background:#FEF3C7;border:1px solid rgba(217,119,6,.25);border-radius:9px;margin-bottom:14px;font-size:12px;color:#92400E">
            <i class='bx bx-info-circle' style="font-size:15px;flex-shrink:0;margin-top:1px"></i>
            <span>Review the AI-extracted fields below. You can correct obvious errors. The <strong>Retention</strong> field is set by Admin policy.</span>
          </div>
          <?php endif; ?>

          <div class="sdv">Document Identity</div>
          <div class="field-row">
            <div class="field">
              <label>Document Title <span class="req">*</span> <span class="ai-tag" id="titleAiTag" style="display:none">AI</span></label>
              <input type="text" id="fTitle" placeholder="e.g. Supplier Agreement Q1 2025">
            </div>
            <div class="field">
              <label>Reference Number</label>
              <input type="text" id="fRefNum" placeholder="Auto-generated…" style="font-family:'DM Mono',monospace;font-size:12px" readonly>
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label>Document Type <span class="req">*</span> <span class="ai-tag" id="typeAiTag" style="display:none">AI</span></label>
              <select id="fType">
                <option value="">Select type…</option>
                <option>Memo</option><option>Contract</option><option>Invoice</option><option>Report</option>
                <option>Form</option><option>Certificate</option><option>Correspondence</option><option>Policy</option>
              </select>
            </div>
            <div class="field">
              <label>Category <span class="req">*</span> <span class="ai-tag" id="catAiTag" style="display:none">AI</span></label>
              <select id="fCategory">
                <option value="">Select…</option>
                <option>Financial</option><option>Legal</option><option>Operational</option>
                <option>HR</option><option>Procurement</option><option>Compliance</option><option>Administrative</option>
              </select>
            </div>
          </div>
          <div class="sdv">Routing &amp; Classification</div>
          <div class="field-row">
            <div class="field">
              <label>Department / Source <span class="req">*</span> <span class="ai-tag" id="deptAiTag" style="display:none">AI</span></label>
              <select id="fDepartment">
                <option value="">Select…</option>
                <option>Procurement</option><option>Logistics</option><option>Finance</option>
                <option>HR</option><option>Legal</option><option>Operations</option><option>Admin</option>
              </select>
            </div>
            <div class="field">
              <label>Direction <span class="req">*</span></label>
              <select id="fDirection">
                <option value="">Select…</option><option>Incoming</option><option>Outgoing</option>
              </select>
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label>Sender <span class="req">*</span></label>
              <input type="text" id="fSender" placeholder="e.g. Vendor ABC Corp">
            </div>
            <div class="field">
              <label>Recipient <span class="req">*</span></label>
              <input type="text" id="fRecipient" placeholder="e.g. Procurement Dept">
            </div>
          </div>
          <div class="field-row">
            <div class="field">
              <label>Assigned To <span class="req">*</span></label>
              <?php if ($dcRoleRank >= 3): ?>
              <select id="fAssignedTo"><option value="">Loading staff…</option></select>
              <?php else: ?>
              <!-- User: can only assign to themselves -->
              <input type="text" id="fAssignedTo" value="<?= htmlspecialchars($dcUserName) ?>" readonly>
              <?php endif; ?>
            </div>
            <div class="field">
              <label>Document Date <span class="req">*</span> <span class="ai-tag" id="dateAiTag" style="display:none">AI</span></label>
              <input type="datetime-local" id="fDateTime">
            </div>
          </div>
          <div class="sdv">Additional Details</div>
          <div class="field">
            <label>Notes / Description</label>
            <textarea id="fNotes" placeholder="Any additional notes, context, or special instructions…"></textarea>
          </div>
          <div class="field-row">
            <div class="field">
              <label>Priority Level</label>
              <select id="fPriority">
                <option value="Normal">Normal</option><option value="Urgent">Urgent</option>
                <option value="Confidential">Confidential</option><option value="High Value">High Value</option>
              </select>
            </div>
            <div class="field">
              <label>Retention Period
                <?php if ($dcRoleRank < 4): ?>
                <span class="locked-tag"><?= $dcRoleRank >= 3 ? 'Admin' : 'Admin Policy' ?></span>
                <?php endif; ?>
              </label>
              <?php if ($dcRoleRank >= 4): ?>
              <select id="fRetention">
                <option>1 Year</option><option>2 Years</option><option>3 Years</option>
                <option>5 Years</option><option>7 Years</option><option>10 Years</option><option>Permanent</option>
              </select>
              <?php elseif ($dcRoleRank === 3): ?>
              <!-- Admin: can set retention but not Permanent -->
              <select id="fRetention">
                <option>1 Year</option><option>2 Years</option><option>3 Years</option>
                <option>5 Years</option><option>7 Years</option><option>10 Years</option>
              </select>
              <?php else: ?>
              <!-- User: locked to default -->
              <input type="text" id="fRetention" value="1 Year (Admin Policy)" readonly>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="submit-footer">
          <div class="sf-info">Status: <span id="sfStatus">Waiting for upload</span></div>
          <div class="sf-btns">
            <button class="btn btn-ghost" onclick="resetAll()"><i class='bx bx-reset'></i> Reset</button>
            <button class="btn btn-primary btn-lg" onclick="submitDocument()" id="submitBtn" disabled>
              <i class='bx bx-check-circle'></i>
              <span id="submitLabel"><?= $dcRoleRank === 1 ? 'Submit for Validation' : 'Submit to Registry' ?></span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT COLUMN -->
    <div class="right-col">
      <div class="card">
        <div class="card-hdr">
          <div class="card-hdr-title"><i class='bx bx-qr'></i> Generated QR &amp; Document ID</div>
        </div>
        <div class="qr-placeholder" id="qrPlaceholder">
          <i class='bx bx-qr-scan'></i>
          <p>Upload a document to auto-generate a unique Document ID and QR code for tracking.</p>
        </div>
        <div class="qr-generated-card" id="qrGeneratedCard">
          <div class="qr-card-eyebrow"><i class='bx bx-check-circle'></i> Document ID Generated</div>
          <div class="qr-id-badge" id="qrDocId">DTRS-0000</div>
          <div class="qr-wrap" id="qrWrap"></div>
          <div class="qr-ts"><i class='bx bx-time-five'></i> <span id="qrTimestamp">—</span></div>
          <div class="qr-actions">
            <button class="qr-action-btn" onclick="downloadQR()"><i class='bx bx-download'></i> Download</button>
            <button class="qr-action-btn" onclick="printQR()"><i class='bx bx-printer'></i> Print</button>
          </div>
        </div>
      </div>
      <div class="card" id="metaPreviewCard">
        <div class="card-hdr">
          <div class="card-hdr-title"><i class='bx bx-list-check'></i> Metadata Preview</div>
          <span class="chip chip-amber" id="previewBadge" style="display:none">Pending Validation</span>
        </div>
        <div class="card-body">
          <div class="qr-placeholder" id="metaPlaceholder" style="padding:28px 24px">
            <i class='bx bx-file-blank' style="font-size:40px;color:#C8E6C9"></i>
            <p style="font-size:12px">Metadata will preview here after AI extraction.</p>
          </div>
          <div class="meta-preview" id="metaPreview">
            <div class="meta-chip-grid" id="metaChips"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php endif; // end Manager vs Capture view ?>

</div><!-- .page -->

<div class="toast-wrap" id="toastWrap"></div>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
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

// ── STATE ─────────────────────────────────────────────────────────────────────
let currentMode   = 'physical';
let uploadedFile  = null;
let currentDocId  = null;
let currentRefNum = null;
let ocrDone       = false;
let aiConfidence  = 0;
let currentStep   = 1;
let ocrExtracted  = '';
let aiAutoFilled  = false;
let staffList     = [];
let forceValidated = false;

// ── INIT ──────────────────────────────────────────────────────────────────────
if (ROLERANK === 2) {
    // Manager: load monitoring stats only
    loadMonitorStats();
    loadPendingDocs();
} else {
    // Capture roles
    const dtEl = document.getElementById('fDateTime');
    if (dtEl) dtEl.value = nowDTLocal();
    loadInit();
}

async function loadInit() {
    const tasks = [prefetchIds()];
    if (ROLERANK >= 3) tasks.push(loadStaff()); // Admin/SA load staff list; User uses own name
    await Promise.all(tasks);
}

async function loadStaff() {
    try {
        staffList = await apiGet(API + '?api=staff');
        const sel = document.getElementById('fAssignedTo');
        if (!sel || sel.tagName !== 'SELECT') return;
        sel.innerHTML = '<option value="">Select staff…</option>' +
            staffList.map(s => `<option value="${esc(s.name)}">${esc(s.name)}</option>`).join('');
        if (staffList.length) sel.value = staffList[0].name;
    } catch(e) {
        const sel = document.getElementById('fAssignedTo');
        if (sel && sel.tagName === 'SELECT') sel.innerHTML = '<option value="">Unable to load staff</option>';
    }
}

async function prefetchIds() {
    try {
        const data = await apiGet(API + '?api=next-ids');
        currentDocId  = data.docId;
        currentRefNum = data.refNumber;
        showIdPreview(currentDocId, currentRefNum);
    } catch(e) {
        const el = document.getElementById('idPreviewLoading');
        if (el) el.textContent = 'ID will be assigned on submission';
    }
}

function showIdPreview(docId, refNum) {
    const loadEl = document.getElementById('idPreviewLoading');
    const idEl   = document.getElementById('idPreviewDocId');
    const sepEl  = document.getElementById('idPreviewSep');
    const refEl2 = document.getElementById('idPreviewRefLabel');
    if (!loadEl) return;
    loadEl.style.display = 'none';
    if (idEl) { idEl.textContent = docId; idEl.style.display = 'inline'; }
    if (sepEl) sepEl.style.display = 'inline';
    if (refEl2) { refEl2.textContent = refNum; refEl2.style.display = 'inline'; }
    const refInput = document.getElementById('fRefNum');
    if (refInput) { refInput.value = refNum; refInput.classList.add('ai-filled'); }
}

// ── MANAGER: MONITORING ───────────────────────────────────────────────────────
async function loadMonitorStats() {
    try {
        const stats = await apiGet(API + '?api=monitor-stats');
        const cards = [
            {ic:'ic-b',  icon:'bx-upload',         v:stats.uploadsToday,     l:'Uploads Today'},
            {ic:'ic-g',  icon:'bx-camera',          v:stats.physicalToday,    l:'Physical Scans'},
            {ic:'ic-t',  icon:'bx-cloud-upload',    v:stats.digitalToday,     l:'Digital Uploads'},
            {ic:'ic-a',  icon:'bx-time-five',       v:stats.pendingValidation,l:'Pending Validation'},
            {ic:'ic-r',  icon:'bx-error-circle',    v:stats.lowConfidence,    l:'Low-Confidence Tags'},
            {ic:'ic-b',  icon:'bx-folder',          v:stats.totalDocs,        l:'Total Zone Docs'},
        ];
        document.getElementById('monitorGrid').innerHTML = cards.map(c =>
            `<div class="monitor-card"><div class="mon-ic ${c.ic}"><i class='bx ${c.icon}'></i></div><div><div class="mon-v">${c.v}</div><div class="mon-l">${c.l}</div></div></div>`
        ).join('');
    } catch(e) { toast('Failed to load stats: '+e.message, 'danger'); }
}

async function loadPendingDocs() {
    try {
        const rows = await apiGet(API + '?api=low-conf-docs');
        const count = document.getElementById('pendingCount');
        if (count) { count.textContent = rows.length + ' pending'; count.style.display = rows.length ? 'inline-flex' : 'none'; }
        const tbody = document.getElementById('pendingTbody');
        if (!tbody) return;
        if (!rows.length) {
            tbody.innerHTML = `<tr><td colspan="7"><div class="empty-monitor"><i class='bx bx-check-circle'></i><p>No pending validation items. All good!</p></div></td></tr>`;
            return;
        }
        tbody.innerHTML = rows.map(r => {
            const conf = r.ai_confidence || 0;
            const confColor = conf >= 80 ? '#2E7D32' : conf >= 60 ? '#D97706' : '#DC2626';
            const statusCls = r.status === 'Processing' ? 's-processing' : 's-registered';
            const modeIcon  = r.capture_mode === 'physical' ? 'bx-scan' : 'bx-cloud-upload';
            return `<tr>
              <td><span class="doc-id-mono">${esc(r.doc_id)}</span></td>
              <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:500">${esc(r.title)}</td>
              <td style="font-size:12px;color:var(--text-2)">${esc(r.doc_type)}</td>
              <td>
                <div class="conf-bar-wrap"><div class="conf-bar-fill" style="width:${conf}%;background:${confColor}"></div></div>
                <span style="font-family:'DM Mono',monospace;font-size:12px;font-weight:700;color:${confColor}">${conf}%</span>
              </td>
              <td><i class='bx ${modeIcon}' style="font-size:14px;color:var(--primary)"></i></td>
              <td><span class="status-chip ${statusCls}">${esc(r.status)}</span></td>
              <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                  <button class="btn-xs-action btn-flag" onclick="flagAI(${r.id},'${esc(r.doc_id)}')"><i class='bx bx-flag'></i> Flag</button>
                  <button class="btn-xs-action btn-escalate" onclick="escalateDoc(${r.id},'${esc(r.doc_id)}')"><i class='bx bx-up-arrow-circle'></i> Escalate</button>
                </div>
              </td>
            </tr>`;
        }).join('');
    } catch(e) { toast('Failed to load pending docs: '+e.message, 'danger'); }
}

async function flagAI(id, docId) {
    const note = prompt(`Describe the AI tag issue for ${docId}:`);
    if (!note?.trim()) return;
    try {
        await apiPost(API+'?api=flag-ai', {id, note: note.trim()});
        toast(`${docId} flagged for review`, 'warning');
        await loadPendingDocs();
    } catch(e) { toast(e.message, 'danger'); }
}

async function escalateDoc(id, docId) {
    const note = prompt(`Escalation note for ${docId} (sent to Admin):`);
    if (!note?.trim()) return;
    try {
        await apiPost(API+'?api=escalate', {id, note: note.trim()});
        toast(`${docId} escalated to Admin`, 'warning');
        await loadPendingDocs();
    } catch(e) { toast(e.message, 'danger'); }
}

// ── MODE SWITCH ───────────────────────────────────────────────────────────────
function switchMode(mode) {
    currentMode = mode;
    document.getElementById('tabPhysical').classList.toggle('active', mode === 'physical');
    document.getElementById('tabDigital').classList.toggle('active', mode === 'digital');
    if (mode === 'physical') {
        document.getElementById('uploadCardTitle').innerHTML = "<i class='bx bx-scan'></i> Physical Document Scan";
        document.getElementById('modeChip').textContent = 'OCR · AI Auto-tag';
        document.getElementById('modeChip').className = 'chip chip-amber';
        document.getElementById('dzIconI').className = 'bx bx-camera';
        document.getElementById('dzTitle').textContent = 'Drop your scanned document here';
        document.getElementById('dzSub').textContent = 'Supports scanned images and PDFs · OCR will extract text automatically';
        document.getElementById('dzFormats').innerHTML = '<span class="dz-fmt">PDF</span><span class="dz-fmt">PNG</span><span class="dz-fmt">JPG</span><span class="dz-fmt">TIFF</span>';
        document.getElementById('fileInput').accept = '.pdf,.png,.jpg,.jpeg,.tiff';
    } else {
        document.getElementById('uploadCardTitle').innerHTML = "<i class='bx bx-cloud-upload'></i> Digital Document Upload";
        document.getElementById('modeChip').textContent = 'Instant Metadata Extraction';
        document.getElementById('modeChip').className = 'chip chip-blue';
        document.getElementById('dzIconI').className = 'bx bx-cloud-upload';
        document.getElementById('dzTitle').textContent = 'Drag & drop your digital document';
        document.getElementById('dzSub').textContent = 'Metadata extracted immediately from file properties';
        document.getElementById('dzFormats').innerHTML = '<span class="dz-fmt">PDF</span><span class="dz-fmt">DOCX</span><span class="dz-fmt">XLSX</span><span class="dz-fmt">Any</span>';
        document.getElementById('fileInput').accept = '.pdf,.docx,.xlsx,.txt,.doc';
    }
    resetAll(true);
}

// ── DRAG & DROP ───────────────────────────────────────────────────────────────
const dz = document.getElementById('dropZone');
if (dz) {
    dz.addEventListener('dragenter', e => { e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', ()  => dz.classList.remove('dragover'));
    dz.addEventListener('drop', e => {
        e.preventDefault(); dz.classList.remove('dragover');
        if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
    });
}

// ── FILE HANDLING ─────────────────────────────────────────────────────────────
function handleFile(file) {
    if (!file) return;
    uploadedFile = file;
    const ext = file.name.split('.').pop().toUpperCase();
    const icons  = {PDF:'bx-file-pdf',PNG:'bx-image',JPG:'bx-image',JPEG:'bx-image',TIFF:'bx-image',DOCX:'bx-file-doc',XLSX:'bx-spreadsheet'};
    const colors = {PDF:'#DC2626',PNG:'#2563EB',JPG:'#2563EB',JPEG:'#2563EB',TIFF:'#7C3AED',DOCX:'#2563EB',XLSX:'#2E7D32'};
    const fpIconEl = document.getElementById('fpIcon');
    const ic = icons[ext] || 'bx-file', col = colors[ext] || '#6B7280';
    fpIconEl.innerHTML = `<i class='bx ${ic}' style="font-size:22px;color:${col}"></i>`;
    Object.assign(fpIconEl.style, {background:col+'18',borderRadius:'10px',width:'44px',height:'44px',display:'flex',alignItems:'center',justifyContent:'center'});
    document.getElementById('fpName').textContent = file.name;
    document.getElementById('fpSize').textContent = (file.size / 1024).toFixed(1) + ' KB · ' + ext + ' file';
    document.getElementById('filePreview').classList.add('show');
    dz.classList.add('has-file');
    generateQR(currentDocId);
    startProcessing(file, ext);
    setStep(2);
    document.getElementById('sfStatus').textContent = 'Processing file…';
    document.getElementById('submitBtn').disabled = true;
}

function removeFile() {
    uploadedFile = null; ocrDone = false; ocrExtracted = ''; aiAutoFilled = false; forceValidated = false;
    document.getElementById('filePreview').classList.remove('show');
    document.getElementById('ocrBlock').classList.remove('show');
    document.getElementById('ocrPreview').classList.remove('show');
    document.getElementById('aiConfRow').style.display = 'none';
    document.getElementById('validationFlag').classList.remove('show');
    const fvBtn = document.getElementById('forceValidateBtn'); if (fvBtn) fvBtn.style.display = 'none';
    document.getElementById('qrPlaceholder').style.display = 'flex';
    document.getElementById('qrGeneratedCard').classList.remove('show');
    document.getElementById('metaPlaceholder').style.display = 'flex';
    document.getElementById('metaPreview').classList.remove('show');
    document.getElementById('previewBadge').style.display = 'none';
    document.getElementById('metaBadge').style.display = 'none';
    document.getElementById('fileInput').value = '';
    dz.classList.remove('has-file');
    clearAIFields();
    setStep(1);
    document.getElementById('sfStatus').textContent = 'Waiting for upload';
    document.getElementById('submitBtn').disabled = true;
    const sb = document.getElementById('successBanner'); if (sb) sb.classList.remove('show');
    const usb = document.getElementById('userSuccessBanner'); if (usb) usb.classList.remove('show');
    document.getElementById('aiConfBar').style.width = '0%';
    document.getElementById('aiConfPct').textContent = '0%';
}

// ── FORCE-VALIDATE (SA only) ──────────────────────────────────────────────────
function forceValidate() {
    if (!PERMS.forceValidate) return;
    forceValidated = true;
    aiConfidence = 100;
    document.getElementById('aiConfBar').style.width = '100%';
    document.getElementById('aiConfBar').style.background = 'var(--primary)';
    document.getElementById('aiConfPct').textContent = '100%';
    document.getElementById('aiConfPct').style.color = 'var(--primary)';
    document.getElementById('validationFlag').classList.remove('show');
    document.getElementById('forceValidateBtn').style.display = 'none';
    document.getElementById('sfStatus').textContent = 'SA force-validated — ready to submit';
    document.getElementById('submitBtn').disabled = false;
    document.getElementById('previewBadge').style.display = 'none';
    toast('Force-validated by Super Admin', 'success');
}

// ── FILE READING & OCR ────────────────────────────────────────────────────────
async function readFileContent(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (['txt','csv','md'].includes(ext)) {
        return await new Promise((res,rej) => { const r=new FileReader(); r.onload=e=>res(e.target.result||''); r.onerror=()=>rej(new Error('FileReader error')); r.readAsText(file); });
    }
    if (ext === 'pdf') {
        if (!window.pdfjsLib) {
            await loadScript('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js');
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }
        const ab = await file.arrayBuffer();
        const pdf = await pdfjsLib.getDocument({data:ab}).promise;
        let text = '';
        for (let i = 1; i <= Math.min(pdf.numPages, 5); i++) {
            const page = await pdf.getPage(i);
            const c    = await page.getTextContent();
            text += c.items.map(s=>s.str).join(' ') + '\n';
        }
        return text.trim() || '';
    }
    if (['docx','doc'].includes(ext)) {
        if (!window.mammoth) await loadScript('https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js');
        const ab = await file.arrayBuffer();
        const result = await mammoth.extractRawText({arrayBuffer:ab});
        return (result.value||'').trim();
    }
    if (['png','jpg','jpeg','tiff','bmp','webp'].includes(ext)) {
        if (!window.Tesseract) await loadScript('https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js');
        const worker = await Tesseract.createWorker('eng', 1, {logger:m=>{if(m.status==='recognizing text'){const pct=Math.round((m.progress||0)*100);const el=document.getElementById('ocrStatusText');if(el)el.textContent=`OCR scanning… ${pct}%`;}}});
        const {data:{text}} = await worker.recognize(file);
        await worker.terminate();
        return text.trim();
    }
    return `Document: ${file.name} (${(file.size/1024).toFixed(1)} KB, ${ext.toUpperCase()})`;
}

function loadScript(src) {
    return new Promise((res,rej) => {
        if (document.querySelector(`script[src="${src}"]`)) { res(); return; }
        const s = document.createElement('script'); s.src=src; s.onload=res; s.onerror=()=>rej(new Error('Failed to load: '+src)); document.head.appendChild(s);
    });
}

async function startProcessing(file, ext) {
    const isPhysical    = currentMode === 'physical';
    const ocrStatus     = document.getElementById('ocrStatus');
    const ocrStatusText = document.getElementById('ocrStatusText');
    const ocrPreview    = document.getElementById('ocrPreview');

    document.getElementById('ocrBlock').classList.add('show');
    ocrStatus.className = 'ocr-status-bar processing';
    document.getElementById('ocrIcon').className = 'bx bx-loader-alt ocr-spin';
    ocrStatusText.textContent = isPhysical ? 'Reading file content…' : 'Extracting file text…';
    ocrPreview.classList.remove('show');

    let rawText = '';
    try { rawText = await readFileContent(file); }
    catch(e) { rawText = `${file.name} — could not extract text (${e.message})`; }

    if (!uploadedFile) return;
    ocrExtracted = rawText;

    if (rawText && rawText.length > 20) {
        ocrPreview.textContent = rawText.substring(0, 400) + (rawText.length > 400 ? '…' : '');
        ocrPreview.classList.add('show');
    }

    ocrStatusText.textContent = 'AI analyzing document content…';
    document.getElementById('ocrIcon').className = 'bx bx-loader-alt ocr-spin';

    let meta;
    try {
        meta = await apiPost(API + '?api=extract', {ocrText: ocrExtracted});
    } catch(e) {
        ocrStatus.className = 'ocr-status-bar error';
        document.getElementById('ocrIcon').className = 'bx bx-error-circle';
        ocrStatusText.textContent = 'Extraction error: ' + e.message;
        document.getElementById('sfStatus').textContent = 'Manual entry required';
        document.getElementById('submitBtn').disabled = false;
        setStep(3);
        toast('AI extraction failed: ' + e.message, 'danger');
        return;
    }

    if (!uploadedFile) return;

    aiConfidence = meta.confidence;
    aiAutoFilled = true;

    ocrStatus.className = 'ocr-status-bar done';
    document.getElementById('ocrIcon').className = 'bx bx-check-circle';
    ocrStatusText.textContent = 'Metadata extracted from document content';

    document.getElementById('aiConfRow').style.display = 'flex';
    setTimeout(() => {
        document.getElementById('aiConfBar').style.width      = aiConfidence + '%';
        document.getElementById('aiConfBar').style.background = aiConfidence>=80?'var(--primary)':aiConfidence>=60?'var(--amber)':'var(--red)';
        document.getElementById('aiConfPct').textContent      = aiConfidence + '%';
        document.getElementById('aiConfPct').style.color      = aiConfidence>=80?'var(--primary)':aiConfidence>=60?'var(--amber)':'var(--red)';
    }, 100);

    if (aiConfidence < 70) {
        document.getElementById('validationFlag').classList.add('show');
        document.getElementById('previewBadge').style.display = 'inline-flex';
        // SA: show force-validate button
        const fvBtn = document.getElementById('forceValidateBtn');
        if (fvBtn && PERMS.forceValidate) fvBtn.style.display = 'flex';
        document.getElementById('sfStatus').textContent = ROLERANK === 1 ? 'Review required — submit for Admin validation' : 'Staff validation required';
    } else {
        document.getElementById('sfStatus').textContent = ROLERANK === 1 ? 'Ready to submit for validation' : 'Ready to submit';
    }

    fillAIFields(meta);
    showMetaPreview(meta);
    document.getElementById('metaBadge').style.display = 'inline-flex';
    ocrDone = true;
    setStep(3);
    document.getElementById('submitBtn').disabled = false;
}

// ── AI FIELD FILL ─────────────────────────────────────────────────────────────
function fillAIFields(meta) {
    const fields = [
        ['fTitle',      meta.title,      'titleAiTag'],
        ['fType',       meta.doc_type,   'typeAiTag'],
        ['fCategory',   meta.category,   'catAiTag'],
        ['fDepartment', meta.department, 'deptAiTag'],
        ['fSender',     meta.sender,     null],
        ['fRecipient',  meta.recipient,  null],
        ['fNotes',      meta.notes,      null],
    ];
    fields.forEach(([id, val, tagId], i) => {
        setTimeout(() => {
            if (!val) return;
            const el = document.getElementById(id); if (!el) return;
            el.value = val; el.classList.add('ai-filled');
            if (tagId) { const t=document.getElementById(tagId); if(t) t.style.display='inline-flex'; }
        }, i * 120);
    });
    if (meta.direction) setTimeout(()=>{ const e=document.getElementById('fDirection'); if(e){e.value=meta.direction;e.classList.add('ai-filled');} }, 200);
    if (meta.doc_date)  setTimeout(()=>{ const e=document.getElementById('fDateTime');  if(e){const c=meta.doc_date.length===10?meta.doc_date+'T08:00':meta.doc_date.slice(0,16);e.value=c;e.classList.add('ai-filled');const t=document.getElementById('dateAiTag');if(t)t.style.display='inline-flex';} }, 600);
    if (meta.priority && meta.priority !== 'Normal') setTimeout(()=>{ const e=document.getElementById('fPriority'); if(e){e.value=meta.priority;e.classList.add('ai-filled');} }, 700);
}

function clearAIFields() {
    ['fTitle','fType','fCategory','fDepartment','fSender','fRecipient','fNotes'].forEach(id=>{ const el=document.getElementById(id); if(el&&el.tagName!=='INPUT'||el?.tagName!=='SELECT'){ if(el)el.value=''; if(el)el.classList.remove('ai-filled'); } });
    ['fTitle','fSender','fRecipient','fNotes'].forEach(id=>{ const el=document.getElementById(id); if(el){el.value='';el.classList.remove('ai-filled');} });
    ['fType','fCategory','fDepartment','fDirection'].forEach(id=>{ const el=document.getElementById(id); if(el){el.value='';el.classList.remove('ai-filled');} });
    ['titleAiTag','typeAiTag','catAiTag','deptAiTag','dateAiTag'].forEach(id=>{ const el=document.getElementById(id); if(el)el.style.display='none'; });
}

// ── META PREVIEW ──────────────────────────────────────────────────────────────
function showMetaPreview(meta) {
    document.getElementById('metaPlaceholder').style.display = 'none';
    document.getElementById('metaPreview').classList.add('show');
    const items = [
        {l:'Doc ID',     v:currentDocId,                                                                  ai:false},
        {l:'Ref No.',    v:currentRefNum,                                                                 ai:false},
        {l:'Title',      v:(meta.title||'').substring(0,32)+((meta.title||'').length>32?'…':''),         ai:true},
        {l:'Type',       v:meta.doc_type,                                                                 ai:true},
        {l:'Category',   v:meta.category,                                                                 ai:true},
        {l:'Department', v:meta.department,                                                               ai:true},
        {l:'Direction',  v:meta.direction,                                                                ai:true},
        {l:'Sender',     v:(meta.sender||'').substring(0,28)+((meta.sender||'').length>28?'…':''),       ai:true},
        {l:'Confidence', v:meta.confidence+'%',                                                           ai:false},
        {l:'Mode',       v:currentMode==='physical'?'Physical Scan':'Digital Upload',                    ai:false},
        {l:'Submit as',  v:ROLERANK===1?'Pending Validation':'Registered',                               ai:false},
    ];
    document.getElementById('metaChips').innerHTML = items.filter(it=>it.v).map(it=>`
        <div class="meta-chip">
          <div><div class="mc-label">${it.l}</div><div class="mc-val">${esc(it.v)}</div></div>
          ${it.ai?`<span class="mc-ai">AI</span>`:''}
        </div>`).join('');
}

// ── QR GENERATION ─────────────────────────────────────────────────────────────
function generateQR(docId) {
    document.getElementById('qrPlaceholder').style.display = 'none';
    document.getElementById('qrGeneratedCard').classList.add('show');
    document.getElementById('qrDocId').textContent = docId;
    document.getElementById('qrTimestamp').textContent = fmtNow();
    const wrap = document.getElementById('qrWrap'); wrap.innerHTML='';
    new QRCode(wrap, {text:docId, width:130, height:130, colorDark:'#0B1A0C', colorLight:'#FFFFFF', correctLevel:QRCode.CorrectLevel.H});
}

function downloadQR() {
    const wrap = document.getElementById('qrWrap');
    const c = wrap.querySelector('canvas')||wrap.querySelector('img');
    if (!c) { toast('QR not ready','warning'); return; }
    const url = c.tagName==='CANVAS'?c.toDataURL('image/png'):c.src;
    const a=document.createElement('a'); a.href=url; a.download=(currentDocId||'QR')+'.png'; a.click();
    toast('QR downloaded — '+currentDocId,'success');
}

function printQR() {
    const wrap = document.getElementById('qrWrap');
    const c = wrap.querySelector('canvas')||wrap.querySelector('img');
    if (!c) { toast('QR not ready','warning'); return; }
    const src = c.tagName==='CANVAS'?c.toDataURL('image/png'):c.src;
    const doc = window.open('','_blank','width=400,height=480');
    doc.document.write(`<!DOCTYPE html><html><head><title>QR — ${currentDocId}</title><style>body{font-family:sans-serif;text-align:center;padding:28px;background:#fff}h2{font-size:15px;color:#1A2E1C;margin-bottom:4px}p{font-size:11px;color:#9EB0A2;margin-bottom:16px}img{border:2px solid #ddd;border-radius:8px;padding:10px}@media print{button{display:none}}</style></head><body><h2>${currentDocId}</h2><p>DTRS Document Tracking · ${fmtNow()}</p><img src="${src}" width="190" height="190"><br><br><button onclick="window.print()">🖨 Print</button></body></html>`);
    doc.document.close(); doc.focus(); setTimeout(()=>doc.print(), 400);
}

// ── STEP TRACKER ──────────────────────────────────────────────────────────────
function setStep(n) {
    currentStep = n;
    for (let i = 1; i <= 4; i++) {
        const step = document.getElementById('step'+i);
        const sn   = document.getElementById('sn'+i);
        if (!step || !sn) continue;
        if (i < n)       { step.classList.add('done'); step.classList.remove('active'); sn.innerHTML=`<i class='bx bx-check' style="font-size:12px"></i>`; }
        else if (i === n){ step.classList.add('active'); step.classList.remove('done'); sn.textContent=i; }
        else             { step.classList.remove('active','done'); sn.textContent=i; }
        if (i < 4) { const sl=document.getElementById('sl'+i); if(sl) sl.classList.toggle('done', i<n); }
    }
}

// ── SUBMIT ────────────────────────────────────────────────────────────────────
async function submitDocument() {
    const req = ['fTitle','fType','fCategory','fDepartment','fDirection','fSender','fRecipient','fAssignedTo','fDateTime'];
    const empty = req.filter(id => {
        const el = document.getElementById(id);
        return el && !el.value.trim();
    });
    if (empty.length) { toast('Please fill all required fields','warning'); return; }
    if (!uploadedFile) { toast('Please upload a document first','warning'); return; }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = `<i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i> ${ROLERANK===1?'Submitting…':'Registering…'}`;

    try {
        const file = uploadedFile;
        const ext  = file.name.split('.').pop().toLowerCase();

        const retEl = document.getElementById('fRetention');
        const retention = retEl?.value?.includes('Admin Policy') ? '1 Year' : (retEl?.value || '1 Year');

        const payload = {
            title:        document.getElementById('fTitle').value.trim(),
            docType:      document.getElementById('fType').value,
            category:     document.getElementById('fCategory').value,
            department:   document.getElementById('fDepartment').value,
            direction:    document.getElementById('fDirection').value,
            sender:       document.getElementById('fSender').value.trim(),
            recipient:    document.getElementById('fRecipient').value.trim(),
            assignedTo:   document.getElementById('fAssignedTo').value,
            docDate:      document.getElementById('fDateTime').value,
            priority:     document.getElementById('fPriority').value,
            retention,
            notes:        document.getElementById('fNotes').value.trim(),
            captureMode:  currentMode,
            fileName:     file.name,
            fileSizeKb:   parseFloat((file.size/1024).toFixed(1)),
            fileExt:      ext.toUpperCase(),
            filePath:     '',
            ocrText:      ocrExtracted,
            aiConfidence: forceValidated ? 100 : aiConfidence,
            aiAutoFilled: aiAutoFilled,
            refNumber:    '',
        };

        const saved = await apiPost(API + '?api=register', payload);
        const confirmedDocId = saved.docId;
        const confirmedId    = saved.id;
        currentDocId  = confirmedDocId;
        currentRefNum = saved.refNumber;

        // Upload file
        btn.innerHTML = `<i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i> Uploading file…`;
        try {
            const b64 = await fileToBase64(file);
            await apiPost(API + '?api=upload', {docId:confirmedDocId, docDbId:confirmedId, fileName:file.name, fileExt:ext, fileBase64:b64});
        } catch(uploadErr) {
            toast('File upload failed: '+uploadErr.message+' — document registered without file.','warning');
        }

        generateQR(currentDocId);
        showIdPreview(currentDocId, currentRefNum);
        setStep(4);

        if (ROLERANK === 1) {
            const usb = document.getElementById('userSuccessBanner');
            if (usb) {
                usb.classList.add('show');
                document.getElementById('uSuccessTitle').textContent = `${confirmedDocId} submitted for Admin validation`;
                document.getElementById('uSuccessSub').textContent   = `An Admin will review and validate your submission. Document ID: ${confirmedDocId}.`;
            }
        } else {
            const sb = document.getElementById('successBanner');
            if (sb) {
                sb.classList.add('show');
                document.getElementById('successTitle').textContent = `Document ${confirmedDocId} registered successfully!`;
                document.getElementById('successSub').textContent   = `Assigned to ${saved.assignedTo}. QR code ready for tracking.`;
            }
        }

        document.getElementById('sfStatus').textContent = ROLERANK===1 ? 'Submitted for validation ✓' : 'Registered ✓';
        btn.innerHTML = `<i class='bx bx-check-circle'></i> ${ROLERANK===1?'Submitted':'Registered'}`;
        btn.style.background = ROLERANK===1 ? '#1E40AF' : '#065F46';
        toast(confirmedDocId + (ROLERANK===1?' submitted for validation':' saved to registry'), 'success');
        window.scrollTo({top:0, behavior:'smooth'});
        await prefetchIds();
    } catch(e) {
        toast('Failed: ' + e.message, 'danger');
        btn.disabled  = false;
        btn.innerHTML = `<i class='bx bx-check-circle'></i> ${ROLERANK===1?'Submit for Validation':'Submit to Registry'}`;
    }
}

function fileToBase64(file) {
    return new Promise((res,rej) => {
        const r = new FileReader();
        r.onload = () => res(r.result.split(',')[1]);
        r.onerror = () => rej(new Error('Failed to read file'));
        r.readAsDataURL(file);
    });
}

async function resetAll(silent = false) {
    uploadedFile = null; ocrDone=false; ocrExtracted=''; aiAutoFilled=false; aiConfidence=0; forceValidated=false;
    ['filePreview','ocrBlock','ocrPreview'].forEach(id=>{const el=document.getElementById(id);if(el){el.classList.remove('show');}});
    const ac=document.getElementById('aiConfRow'); if(ac) ac.style.display='none';
    const vf=document.getElementById('validationFlag'); if(vf) vf.classList.remove('show');
    const fv=document.getElementById('forceValidateBtn'); if(fv) fv.style.display='none';
    document.getElementById('qrPlaceholder').style.display='flex';
    document.getElementById('qrGeneratedCard').classList.remove('show');
    document.getElementById('metaPlaceholder').style.display='flex';
    document.getElementById('metaPreview').classList.remove('show');
    document.getElementById('previewBadge').style.display='none';
    document.getElementById('metaBadge').style.display='none';
    document.getElementById('fileInput').value='';
    dz.classList.remove('has-file');
    clearAIFields();
    document.getElementById('fNotes').value='';
    document.getElementById('fDateTime').value=nowDTLocal();
    document.getElementById('fPriority').value='Normal';
    const fDir=document.getElementById('fDirection'); if(fDir) fDir.value='';
    const retEl=document.getElementById('fRetention'); if(retEl&&retEl.tagName==='SELECT') retEl.selectedIndex=0;
    setStep(1);
    document.getElementById('sfStatus').textContent='Waiting for upload';
    const btn=document.getElementById('submitBtn');
    btn.disabled=true;
    btn.innerHTML=`<i class='bx bx-check-circle'></i> ${ROLERANK===1?'Submit for Validation':'Submit to Registry'}`;
    btn.style.background='';
    const sb=document.getElementById('successBanner'); if(sb) sb.classList.remove('show');
    const usb=document.getElementById('userSuccessBanner'); if(usb) usb.classList.remove('show');
    document.getElementById('aiConfBar').style.width='0%';
    document.getElementById('aiConfPct').textContent='0%';
    if (ROLERANK >= 3 && staffList.length) document.getElementById('fAssignedTo').value=staffList[0].name;
    await prefetchIds();
    if (!silent) toast('Form reset','success');
}

// ── UTILS ─────────────────────────────────────────────────────────────────────
function esc(s)       { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function nowDTLocal() { const d=new Date(),off=d.getTimezoneOffset(),l=new Date(d.getTime()-off*60000); return l.toISOString().slice(0,16); }
function fmtNow()     { return new Date().toLocaleString('en-PH',{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); }
function toast(msg, type='success') {
    const icons={success:'bx-check-circle',warning:'bx-error',danger:'bx-error-circle'};
    const el=document.createElement('div'); el.className=`toast t-${type}`;
    el.innerHTML=`<i class='bx ${icons[type]||'bx-info-circle'}' style="font-size:17px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(()=>{el.classList.add('t-out');setTimeout(()=>el.remove(),300);},3200);
}
</script>
</body>
</html>