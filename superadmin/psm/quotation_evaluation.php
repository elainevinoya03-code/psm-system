<?php
/**
 * PSM — Quotation Evaluation  (Single File: API + Page)
 * File: /Log1/superadmin/psm/quotation_evaluation.php
 *
 * If ?action= is present  → runs as JSON API and exits.
 * Otherwise               → renders the full HTML page.
 *
 * Uses Supabase REST API (same pattern as requests.php) — no direct PDO.
 */

declare(strict_types=1);
session_start();
// Ensure any PHP notices/warnings do NOT break JSON responses
if (!ob_get_level()) {
    ob_start();
}

// ── Resolve project root ───────────────────────────────────────────────────
$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';

// ── Session guard ──────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    if (!empty($_GET['action']) || !empty($_POST['action'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthenticated.']);
    } else {
        header('Location: /login.php');
    }
    exit;
}

$userId   = $_SESSION['user_id']        ?? '';
$userName = $_SESSION['full_name']      ?? 'Unknown';
$roles    = $_SESSION['roles']          ?? [];
$isSA     = $_SESSION['is_super_admin'] ?? false;
$ip       = $_SERVER['REMOTE_ADDR']     ?? 'unknown';
$canScore = $isSA || in_array('Admin', $roles, true);

// ── Role gate: only Super Admin / Admin / Manager may access this module ─────
$isAdminOrSA = $isSA || in_array('Admin', $roles, true);
$isManager   = in_array('Manager', $roles, true);
if (!$isAdminOrSA && !$isManager) {
    $apiAction = trim($_GET['action'] ?? $_POST['action'] ?? '');
    if ($apiAction !== '') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized to access quotation evaluations.']);
    } else {
        header('Location: /user_dashboard.php');
    }
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
//  API LAYER — runs only when ?action= is present, then exits
// ══════════════════════════════════════════════════════════════════════════════
$apiAction = trim($_GET['action'] ?? $_POST['action'] ?? '');

if ($apiAction !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    // ── Supabase REST helper (same pattern as requests.php) ─────────────────
    function qe_sb_rest(string $table, string $method = 'GET', array $query = [], $body = null, array $extra = [], ?int &$totalCount = null): array {
        $url = SUPABASE_URL . '/rest/v1/' . $table;
        if ($query) $url .= '?' . http_build_query($query);
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
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $contentRange = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $h) use (&$contentRange) {
            if (stripos($h, 'Content-Range:') === 0) $contentRange = trim(substr($h, 14));
            return strlen($h);
        });
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($contentRange && preg_match('#\d+-\d+/(\d+)#', $contentRange, $m)) $totalCount = (int)$m[1];
        if ($res === false || $res === '') {
            if ($code >= 400) throw new RuntimeException('Supabase request failed');
            return [];
        }
        $data = json_decode($res, true);
        if ($code >= 400) {
            $msg = is_array($data) ? ($data['message'] ?? $data['hint'] ?? $res) : $res;
            throw new RuntimeException('Supabase: ' . $msg);
        }
        return is_array($data) ? $data : [];
    }

    // ── Response helpers ───────────────────────────────────────────────────
    function ok(array $data = [], string $message = 'OK'): void {
        // Remove any previously buffered output (e.g. notices/warnings)
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['success' => true, 'message' => $message] + $data);
        exit;
    }
    function fail(string $message, int $code = 400): void {
        http_response_code($code);
        // Remove any previously buffered output (e.g. notices/warnings)
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    function input(): array {
        static $body = null;
        if ($body === null) {
            $raw  = file_get_contents('php://input');
            $body = json_decode($raw ?: '{}', true) ?? [];
        }
        return $body;
    }
    function requireSA(): void {
        global $isSA;
        if (!$isSA) fail('Super Admin access required.', 403);
    }
    function requireRole(array $allowed): void {
        global $roles;
        foreach ($allowed as $r) {
            if (in_array($r, $roles, true)) return;
        }
        fail('Insufficient permissions.', 403);
    }

    // ── Calc overall score using weights from Supabase ────────────────────────
    function calcOverall(float $p, float $q, float $d, float $w, string $scope = 'global'): float {
        $rows = qe_sb_rest('psm_scoring_weights', 'GET', ['scope' => 'eq.' . $scope, 'limit' => 1]);
        $wt = $rows[0] ?? ['price_weight'=>0.40,'quality_weight'=>0.30,'delivery_weight'=>0.20,'warranty_weight'=>0.10];
        return round(
            $p*(float)($wt['price_weight']??0.4) + $q*(float)($wt['quality_weight']??0.3) +
            $d*(float)($wt['delivery_weight']??0.2) + $w*(float)($wt['warranty_weight']??0.1), 2
        );
    }

    // ── Audit log writer ───────────────────────────────────────────────────
    function logAudit(int $evalId, int $rfqId, int $supplierId,
                      string $action, string $actorName, string $actorRole,
                      string $actorUserId, array $scores = [], ?string $remarks = null,
                      string $ip = '', bool $isSa = false): void {
        qe_sb_rest('psm_evaluation_audit_log', 'POST', [], [
            'evaluation_id'=>$evalId,'rfq_id'=>$rfqId,'supplier_id'=>$supplierId,
            'action_label'=>$action,'actor_name'=>$actorName,'actor_role'=>$actorRole,
            'actor_user_id'=>$actorUserId?:null,
            'price_score'=>$scores['price_score']??null,'quality_score'=>$scores['quality_score']??null,
            'delivery_score'=>$scores['delivery_score']??null,'warranty_score'=>$scores['warranty_score']??null,
            'overall_score'=>$scores['overall_score']??null,
            'remarks'=>$remarks,'ip_address'=>$ip,'is_super_admin'=>$isSa,
        ]);
    }

    // ── Route ──────────────────────────────────────────────────────────────
    try {
        switch ($apiAction) {

            // ── LIST ────────────────────────────────────────────────────────
            case 'list':
                requireRole(['Super Admin','Admin','Manager','Staff']);
                $search  = trim($_GET['q']        ?? '');
                $branch  = trim($_GET['branch']   ?? '');
                $dept    = trim($_GET['dept']     ?? '');
                $rfqId   = trim($_GET['rfq_id']   ?? '');
                $status  = trim($_GET['status']   ?? '');
                $dateFrom= trim($_GET['date_from']?? '');
                $dateTo  = trim($_GET['date_to']  ?? '');
                $page    = max(1,(int)($_GET['page']    ?? 1));
                $perPage = min(100,max(5,(int)($_GET['per_page']??10)));
                $offset  = ($page-1)*$perPage;

                $query = [
                    'select' => '*',
                    'order'  => 'rfq_id.asc,overall_score.desc,created_at.asc',
                    'limit'  => $perPage,
                    'offset' => $offset,
                ];
                // Scope: Super Admin/Admin see all; Manager limited to own department/zone
                if (!$isSA && !in_array('Admin',$roles,true)) {
                    $zone = $_SESSION['zone'] ?? '';
                    if ($zone) $query['department'] = 'eq.' . $zone;
                }
                if ($branch)  $query['branch']       = 'eq.' . $branch;
                if ($dept)    $query['department']  = 'eq.' . $dept;
                if ($rfqId)   $query['rfq_id']      = 'eq.' . (int)$rfqId;
                if ($status)  $query['eval_status'] = 'eq.' . $status;
                if ($dateFrom && $dateTo) $query['and'] = '(scored_at.gte.' . $dateFrom . ',scored_at.lte.' . $dateTo . 'T23:59:59)';
                elseif ($dateFrom) $query['scored_at'] = 'gte.' . $dateFrom;
                elseif ($dateTo)   $query['scored_at'] = 'lte.' . $dateTo . 'T23:59:59';
                if ($search)  $query['or'] = '(supplier_name.ilike.*' . $search . '*,rfq_no.ilike.*' . $search . '*,department.ilike.*' . $search . '*,branch.ilike.*' . $search . '*)';

                $totalCount = 0;
                $data = qe_sb_rest('psm_evaluation_summary', 'GET', $query, null, ['Prefer: count=exact'], $totalCount);
                $total = $totalCount ?: count($data);

                if (!empty($data)) {
                    $supIds = array_unique(array_filter(array_column($data, 'supplier_id')));
                    $suppliers = [];
                    if ($supIds) {
                        $suppliers = qe_sb_rest('psm_suppliers', 'GET', ['id' => 'in.(' . implode(',', $supIds) . ')', 'select' => 'id,contact_person,email,phone,status,rating']);
                    }
                    $supMap = [];
                    foreach ($suppliers as $s) { $supMap[(int)$s['id']] = $s; }
                    foreach ($data as &$r) {
                        $s = $supMap[(int)$r['supplier_id']] ?? [];
                        $r['contact_person'] = $s['contact_person'] ?? null;
                        $r['supplier_email'] = $s['email'] ?? null;
                        $r['supplier_phone'] = $s['phone'] ?? null;
                        $r['supplier_status'] = $s['status'] ?? null;
                        $r['supplier_rating'] = $s['rating'] ?? null;
                    }
                }

                $statsFilters = ['select' => 'eval_status', 'limit' => 5000];
                if (!$isSA && !in_array('Admin',$roles,true)) { $zone = $_SESSION['zone'] ?? ''; if ($zone) $statsFilters['department'] = 'eq.' . $zone; }
                if ($branch)  $statsFilters['branch']       = 'eq.' . $branch;
                if ($dept)    $statsFilters['department']  = 'eq.' . $dept;
                if ($rfqId)   $statsFilters['rfq_id']      = 'eq.' . (int)$rfqId;
                if ($status)  $statsFilters['eval_status'] = 'eq.' . $status;
                if ($dateFrom && $dateTo) $statsFilters['and'] = '(scored_at.gte.' . $dateFrom . ',scored_at.lte.' . $dateTo . 'T23:59:59)';
                elseif ($dateFrom) $statsFilters['scored_at'] = 'gte.' . $dateFrom;
                elseif ($dateTo)   $statsFilters['scored_at'] = 'lte.' . $dateTo . 'T23:59:59';
                if ($search)  $statsFilters['or'] = '(supplier_name.ilike.*' . $search . '*,rfq_no.ilike.*' . $search . '*,department.ilike.*' . $search . '*,branch.ilike.*' . $search . '*)';
                $allRows = qe_sb_rest('psm_evaluation_summary', 'GET', $statsFilters);
                $stats = ['total'=>count($allRows),'pending'=>0,'scored'=>0,'winners'=>0,'endorsed'=>0];
                foreach ($allRows as $r) {
                    $st = $r['eval_status'] ?? '';
                    if ($st==='Pending') $stats['pending']++;
                    elseif ($st==='Scored') $stats['scored']++;
                    elseif ($st==='Winner') $stats['winners']++;
                    elseif ($st==='Endorsed') $stats['endorsed']++;
                }

                $branches = array_unique(array_column(qe_sb_rest('psm_evaluation_summary','GET',['select'=>'branch','limit'=>500]),'branch'));
                $depts    = array_unique(array_column(qe_sb_rest('psm_evaluation_summary','GET',['select'=>'department','limit'=>500]),'department'));
                $rfqsRaw  = qe_sb_rest('psm_evaluation_summary','GET',['select'=>'rfq_id,rfq_no','limit'=>500]);
                $rfqs     = [];
                foreach ($rfqsRaw as $r) {
                    $id = $r['rfq_id'] ?? 0;
                    if (!isset($rfqs[$id])) $rfqs[$id] = $r;
                }
                $rfqs = array_values($rfqs);

                ok([
                    'data'     => $data,
                    'total'    => $total,
                    'page'     => $page,
                    'per_page' => $perPage,
                    'pages'    => max(1,(int)ceil($total/$perPage)),
                    'stats'    => $stats,
                    'filters'  => ['branches'=>array_values(array_filter($branches)),'depts'=>array_values(array_filter($depts)),'rfqs'=>array_values($rfqs)],
                ]);

            // ── CREATE (seed from RFQ responses) ───────────────────────────
            case 'create':
                requireRole(['Super Admin','Admin']);
                $body  = input();
                $rfqId = (int)($body['rfq_id'] ?? 0);
                if (!$rfqId) fail('rfq_id is required.');

                $rfq = qe_sb_rest('psm_rfqs', 'GET', ['id' => 'eq.' . $rfqId, 'limit' => 1]);
                if (empty($rfq)) fail('RFQ not found.', 404);

                $responses = qe_sb_rest('psm_rfq_responses', 'GET', ['rfq_id' => 'eq.' . $rfqId]);
                if (empty($responses)) fail('No supplier responses found for this RFQ.');

                $existing = qe_sb_rest('psm_quotation_evaluations', 'GET', ['rfq_id' => 'eq.' . $rfqId]);
                $existingKeys = array_flip(array_map(fn($e)=>$e['rfq_id'].'_'.$e['supplier_id'], $existing));

                $inserted = 0;
                foreach ($responses as $r) {
                    $key = $rfqId . '_' . $r['supplier_id'];
                    if (isset($existingKeys[$key])) continue;
                    qe_sb_rest('psm_quotation_evaluations', 'POST', [], [
                        'rfq_id'=>$rfqId,'supplier_id'=>$r['supplier_id'],'response_id'=>$r['id']??null,
                        'unit_price'=>$r['amount']??0,'total_price'=>$r['amount']??0,
                        'delivery_terms'=>($r['lead_days']??0) ? ($r['lead_days']." days after PO") : '',
                        'warranty'=>$r['notes']??'',
                    ]);
                    $inserted++;
                }
                ok(['inserted'=>$inserted],"{$inserted} evaluation record(s) created.");

            // ── SCORE ──────────────────────────────────────────────────────
            case 'score':
                // Only Super Admin / Admin can score suppliers (Managers/User are read-only)
                requireRole(['Super Admin','Admin']);
                $body = input();
                $id   = (int)($body['id'] ?? 0);
                if (!$id) fail('Evaluation id is required.');

                $ps = max(0,min(100,(float)($body['price_score']   ??0)));
                $qs = max(0,min(100,(float)($body['quality_score'] ??0)));
                $ds = max(0,min(100,(float)($body['delivery_score']??0)));
                $ws = max(0,min(100,(float)($body['warranty_score']??0)));
                $remarks = trim($body['remarks']??'');

                $evals = qe_sb_rest('psm_quotation_evaluations', 'GET', ['id' => 'eq.' . $id, 'limit' => 1]);
                $eval = $evals[0] ?? null;
                if (!$eval) fail('Evaluation record not found.',404);
                if (($eval['eval_status']??'')==='Endorsed') fail('Cannot re-score an endorsed evaluation.');

                $branch = $eval['branch'] ?? 'global';
                $metrics = qe_sb_rest('psm_supplier_metrics', 'GET', ['supplier_id' => 'eq.' . $eval['supplier_id'], 'limit' => 1]);
                if (!empty($metrics)) $branch = $metrics[0]['branch'] ?? $branch;

                $overall   = calcOverall($ps,$qs,$ds,$ws,$branch);
                $newStatus = ($overall>0 && ($eval['eval_status']??'')==='Pending') ? 'Scored' : ($eval['eval_status']??'Pending');

                qe_sb_rest('psm_quotation_evaluations', 'PATCH', ['id' => 'eq.' . $id], [
                    'price_score'=>$ps,'quality_score'=>$qs,'delivery_score'=>$ds,'warranty_score'=>$ws,
                    'overall_score'=>$overall,'remarks'=>$remarks?:null,'eval_status'=>$newStatus,
                    'scored_by'=>$userName,'scored_by_user_id'=>$userId?:null,'scored_at'=>date('c'),
                ]);

                logAudit($id,(int)$eval['rfq_id'],(int)$eval['supplier_id'],
                    "Scored (Overall: {$overall})",$userName,implode(', ',$roles),$userId,
                    ['price_score'=>$ps,'quality_score'=>$qs,'delivery_score'=>$ds,'warranty_score'=>$ws,'overall_score'=>$overall],
                    $remarks,$ip,$isSA);

                ok(['overall_score'=>$overall,'eval_status'=>$newStatus],"Score saved. Overall: {$overall}");

            // ── UPDATE PRICING ─────────────────────────────────────────────
            case 'update_pricing':
                // Only Super Admin / Admin can adjust quotation pricing
                requireRole(['Super Admin','Admin']);
                $body = input(); $id = (int)($body['id']??0);
                if (!$id) fail('id is required.');
                $ev = qe_sb_rest('psm_quotation_evaluations', 'GET', ['id' => 'eq.' . $id, 'limit' => 1]);
                $eval2 = $ev[0] ?? null;
                if (!$eval2) fail('Evaluation not found.',404);
                if (($eval2['eval_status']??'')==='Endorsed') fail('Cannot edit an endorsed evaluation.');
                $upd = [];
                foreach (['unit_price','total_price','delivery_terms','warranty'] as $col) {
                    if (isset($body[$col])) $upd[$col] = in_array($col,['unit_price','total_price']) ? (float)$body[$col] : trim($body[$col]);
                }
                if (empty($upd)) fail('No fields to update.');
                qe_sb_rest('psm_quotation_evaluations', 'PATCH', ['id' => 'eq.' . $id], $upd);
                ok([],'Pricing updated.');

            // ── WINNER ─────────────────────────────────────────────────────
            case 'winner':
                // Only Super Admin / Admin can select winning supplier
                requireRole(['Super Admin','Admin']);
                $body = input(); $id = (int)($body['id']??0);
                if (!$id) fail('Evaluation id is required.');
                $ev = qe_sb_rest('psm_quotation_evaluations', 'GET', ['id' => 'eq.' . $id, 'limit' => 1]);
                $eval3 = $ev[0] ?? null;
                if (!$eval3) fail('Evaluation not found.',404);
                if (($eval3['eval_status']??'')==='Endorsed') fail('Cannot change winner after endorsement.');
                $rfqId3 = (int)$eval3['rfq_id'];
                $sup = qe_sb_rest('psm_suppliers', 'GET', ['id' => 'eq.' . $eval3['supplier_id'], 'limit' => 1]);
                $supplierName = $sup[0]['name'] ?? 'Supplier';
                $winners = qe_sb_rest('psm_quotation_evaluations', 'GET', ['rfq_id' => 'eq.' . $rfqId3, 'eval_status' => 'eq.Winner']);
                foreach ($winners as $w) {
                    qe_sb_rest('psm_quotation_evaluations', 'PATCH', ['id' => 'eq.' . $w['id']], ['eval_status' => 'Scored']);
                }
                qe_sb_rest('psm_quotation_evaluations', 'PATCH', ['id' => 'eq.' . $id], ['eval_status' => 'Winner']);
                logAudit($id,$rfqId3,(int)$eval3['supplier_id'],"Winner Selected",$userName,implode(', ',$roles),$userId,['overall_score'=>$eval3['overall_score']??null],$body['remarks']??null,$ip,$isSA);
                ok([],"{$supplierName} selected as winner.");

            // ── ENDORSE ────────────────────────────────────────────────────
            case 'endorse':
                // Only Super Admin / Admin can endorse to Legal
                requireRole(['Super Admin','Admin']);
                $body    = input();
                $id      = (int)($body['id']??0);
                $notes   = trim($body['notes']??'');
                $contact = trim($body['legal_contact']??'');
                if (!$id)    fail('Evaluation id is required.');
                if (!$notes) fail('Endorsement notes are required.');
                $ev = qe_sb_rest('psm_quotation_evaluations', 'GET', ['id' => 'eq.' . $id, 'limit' => 1]);
                $eval4 = $ev[0] ?? null;
                if (!$eval4) fail('Evaluation not found.',404);
                if (($eval4['eval_status']??'')!=='Winner') fail('Only a "Winner" evaluation can be endorsed.');
                qe_sb_rest('psm_quotation_evaluations', 'PATCH', ['id' => 'eq.' . $id], ['eval_status' => 'Endorsed']);
                qe_sb_rest('psm_endorsement_log', 'POST', [], [
                    'rfq_id'=>$eval4['rfq_id'],'evaluation_id'=>$id,'supplier_id'=>$eval4['supplier_id'],
                    'notes'=>$notes,'legal_contact'=>$contact?:null,'endorsed_by'=>$userName,'endorsed_by_user_id'=>$userId?:null,
                ]);
                logAudit($id,(int)$eval4['rfq_id'],(int)$eval4['supplier_id'],"Endorsed to Legal",$userName,implode(', ',$roles),$userId,['overall_score'=>$eval4['overall_score']??null],$notes,$ip,$isSA);
                ok([],'Evaluation endorsed to Legal.');

            // ── SA OVERRIDE ────────────────────────────────────────────────
            case 'override':
                requireSA();
                $body   = input();
                $type   = trim($body['type']  ??'');
                $reason = trim($body['reason']??'');
                if (!$type)   fail('Override type is required.');
                if (!$reason) fail('Override reason is required.');
                try {
                    switch ($type) {
                        case 'winner':
                            $targetId = (int)($body['evaluation_id']??0);
                            if (!$targetId) fail('evaluation_id is required for winner override.');
                            $evList = qe_sb_rest('psm_quotation_evaluations', 'GET', ['id' => 'eq.' . $targetId, 'limit' => 1]);
                            $ev = $evList[0] ?? null;
                            if (!$ev) fail('Evaluation not found.',404);
                            $sup = qe_sb_rest('psm_suppliers', 'GET', ['id' => 'eq.' . $ev['supplier_id'], 'limit' => 1]);
                            $supName = $sup[0]['name'] ?? 'Supplier';
                            $winners = qe_sb_rest('psm_quotation_evaluations', 'GET', ['rfq_id' => 'eq.' . $ev['rfq_id'], 'eval_status' => 'eq.Winner']);
                            foreach ($winners as $w) qe_sb_rest('psm_quotation_evaluations', 'PATCH', ['id' => 'eq.' . $w['id']], ['eval_status' => 'Scored']);
                            qe_sb_rest('psm_quotation_evaluations', 'PATCH', ['id' => 'eq.' . $targetId], ['eval_status'=>'Winner','is_overridden'=>true,'override_reason'=>$reason,'overridden_by'=>$userName,'overridden_at'=>date('c')]);
                            logAudit($targetId,(int)$ev['rfq_id'],(int)$ev['supplier_id'],"SA Override — Winner changed to {$supName}",$userName,'Super Admin',$userId,['overall_score'=>$ev['overall_score']??null],$reason,$ip,true);
                            break;
                        case 'score':
                            $targetId = (int)($body['evaluation_id']??0);
                            if (!$targetId) fail('evaluation_id is required for score override.');
                            $evList = qe_sb_rest('psm_quotation_evaluations', 'GET', ['id' => 'eq.' . $targetId, 'limit' => 1]);
                            $ev2 = $evList[0] ?? null;
                            if (!$ev2) fail('Evaluation not found.',404);
                            $ps2=max(0,min(100,(float)($body['price_score']   ??$ev2['price_score']??0)));
                            $qs2=max(0,min(100,(float)($body['quality_score'] ??$ev2['quality_score']??0)));
                            $ds2=max(0,min(100,(float)($body['delivery_score']??$ev2['delivery_score']??0)));
                            $ws2=max(0,min(100,(float)($body['warranty_score']??$ev2['warranty_score']??0)));
                            $ov2=calcOverall($ps2,$qs2,$ds2,$ws2);
                            qe_sb_rest('psm_quotation_evaluations', 'PATCH', ['id' => 'eq.' . $targetId], [
                                'price_score'=>$ps2,'quality_score'=>$qs2,'delivery_score'=>$ds2,'warranty_score'=>$ws2,'overall_score'=>$ov2,
                                'is_overridden'=>true,'override_reason'=>$reason,'overridden_by'=>$userName,'overridden_at'=>date('c'),
                                'scored_by'=>$userName,'scored_by_user_id'=>$userId,'scored_at'=>date('c'),
                            ]);
                            logAudit($targetId,(int)$ev2['rfq_id'],(int)$ev2['supplier_id'],"SA Override — Score adjusted (Overall: {$ov2})",$userName,'Super Admin',$userId,['price_score'=>$ps2,'quality_score'=>$qs2,'delivery_score'=>$ds2,'warranty_score'=>$ws2,'overall_score'=>$ov2],$reason,$ip,true);
                            break;
                        case 'endorse':
                            $targetId = (int)($body['evaluation_id']??0);
                            if (!$targetId) fail('evaluation_id is required.');
                            $evList = qe_sb_rest('psm_quotation_evaluations', 'GET', ['id' => 'eq.' . $targetId, 'limit' => 1]);
                            $ev3 = $evList[0] ?? null;
                            if (!$ev3) fail('Evaluation not found.',404);
                            qe_sb_rest('psm_quotation_evaluations', 'PATCH', ['id' => 'eq.' . $targetId], ['eval_status'=>'Endorsed','is_overridden'=>true,'override_reason'=>$reason,'overridden_by'=>$userName,'overridden_at'=>date('c')]);
                            logAudit($targetId,(int)$ev3['rfq_id'],(int)$ev3['supplier_id'],"SA Override — Forced Endorsement",$userName,'Super Admin',$userId,[],$reason,$ip,true);
                            break;
                        case 'reset':
                            $rfqId5=(int)($body['rfq_id']??0);
                            if (!$rfqId5) fail('rfq_id is required for reset.');
                            $toReset = qe_sb_rest('psm_quotation_evaluations', 'GET', ['rfq_id' => 'eq.' . $rfqId5, 'eval_status' => 'neq.Endorsed']);
                            $upd = ['price_score'=>0,'quality_score'=>0,'delivery_score'=>0,'warranty_score'=>0,'overall_score'=>0,'remarks'=>null,'eval_status'=>'Pending','scored_by'=>null,'scored_at'=>null,'is_overridden'=>true,'override_reason'=>$reason,'overridden_by'=>$userName,'overridden_at'=>date('c')];
                            foreach ($toReset as $row) {
                                qe_sb_rest('psm_quotation_evaluations', 'PATCH', ['id' => 'eq.' . $row['id']], $upd);
                                logAudit((int)$row['id'],$rfqId5,(int)$row['supplier_id'],"SA Override — Scores Reset",$userName,'Super Admin',$userId,[],$reason,$ip,true);
                            }
                            break;
                        default: fail("Unknown override type: {$type}");
                    }
                } catch (Throwable $e) { throw $e; }
                ok([],"Override applied: {$type}");

            // ── SCORING HISTORY ────────────────────────────────────────────
            case 'history':
                requireRole(['Super Admin','Admin','Manager']);
                $rfqF    = trim($_GET['rfq_id']??'');
                $page6   = max(1,(int)($_GET['page']??1));
                $perPage6= min(200,max(10,(int)($_GET['per_page']??50)));
                $offset6 = ($page6-1)*$perPage6;
                $hQuery = ['select'=>'*','order'=>'occurred_at.desc','limit'=>$perPage6,'offset'=>$offset6];
                if ($rfqF) $hQuery['rfq_id'] = 'eq.' . (int)$rfqF;
                if (!$isSA && !in_array('Admin',$roles,true)) {
                    $zone = $_SESSION['zone'] ?? '';
                    if ($zone) {
                        $zoneRfqs = qe_sb_rest('psm_rfqs', 'GET', ['department' => 'eq.' . $zone, 'select' => 'id']);
                        $allowedRfq = array_column($zoneRfqs, 'id');
                        if (empty($allowedRfq)) { $histRows = []; $total6 = 0; }
                        else {
                            if ($rfqF && !in_array((int)$rfqF, $allowedRfq)) { $histRows = []; $total6 = 0; }
                            else {
                                if (!$rfqF) $hQuery['rfq_id'] = 'in.(' . implode(',', $allowedRfq) . ')';
                                $total6 = 0;
                                $histRows = qe_sb_rest('psm_evaluation_audit_log', 'GET', $hQuery, null, ['Prefer: count=exact'], $total6);
                            }
                        }
                    } else {
                        $total6 = 0;
                        $histRows = qe_sb_rest('psm_evaluation_audit_log', 'GET', $hQuery, null, ['Prefer: count=exact'], $total6);
                    }
                } else {
                    $total6 = 0;
                    $histRows = qe_sb_rest('psm_evaluation_audit_log', 'GET', $hQuery, null, ['Prefer: count=exact'], $total6);
                }
                if (!empty($histRows)) {
                    $rfqIds = array_unique(array_column($histRows, 'rfq_id'));
                    $supIds = array_unique(array_column($histRows, 'supplier_id'));
                    $rfqs = qe_sb_rest('psm_rfqs', 'GET', ['id' => 'in.(' . implode(',', $rfqIds) . ')', 'select' => 'id,rfq_no,department']);
                    $sups = qe_sb_rest('psm_suppliers', 'GET', ['id' => 'in.(' . implode(',', $supIds) . ')', 'select' => 'id,name']);
                    $metrics = qe_sb_rest('psm_supplier_metrics', 'GET', ['supplier_id' => 'in.(' . implode(',', $supIds) . ')', 'select' => 'supplier_id,branch']);
                    $rfqMap = array_column($rfqs, null, 'id');
                    $supMap = array_column($sups, null, 'id');
                    $metMap = array_column($metrics, null, 'supplier_id');
                    foreach ($histRows as &$h) {
                        $r = $rfqMap[$h['rfq_id']] ?? []; $h['rfq_no'] = $r['rfq_no'] ?? ''; $h['department'] = $r['department'] ?? '';
                        $s = $supMap[$h['supplier_id']] ?? []; $h['supplier_name'] = $s['name'] ?? '';
                        $m = $metMap[$h['supplier_id']] ?? []; $h['branch'] = $m['branch'] ?? null;
                    }
                }
                ok(['data'=>$histRows,'total'=>$total6,'page'=>$page6,'per_page'=>$perPage6,'pages'=>max(1,(int)ceil($total6/$perPage6))]);

            // ── GET WEIGHTS ────────────────────────────────────────────────
            case 'weights':
                requireSA();
                $scope = trim($_GET['scope']??'global');
                $rows  = qe_sb_rest('psm_scoring_weights', 'GET', ['scope' => 'eq.' . $scope, 'limit' => 1]);
                $row   = $rows[0] ?? null;
                if (!$row) fail("Weights config not found for scope: {$scope}",404);
                ok(['weights'=>$row]);

            // ── UPDATE WEIGHTS ─────────────────────────────────────────────
            case 'update_weights':
                requireSA();
                $body  = input();
                $scope = trim($body['scope']??'global');
                $pw    = (float)($body['price_weight']   ??0.40);
                $qw    = (float)($body['quality_weight'] ??0.30);
                $dw    = (float)($body['delivery_weight']??0.20);
                $ww    = (float)($body['warranty_weight']??0.10);
                $sum   = round($pw+$qw+$dw+$ww,4);
                if ($sum<0.99||$sum>1.01) fail("Weights must sum to 1.0 (got {$sum}).");
                $existing = qe_sb_rest('psm_scoring_weights', 'GET', ['scope' => 'eq.' . $scope, 'limit' => 1]);
                $payload = ['scope'=>$scope,'price_weight'=>$pw,'quality_weight'=>$qw,'delivery_weight'=>$dw,'warranty_weight'=>$ww,'updated_by'=>$userName,'updated_at'=>date('c')];
                if (!empty($existing)) {
                    qe_sb_rest('psm_scoring_weights', 'PATCH', ['scope' => 'eq.' . $scope], $payload);
                } else {
                    qe_sb_rest('psm_scoring_weights', 'POST', [], $payload);
                }
                ok([],'Scoring weights updated.');

            // ── EXPORT CSV ─────────────────────────────────────────────────
            case 'export':
                requireRole(['Super Admin','Admin','Manager']);
                $expQuery = ['select'=>'rfq_no,pr_ref,department,date_issued,supplier_name,supplier_category,branch,unit_price,total_price,delivery_terms,warranty,price_score,quality_score,delivery_score,warranty_score,overall_score,rank_in_rfq,remarks,eval_status,scored_by,scored_at,is_overridden,override_reason,overridden_by','order'=>'rfq_no.asc,overall_score.desc','limit'=>5000];
                if (!$isSA && !in_array('Admin',$roles,true)) { $zone=$_SESSION['zone']??''; if ($zone) $expQuery['department']='eq.'.$zone; }
                if ($rfqE=trim($_GET['rfq_id']??'')) $expQuery['rfq_id']='eq.'.(int)$rfqE;
                if ($brE=trim($_GET['branch']??'')) $expQuery['branch']='eq.'.$brE;
                $rows7 = qe_sb_rest('psm_evaluation_summary', 'GET', $expQuery);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="quotation_evaluation_'.date('Ymd_His').'.csv"');
                $out=fopen('php://output','w');
                if (!empty($rows7)) { fputcsv($out,array_keys($rows7[0])); foreach ($rows7 as $r7) fputcsv($out,$r7); }
                fclose($out);
                exit;

            // ── RFQ LIST ───────────────────────────────────────────────────
            case 'rfq_list':
                requireRole(['Super Admin','Admin','Manager']);
                $rfqQuery = ['select'=>'id,rfq_no,pr_ref,department,date_issued','order'=>'date_issued.desc','limit'=>200];
                $rfqQuery['or'] = '(status.eq.Responded,status.eq.Closed)';
                if (!$isSA && !in_array('Admin',$roles,true)) { $zone=$_SESSION['zone']??''; if ($zone) $rfqQuery['department']='eq.'.$zone; }
                $rfqs = qe_sb_rest('psm_rfqs', 'GET', $rfqQuery);
                $responses = qe_sb_rest('psm_rfq_responses', 'GET', ['select'=>'rfq_id,supplier_id','limit'=>5000]);
                $evals = qe_sb_rest('psm_quotation_evaluations', 'GET', ['select'=>'rfq_id,id','limit'=>5000]);
                $respCount = []; $evalCount = [];
                foreach ($responses as $r) {
                    $rid = $r['rfq_id'];
                    if (!isset($respCount[$rid])) $respCount[$rid] = [];
                    $respCount[$rid][$r['supplier_id']] = 1;
                }
                foreach ($respCount as $rid => $s) $respCount[$rid] = count($s);
                foreach ($evals as $e) { $eid = $e['rfq_id']; $evalCount[$eid] = ($evalCount[$eid] ?? 0) + 1; }
                foreach ($rfqs as &$r) {
                    $r['response_count'] = $respCount[$r['id']] ?? 0;
                    $r['eval_count'] = $evalCount[$r['id']] ?? 0;
                }
                ok(['data'=>$rfqs]);

            // ── GET SINGLE ─────────────────────────────────────────────────
            case 'get':
                requireRole(['Super Admin','Admin','Manager']);
                $id8=(int)($_GET['id']??0);
                if (!$id8) fail('id is required.');
                $rows = qe_sb_rest('psm_evaluation_summary', 'GET', ['id' => 'eq.' . $id8, 'limit' => 1]);
                $row8 = $rows[0] ?? null;
                if (!$row8) fail('Evaluation not found.',404);
                ok(['data'=>$row8]);

            default:
                fail("Unknown action: {$apiAction}",404);
        }

    } catch (Throwable $e) {
        error_log('[QE Error] '.$e->getMessage());
        fail($e->getMessage(),500);
    }

    exit; // safety net — all cases above already exit
}

// ══════════════════════════════════════════════════════════════════════════════
//  PAGE LAYER — renders when no ?action= is present
// ══════════════════════════════════════════════════════════════════════════════
include $root . '/includes/superadmin_sidebar.php';
include $root . '/includes/header.php';

// The JS API URL now points back to THIS same file
$apiUrl = $_SERVER['PHP_SELF'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation Evaluation — PSM</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= (defined('LOG1_WEB_BASE') ? LOG1_WEB_BASE : '') ?>/css/base.css">
    <link rel="stylesheet" href="<?= (defined('LOG1_WEB_BASE') ? LOG1_WEB_BASE : '') ?>/css/sidebar.css">
    <link rel="stylesheet" href="<?= (defined('LOG1_WEB_BASE') ? LOG1_WEB_BASE : '') ?>/css/header.css">
    <style>
        #mainContent,#scoreModal,#histModal,#legalModal,#overrideModal,#cfmModal,#weightsModal,.qe-page,#qeTw {
            --primary:#2E7D32;--primary-dark:#1B5E20;--primary-light:#E8F5E9;
            --primary-mid:rgba(46,125,50,.18);--danger:#DC2626;--warning:#D97706;
            --info:#2563EB;--success:#059669;--gold:#B45309;
            --bg:var(--bg-color,#F4F7F4);--surface:#FFFFFF;
            --border:rgba(46,125,50,.12);--border-mid:rgba(46,125,50,.22);
            --text-1:var(--text-primary,#0A1F0D);--text-2:var(--text-secondary,#5D6F62);
            --text-3:#9EB0A2;--shadow-sm:var(--shadow-light,0 1px 4px rgba(0,0,0,.06));
            --shadow-md:0 4px 16px rgba(46,125,50,.12);--shadow-xl:0 20px 60px rgba(0,0,0,.22);
            --radius:12px;--tr:var(--transition,all .18s ease);
            --font:'Sora',sans-serif;--mono:'DM Mono',monospace;
        }
        #mainContent *,#scoreModal *,#histModal *,#legalModal *,#overrideModal *,#cfmModal *,#weightsModal *{box-sizing:border-box}
        .sa-badge,.role-badge,.user-role-badge,.header-role,.badge-superadmin,[class*="role-badge"],.header-user-role{display:none!important}
        .qe-page{max-width:1600px;margin:0 auto;padding:0 0 3rem}
        .ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:28px;animation:qeFadeUp .4s both}
        .ph .eyebrow{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--primary);margin-bottom:4px}
        .ph h1{font-size:26px;font-weight:800;color:var(--text-1);line-height:1.15}
        .ph-acts{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
        .qe-btn{display:inline-flex;align-items:center;gap:7px;font-family:var(--font);font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap}
        .qe-btn-p{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3)}.qe-btn-p:hover{background:var(--primary-dark);transform:translateY(-1px)}
        .qe-btn-g{background:var(--surface);color:var(--text-2);border:1px solid var(--border-mid)}.qe-btn-g:hover{background:#F0FBF1;color:var(--text-1)}
        .qe-btn-s{font-size:12px;padding:7px 14px}
        .qe-btn-warn{background:var(--warning);color:#fff}.qe-btn-warn:hover{background:#B45309;transform:translateY(-1px)}
        .qe-btn-danger{background:var(--danger);color:#fff}.qe-btn-danger:hover{background:#B91C1C;transform:translateY(-1px)}
        .qe-btn-info{background:var(--info);color:#fff}.qe-btn-info:hover{background:#1D4ED8;transform:translateY(-1px)}
        .qe-btn-gold{background:#92400E;color:#fff}.qe-btn-gold:hover{background:#78350F;transform:translateY(-1px)}
        .qe-btn-sa{background:#1B5E20;color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.35)}.qe-btn-sa:hover{background:#14531c;transform:translateY(-1px)}
        .qe-btn:disabled{opacity:.4;pointer-events:none}
        .qe-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:14px;margin-bottom:24px}
        .qe-stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;box-shadow:var(--shadow-sm);display:flex;align-items:center;gap:12px;animation:qeFadeUp .4s both}
        .qe-stat:nth-child(1){animation-delay:.05s}.qe-stat:nth-child(2){animation-delay:.1s}.qe-stat:nth-child(3){animation-delay:.15s}.qe-stat:nth-child(4){animation-delay:.2s}.qe-stat:nth-child(5){animation-delay:.25s}
        .qe-sc-ic{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
        .ic-g{background:#E8F5E9;color:var(--primary)}.ic-o{background:#FEF3C7;color:var(--warning)}.ic-b{background:#EFF6FF;color:var(--info)}.ic-gold{background:#FEF3C7;color:#92400E}.ic-t{background:#CCFBF1;color:#0D9488}
        .qe-sv{font-size:22px;font-weight:800;line-height:1}.qe-sl{font-size:11px;color:var(--text-2);margin-top:2px}
        .qe-toolbar,.qe-toolbar-r2{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;animation:qeFadeUp .4s .1s both}
        .qe-toolbar-r2{margin-bottom:18px;animation-delay:.12s}
        .qe-sw{position:relative;flex:1;min-width:200px}
        .qe-sw i{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:18px;color:var(--text-3);pointer-events:none}
        .qe-sin{width:100%;padding:9px 12px 9px 38px;font-family:var(--font);font-size:13px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr)}
        .qe-sin:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
        .qe-sin::placeholder{color:var(--text-3)}
        .qe-fsel{font-family:var(--font);font-size:13px;padding:9px 30px 9px 12px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);cursor:pointer;outline:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;transition:var(--tr)}
        .qe-fsel:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
        .qe-date-range{display:flex;align-items:center;gap:6px}
        .qe-date-range label{font-size:12px;color:var(--text-2);font-weight:500;white-space:nowrap}
        .qe-date-in{font-family:var(--font);font-size:13px;padding:9px 12px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr)}
        .qe-date-in:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
        .qe-cmp-wrap{overflow-x:auto;border-radius:16px;box-shadow:var(--shadow-md);background:var(--surface);border:1px solid var(--border);animation:qeFadeUp .4s .2s both}
        .qe-cmp-hd{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:18px 22px 14px;border-bottom:1px solid var(--border);background:var(--bg);border-radius:16px 16px 0 0}
        .qe-cmp-hd-left{display:flex;align-items:center;gap:10px}
        .qe-cmp-hd-left .qe-icon{width:38px;height:38px;border-radius:10px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:18px}
        .qe-cmp-hd-title{font-size:16px;font-weight:700;color:var(--text-1)}
        .qe-cmp-hd-sub{font-size:12px;color:var(--text-2);margin-top:2px}
        .qe-sa-banner{display:flex;align-items:flex-start;gap:10px;padding:10px 16px;margin:14px 22px 0;background:linear-gradient(135deg,rgba(27,94,32,.05),rgba(46,125,50,.08));border:1px solid rgba(46,125,50,.2);border-radius:10px;font-size:12px;color:var(--primary)}
        .qe-sa-banner i{font-size:16px;flex-shrink:0;margin-top:1px}
        .qe-cmp-table{width:100%;border-collapse:collapse;font-size:13px;min-width:900px}
        .qe-cmp-table thead tr th{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-2);padding:12px 14px;text-align:left;background:#FAFCFA;border-bottom:1px solid var(--border);white-space:nowrap}
        .qe-cmp-table thead tr th:first-child{padding-left:22px;min-width:200px}
        .qe-cmp-table tbody tr{border-bottom:1px solid var(--border);transition:background .12s}
        .qe-cmp-table tbody tr:last-child{border-bottom:none}
        .qe-cmp-table tbody tr:hover{background:#F7FBF7}
        .qe-cmp-table tbody tr.qe-winner-row{background:linear-gradient(90deg,rgba(46,125,50,.06),rgba(46,125,50,.02))}
        .qe-cmp-table tbody tr.qe-winner-row:hover{background:linear-gradient(90deg,rgba(46,125,50,.1),rgba(46,125,50,.04))}
        .qe-cmp-table tbody td{padding:14px;vertical-align:middle}
        .qe-cmp-table tbody td:first-child{padding-left:22px}
        .qe-cmp-table tbody td:last-child{padding-right:22px}
        .qe-sup-cell{display:flex;align-items:center;gap:10px}
        .qe-sup-av{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;color:#fff;flex-shrink:0}
        .qe-sup-name{font-size:13px;font-weight:600;color:var(--text-1)}
        .qe-sup-cat{font-size:11px;color:var(--text-3);margin-top:2px}
        .qe-winner-badge{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border:1px solid #FDE68A;padding:2px 8px;border-radius:20px;margin-top:4px}
        .qe-best-price{color:var(--primary);font-weight:700}
        .qe-price-val{font-family:var(--mono);font-weight:600;font-size:13px}
        .qe-price-sub{font-size:11px;color:var(--text-3);margin-top:2px}
        .qe-del-terms{font-size:12px;color:var(--text-2)}
        .qe-warranty-val{font-size:12px;color:var(--text-2);display:flex;align-items:center;gap:4px}
        .qe-score-wrap{display:flex;align-items:center;gap:10px}
        .qe-score-bar-bg{flex:1;height:6px;background:#E5E7EB;border-radius:6px;overflow:hidden;min-width:60px}
        .qe-score-bar-fill{height:100%;border-radius:6px;transition:width .5s ease}
        .qe-score-edit-btn{width:24px;height:24px;border-radius:6px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:13px;color:var(--text-3);transition:var(--tr);flex-shrink:0}
        .qe-score-edit-btn:hover{border-color:var(--primary);color:var(--primary);background:var(--primary-light)}
        .qe-remarks-val{font-size:12px;color:var(--text-2);max-width:160px;line-height:1.4}
        .qe-rank-badge{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;font-size:11px;font-weight:800}
        .qe-rank-1{background:#FEF3C7;color:#92400E;border:2px solid #FDE68A}
        .qe-rank-2{background:#F3F4F6;color:#374151;border:2px solid #D1D5DB}
        .qe-rank-3{background:#FEF2F2;color:#991B1B;border:2px solid #FECACA}
        .qe-rank-n{background:#F9FAFB;color:#9CA3AF;border:2px solid #E5E7EB}
        .qe-pag{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 22px;border-top:1px solid var(--border);background:var(--bg);border-radius:0 0 16px 16px;font-size:13px;color:var(--text-2)}
        .qe-chip{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px}
        .qe-chip::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}
        .qe-ch-pending{background:#F3F4F6;color:#6B7280}
        .qe-ch-scored{background:#E8F5E9;color:var(--primary)}
        .qe-ch-winner{background:#FEF3C7;color:#92400E}
        .qe-ch-endorsed{background:#EFF6FF;color:var(--info)}
        #scoreModal,#histModal,#legalModal,#overrideModal,#cfmModal,#weightsModal{
            position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1100;
            display:flex;align-items:center;justify-content:center;padding:20px;
            opacity:0;pointer-events:none;transition:opacity .25s}
        #scoreModal.show,#histModal.show,#legalModal.show,#overrideModal.show,#cfmModal.show,#weightsModal.show{opacity:1;pointer-events:all}
        .qe-mbox{background:var(--surface);border-radius:20px;width:560px;max-width:100%;max-height:88vh;display:flex;flex-direction:column;box-shadow:var(--shadow-xl);transform:scale(.96);transition:transform .25s;overflow:hidden}
        .qe-mbox-lg{width:820px}
        #scoreModal.show .qe-mbox,#histModal.show .qe-mbox,#legalModal.show .qe-mbox,
        #overrideModal.show .qe-mbox,#cfmModal.show .qe-mbox,#weightsModal.show .qe-mbox{transform:scale(1)}
        .qe-m-hd{padding:22px 26px 16px;border-bottom:1px solid var(--border);background:var(--bg);display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-shrink:0}
        .qe-m-hd-ti{display:flex;align-items:center;gap:12px}
        .qe-m-hd-ic{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
        .qe-m-hd-nm{font-size:17px;font-weight:700;color:var(--text-1)}
        .qe-m-hd-sub{font-size:12px;color:var(--text-2);margin-top:3px}
        .qe-m-cl{width:34px;height:34px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--text-2);transition:var(--tr);flex-shrink:0}
        .qe-m-cl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA}
        .qe-m-body{flex:1;overflow-y:auto;padding:22px 26px}
        .qe-m-body::-webkit-scrollbar{width:4px}.qe-m-body::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px}
        .qe-m-ft{padding:14px 26px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap}
        .qe-score-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
        .qe-fg{display:flex;flex-direction:column;gap:5px}
        .qe-fl{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-2)}
        .qe-fl span{color:var(--danger)}
        .qe-fi,.qe-fta{font-family:var(--font);font-size:13px;padding:9px 12px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);width:100%}
        .qe-fi:focus,.qe-fta:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
        .qe-fta{resize:vertical;min-height:70px}
        .qe-full{grid-column:1/-1}
        .qe-slider-row{display:flex;align-items:center;gap:12px}
        .qe-slider{flex:1;appearance:none;height:6px;border-radius:6px;background:#E5E7EB;outline:none;cursor:pointer}
        .qe-slider::-webkit-slider-thumb{appearance:none;width:18px;height:18px;border-radius:50%;background:var(--primary);border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.2);cursor:pointer}
        .qe-score-disp{font-family:var(--mono);font-size:15px;font-weight:700;color:var(--primary);min-width:36px;text-align:right}
        .qe-sdv{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);display:flex;align-items:center;gap:10px;margin:14px 0 10px}
        .qe-sdv::after{content:'';flex:1;height:1px;background:var(--border)}
        .qe-hist-tbl{width:100%;border-collapse:collapse;font-size:12px}
        .qe-hist-tbl th{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-2);padding:8px 10px;text-align:left;background:var(--bg);border-bottom:1px solid var(--border)}
        .qe-hist-tbl td{padding:10px;border-bottom:1px solid var(--border);color:var(--text-1)}
        .qe-hist-tbl tr:last-child td{border-bottom:none}
        .qe-hist-tbl tr:hover td{background:#F7FBF7}
        .qe-empty{padding:56px 20px;text-align:center;color:var(--text-3)}
        .qe-empty i{font-size:44px;display:block;margin-bottom:10px;color:#C8E6C9}
        .qe-empty p{font-size:13px}
        #qeTw{position:fixed;bottom:28px;right:28px;display:flex;flex-direction:column;gap:10px;z-index:9999;pointer-events:none;max-width:min(420px,calc(100vw - 40px))}
        .qe-toast{background:#0A1F0D;color:#fff;padding:14px 18px;border-radius:12px;font-size:13px;font-weight:500;line-height:1.5;display:flex;align-items:flex-start;gap:12px;box-shadow:0 10px 40px rgba(0,0,0,.25),0 2px 8px rgba(0,0,0,.15);pointer-events:all;width:fit-content;max-width:min(400px,calc(100vw - 56px));max-height:180px;overflow-y:auto;word-break:break-word;animation:qeTIn .3s ease}
        .qe-toast i{flex-shrink:0;margin-top:1px}
        .qe-toast.success{background:var(--primary,#2E7D32)}.qe-toast.warning{background:var(--warning,#D97706)}.qe-toast.danger{background:var(--danger,#DC2626)}.qe-toast.info{background:var(--info,#2563EB)}
        .qe-toast.out{animation:qeTOut .3s ease forwards}
        .qe-loading{display:flex;align-items:center;justify-content:center;padding:56px 20px;gap:12px;color:var(--text-3);font-size:13px}
        .qe-spin{width:20px;height:20px;border:2px solid var(--border-mid);border-top-color:var(--primary);border-radius:50%;animation:spin .7s linear infinite}
        @keyframes qeFadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
        @keyframes qeTIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
        @keyframes qeTOut{from{opacity:1}to{opacity:0;transform:translateY(10px)}}
        @keyframes qeShake{0%,100%{transform:translateX(0)}25%,75%{transform:translateX(-5px)}50%{transform:translateX(5px)}}
        @keyframes spin{to{transform:rotate(360deg)}}
        @media(max-width:768px){.qe-score-grid{grid-template-columns:1fr}.qe-mbox-lg{width:100%}}
    </style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="qe-page">

    <!-- Page Header -->
    <div class="ph">
        <div>
            <p class="eyebrow">PSM · Procurement &amp; Sourcing Management</p>
            <h1>Quotation Evaluation</h1>
        </div>
        <div class="ph-acts">
            <button class="qe-btn qe-btn-g" id="histBtn"><i class='bx bx-history'></i> Scoring History</button>
            <button class="qe-btn qe-btn-g" id="expBtn"><i class='bx bx-export'></i> Export CSV</button>
            <?php if ($isSA): ?>
            <button class="qe-btn qe-btn-g" id="weightsBtn"><i class='bx bx-slider-alt'></i> Scoring Weights</button>
            <button class="qe-btn qe-btn-sa" id="saOverrideBtn"><i class='bx bx-shield-quarter'></i> SA Override</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats -->
    <div class="qe-stats" id="statsRow">
        <?php foreach([
            ['ic-g','bx-file-find','Total Quotes'],
            ['ic-o','bx-time-five','Pending Scoring'],
            ['ic-b','bx-bar-chart-alt-2','Scored'],
            ['ic-gold','bx-trophy','Winners Selected'],
            ['ic-t','bx-send','Endorsed to Legal'],
        ] as $s): ?>
        <div class="qe-stat">
            <div class="qe-sc-ic <?=$s[0]?>"><i class='bx <?=$s[1]?>'></i></div>
            <div><div class="qe-sv stat-val">–</div><div class="qe-sl"><?=$s[2]?></div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Toolbar Row 1 -->
    <div class="qe-toolbar">
        <div class="qe-sw">
            <i class='bx bx-search'></i>
            <input type="text" class="qe-sin" id="srch" placeholder="Search supplier, RFQ, or department…">
        </div>
        <select class="qe-fsel" id="fBranch"><option value="">All Branches</option></select>
        <select class="qe-fsel" id="fDept"><option value="">All Departments</option></select>
        <select class="qe-fsel" id="fRfq"><option value="">All RFQ References</option></select>
        <select class="qe-fsel" id="fStatus">
            <option value="">All Statuses</option>
            <option>Pending</option><option>Scored</option><option>Winner</option><option>Endorsed</option>
        </select>
    </div>

    <!-- Toolbar Row 2 -->
    <div class="qe-toolbar-r2">
        <div class="qe-date-range">
            <label>Date Scored:</label>
            <input type="date" class="qe-date-in" id="fDateFrom">
            <span style="font-size:12px;color:var(--text-3)">to</span>
            <input type="date" class="qe-date-in" id="fDateTo">
        </div>
        <button class="qe-btn qe-btn-g qe-btn-s" id="clearDates"><i class='bx bx-x'></i> Clear</button>
        <?php if ($canScore): ?>
        <button class="qe-btn qe-btn-p qe-btn-s" id="rankBtn"><i class='bx bx-sort-alt-2'></i> Rank Suppliers</button>
        <button class="qe-btn qe-btn-gold qe-btn-s" id="endorseBtn"><i class='bx bx-send'></i> Endorse to Legal</button>
        <button class="qe-btn qe-btn-p qe-btn-s" id="winnerBtn"><i class='bx bx-trophy'></i> Auto-Select Winner</button>
        <?php endif; ?>
    </div>

    <!-- Table Card -->
    <div class="qe-cmp-wrap">
        <div class="qe-cmp-hd">
            <div class="qe-cmp-hd-left">
                <div class="qe-icon"><i class='bx bx-table'></i></div>
                <div>
                    <div class="qe-cmp-hd-title" id="cmpTitle">Quotation Comparison</div>
                    <div class="qe-cmp-hd-sub" id="cmpSub">Side-by-side supplier evaluation</div>
                </div>
            </div>
            <div id="statusChipArea"></div>
        </div>
        <?php if ($isSA): ?>
        <div class="qe-sa-banner">
            <i class='bx bx-shield-quarter'></i>
            <div><strong>Super Admin:</strong> You can override the selected winning supplier, view full scoring history across all branches, compare evaluations system-wide, and adjust scoring weight criteria.</div>
        </div>
        <?php endif; ?>
        <div style="overflow-x:auto">
            <table class="qe-cmp-table">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Unit Price</th>
                        <th>Total Price</th>
                        <th>Delivery Terms</th>
                        <th>Warranty</th>
                        <th>Score</th>
                        <th>Remarks</th>
                        <th>Status</th>
                        <?php if ($canScore): ?><th style="width:110px">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="cmpTb">
                    <tr><td colspan="<?=$canScore?9:8?>"><div class="qe-loading"><div class="qe-spin"></div> Loading evaluations…</div></td></tr>
                </tbody>
            </table>
        </div>
        <div class="qe-pag" id="pag"></div>
    </div>

</div>
</main>

<!-- ── Score Modal ── -->
<div id="scoreModal">
    <div class="qe-mbox">
        <div class="qe-m-hd">
            <div class="qe-m-hd-ti">
                <div class="qe-m-hd-ic" style="background:#E8F5E9;color:var(--primary)"><i class='bx bx-bar-chart-alt-2'></i></div>
                <div><div class="qe-m-hd-nm" id="scoreModalNm">Score Supplier</div><div class="qe-m-hd-sub" id="scoreModalSub">Enter evaluation scores</div></div>
            </div>
            <button class="qe-m-cl" id="scoreClose"><i class='bx bx-x'></i></button>
        </div>
        <div class="qe-m-body">
            <div class="qe-score-grid">
                <?php foreach([['priceScore','Price Score'],['qualScore','Quality / Compliance Score'],['delScore','Delivery Score'],['warnScore','Warranty / Support Score']] as [$sid,$slabel]): ?>
                <div class="qe-fg qe-full">
                    <label class="qe-fl"><?=$slabel?> <span>*</span></label>
                    <div class="qe-slider-row">
                        <input type="range" class="qe-slider" id="<?=$sid?>" min="0" max="100" value="0">
                        <div class="qe-score-disp" id="<?=$sid?>Disp">0</div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="qe-sdv qe-full" style="margin:6px 0 8px">Computed Overall Score</div>
                <div class="qe-fg qe-full" style="background:var(--primary-light);border:1px solid var(--border-mid);border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:14px">
                    <div id="overallGauge" style="font-size:32px;font-weight:800;font-family:var(--mono);color:var(--primary)">0</div>
                    <div style="flex:1">
                        <div style="font-size:12px;color:var(--text-2)" id="weightsLabel">Weighted Average (Price 40% · Quality 30% · Delivery 20% · Warranty 10%)</div>
                        <div class="qe-score-bar-bg" style="margin-top:6px;height:8px"><div class="qe-score-bar-fill" id="overallBar" style="width:0%;background:var(--primary)"></div></div>
                    </div>
                </div>
                <div class="qe-fg qe-full"><label class="qe-fl">Remarks</label><textarea class="qe-fta" id="scoreRemarks" placeholder="Evaluation remarks, observations…"></textarea></div>
            </div>
        </div>
        <div class="qe-m-ft">
            <button class="qe-btn qe-btn-g qe-btn-s" id="scoreCancelBtn">Cancel</button>
            <button class="qe-btn qe-btn-p qe-btn-s" id="scoreSaveBtn"><i class='bx bx-check'></i> Save Score</button>
        </div>
    </div>
</div>

<!-- ── History Modal ── -->
<div id="histModal">
    <div class="qe-mbox qe-mbox-lg">
        <div class="qe-m-hd">
            <div class="qe-m-hd-ti">
                <div class="qe-m-hd-ic" style="background:#EFF6FF;color:var(--info)"><i class='bx bx-history'></i></div>
                <div><div class="qe-m-hd-nm">Full Scoring History</div><div class="qe-m-hd-sub"><?=$isSA?'All records across all branches — Super Admin view':'Evaluation records for your scope'?></div></div>
            </div>
            <button class="qe-m-cl" id="histClose"><i class='bx bx-x'></i></button>
        </div>
        <div class="qe-m-body">
            <?php if ($isSA): ?>
            <div class="qe-sa-banner" style="margin:0 0 16px"><i class='bx bx-shield-quarter'></i><div><strong>Super Admin:</strong> Compare evaluations across branches and view full scoring timeline.</div></div>
            <?php endif; ?>
            <div id="histContent"><div class="qe-loading"><div class="qe-spin"></div> Loading history…</div></div>
        </div>
        <div class="qe-m-ft"><button class="qe-btn qe-btn-g qe-btn-s" id="histCloseBtn"><i class='bx bx-x'></i> Close</button></div>
    </div>
</div>

<!-- ── Endorse Modal ── -->
<div id="legalModal">
    <div class="qe-mbox">
        <div class="qe-m-hd">
            <div class="qe-m-hd-ti">
                <div class="qe-m-hd-ic" style="background:#EFF6FF;color:var(--info)"><i class='bx bx-briefcase'></i></div>
                <div><div class="qe-m-hd-nm">Endorse to Legal</div><div class="qe-m-hd-sub" id="legalSub">Send winning quotation for legal review</div></div>
            </div>
            <button class="qe-m-cl" id="legalClose"><i class='bx bx-x'></i></button>
        </div>
        <div class="qe-m-body">
            <div class="qe-fg" style="margin-bottom:14px">
                <label class="qe-fl">Selected Winner</label>
                <div id="legalWinnerInfo" style="font-size:13px;font-weight:600;color:var(--text-1);padding:10px 12px;background:var(--primary-light);border:1px solid var(--border-mid);border-radius:10px">—</div>
            </div>
            <input type="hidden" id="legalEvalId">
            <div class="qe-fg" style="margin-bottom:14px"><label class="qe-fl">Endorsement Notes <span style="color:var(--danger)">*</span></label><textarea class="qe-fta" id="legalNotes" placeholder="Brief summary for legal team…"></textarea></div>
            <div class="qe-fg"><label class="qe-fl">Legal Contact / Officer</label><input type="text" class="qe-fi" id="legalContact" placeholder="e.g. Atty. Maria Santos"></div>
        </div>
        <div class="qe-m-ft">
            <button class="qe-btn qe-btn-g qe-btn-s" id="legalCancelBtn">Cancel</button>
            <button class="qe-btn qe-btn-gold qe-btn-s" id="legalConfirmBtn"><i class='bx bx-send'></i> Endorse to Legal</button>
        </div>
    </div>
</div>

<!-- ── SA Override Modal ── -->
<div id="overrideModal">
    <div class="qe-mbox">
        <div class="qe-m-hd">
            <div class="qe-m-hd-ti">
                <div class="qe-m-hd-ic" style="background:var(--primary-light);color:var(--primary)"><i class='bx bx-shield-quarter'></i></div>
                <div><div class="qe-m-hd-nm">Override Evaluation Results</div><div class="qe-m-hd-sub">Super Admin — override winner or scores</div></div>
            </div>
            <button class="qe-m-cl" id="overrideClose"><i class='bx bx-x'></i></button>
        </div>
        <div class="qe-m-body">
            <div class="qe-sa-banner" style="margin:0 0 16px"><i class='bx bx-shield-quarter'></i><div>This action is permanently recorded in the audit trail. A justification reason is required.</div></div>
            <div class="qe-fg" style="margin-bottom:14px">
                <label class="qe-fl">Override Type <span style="color:var(--danger)">*</span></label>
                <select class="qe-fi" id="overrideType" style="cursor:pointer;appearance:none;background-image:url('data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27%235D6F62%27 stroke-width=%272.5%27%3E%3Cpolyline points=%276 9 12 15 18 9%27/%3E%3C/svg%3E');background-repeat:no-repeat;background-position:right 10px center;padding-right:32px">
                    <option value="">Select override type…</option>
                    <option value="winner">Override Winner Selection</option>
                    <option value="score">Override Score</option>
                    <option value="endorse">Force Endorsement</option>
                    <option value="reset">Reset All Scores for RFQ</option>
                </select>
            </div>
            <div class="qe-fg" id="evalOverrideWrap" style="margin-bottom:14px;display:none">
                <label class="qe-fl" id="evalOverrideLabel">Evaluation <span style="color:var(--danger)">*</span></label>
                <select class="qe-fi" id="overrideWinner" style="cursor:pointer;appearance:none;background-image:url('data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27%235D6F62%27 stroke-width=%272.5%27%3E%3Cpolyline points=%276 9 12 15 18 9%27/%3E%3C/svg%3E');background-repeat:no-repeat;background-position:right 10px center;padding-right:32px"><option value="">Select…</option></select>
            </div>
            <div class="qe-fg" id="rfqResetWrap" style="margin-bottom:14px;display:none">
                <label class="qe-fl">RFQ to Reset <span style="color:var(--danger)">*</span></label>
                <select class="qe-fi" id="overrideRfqId" style="cursor:pointer;appearance:none;background-image:url('data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27%235D6F62%27 stroke-width=%272.5%27%3E%3Cpolyline points=%276 9 12 15 18 9%27/%3E%3C/svg%3E');background-repeat:no-repeat;background-position:right 10px center;padding-right:32px"><option value="">Select RFQ…</option></select>
            </div>
            <div class="qe-fg" id="scoreOverrideWrap" style="margin-bottom:14px;display:none">
                <label class="qe-fl">New Scores (optional — leave blank to keep current)</label>
                <div class="qe-score-grid" style="grid-template-columns:repeat(4,1fr);gap:10px">
                    <div><label style="font-size:10px;color:var(--text-3)">Price</label><input type="number" class="qe-fi" id="overridePrice" min="0" max="100" placeholder="—"></div>
                    <div><label style="font-size:10px;color:var(--text-3)">Quality</label><input type="number" class="qe-fi" id="overrideQual" min="0" max="100" placeholder="—"></div>
                    <div><label style="font-size:10px;color:var(--text-3)">Delivery</label><input type="number" class="qe-fi" id="overrideDel" min="0" max="100" placeholder="—"></div>
                    <div><label style="font-size:10px;color:var(--text-3)">Warranty</label><input type="number" class="qe-fi" id="overrideWarn" min="0" max="100" placeholder="—"></div>
                </div>
            </div>
            <div class="qe-fg" style="margin-bottom:14px"><label class="qe-fl">Override Reason <span style="color:var(--danger)">*</span></label><textarea class="qe-fta" id="overrideReason" placeholder="State a clear reason. This is required and will appear in the audit trail."></textarea></div>
        </div>
        <div class="qe-m-ft">
            <button class="qe-btn qe-btn-g qe-btn-s" id="overrideCancelBtn">Cancel</button>
            <button class="qe-btn qe-btn-sa qe-btn-s" id="overrideConfirmBtn"><i class='bx bx-shield-quarter'></i> Apply Override</button>
        </div>
    </div>
</div>

<!-- ── Scoring Weights Modal ── -->
<div id="weightsModal">
    <div class="qe-mbox">
        <div class="qe-m-hd">
            <div class="qe-m-hd-ti">
                <div class="qe-m-hd-ic" style="background:var(--primary-light);color:var(--primary)"><i class='bx bx-slider-alt'></i></div>
                <div><div class="qe-m-hd-nm">Scoring Weights</div><div class="qe-m-hd-sub">Adjust criteria weights (must sum to 100%)</div></div>
            </div>
            <button class="qe-m-cl" id="weightsClose"><i class='bx bx-x'></i></button>
        </div>
        <div class="qe-m-body">
            <div class="qe-sa-banner" style="margin:0 0 16px"><i class='bx bx-shield-quarter'></i><div>Changes affect all future score calculations. Existing scores are not retroactively recalculated.</div></div>
            <div class="qe-score-grid">
                <?php foreach([['wPriceScore','Price Weight (%)','40'],['wQualScore','Quality / Compliance (%)','30'],['wDelScore','Delivery Weight (%)','20'],['wWarnScore','Warranty / Support (%)','10']] as [$wid,$wlabel,$wdef]): ?>
                <div class="qe-fg"><label class="qe-fl"><?=$wlabel?></label><input type="number" class="qe-fi weight-inp" id="<?=$wid?>" min="0" max="100" step="1" value="<?=$wdef?>"></div>
                <?php endforeach; ?>
                <div class="qe-fg qe-full" id="weightSumDisplay" style="font-size:13px;padding:8px 12px;border-radius:8px;background:#F3F4F6;color:var(--text-2)">Sum: <strong id="weightSum">100</strong>% (must equal 100%)</div>
            </div>
        </div>
        <div class="qe-m-ft">
            <button class="qe-btn qe-btn-g qe-btn-s" id="weightsCancelBtn">Cancel</button>
            <button class="qe-btn qe-btn-sa qe-btn-s" id="weightsSaveBtn"><i class='bx bx-check'></i> Save Weights</button>
        </div>
    </div>
</div>

<!-- ── Confirm Modal ── -->
<div id="cfmModal">
    <div class="qe-mbox" style="width:420px">
        <div class="qe-m-hd">
            <div class="qe-m-hd-ti"><div class="qe-m-hd-ic" id="cfmIc"></div><div><div class="qe-m-hd-nm" id="cfmTitle"></div><div class="qe-m-hd-sub" id="cfmSub"></div></div></div>
            <button class="qe-m-cl" id="cfmClose"><i class='bx bx-x'></i></button>
        </div>
        <div class="qe-m-body" id="cfmBody"></div>
        <div class="qe-m-ft">
            <button class="qe-btn qe-btn-g qe-btn-s" id="cfmCancelBtn">Cancel</button>
            <button class="qe-btn qe-btn-s" id="cfmOkBtn">Confirm</button>
        </div>
    </div>
</div>

<div id="qeTw"></div>

<script>
// ── Config injected from PHP ──────────────────────────────────────────────
const API       = <?=json_encode($apiUrl)?>;   // points back to THIS file
const CAN_SCORE = <?=json_encode($canScore)?>;
const IS_SA     = <?=json_encode($isSA)?>;
const COLORS    = ['#2E7D32','#1B5E20','#388E3C','#0D9488','#2563EB','#7C3AED','#D97706','#DC2626','#0891B2','#059669'];

// ── Helpers ───────────────────────────────────────────────────────────────
const gc  = n => { let h=0; for(const c of n) h=(h*31+c.charCodeAt(0))%COLORS.length; return COLORS[h]; };
const ini = n => n.split(' ').map(w=>w[0]).join('').substring(0,2).toUpperCase();
const esc = s => String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'}) : '—';
const fmtPHP  = n => '₱'+parseFloat(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const scoreColor = s => s>=85?'#2E7D32':s>=65?'#D97706':'#DC2626';
const chipCls    = s => ({Pending:'qe-ch-pending',Scored:'qe-ch-scored',Winner:'qe-ch-winner',Endorsed:'qe-ch-endorsed'}[s]||'qe-ch-pending');
const chip       = s => `<span class="qe-chip ${chipCls(s)}">${s}</span>`;

// ── State ─────────────────────────────────────────────────────────────────
let state = {
    page:1, perPage:10, total:0, pages:1,
    data:[], stats:{}, filters:{branches:[],depts:[],rfqs:[]},
    scoreTargetId:null, cfmCb:null,
    weights:{price_weight:0.4,quality_weight:0.3,delivery_weight:0.2,warranty_weight:0.1},
};

// ── API fetch ─────────────────────────────────────────────────────────────
async function apiFetch(params, method='GET', body=null) {
    const url = new URL(API, location.origin);
    Object.entries(params).forEach(([k,v]) => url.searchParams.set(k,v));
    const opts = {method, headers:{'Content-Type':'application/json'}};
    if (body) opts.body = JSON.stringify(body);
    const r = await fetch(url.toString(), opts);
    return r.json();
}

// ── Load data ─────────────────────────────────────────────────────────────
async function loadData() {
    document.getElementById('cmpTb').innerHTML =
        `<tr><td colspan="${CAN_SCORE?9:8}"><div class="qe-loading"><div class="qe-spin"></div> Loading…</div></td></tr>`;
    try {
        const res = await apiFetch({
            action:'list', page:state.page, per_page:state.perPage,
            q:document.getElementById('srch').value.trim(),
            branch:document.getElementById('fBranch').value,
            dept:document.getElementById('fDept').value,
            rfq_id:document.getElementById('fRfq').value,
            status:document.getElementById('fStatus').value,
            date_from:document.getElementById('fDateFrom').value,
            date_to:document.getElementById('fDateTo').value,
        });
        if (!res.success) { toast(res.message||'Failed to load','danger'); return; }
        state.data=res.data; state.total=res.total; state.pages=res.pages;
        state.stats=res.stats; state.filters=res.filters;
        renderStats(); renderDropdowns(); renderTable();
    } catch { toast('Network error loading data','danger'); }
}

function renderStats() {
    const s=state.stats, vals=document.querySelectorAll('.stat-val');
    if (vals.length>=5) { vals[0].textContent=s.total||0; vals[1].textContent=s.pending||0; vals[2].textContent=s.scored||0; vals[3].textContent=s.winners||0; vals[4].textContent=s.endorsed||0; }
}

function renderDropdowns() {
    const f=state.filters;
    const brEl=document.getElementById('fBranch'),brV=brEl.value;
    brEl.innerHTML='<option value="">All Branches</option>'+(f.branches||[]).map(b=>`<option${b===brV?' selected':''}>${esc(b)}</option>`).join('');
    const dpEl=document.getElementById('fDept'),dpV=dpEl.value;
    dpEl.innerHTML='<option value="">All Departments</option>'+(f.depts||[]).map(d=>`<option${d===dpV?' selected':''}>${esc(d)}</option>`).join('');
    const rfqEl=document.getElementById('fRfq'),rfqV=rfqEl.value;
    rfqEl.innerHTML='<option value="">All RFQ References</option>'+(f.rfqs||[]).map(r=>`<option value="${r.rfq_id}"${String(r.rfq_id)===rfqV?' selected':''}>${esc(r.rfq_no)}</option>`).join('');
}

function renderTable() {
    const list=state.data;
    if (!list.length) {
        document.getElementById('cmpTb').innerHTML=`<tr><td colspan="${CAN_SCORE?9:8}"><div class="qe-empty"><i class='bx bx-file-find'></i><p>No quotations match your filters.</p></div></td></tr>`;
        document.getElementById('pag').innerHTML='';
        document.getElementById('cmpTitle').textContent='Quotation Comparison';
        document.getElementById('cmpSub').textContent='0 quotes';
        document.getElementById('statusChipArea').innerHTML='';
        return;
    }
    const rfqIds=[...new Set(list.map(e=>e.rfq_id))];
    if (rfqIds.length===1) {
        document.getElementById('cmpTitle').textContent=`${list[0].rfq_no} — Comparison`;
        document.getElementById('cmpSub').textContent=`${list.length} supplier quote${list.length!==1?'s':''}`;
    } else {
        document.getElementById('cmpTitle').textContent='Quotation Comparison';
        document.getElementById('cmpSub').textContent=`${state.total} quotes across ${rfqIds.length} RFQs`;
    }
    const winner=list.find(e=>e.eval_status==='Winner');
    document.getElementById('statusChipArea').innerHTML=winner
        ?`<div style="display:flex;align-items:center;gap:6px;font-size:12px;color:#92400E;font-weight:600;background:#FEF3C7;border:1px solid #FDE68A;border-radius:20px;padding:4px 12px"><i class='bx bx-trophy' style="font-size:14px"></i>${esc(winner.supplier_name)}</div>`:'';

    document.getElementById('cmpTb').innerHTML = list.map(e => {
        const isWinner=e.eval_status==='Winner';
        const overall=parseFloat(e.overall_score)||0;
        const barColor=scoreColor(overall);
        const rfqPeers=list.filter(x=>x.rfq_id===e.rfq_id);
        const minPrice=Math.min(...rfqPeers.map(x=>parseFloat(x.total_price)||0));
        const priceClass=rfqPeers.length>1&&parseFloat(e.total_price)===minPrice?'qe-best-price':'';
        const rankBadge=e.rank_in_rfq
            ?`<span class="qe-rank-badge qe-rank-${e.rank_in_rfq<=3?e.rank_in_rfq:'n'}">${e.rank_in_rfq}</span>`
            :`<span class="qe-rank-badge qe-rank-n">—</span>`;
        const actionsHtml=CAN_SCORE?`<td onclick="event.stopPropagation()" style="white-space:nowrap">
            ${e.eval_status!=='Endorsed'?`<button class="qe-btn qe-btn-g qe-btn-s" style="padding:5px 8px" onclick="openScore(${e.id})" title="Score"><i class='bx bx-bar-chart-alt-2'></i></button>`:''}
            ${e.eval_status!=='Winner'&&e.eval_status!=='Endorsed'?`<button class="qe-btn qe-btn-gold qe-btn-s" style="padding:5px 8px;margin-left:4px" onclick="selectWinner(${e.id})" title="Select Winner"><i class='bx bx-trophy'></i></button>`:''}
        </td>`:'';
        return `<tr class="${isWinner?'qe-winner-row':''}" data-id="${e.id}">
            <td><div class="qe-sup-cell">
                ${rankBadge}
                <div class="qe-sup-av" style="background:${gc(e.supplier_name)}">${ini(e.supplier_name)}</div>
                <div>
                    <div class="qe-sup-name">${esc(e.supplier_name)}</div>
                    <div class="qe-sup-cat">${esc(e.supplier_category)} · ${esc(e.branch||e.department)}</div>
                    ${isWinner?`<div class="qe-winner-badge"><i class='bx bx-trophy' style="font-size:10px"></i>Winner</div>`:''}
                    ${e.is_overridden?`<div style="font-size:9px;color:#DC2626;margin-top:2px"><i class='bx bx-shield-quarter'></i> SA Override</div>`:''}
                </div>
            </div></td>
            <td><div class="qe-price-val ${priceClass}">${fmtPHP(e.unit_price)}</div><div class="qe-price-sub">Per unit</div></td>
            <td><div class="qe-price-val ${priceClass}">${fmtPHP(e.total_price)}</div>${priceClass?`<div class="qe-price-sub" style="color:var(--primary);font-weight:600">Lowest quote</div>`:''}</td>
            <td><div class="qe-del-terms">${esc(e.delivery_terms)||'—'}</div></td>
            <td><div class="qe-warranty-val"><i class='bx bx-shield-check' style="font-size:13px;color:var(--primary)"></i>${esc(e.warranty)||'—'}</div></td>
            <td>
                <div class="qe-score-wrap">
                    <div style="min-width:30px;font-family:var(--mono);font-size:12px;font-weight:700;color:${barColor}">${overall||'—'}</div>
                    <div class="qe-score-bar-bg" style="min-width:55px"><div class="qe-score-bar-fill" style="width:${overall}%;background:${barColor}"></div></div>
                    ${CAN_SCORE&&e.eval_status!=='Endorsed'?`<button class="qe-score-edit-btn" onclick="openScore(${e.id})" title="Score"><i class='bx bx-pencil'></i></button>`:''}
                </div>
                ${overall?`<div style="font-size:10px;color:var(--text-3);margin-top:3px">P:${e.price_score} Q:${e.quality_score} D:${e.delivery_score} W:${e.warranty_score}</div>`:''}
                ${e.scored_by?`<div style="font-size:10px;color:var(--text-3)">by ${esc(e.scored_by)} · ${fmtDate(e.scored_at)}</div>`:''}
            </td>
            <td><div class="qe-remarks-val">${esc(e.remarks)||'<span style="color:var(--text-3)">—</span>'}</div></td>
            <td>${chip(e.eval_status)}</td>
            ${actionsHtml}
        </tr>`;
    }).join('');
    renderPag();
}

function renderPag() {
    const el=document.getElementById('pag');
    const {page,pages,total,perPage}=state;
    const s=(page-1)*perPage+1,e2=Math.min(page*perPage,total);
    const bStyle=a=>`width:30px;height:30px;border-radius:7px;border:1px solid ${a?'var(--primary)':'var(--border-mid)'};background:${a?'var(--primary)':'var(--surface)'};color:${a?'#fff':'var(--text-1)'};cursor:pointer;font-family:var(--font);font-size:13px;font-weight:500`;
    let btns='';
    for(let i=1;i<=pages;i++){
        if(i===1||i===pages||(i>=page-2&&i<=page+2)) btns+=`<button onclick="goPg(${i})" style="${bStyle(i===page)}">${i}</button>`;
        else if(i===page-3||i===page+3) btns+=`<button style="${bStyle(false)};cursor:default;color:var(--text-3)" disabled>…</button>`;
    }
    const navStyle=`width:30px;height:30px;border-radius:7px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;font-size:16px;display:grid;place-content:center;color:var(--text-2)`;
    el.innerHTML=`<span>${total?`Showing ${s}–${e2} of ${total} quote${total!==1?'s':''}`:'No results'}</span>
        <div style="display:flex;gap:6px;align-items:center">
            <button onclick="goPg(${page-1})" style="${navStyle}" ${page<=1?'disabled':''}><i class='bx bx-chevron-left'></i></button>
            ${btns}
            <button onclick="goPg(${page+1})" style="${navStyle}" ${page>=pages?'disabled':''}><i class='bx bx-chevron-right'></i></button>
        </div>`;
}
window.goPg=p=>{state.page=p;loadData()};

// ── Score Modal ───────────────────────────────────────────────────────────
function openScore(id) {
    const e=state.data.find(x=>x.id===id); if(!e) return;
    state.scoreTargetId=id;
    document.getElementById('scoreModalNm').textContent=`Score: ${e.supplier_name}`;
    document.getElementById('scoreModalSub').textContent=`${e.rfq_no} · ${e.branch||e.department}`;
    [['priceScore','price_score'],['qualScore','quality_score'],['delScore','delivery_score'],['warnScore','warranty_score']].forEach(([sid,key])=>{
        document.getElementById(sid).value=e[key]||0;
        document.getElementById(sid+'Disp').textContent=e[key]||0;
    });
    document.getElementById('scoreRemarks').value=e.remarks||'';
    updateOverall();
    document.getElementById('scoreModal').classList.add('show');
}
window.openScore=openScore;

['priceScore','qualScore','delScore','warnScore'].forEach(id=>{
    const el=document.getElementById(id);
    if(el) el.addEventListener('input',function(){document.getElementById(id+'Disp').textContent=this.value;updateOverall();});
});

function updateOverall() {
    const p=+document.getElementById('priceScore').value, q=+document.getElementById('qualScore').value;
    const d=+document.getElementById('delScore').value,   w=+document.getElementById('warnScore').value;
    const wt=state.weights;
    const o=Math.round(p*wt.price_weight+q*wt.quality_weight+d*wt.delivery_weight+w*wt.warranty_weight);
    document.getElementById('overallGauge').textContent=o;
    document.getElementById('overallGauge').style.color=scoreColor(o);
    document.getElementById('overallBar').style.width=o+'%';
    document.getElementById('overallBar').style.background=scoreColor(o);
}

['scoreClose','scoreCancelBtn'].forEach(id=>document.getElementById(id).onclick=()=>document.getElementById('scoreModal').classList.remove('show'));
document.getElementById('scoreModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('show')});

document.getElementById('scoreSaveBtn').addEventListener('click',async()=>{
    const id=state.scoreTargetId; if(!id) return;
    const btn=document.getElementById('scoreSaveBtn');
    btn.disabled=true; btn.innerHTML='<div class="qe-spin" style="width:14px;height:14px;border-width:2px"></div> Saving…';
    try {
        const res=await apiFetch({action:'score'},'POST',{
            id, price_score:+document.getElementById('priceScore').value,
            quality_score:+document.getElementById('qualScore').value,
            delivery_score:+document.getElementById('delScore').value,
            warranty_score:+document.getElementById('warnScore').value,
            remarks:document.getElementById('scoreRemarks').value.trim(),
        });
        if(res.success){document.getElementById('scoreModal').classList.remove('show');toast(`Score saved (Overall: ${res.overall_score})`,'success');loadData();}
        else toast(res.message||'Failed to save score','danger');
    } catch{toast('Network error','danger');}
    finally{btn.disabled=false;btn.innerHTML='<i class="bx bx-check"></i> Save Score';}
});

// ── Select Winner ─────────────────────────────────────────────────────────
function selectWinner(id) {
    const e=state.data.find(x=>x.id===id); if(!e) return;
    openCfm({ic:'bx-trophy',bg:'#FEF3C7',fc:'#92400E',title:'Select Winner',sub:`Select ${e.supplier_name} as winning supplier?`,
        body:`<strong>${esc(e.supplier_name)}</strong> will be marked as the <strong>Winner</strong> for <strong>${esc(e.rfq_no)}</strong>. Previous winners in this RFQ will be cleared.`,
        btnCls:'qe-btn-gold',btnTxt:'<i class="bx bx-trophy"></i> Select Winner',
        cb:async()=>{try{const res=await apiFetch({action:'winner'},'POST',{id});res.success?(toast(res.message,'success'),loadData()):toast(res.message,'danger');}catch{toast('Network error','danger');}}
    });
}
window.selectWinner=selectWinner;

// ── Auto-Select Winner ────────────────────────────────────────────────────
document.getElementById('winnerBtn')?.addEventListener('click',()=>{
    const scored=state.data.filter(e=>parseFloat(e.overall_score)>0);
    if(!scored.length){toast('No scored quotes to select winner from','warning');return;}
    const best=scored.reduce((b,e)=>parseFloat(e.overall_score)>parseFloat(b.overall_score)?e:b,scored[0]);
    openCfm({ic:'bx-trophy',bg:'#FEF3C7',fc:'#92400E',title:'Auto-Select Winner',sub:'Select the highest-scoring supplier?',
        body:`<strong>${esc(best.supplier_name)}</strong> — Overall: <strong>${best.overall_score}</strong> on ${esc(best.rfq_no)}.`,
        btnCls:'qe-btn-gold',btnTxt:'<i class="bx bx-trophy"></i> Select Winner',
        cb:async()=>{try{const res=await apiFetch({action:'winner'},'POST',{id:best.id});res.success?(toast(res.message,'success'),loadData()):toast(res.message,'danger');}catch{toast('Network error','danger');}}
    });
});

// ── Rank ──────────────────────────────────────────────────────────────────
document.getElementById('rankBtn')?.addEventListener('click',()=>{
    state.data.sort((a,b)=>parseFloat(b.overall_score||0)-parseFloat(a.overall_score||0));
    renderTable(); toast('Suppliers ranked by overall score','success');
});

// ── Endorse ───────────────────────────────────────────────────────────────
document.getElementById('endorseBtn')?.addEventListener('click',()=>{
    const winner=state.data.find(e=>e.eval_status==='Winner');
    document.getElementById('legalEvalId').value=winner?winner.id:'';
    document.getElementById('legalWinnerInfo').textContent=winner?`${winner.supplier_name} · ${winner.rfq_no} · Score: ${winner.overall_score}`:'No winner selected yet — please select a winner first.';
    document.getElementById('legalSub').textContent=winner?`Send ${winner.supplier_name}'s quote for legal review`:'Send winning quotation for legal review';
    document.getElementById('legalNotes').value=''; document.getElementById('legalContact').value='';
    document.getElementById('legalModal').classList.add('show');
});
['legalClose','legalCancelBtn'].forEach(id=>document.getElementById(id).onclick=()=>document.getElementById('legalModal').classList.remove('show'));
document.getElementById('legalModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('show')});
document.getElementById('legalConfirmBtn').addEventListener('click',async()=>{
    const evalId=document.getElementById('legalEvalId').value, notes=document.getElementById('legalNotes').value.trim();
    if(!evalId){toast('No winner evaluation to endorse','danger');return;}
    if(!notes){shk('legalNotes');toast('Endorsement notes are required','danger');return;}
    const btn=document.getElementById('legalConfirmBtn'); btn.disabled=true;
    try{
        const res=await apiFetch({action:'endorse'},'POST',{id:+evalId,notes,legal_contact:document.getElementById('legalContact').value.trim()});
        if(res.success){document.getElementById('legalModal').classList.remove('show');toast('Quotation endorsed to Legal','success');loadData();}
        else toast(res.message,'danger');
    }catch{toast('Network error','danger');}
    finally{btn.disabled=false;}
});

// ── Scoring History ───────────────────────────────────────────────────────
document.getElementById('histBtn').addEventListener('click',async()=>{
    document.getElementById('histContent').innerHTML=`<div class="qe-loading"><div class="qe-spin"></div> Loading history…</div>`;
    document.getElementById('histModal').classList.add('show');
    try{
        const params={action:'history',per_page:200,page:1};
        const rfqFilter=document.getElementById('fRfq').value;
        if(rfqFilter) params.rfq_id=rfqFilter;
        const res=await apiFetch(params);
        if(!res.success){document.getElementById('histContent').innerHTML=`<p style="color:var(--danger)">${esc(res.message)}</p>`;return;}
        const rows=res.data;
        document.getElementById('histContent').innerHTML=rows.length
            ?`<table class="qe-hist-tbl"><thead><tr><th>RFQ</th><th>Branch</th><th>Supplier</th><th>Price</th><th>Quality</th><th>Delivery</th><th>Warranty</th><th>Overall</th><th>Remarks</th><th>Scored By</th><th>Date</th>${IS_SA?'<th>Override</th>':''}</tr></thead>
              <tbody>${rows.map(h=>`<tr>
                <td style="font-family:var(--mono);font-size:11px;color:var(--primary)">${esc(h.rfq_no)}</td>
                <td style="font-size:11px;color:var(--text-2)">${esc(h.branch||'—')}</td>
                <td><div style="display:flex;align-items:center;gap:7px"><div class="qe-sup-av" style="background:${gc(h.supplier_name)};width:22px;height:22px;font-size:8px;border-radius:6px">${ini(h.supplier_name)}</div><span style="font-size:12px;font-weight:600">${esc(h.supplier_name)}</span></div></td>
                <td style="font-family:var(--mono);font-size:12px">${h.price_score??'—'}</td>
                <td style="font-family:var(--mono);font-size:12px">${h.quality_score??'—'}</td>
                <td style="font-family:var(--mono);font-size:12px">${h.delivery_score??'—'}</td>
                <td style="font-family:var(--mono);font-size:12px">${h.warranty_score??'—'}</td>
                <td style="font-family:var(--mono);font-size:12px;font-weight:700;color:${scoreColor(h.overall_score??0)}">${h.overall_score??'—'}</td>
                <td style="font-size:11px;color:var(--text-2);max-width:120px">${esc(h.remarks)||'—'}</td>
                <td style="font-size:11px;color:var(--text-3)">${esc(h.actor_name)}</td>
                <td style="font-size:11px;color:var(--text-3);font-family:var(--mono)">${fmtDate(h.occurred_at)}</td>
                ${IS_SA?`<td style="font-size:10px;color:${h.is_super_admin?'var(--primary)':'var(--text-3)'}">${h.is_super_admin?'SA Override':'—'}</td>`:''}
              </tr>`).join('')}</tbody></table>`
            :`<div class="qe-empty"><i class='bx bx-history'></i><p>No scoring history yet.</p></div>`;
    }catch{document.getElementById('histContent').innerHTML=`<p style="color:var(--danger)">Failed to load history.</p>`;}
});
['histClose','histCloseBtn'].forEach(id=>document.getElementById(id).onclick=()=>document.getElementById('histModal').classList.remove('show'));
document.getElementById('histModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('show')});

// ── SA Override ───────────────────────────────────────────────────────────
function populateOverrideEvalDropdown(type){
    const sel=document.getElementById('overrideWinner');
    let opts='<option value="">Select…</option>';
    if(type==='winner'){
        opts+=state.data.filter(e=>parseFloat(e.overall_score)>0).map(e=>`<option value="${e.id}">${esc(e.supplier_name)} (${esc(e.rfq_no)}) — ${e.overall_score}</option>`).join('');
    }else if(type==='score'){
        opts+=state.data.filter(e=>e.eval_status!=='Endorsed').map(e=>`<option value="${e.id}">${esc(e.supplier_name)} (${esc(e.rfq_no)}) — Score: ${e.overall_score||'—'}</option>`).join('');
    }else if(type==='endorse'){
        opts+=state.data.filter(e=>e.eval_status==='Winner').map(e=>`<option value="${e.id}">${esc(e.supplier_name)} (${esc(e.rfq_no)})</option>`).join('');
    }
    sel.innerHTML=opts;
}
document.getElementById('saOverrideBtn')?.addEventListener('click',()=>{
    document.getElementById('overrideType').value='';
    document.getElementById('overrideReason').value='';
    document.getElementById('evalOverrideWrap').style.display='none';
    document.getElementById('rfqResetWrap').style.display='none';
    document.getElementById('overrideWinner').innerHTML='<option value="">Select…</option>';
    const rfqsUniq=[...new Map(state.data.map(e=>[e.rfq_id,e])).values()];
    document.getElementById('overrideRfqId').innerHTML='<option value="">Select RFQ…</option>'
        +rfqsUniq.map(e=>`<option value="${e.rfq_id}">${esc(e.rfq_no)}</option>`).join('');
    document.getElementById('overrideModal').classList.add('show');
});
document.getElementById('overrideType').addEventListener('change',function(){
    const v=this.value;
    document.getElementById('evalOverrideWrap').style.display=(v==='winner'||v==='score'||v==='endorse')?'block':'none';
    document.getElementById('rfqResetWrap').style.display=v==='reset'?'block':'none';
    document.getElementById('scoreOverrideWrap').style.display=v==='score'?'block':'none';
    ['overridePrice','overrideQual','overrideDel','overrideWarn'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    if(v==='winner') document.getElementById('evalOverrideLabel').innerHTML='New Winning Supplier <span style="color:var(--danger)">*</span>';
    else if(v==='score') document.getElementById('evalOverrideLabel').innerHTML='Evaluation to Override (Score) <span style="color:var(--danger)">*</span>';
    else if(v==='endorse') document.getElementById('evalOverrideLabel').innerHTML='Evaluation to Force Endorse <span style="color:var(--danger)">*</span>';
    populateOverrideEvalDropdown(v);
});
document.getElementById('overrideWinner').addEventListener('change',function(){
    if(document.getElementById('overrideType').value!=='score') return;
    const eId=this.value; if(!eId) return;
    const e=state.data.find(x=>x.id===+eId); if(!e) return;
    document.getElementById('overridePrice').value=e.price_score??'';
    document.getElementById('overrideQual').value=e.quality_score??'';
    document.getElementById('overrideDel').value=e.delivery_score??'';
    document.getElementById('overrideWarn').value=e.warranty_score??'';
    document.getElementById('scoreOverrideWrap').style.display='block';
});
['overrideClose','overrideCancelBtn'].forEach(id=>document.getElementById(id).onclick=()=>document.getElementById('overrideModal').classList.remove('show'));
document.getElementById('overrideModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('show')});
document.getElementById('overrideConfirmBtn')?.addEventListener('click',async()=>{
    const type=document.getElementById('overrideType').value, reason=document.getElementById('overrideReason').value.trim();
    if(!type){shk('overrideType');toast('Select an override type','danger');return;}
    if(!reason){shk('overrideReason');toast('Override reason is required','danger');return;}
    const payload={type,reason};
    if(type==='winner'||type==='score'||type==='endorse'){
        const eId=document.getElementById('overrideWinner').value;
        if(!eId){shk('overrideWinner');toast(type==='winner'?'Select the new winning supplier':type==='score'?'Select evaluation to override':'Select evaluation to endorse','danger');return;}
        payload.evaluation_id=+eId;
        if(type==='score'){
            const ps=document.getElementById('overridePrice').value, qs=document.getElementById('overrideQual').value;
            const ds=document.getElementById('overrideDel').value, ws=document.getElementById('overrideWarn').value;
            if(ps!=='') payload.price_score=Math.max(0,Math.min(100,+ps));
            if(qs!=='') payload.quality_score=Math.max(0,Math.min(100,+qs));
            if(ds!=='') payload.delivery_score=Math.max(0,Math.min(100,+ds));
            if(ws!=='') payload.warranty_score=Math.max(0,Math.min(100,+ws));
        }
    }
    if(type==='reset') {const rId=document.getElementById('overrideRfqId').value;if(!rId){shk('overrideRfqId');toast('Select an RFQ to reset','danger');return;}payload.rfq_id=+rId;}
    const btn=document.getElementById('overrideConfirmBtn'); btn.disabled=true;
    try{
        const res=await apiFetch({action:'override'},'POST',payload);
        if(res.success){document.getElementById('overrideModal').classList.remove('show');toast(`Override applied: ${type}`,'success');loadData();}
        else toast(res.message,'danger');
    }catch{toast('Network error','danger');}
    finally{btn.disabled=false;}
});

// ── Scoring Weights ───────────────────────────────────────────────────────
document.getElementById('weightsBtn')?.addEventListener('click',async()=>{
    try{const res=await apiFetch({action:'weights',scope:'global'});
        if(res.success){const w=res.weights;
            document.getElementById('wPriceScore').value=Math.round(w.price_weight*100);
            document.getElementById('wQualScore').value=Math.round(w.quality_weight*100);
            document.getElementById('wDelScore').value=Math.round(w.delivery_weight*100);
            document.getElementById('wWarnScore').value=Math.round(w.warranty_weight*100);}
    }catch{}
    updateWeightsSum();
    document.getElementById('weightsModal').classList.add('show');
});
['weightsClose','weightsCancelBtn'].forEach(id=>document.getElementById(id).onclick=()=>document.getElementById('weightsModal').classList.remove('show'));
document.getElementById('weightsModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('show')});
document.querySelectorAll('.weight-inp').forEach(el=>el.addEventListener('input',updateWeightsSum));
function updateWeightsSum(){
    const sum=['wPriceScore','wQualScore','wDelScore','wWarnScore'].reduce((a,id)=>a+(+document.getElementById(id).value||0),0);
    const el=document.getElementById('weightSum'); el.textContent=sum;
    el.style.color=sum===100?'var(--primary)':'var(--danger)';
    document.getElementById('weightSumDisplay').style.background=sum===100?'#E8F5E9':'#FEF2F2';
}
document.getElementById('weightsSaveBtn')?.addEventListener('click',async()=>{
    const pw=+document.getElementById('wPriceScore').value, qw=+document.getElementById('wQualScore').value;
    const dw=+document.getElementById('wDelScore').value,   ww=+document.getElementById('wWarnScore').value;
    if(pw+qw+dw+ww!==100){toast('Weights must sum to 100%','danger');return;}
    const btn=document.getElementById('weightsSaveBtn'); btn.disabled=true;
    try{
        const res=await apiFetch({action:'update_weights'},'POST',{scope:'global',price_weight:pw/100,quality_weight:qw/100,delivery_weight:dw/100,warranty_weight:ww/100});
        if(res.success){
            state.weights={price_weight:pw/100,quality_weight:qw/100,delivery_weight:dw/100,warranty_weight:ww/100};
            document.getElementById('weightsLabel').textContent=`Weighted Average (Price ${pw}% · Quality ${qw}% · Delivery ${dw}% · Warranty ${ww}%)`;
            document.getElementById('weightsModal').classList.remove('show');
            toast('Scoring weights updated','success');
        }else toast(res.message,'danger');
    }catch{toast('Network error','danger');}
    finally{btn.disabled=false;}
});

// ── Confirm Modal ─────────────────────────────────────────────────────────
function openCfm({ic,bg,fc,title,sub,body,btnCls,btnTxt,cb}){
    document.getElementById('cfmIc').style.cssText=`background:${bg};color:${fc}`;
    document.getElementById('cfmIc').innerHTML=`<i class='bx ${ic}'></i>`;
    document.getElementById('cfmTitle').textContent=title;
    document.getElementById('cfmSub').textContent=sub;
    document.getElementById('cfmBody').innerHTML=`<div style="line-height:1.65;font-size:13.5px;color:#374151">${body}</div>`;
    const ok=document.getElementById('cfmOkBtn'); ok.className=`qe-btn qe-btn-s ${btnCls}`; ok.innerHTML=btnTxt;
    state.cfmCb=cb; document.getElementById('cfmModal').classList.add('show');
}
['cfmClose','cfmCancelBtn'].forEach(id=>document.getElementById(id).onclick=()=>document.getElementById('cfmModal').classList.remove('show'));
document.getElementById('cfmModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('show')});
document.getElementById('cfmOkBtn').addEventListener('click',()=>{document.getElementById('cfmModal').classList.remove('show');if(state.cfmCb)state.cfmCb();});

// ── Export ────────────────────────────────────────────────────────────────
document.getElementById('expBtn').addEventListener('click',()=>{
    const url=new URL(API,location.origin);
    url.searchParams.set('action','export');
    const rfqF=document.getElementById('fRfq').value, brF=document.getElementById('fBranch').value;
    if(rfqF) url.searchParams.set('rfq_id',rfqF);
    if(brF)  url.searchParams.set('branch',brF);
    window.open(url.toString(),'_blank');
    toast('Exporting CSV…','info');
});

// ── Filters ───────────────────────────────────────────────────────────────
let debounceTimer;
['srch','fBranch','fDept','fRfq','fStatus','fDateFrom','fDateTo'].forEach(id=>{
    document.getElementById(id).addEventListener('input',()=>{clearTimeout(debounceTimer);debounceTimer=setTimeout(()=>{state.page=1;loadData();},350);});
});
document.getElementById('clearDates').addEventListener('click',()=>{
    document.getElementById('fDateFrom').value=''; document.getElementById('fDateTo').value='';
    state.page=1; loadData();
});

// ── Toast & Shake ─────────────────────────────────────────────────────────
function toast(msg,type='success'){
    const icons={success:'bx-check-circle',danger:'bx-error-circle',warning:'bx-error',info:'bx-info-circle'};
    if (/Database error|Connection refused|could not connect/i.test(msg)) {
        msg='Database connection failed. Check that PostgreSQL is running on port 5432.';
    }
    const maxLen=200;
    const displayMsg=msg.length>maxLen?msg.substring(0,maxLen)+'…':msg;
    const el=document.createElement('div'); el.className=`qe-toast ${type}`;
    el.innerHTML=`<i class='bx ${icons[type]}' style="font-size:18px"></i><span>${esc(displayMsg)}</span>`;
    document.getElementById('qeTw').appendChild(el);
    setTimeout(()=>{el.classList.add('out');setTimeout(()=>el.remove(),300);},type==='danger'?5000:3200);
}
function shk(id){
    const el=document.getElementById(id); if(!el) return;
    el.style.borderColor='#DC2626'; el.style.animation='none'; el.offsetHeight;
    el.style.animation='qeShake .3s ease';
    setTimeout(()=>{el.style.borderColor='';el.style.animation='';},600);
}

// ── Init ──────────────────────────────────────────────────────────────────
(async()=>{
    try{
        const res=await apiFetch({action:'weights',scope:'global'});
        if(res.success){
            const w=res.weights;
            state.weights={price_weight:parseFloat(w.price_weight),quality_weight:parseFloat(w.quality_weight),delivery_weight:parseFloat(w.delivery_weight),warranty_weight:parseFloat(w.warranty_weight)};
            const wt=state.weights;
            document.getElementById('weightsLabel').textContent=`Weighted Average (Price ${Math.round(wt.price_weight*100)}% · Quality ${Math.round(wt.quality_weight*100)}% · Delivery ${Math.round(wt.delivery_weight*100)}% · Warranty ${Math.round(wt.warranty_weight*100)}%)`;
        }
    }catch{}
    loadData();
})();
</script>
</body>
</html>