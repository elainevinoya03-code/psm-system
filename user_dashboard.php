<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── HELPERS (mirrors disposal backend pattern exactly) ───────────────────────
function sd_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function sd_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function sd_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

// Supabase REST helper — identical pattern to disposal backend
function sd_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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

// ── SESSION: current user context ─────────────────────────────────────────────
$current_user_id = $_SESSION['user_id']    ?? null;
$current_user    = $_SESSION['full_name']  ?? 'Staff User';
$current_zone    = $_SESSION['zone']       ?? 'Zone B — Storage 1';
$current_role    = $_SESSION['role']       ?? 'Staff';

// ── API ROUTER ─────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    try {

        // ── GET: my task summary (counts from each module) ────────────────────
        if ($api === 'summary' && $method === 'GET') {
            $uid = $current_user_id;

            // SWS: pending stock transactions assigned to this user's zone
            $sws_txns = sd_sb('sws_transactions', 'GET', [
                'select' => 'id,txn_id,type,status,item_name,ref_doc,zone,date_time',
                'status' => 'in.(Pending,Processing)',
                'zone'   => 'eq.' . $current_zone,
                'order'  => 'date_time.asc',
                'limit'  => '50',
            ]);

            // SWS cycle counts pending in this zone
            $sws_counts = sd_sb('sws_cycle_counts', 'GET', [
                'select' => 'id,record_no,count_date,item_name,status,counted_by',
                'status' => 'eq.Pending',
                'zone'   => 'eq.' . $current_zone,
                'order'  => 'count_date.asc',
                'limit'  => '20',
            ]);

            // PSM: Purchase Requests — drafts filed by this user
            $psm_prs = sd_sb('psm_purchase_requests', 'GET', [
                'select' => 'id,pr_number,status,purpose,total_amount,date_needed,created_at',
                'status' => 'in.(Draft,Returned)',
                'created_user_id' => 'eq.' . ($uid ?? 'none'),
                'order'  => 'date_needed.asc',
                'limit'  => '20',
            ]);

            // PLT: Assignments for this user
            $plt_assigns = sd_sb('plt_assignments', 'GET', [
                'select'      => 'id,assignment_id,task,status,priority,due_date,zone',
                'assigned_to' => 'eq.' . $current_user,
                'status'      => 'in.(Assigned,In Progress,Overdue)',
                'order'       => 'due_date.asc',
                'limit'       => '20',
            ]);

            // PLT: Deliveries assigned to this user
            $plt_deliveries = sd_sb('plt_deliveries', 'GET', [
                'select'      => 'id,delivery_id,supplier,po_ref,expected_date,status,zone',
                'assigned_to' => 'eq.' . $current_user,
                'status'      => 'in.(Scheduled,In Transit,Delayed)',
                'order'       => 'expected_date.asc',
                'limit'       => '20',
            ]);

            // ALMS: Maintenance schedules assigned to this user
            $alms_maint = sd_sb('alms_maintenance_schedules', 'GET', [
                'select'  => 'id,schedule_id,asset_id,asset_name,type,next_due,status,zone',
                'tech_id' => 'eq.' . ($uid ?? 'none'),
                'status'  => 'in.(Scheduled,In Progress,Overdue)',
                'order'   => 'next_due.asc',
                'limit'   => '20',
            ]);

            // ALMS: Repair logs assigned to this user
            $alms_repairs = sd_sb('alms_repair_logs', 'GET', [
                'select'      => 'id,log_id,asset_id,asset_name,issue,status,date_reported,zone',
                'tech_user_id'=> 'eq.' . ($uid ?? 'none'),
                'status'      => 'in.(Reported,In Progress,Escalated)',
                'order'       => 'date_reported.asc',
                'limit'       => '20',
            ]);

            // DTRS: Documents assigned to this user
            $dtrs_docs = sd_sb('dtrs_documents', 'GET', [
                'select'      => 'id,doc_id,title,doc_type,status,priority,needs_validation,created_at',
                'assigned_to' => 'eq.' . $current_user,
                'status'      => 'in.(Registered,In Transit,Processing)',
                'order'       => 'created_at.desc',
                'limit'       => '20',
            ]);

            // DTRS: Routes assigned to this user
            $dtrs_routes = sd_sb('dtrs_routes', 'GET', [
                'select'  => 'id,route_id,doc_name,from_dept,to_dept,route_type,priority,status,due_date',
                'assignee'=> 'eq.' . $current_user,
                'status'  => 'in.(In Transit,Received)',
                'order'   => 'due_date.asc',
                'limit'   => '20',
            ]);

            sd_ok([
                'sws'  => ['txns' => $sws_txns, 'counts' => $sws_counts],
                'psm'  => ['prs'  => $psm_prs],
                'plt'  => ['assigns' => $plt_assigns, 'deliveries' => $plt_deliveries],
                'alms' => ['maint' => $alms_maint, 'repairs' => $alms_repairs],
                'dtrs' => ['docs'  => $dtrs_docs, 'routes' => $dtrs_routes],
            ]);
        }

        // ── GET: my MTD transaction stats ──────────────────────────────────────
        if ($api === 'mtd-stats' && $method === 'GET') {
            $mtdStart = date('Y-m-01');
            $today    = date('Y-m-d');

            // SWS transactions this month in this zone processed by current user
            $sws_mtd = sd_sb('sws_transactions', 'GET', [
                'select'       => 'id,type,status,date_time',
                'zone'         => 'eq.' . $current_zone,
                'status'       => 'eq.Completed',
                'date_time'    => 'gte.' . $mtdStart . 'T00:00:00',
                'order'        => 'date_time.asc',
            ]);

            // ALMS maintenance completed by this user MTD
            $alms_mtd = sd_sb('alms_maintenance_schedules', 'GET', [
                'select'  => 'id,status,updated_at',
                'tech_id' => 'eq.' . ($current_user_id ?? 'none'),
                'status'  => 'eq.Completed',
                'updated_at' => 'gte.' . $mtdStart . 'T00:00:00',
            ]);

            // DTRS documents processed MTD
            $dtrs_mtd = sd_sb('dtrs_documents', 'GET', [
                'select'      => 'id,status,updated_at',
                'assigned_to' => 'eq.' . $current_user,
                'status'      => 'eq.Completed',
                'updated_at'  => 'gte.' . $mtdStart . 'T00:00:00',
            ]);

            // Build daily breakdown for SWS (last 14 days)
            $daily = [];
            for ($i = 13; $i >= 0; $i--) {
                $day = date('Y-m-d', strtotime("-$i days"));
                $cnt = count(array_filter($sws_mtd, fn($t) => substr($t['date_time'], 0, 10) === $day));
                $daily[] = ['date' => $day, 'label' => date('d', strtotime($day)), 'count' => $cnt];
            }

            sd_ok([
                'sws_mtd'    => count($sws_mtd),
                'alms_mtd'   => count($alms_mtd),
                'dtrs_mtd'   => count($dtrs_mtd),
                'total_mtd'  => count($sws_mtd) + count($alms_mtd) + count($dtrs_mtd),
                'daily'      => $daily,
            ]);
        }

        // ── GET: my task history ────────────────────────────────────────────────
        if ($api === 'history' && $method === 'GET') {
            $limit  = (int)($_GET['limit']  ?? 30);
            $module = trim($_GET['module'] ?? '');
            $status = trim($_GET['status'] ?? '');

            $hist = [];

            // SWS completed transactions
            if (!$module || $module === 'SWS') {
                $q = [
                    'select'   => 'id,txn_id,item_name,type,status,ref_doc,zone,date_time,updated_at',
                    'zone'     => 'eq.' . $current_zone,
                    'status'   => 'eq.Completed',
                    'order'    => 'updated_at.desc',
                    'limit'    => '20',
                ];
                $rows = sd_sb('sws_transactions', 'GET', $q);
                foreach ($rows as $r) {
                    $hist[] = [
                        'id'      => $r['txn_id'],
                        'desc'    => ($r['type']==='in'?'Stock In':'Stock Out') . ' — ' . ($r['item_name']??''),
                        'module'  => 'SWS',
                        'started' => $r['date_time']  ?? '',
                        'done'    => $r['updated_at'] ?? '',
                        'status'  => 'Completed',
                    ];
                }
            }

            // ALMS completed maintenance
            if (!$module || $module === 'ALMS') {
                $q = [
                    'select'  => 'id,schedule_id,asset_name,type,status,updated_at,created_at',
                    'tech_id' => 'eq.' . ($current_user_id ?? 'none'),
                    'status'  => 'eq.Completed',
                    'order'   => 'updated_at.desc',
                    'limit'   => '20',
                ];
                $rows = sd_sb('alms_maintenance_schedules', 'GET', $q);
                foreach ($rows as $r) {
                    $hist[] = [
                        'id'      => $r['schedule_id'],
                        'desc'    => $r['type'] . ' — ' . ($r['asset_name']??''),
                        'module'  => 'ALMS',
                        'started' => $r['created_at']  ?? '',
                        'done'    => $r['updated_at']  ?? '',
                        'status'  => 'Completed',
                    ];
                }
            }

            // DTRS processed documents
            if (!$module || $module === 'DTRS') {
                $q = [
                    'select'      => 'id,doc_id,title,doc_type,status,created_at,updated_at',
                    'assigned_to' => 'eq.' . $current_user,
                    'status'      => 'in.(Completed,Archived)',
                    'order'       => 'updated_at.desc',
                    'limit'       => '20',
                ];
                $rows = sd_sb('dtrs_documents', 'GET', $q);
                foreach ($rows as $r) {
                    $hist[] = [
                        'id'      => $r['doc_id'],
                        'desc'    => ($r['doc_type']??'Document') . ' — ' . ($r['title']??''),
                        'module'  => 'DTRS',
                        'started' => $r['created_at']  ?? '',
                        'done'    => $r['updated_at']  ?? '',
                        'status'  => 'Completed',
                    ];
                }
            }

            // PSM submitted PRs
            if (!$module || $module === 'PSM') {
                $q = [
                    'select'          => 'id,pr_number,purpose,status,created_at,updated_at',
                    'created_user_id' => 'eq.' . ($current_user_id ?? 'none'),
                    'status'          => 'nin.(Draft,Returned)',
                    'order'           => 'updated_at.desc',
                    'limit'           => '15',
                ];
                $rows = sd_sb('psm_purchase_requests', 'GET', $q);
                foreach ($rows as $r) {
                    $hist[] = [
                        'id'      => $r['pr_number'],
                        'desc'    => 'PR — ' . ($r['purpose'] ?? 'Purchase Request'),
                        'module'  => 'PSM',
                        'started' => $r['created_at']  ?? '',
                        'done'    => $r['updated_at']  ?? '',
                        'status'  => 'Submitted',
                    ];
                }
            }

            // Sort all by done desc
            usort($hist, fn($a,$b) => strcmp($b['done'],$a['done']));

            sd_ok(array_slice($hist, 0, $limit));
        }

        // ── POST: start a maintenance task (update status to In Progress) ──────
        if ($api === 'start-maint' && $method === 'POST') {
            $b      = sd_body();
            $sid    = (int)($b['scheduleId'] ?? 0);
            $note   = trim($b['note'] ?? '');
            $now    = date('Y-m-d H:i:s');

            if (!$sid) sd_err('Missing scheduleId', 400);

            $rows = sd_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,schedule_id,status,asset_name',
                'id'     => 'eq.' . $sid, 'limit' => '1',
            ]);
            if (empty($rows)) sd_err('Schedule not found', 404);
            $s = $rows[0];
            if (!in_array($s['status'], ['Scheduled','Overdue'], true))
                sd_err('Task cannot be started in current status: ' . $s['status'], 400);

            sd_sb('alms_maintenance_schedules', 'PATCH', ['id' => 'eq.' . $sid], [
                'status'     => 'In Progress',
                'updated_at' => $now,
            ]);

            // Audit log — same pattern as disposal backend
            sd_sb('alms_maintenance_audit_log', 'POST', [], [[
                'schedule_id'   => $sid,
                'action_label'  => 'Task Started by Staff',
                'actor_name'    => $current_user,
                'actor_role'    => $current_role,
                'actor_user_id' => $current_user_id,
                'note'          => $note ?: 'Staff marked task as In Progress.',
                'icon'          => 'bx-play-circle',
                'css_class'     => 'ad-o',
                'is_super_admin'=> false,
                'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                'occurred_at'   => $now,
            ]]);

            $updated = sd_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,schedule_id,asset_name,type,status,next_due,zone',
                'id'     => 'eq.' . $sid, 'limit' => '1',
            ]);
            sd_ok($updated[0] ?? []);
        }

        // ── POST: complete a maintenance task ─────────────────────────────────
        if ($api === 'complete-maint' && $method === 'POST') {
            $b    = sd_body();
            $sid  = (int)($b['scheduleId'] ?? 0);
            $note = trim($b['note'] ?? '');
            $now  = date('Y-m-d H:i:s');

            if (!$sid) sd_err('Missing scheduleId', 400);

            $rows = sd_sb('alms_maintenance_schedules', 'GET', [
                'select' => 'id,schedule_id,status,asset_name,freq',
                'id'     => 'eq.' . $sid, 'limit' => '1',
            ]);
            if (empty($rows)) sd_err('Schedule not found', 404);
            $s = $rows[0];
            if ($s['status'] !== 'In Progress')
                sd_err('Task must be In Progress to complete.', 400);

            // Calculate next due date based on frequency
            $freqMap = ['Daily'=>1,'Weekly'=>7,'Monthly'=>30,'Quarterly'=>91,'Annual'=>365];
            $days    = $freqMap[$s['freq'] ?? 'Monthly'] ?? 30;
            $nextDue = date('Y-m-d', strtotime("+$days days"));

            sd_sb('alms_maintenance_schedules', 'PATCH', ['id' => 'eq.' . $sid], [
                'status'     => 'Completed',
                'last_done'  => date('Y-m-d'),
                'next_due'   => $nextDue,
                'updated_at' => $now,
            ]);

            sd_sb('alms_maintenance_audit_log', 'POST', [], [[
                'schedule_id'   => $sid,
                'action_label'  => 'Task Completed by Staff',
                'actor_name'    => $current_user,
                'actor_role'    => $current_role,
                'actor_user_id' => $current_user_id,
                'note'          => $note ?: 'Maintenance task completed successfully.',
                'icon'          => 'bx-check-circle',
                'css_class'     => 'ad-a',
                'is_super_admin'=> false,
                'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
                'occurred_at'   => $now,
            ]]);

            sd_ok(['nextDue' => $nextDue, 'scheduleId' => $s['schedule_id']]);
        }

        // ── POST: complete SWS transaction ────────────────────────────────────
        if ($api === 'complete-txn' && $method === 'POST') {
            $b    = sd_body();
            $tid  = trim($b['txnId'] ?? '');
            $note = trim($b['note']  ?? '');
            $now  = date('Y-m-d H:i:s');
            if (!$tid) sd_err('Missing txnId', 400);

            $rows = sd_sb('sws_transactions', 'GET', [
                'select' => 'id,txn_id,item_id,item_code,item_name,qty,type,status,zone',
                'txn_id' => 'eq.' . $tid, 'limit' => '1',
            ]);
            if (empty($rows)) sd_err('Transaction not found', 404);
            $txn = $rows[0];
            if (!in_array($txn['status'], ['Pending','Processing'], true))
                sd_err('Cannot complete — status is: ' . $txn['status'], 400);

            // Update transaction to Completed
            sd_sb('sws_transactions', 'PATCH', ['txn_id' => 'eq.' . $tid], [
                'status'     => 'Completed',
                'updated_at' => $now,
            ]);

            // Update inventory stock
            if ($txn['item_id']) {
                $inv = sd_sb('sws_inventory', 'GET', [
                    'select' => 'id,stock', 'id' => 'eq.' . $txn['item_id'], 'limit' => '1',
                ]);
                if (!empty($inv)) {
                    $oldStock = (int)$inv[0]['stock'];
                    $newStock = $txn['type'] === 'in'
                        ? $oldStock + (int)$txn['qty']
                        : max(0, $oldStock - (int)$txn['qty']);

                    sd_sb('sws_inventory', 'PATCH', ['id' => 'eq.' . $txn['item_id']], [
                        'stock'      => $newStock,
                        'updated_at' => $now,
                    ]);

                    // Inventory audit — matching sws pattern
                    sd_sb('sws_inventory_audit', 'POST', [], [[
                        'item_id'    => (int)$txn['item_id'],
                        'action'     => $txn['type'] === 'in' ? 'add' : 'transfer_out',
                        'detail'     => "TXN {$tid} completed by {$current_user}." . ($note ? " Note: $note" : ''),
                        'old_stock'  => $oldStock,
                        'new_stock'  => $newStock,
                        'actor_name' => $current_user,
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'occurred_at'=> $now,
                    ]]);
                }
            }

            // TXN audit
            sd_sb('sws_txn_audit', 'POST', [], [[
                'txn_id'     => $tid,
                'action'     => 'completed',
                'detail'     => 'Completed by staff ' . $current_user . ($note ? ". Note: $note" : '.'),
                'actor_name' => $current_user,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'occurred_at'=> $now,
            ]]);

            sd_ok(['txnId' => $tid, 'status' => 'Completed']);
        }

        // ── POST: submit PR draft ─────────────────────────────────────────────
        if ($api === 'submit-pr' && $method === 'POST') {
            $b    = sd_body();
            $prid = (int)($b['prId'] ?? 0);
            $note = trim($b['note']  ?? '');
            $now  = date('Y-m-d H:i:s');
            if (!$prid) sd_err('Missing prId', 400);

            $rows = sd_sb('psm_purchase_requests', 'GET', [
                'select' => 'id,pr_number,status,created_user_id',
                'id'     => 'eq.' . $prid,
                'created_user_id' => 'eq.' . ($current_user_id ?? 'none'),
                'limit'  => '1',
            ]);
            if (empty($rows)) sd_err('PR not found or not yours', 404);
            $pr = $rows[0];
            if (!in_array($pr['status'], ['Draft','Returned'], true))
                sd_err('PR cannot be submitted from status: ' . $pr['status'], 400);

            sd_sb('psm_purchase_requests', 'PATCH', ['id' => 'eq.' . $prid], [
                'status'     => 'Pending',
                'updated_at' => $now,
            ]);

            sd_sb('psm_pr_audit_log', 'POST', [], [[
                'pr_id'       => $prid,
                'action_label'=> 'PR Submitted by Staff',
                'actor_name'  => $current_user,
                'actor_role'  => $current_role,
                'remarks'     => $note ?: 'Submitted for review.',
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'icon'        => 'bx-send',
                'css_class'   => 'bx-s',
                'is_super_admin' => false,
                'occurred_at' => $now,
            ]]);

            sd_ok(['prId' => $prid, 'prNumber' => $pr['pr_number'], 'status' => 'Pending']);
        }

        // ── POST: complete routing task in DTRS ───────────────────────────────
        if ($api === 'complete-route' && $method === 'POST') {
            $b      = sd_body();
            $rid    = (int)($b['routeId'] ?? 0);
            $note   = trim($b['note']    ?? '');
            $now    = date('Y-m-d H:i:s');
            if (!$rid) sd_err('Missing routeId', 400);

            $rows = sd_sb('dtrs_routes', 'GET', [
                'select'  => 'id,route_id,doc_name,status,assignee',
                'id'      => 'eq.' . $rid,
                'assignee'=> 'eq.' . $current_user,
                'limit'   => '1',
            ]);
            if (empty($rows)) sd_err('Route not found or not assigned to you', 404);
            $route = $rows[0];
            if (!in_array($route['status'], ['In Transit','Received'], true))
                sd_err('Route cannot be completed from status: ' . $route['status'], 400);

            sd_sb('dtrs_routes', 'PATCH', ['id' => 'eq.' . $rid], [
                'status'     => 'Completed',
                'updated_at' => $now,
            ]);

            // Add route history step
            sd_sb('dtrs_route_history', 'POST', [], [[
                'route_id'   => $rid,
                'role_label' => 'Completed — ' . $current_user,
                'actor_name' => $current_user,
                'step_type'  => 'rtd-done',
                'icon'       => 'bx-check',
                'note'       => $note ?: 'Task completed.',
                'occurred_at'=> $now,
            ]]);

            // Route audit
            sd_sb('dtrs_route_audit', 'POST', [], [[
                'route_id'     => $rid,
                'action_label' => 'Route Completed by Staff',
                'actor_name'   => $current_user,
                'actor_role'   => $current_role,
                'dot_class'    => 'dot-g',
                'is_super_admin'=> false,
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
                'occurred_at'  => $now,
            ]]);

            sd_ok(['routeId' => $rid, 'routeCode' => $route['route_id'], 'status' => 'Completed']);
        }

        // ── POST: escalate to manager ─────────────────────────────────────────
        if ($api === 'escalate' && $method === 'POST') {
            $b       = sd_body();
            $issueType = trim($b['issueType'] ?? 'Other');
            $desc    = trim($b['description'] ?? '');
            $now     = date('Y-m-d H:i:s');
            if (!$desc) sd_err('Description is required', 400);

            // Write to notifications table
            $notifId = 'ALT-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            sd_sb('notifications', 'POST', [], [[
                'notif_id'    => $notifId,
                'category'    => 'Assignment Overdue',
                'module'      => 'System',
                'severity'    => 'High',
                'title'       => "Staff Escalation — {$issueType}",
                'description' => "[{$current_user} · {$current_zone}] {$desc}",
                'zone'        => $current_zone,
                'status'      => 'escalated',
                'source_table'=> 'staff_dashboard',
                'escalated_by'=> $current_user,
                'escalated_at'=> $now,
                'escalate_priority' => 'High',
                'escalate_remarks'  => $desc,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]]);

            sd_ok(['notifId' => $notifId, 'message' => 'Escalation submitted to Manager.']);
        }

        sd_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        sd_err('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── HTML RENDER ───────────────────────────────────────────────────────────────
$root_html = $_SERVER['DOCUMENT_ROOT'];
include $root_html . '/includes/superadmin_sidebar.php';
include $root_html . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Dashboard — Staff</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
/* ── ROOT TOKENS ─────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;}
#mainContent,#taskModal,#escalModal,.sd-toasts{
  --s:#fff;--bg:#F4F7F4;--bd:rgba(46,125,50,.13);--bdm:rgba(46,125,50,.26);
  --t1:#1A2E1C;--t2:#5D6F62;--t3:#9EB0A2;
  --hbg:#F0FAF0;
  --grn:#2E7D32;--gdk:#1B5E20;--glt:#E8F5E9;
  --red:#DC2626;--amb:#D97706;--blu:#2563EB;--tel:#0D9488;--pur:#7C3AED;
  --shsm:0 1px 4px rgba(46,125,50,.08);
  --shmd:0 4px 20px rgba(46,125,50,.12);
  --shlg:0 24px 60px rgba(0,0,0,.2);
  --rad:12px;--tr:all .17s cubic-bezier(.4,0,.2,1);
  font-family:'IBM Plex Sans',sans-serif;
}
/* ── LAYOUT ──────────────────────────────────────────────── */
.sd-wrap{max-width:1480px;margin:0 auto;padding:0 0 5rem;}

/* ── PAGE HEADER ─────────────────────────────────────────── */
.sd-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;animation:UP .4s both;}
.sd-ph .ey{font-size:10.5px;font-weight:600;letter-spacing:.15em;text-transform:uppercase;color:var(--grn);margin-bottom:4px;}
.sd-ph h1{font-size:24px;font-weight:700;color:var(--t1);line-height:1.2;}
.sd-ph-r{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.zone-pill{display:inline-flex;align-items:center;gap:5px;background:var(--glt);color:var(--grn);border:1.5px solid rgba(46,125,50,.3);font-size:11px;font-weight:700;padding:5px 12px;border-radius:20px;}
#liveClock{font-family:'IBM Plex Mono',monospace;font-size:11.5px;color:var(--t3);}

/* ── BUTTONS ─────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:6px;font-family:'IBM Plex Sans',sans-serif;font-size:12.5px;font-weight:600;padding:8px 16px;border-radius:9px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3);}
.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}
.btn-ghost:hover{background:var(--hbg);color:var(--t1);}
.btn-warn{background:#FFFBEB;color:#92400E;border:1px solid #FCD34D;}
.btn-warn:hover{background:#FEF3C7;}
.btn-sm{font-size:11.5px;padding:6px 12px;}
.btn-xs{font-size:11px;padding:4px 9px;border-radius:6px;}
.btn:disabled{opacity:.4;pointer-events:none;}

/* ── SECTION DIVIDER ─────────────────────────────────────── */
.sec-div{display:flex;align-items:center;gap:10px;margin:22px 0 14px;animation:UP .4s both;}
.sec-div span{font-size:10.5px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--t3);white-space:nowrap;}
.sec-div::after{content:'';flex:1;height:1px;background:var(--bd);}

/* ── LOADING SKELETON ─────────────────────────────────────── */
.skel{background:linear-gradient(90deg,#E5E7EB 25%,#F3F4F6 50%,#E5E7EB 75%);background-size:200% 100%;animation:SKEL 1.4s ease infinite;border-radius:6px;}
@keyframes SKEL{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── KPI BAR ─────────────────────────────────────────────── */
.kpi-bar{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;animation:UP .4s .04s both;}
.kpi-card{background:var(--s);border:1px solid var(--bd);border-radius:14px;padding:16px 18px;box-shadow:var(--shsm);position:relative;overflow:hidden;}
.kpi-card::after{content:'';position:absolute;top:0;right:0;width:3px;height:100%;border-radius:0 14px 14px 0;}
.kc-grn::after{background:var(--grn)}.kc-blu::after{background:var(--blu)}.kc-amb::after{background:var(--amb)}.kc-red::after{background:var(--red)}.kc-pur::after{background:var(--pur)}.kc-tel::after{background:var(--tel)}
.kpi-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--t3);margin-bottom:6px;}
.kpi-val{font-size:26px;font-weight:700;color:var(--t1);line-height:1;margin-bottom:4px;font-family:'IBM Plex Mono',monospace;}
.kpi-val.sm{font-size:16px;}
.kpi-sub{display:flex;align-items:center;gap:4px;font-size:11px;color:var(--t2);flex-wrap:wrap;gap:4px;margin-top:4px;}
.kpi-bar-fill{height:3px;background:#E5E7EB;border-radius:2px;margin-top:8px;overflow:hidden;}
.kpi-bar-inner{height:100%;border-radius:2px;transition:width .6s ease;}
.chip{display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;white-space:nowrap;}
.chip::before{content:'';width:4px;height:4px;border-radius:50%;background:currentColor;flex-shrink:0;}
.c-grn{background:#DCFCE7;color:#166534}.c-amb{background:#FEF3C7;color:#92400E}.c-red{background:#FEE2E2;color:#991B1B}.c-blu{background:#EFF6FF;color:#1D4ED8}.c-tel{background:#CCFBF1;color:#0F766E}.c-gry{background:#F3F4F6;color:#374151}.c-pur{background:#F5F3FF;color:#5B21B6}

/* ── MODULE ACTIVITY STRIP ───────────────────────────────── */
.mod-strip{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;animation:UP .4s .06s both;}
.mac{background:var(--s);border:1px solid var(--bd);border-radius:13px;padding:14px;box-shadow:var(--shsm);cursor:pointer;transition:var(--tr);display:flex;flex-direction:column;gap:10px;}
.mac:hover{transform:translateY(-2px);box-shadow:var(--shmd);}
.mac.active{border-color:var(--grn);box-shadow:0 0 0 2px rgba(46,125,50,.15);}
.mac-top{display:flex;align-items:flex-start;justify-content:space-between;}
.mac-ic{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.ic-b{background:#EFF6FF;color:var(--blu)}.ic-t{background:#CCFBF1;color:var(--tel)}.ic-a{background:#FEF3C7;color:var(--amb)}.ic-r{background:#FEE2E2;color:var(--red)}.ic-g{background:#DCFCE7;color:#166534}.ic-p{background:#F5F3FF;color:var(--pur)}.ic-d{background:#F3F4F6;color:#374151}
.mac-name{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);}
.mac-stat{font-size:28px;font-weight:700;color:var(--t1);line-height:1;font-family:'IBM Plex Mono',monospace;}
.mac-sub{font-size:11px;color:var(--t2);}
.mac-prog{height:3px;background:#E5E7EB;border-radius:2px;overflow:hidden;}
.mac-prog-bar{height:100%;border-radius:2px;transition:width .5s ease;}

/* ── MAIN GRID ───────────────────────────────────────────── */
.sd-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px;}
.sd-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;}
.sd-full{margin-bottom:16px;}

/* ── CARD ────────────────────────────────────────────────── */
.card{background:var(--s);border:1px solid var(--bd);border-radius:16px;overflow:hidden;box-shadow:var(--shsm);animation:UP .4s both;}
.card-hd{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:1px solid var(--bd);background:var(--bg);}
.card-hd-l{display:flex;align-items:center;gap:9px;}
.card-hd-ic{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.card-hd-t{font-size:13px;font-weight:700;color:var(--t1);}
.card-hd-s{font-size:11px;color:var(--t2);margin-top:1px;}
.card-hd-r{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.card-body{padding:16px 18px;}

/* ── TASK QUEUE ──────────────────────────────────────────── */
.task-item{display:flex;gap:11px;align-items:flex-start;padding:11px 0;border-bottom:1px solid var(--bd);cursor:default;}
.task-item:last-child{border-bottom:none;padding-bottom:0;}
.task-item:first-child{padding-top:0;}
.task-item:hover{background:var(--hbg);margin:0 -18px;padding:11px 18px;}
.task-pri{width:26px;height:26px;border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;}
.tp-r{background:#FEE2E2;color:#DC2626}.tp-a{background:#FEF3C7;color:#D97706}.tp-g{background:#DCFCE7;color:#166534}.tp-b{background:#EFF6FF;color:#2563EB}
.task-body{flex:1;min-width:0;}
.task-body .tt{font-size:12.5px;font-weight:600;color:var(--t1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.task-body .ts{font-size:10.5px;color:var(--t3);margin-top:2px;font-family:'IBM Plex Mono',monospace;}
.task-meta{display:flex;align-items:center;gap:4px;margin-top:5px;flex-wrap:wrap;}
.task-acts{display:flex;gap:4px;flex-shrink:0;align-items:center;}
.urg-red   .task-body .tt{color:#991B1B;}
.urg-yellow .task-body .tt{color:#92400E;}

/* ── ALERTS PANEL ────────────────────────────────────────── */
.alert-item{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--bd);}
.alert-item:last-child{border-bottom:none;padding-bottom:0;}
.alert-item:first-child{padding-top:0;}
.alert-dot{width:26px;height:26px;border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;}
.alert-body{flex:1;min-width:0;}
.alert-body .ab{font-size:12px;font-weight:500;color:var(--t1);line-height:1.4;}
.alert-body .at{font-size:10.5px;color:var(--t3);margin-top:3px;font-family:'IBM Plex Mono',monospace;}
.alert-ts{font-family:'IBM Plex Mono',monospace;font-size:10px;color:var(--t3);flex-shrink:0;white-space:nowrap;padding-left:6px;}

/* ── PERF METERS ─────────────────────────────────────────── */
.perf-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--bd);}
.perf-row:last-child{border-bottom:none;padding-bottom:0;}
.perf-row:first-child{padding-top:0;}
.perf-label{font-size:12px;font-weight:500;color:var(--t1);flex:1;}
.perf-track{flex:1;height:5px;background:#E5E7EB;border-radius:3px;overflow:hidden;}
.perf-bar{height:100%;border-radius:3px;transition:width .5s ease;}
.perf-num{font-family:'IBM Plex Mono',monospace;font-size:11.5px;font-weight:600;width:38px;text-align:right;flex-shrink:0;}

/* ── BAR CHART ───────────────────────────────────────────── */
.bar-chart{display:flex;align-items:flex-end;gap:5px;height:100px;padding-top:8px;}
.bc-col{display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;}
.bc-bar{width:100%;border-radius:4px 4px 0 0;min-height:3px;cursor:pointer;transition:filter .15s;}
.bc-bar:hover{filter:brightness(.85);}
.bc-val{font-size:9px;font-family:'IBM Plex Mono',monospace;font-weight:600;color:var(--t1);}
.bc-lbl{font-size:9px;color:var(--t3);margin-top:4px;}

/* ── REPORT TABLE ────────────────────────────────────────── */
.sel{font-family:'IBM Plex Sans',sans-serif;font-size:12px;padding:7px 24px 7px 9px;border:1px solid var(--bdm);border-radius:8px;background:var(--s);color:var(--t1);cursor:pointer;outline:none;appearance:none;transition:var(--tr);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 7px center;}
.sel:focus{border-color:var(--grn);outline:none;}
.rpt-tabs{display:flex;gap:3px;padding:12px 18px 0;border-bottom:1px solid var(--bd);}
.rpt-tab{font-family:'IBM Plex Sans',sans-serif;font-size:12px;font-weight:600;padding:7px 14px;border-radius:7px 7px 0 0;cursor:pointer;border:none;background:transparent;color:var(--t2);transition:var(--tr);}
.rpt-tab.active{background:var(--grn);color:#fff;}
.rpt-tab:hover:not(.active){background:var(--hbg);color:var(--t1);}
.rpt-panel{display:none;padding:18px;}
.rpt-panel.active{display:block;}
.rpt-filters{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px;}
.sa-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.sa-tbl thead th{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:8px 10px;background:var(--bg);border-bottom:1px solid var(--bd);text-align:left;white-space:nowrap;}
.sa-tbl tbody tr{border-bottom:1px solid var(--bd);}
.sa-tbl tbody tr:last-child{border-bottom:none;}
.sa-tbl tbody tr:hover{background:var(--hbg);}
.sa-tbl tbody td{padding:9px 10px;vertical-align:middle;}

/* ── SCOPE NOTICE ────────────────────────────────────────── */
.scope-notice{display:flex;align-items:center;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:9px;padding:9px 13px;font-size:12px;font-weight:500;color:#92400E;margin-bottom:14px;}
.scope-notice i{font-size:15px;flex-shrink:0;color:var(--amb);}

/* ── MODAL ───────────────────────────────────────────────── */
#taskModal,#escalModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9100;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s;}
#taskModal.on,#escalModal.on{opacity:1;pointer-events:all;}
.modal-box{background:#fff;border-radius:15px;width:460px;max-width:100%;box-shadow:var(--shlg);overflow:hidden;}
.mhd{padding:18px 20px 14px;border-bottom:1px solid var(--bd);background:var(--bg);display:flex;align-items:flex-start;justify-content:space-between;}
.mhd-t{font-size:15px;font-weight:700;color:var(--t1);}
.mhd-s{font-size:11.5px;color:var(--t2);margin-top:2px;}
.m-cl{width:30px;height:30px;border-radius:7px;border:1px solid var(--bdm);background:#fff;cursor:pointer;display:grid;place-content:center;font-size:17px;color:var(--t2);transition:var(--tr);}
.m-cl:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA;}
.mbody{padding:18px 20px;display:flex;flex-direction:column;gap:12px;max-height:65vh;overflow-y:auto;}
.mbody::-webkit-scrollbar{width:4px;}.mbody::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px;}
.mft{padding:12px 20px;border-top:1px solid var(--bd);background:var(--bg);display:flex;gap:8px;justify-content:flex-end;}
.fg{display:flex;flex-direction:column;gap:5px;}
.fg label{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2);}
.fg input,.fg select,.fg textarea{font-family:'IBM Plex Sans',sans-serif;font-size:12.5px;padding:9px 11px;border:1px solid var(--bdm);border-radius:8px;background:#fff;color:var(--t1);outline:none;transition:var(--tr);width:100%;}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.1);}
.fg textarea{resize:vertical;min-height:64px;}
.fn{font-size:11px;color:var(--t2);background:var(--bg);border-radius:7px;padding:8px 11px;border:1px solid var(--bd);}

/* ── TOAST ───────────────────────────────────────────────── */
.sd-toasts{position:fixed;bottom:26px;right:26px;z-index:9999;display:flex;flex-direction:column;gap:9px;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:11px 16px;border-radius:9px;font-size:12.5px;font-weight:500;display:flex;align-items:center;gap:9px;box-shadow:var(--shlg);pointer-events:all;min-width:200px;animation:TIN .28s ease;}
.toast.ts{background:var(--grn)}.toast.tw{background:var(--amb)}.toast.td{background:var(--red)}.toast.out{animation:TOUT .28s ease forwards;}

/* ── LOADING STATES ──────────────────────────────────────── */
.spinner{width:18px;height:18px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:SPIN .7s linear infinite;flex-shrink:0;}
@keyframes SPIN{to{transform:rotate(360deg)}}

/* ── ANIMATIONS ──────────────────────────────────────────── */
@keyframes UP{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}

/* ── RESPONSIVE ──────────────────────────────────────────── */
@media(max-width:1200px){.kpi-bar{grid-template-columns:repeat(3,1fr)}.mod-strip{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){.sd-grid{grid-template-columns:1fr}.sd-grid-3{grid-template-columns:1fr}.kpi-bar{grid-template-columns:1fr 1fr}.mod-strip{grid-template-columns:1fr 1fr}}
@media(max-width:540px){.kpi-bar{grid-template-columns:1fr}.mod-strip{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="sd-wrap">

  <!-- PAGE HEADER -->
  <div class="sd-ph">
    <div>
      <p class="ey">Logistics 1 — Staff Dashboard</p>
      <h1 id="greeting">My Tasks &amp; Activities</h1>
    </div>
    <div class="sd-ph-r">
      <span id="liveClock"></span>
      <div class="zone-pill"><i class="bx bx-map-pin"></i> <span id="zoneLabel"><?= htmlspecialchars($current_zone) ?></span></div>
      <button class="btn btn-warn btn-sm" id="escalBtn"><i class="bx bx-up-arrow-circle"></i> Escalate Issue</button>
      <button class="btn btn-ghost btn-sm" onclick="exportMyReport()"><i class="bx bx-export"></i> Export</button>
    </div>
  </div>

  <!-- KPI BAR -->
  <div class="kpi-bar" id="kpiBar">
    <?php for($i=0;$i<5;$i++): ?>
    <div class="kpi-card"><div class="skel" style="height:18px;width:60%;margin-bottom:8px"></div><div class="skel" style="height:30px;width:40%"></div></div>
    <?php endfor; ?>
  </div>

  <!-- MODULE ACTIVITY -->
  <div class="mod-strip" id="modStrip">
    <?php foreach(['SWS','PSM','PLT','ALMS','DTRS'] as $mod): ?>
    <div class="mac"><div class="mac-top"><div class="mac-ic ic-d"><i class="bx bx-loader-circle"></i></div></div><div class="skel" style="height:30px;width:40%"></div><div class="skel" style="height:12px;width:80%;margin-top:4px"></div></div>
    <?php endforeach; ?>
  </div>

  <!-- TASK QUEUE + ALERTS -->
  <div class="sd-grid">
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-g"><i class="bx bx-list-check"></i></div>
          <div><div class="card-hd-t">My Task Queue</div><div class="card-hd-s">Live from all modules — start, update, complete</div></div>
        </div>
        <div class="card-hd-r">
          <select class="sel" id="taskModFilter" onchange="renderTasks()">
            <option value="">All Modules</option>
            <option>SWS</option><option>PSM</option><option>PLT</option><option>ALMS</option><option>DTRS</option>
          </select>
          <select class="sel" id="taskPriFilter" onchange="renderTasks()">
            <option value="">All Priority</option>
            <option value="red">Critical/Overdue</option>
            <option value="amb">Due Soon</option>
            <option value="grn">Normal</option>
          </select>
        </div>
      </div>
      <div class="card-body" id="taskQueue">
        <div style="display:flex;flex-direction:column;gap:12px">
          <?php for($i=0;$i<5;$i++): ?>
          <div class="skel" style="height:56px;width:100%;border-radius:8px"></div>
          <?php endfor; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-r"><i class="bx bx-bell"></i></div>
          <div><div class="card-hd-t">My Alerts</div><div class="card-hd-s">Overdue &amp; compliance reminders</div></div>
        </div>
        <span class="chip c-red" id="alertCount" style="margin-left:auto">Loading…</span>
      </div>
      <div class="card-body" id="alertPanel">
        <div style="display:flex;flex-direction:column;gap:10px">
          <?php for($i=0;$i<3;$i++): ?>
          <div class="skel" style="height:44px;width:100%;border-radius:7px"></div>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- PERFORMANCE + CHART -->
  <div class="sd-grid-3">
    <div class="card">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-p"><i class="bx bx-line-chart"></i></div>
          <div><div class="card-hd-t">Task Completion</div><div class="card-hd-s">Today vs target</div></div>
        </div>
      </div>
      <div class="card-body" id="perfPanel">
        <?php for($i=0;$i<4;$i++): ?>
        <div class="skel" style="height:20px;width:100%;border-radius:4px;margin-bottom:10px"></div>
        <?php endfor; ?>
      </div>
    </div>

    <div class="card" style="grid-column:span 2;">
      <div class="card-hd">
        <div class="card-hd-l">
          <div class="card-hd-ic ic-b"><i class="bx bx-bar-chart-alt-2"></i></div>
          <div><div class="card-hd-t">My MTD Transactions</div><div class="card-hd-s">Personal activity — last 14 days</div></div>
        </div>
        <span id="mtdTotal" class="chip c-grn" style="margin-left:auto">Loading…</span>
      </div>
      <div class="card-body">
        <div class="bar-chart" id="barChart">
          <?php for($i=0;$i<14;$i++): ?>
          <div class="bc-col"><div class="skel bc-bar" style="height:40px"></div></div>
          <?php endfor; ?>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:5px" id="chartLabels"></div>
      </div>
    </div>
  </div>

  <!-- REPORTS -->
  <div class="sec-div"><span>My Reports</span></div>
  <div class="scope-notice"><i class="bx bx-lock-alt"></i> Personal activity only. Team &amp; zone analytics require Manager or Admin access.</div>
  <div class="card sd-full">
    <div class="rpt-tabs">
      <button class="rpt-tab active" onclick="setRptTab('history',this)">Task History</button>
      <button class="rpt-tab" onclick="setRptTab('contrib',this)">Module Breakdown</button>
    </div>
    <div class="rpt-panel active" id="rpt-history">
      <div class="rpt-filters">
        <select class="sel" id="histModFilter" onchange="loadHistory()">
          <option value="">All Modules</option>
          <option>SWS</option><option>PSM</option><option>PLT</option><option>ALMS</option><option>DTRS</option>
        </select>
        <button class="btn btn-ghost btn-sm" onclick="loadHistory()"><i class="bx bx-refresh"></i> Refresh</button>
        <button class="btn btn-primary btn-sm" onclick="exportCSV()"><i class="bx bx-download"></i> Export CSV</button>
      </div>
      <div style="overflow-x:auto"><table class="sa-tbl"><thead><tr><th>Record ID</th><th>Description</th><th>Module</th><th>Date</th><th>Status</th></tr></thead><tbody id="histTbody"><tr><td colspan="5" style="padding:24px;text-align:center;color:var(--t3)">Loading…</td></tr></tbody></table></div>
    </div>
    <div class="rpt-panel" id="rpt-contrib">
      <div id="contribTable" style="padding-top:4px"></div>
    </div>
  </div>

</div>
</main>

<div class="sd-toasts" id="toastWrap"></div>

<!-- TASK ACTION MODAL -->
<div id="taskModal">
  <div class="modal-box">
    <div class="mhd">
      <div><div class="mhd-t" id="tmTitle">Task Action</div><div class="mhd-s" id="tmSub"></div></div>
      <button class="m-cl" onclick="closeModal('taskModal')"><i class="bx bx-x"></i></button>
    </div>
    <div class="mbody">
      <div class="fn" id="tmInfo"></div>
      <div class="fg">
        <label>Work Note (optional)</label>
        <textarea id="tmNote" placeholder="Describe what was done or any observations…"></textarea>
      </div>
    </div>
    <div class="mft">
      <button class="btn btn-ghost btn-sm" onclick="closeModal('taskModal')">Cancel</button>
      <button class="btn btn-primary btn-sm" id="tmConfirm"><i class="bx bx-check"></i> Confirm</button>
    </div>
  </div>
</div>

<!-- ESCALATION MODAL -->
<div id="escalModal">
  <div class="modal-box">
    <div class="mhd">
      <div><div class="mhd-t">Escalate Issue to Manager</div><div class="mhd-s">This will immediately notify your Manager and log the escalation.</div></div>
      <button class="m-cl" onclick="closeModal('escalModal')"><i class="bx bx-x"></i></button>
    </div>
    <div class="mbody">
      <div class="fg">
        <label>Issue Type</label>
        <select id="escalType">
          <option>Overdue Task — Need Extension</option>
          <option>Missing Equipment / Asset</option>
          <option>Safety Concern</option>
          <option>Unclear Task Instructions</option>
          <option>System / App Issue</option>
          <option>Other</option>
        </select>
      </div>
      <div class="fg">
        <label>Description *</label>
        <textarea id="escalDesc" placeholder="Describe the issue clearly and concisely…"></textarea>
      </div>
      <div class="fn"><i class="bx bx-info-circle"></i> Your Manager will be notified immediately. All escalations are logged to the system audit trail.</div>
    </div>
    <div class="mft">
      <button class="btn btn-ghost btn-sm" onclick="closeModal('escalModal')">Cancel</button>
      <button class="btn btn-warn btn-sm" id="escalConfirm"><i class="bx bx-up-arrow-circle"></i> Submit Escalation</button>
    </div>
  </div>
</div>

<script>
const API = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';
const MY_NAME = '<?= addslashes($current_user) ?>';
const MY_ZONE = '<?= addslashes($current_zone) ?>';

// ── API HELPERS (exact same pattern as disposal backend) ──────────────────────
async function apiFetch(path, opts={}) {
    const r = await fetch(path, {headers:{'Content-Type':'application/json'}, ...opts});
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p => apiFetch(p);
const apiPost = (p,b) => apiFetch(p, {method:'POST', body:JSON.stringify(b)});

// ── STATE ─────────────────────────────────────────────────────────────────────
let SUMMARY = null;
let MTD     = null;
let HISTORY = [];
let activeModal = null;
let pendingAction = null;

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc  = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fDT  = s => { if(!s) return '—'; const d=new Date(s); return d.toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); };
const fD   = s => { if(!s) return '—'; return new Date(s+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); };
const today= () => new Date().toISOString().split('T')[0];

// Module styling constants
const MOD_CLS  = {SWS:'c-blu',PSM:'c-tel',PLT:'c-pur',ALMS:'c-amb',DTRS:'c-red'};
const MOD_IC   = {SWS:'bx-package',PSM:'bx-receipt',PLT:'bx-trip',ALMS:'bx-wrench',DTRS:'bx-file-blank'};
const MOD_IC_CLS={SWS:'ic-b',PSM:'ic-t',PLT:'ic-p',ALMS:'ic-a',DTRS:'ic-r'};
const STAT_CLS = {'In Progress':'c-blu','Pending':'c-amb','Done':'c-grn','Completed':'c-grn','Submitted':'c-tel','Overdue':'c-red','Draft':'c-gry','Returned':'c-amb'};

// ── CLOCK ──────────────────────────────────────────────────────────────────────
function updateClock(){
    const d = new Date();
    document.getElementById('liveClock').textContent =
        d.toLocaleString('en-PH',{weekday:'short',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'});
    // greeting
    const h = d.getHours();
    const g = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
    const greet = document.getElementById('greeting');
    if (greet) greet.textContent = `${g}, ${MY_NAME.split(' ')[0]}`;
}
setInterval(updateClock, 1000);
updateClock();

// ── LOAD ALL ──────────────────────────────────────────────────────────────────
async function loadAll() {
    try {
        [SUMMARY, MTD] = await Promise.all([
            apiGet(API + '?api=summary'),
            apiGet(API + '?api=mtd-stats'),
        ]);
        renderKPIs();
        renderModStrip();
        renderTasks();
        renderAlerts();
        renderPerf();
        renderChart();
    } catch(e) {
        toast('Failed to load dashboard: ' + e.message, 'd');
    }
    loadHistory();
    renderContrib();
}

// ── KPI BAR ───────────────────────────────────────────────────────────────────
function renderKPIs() {
    const s = SUMMARY;
    const swsPending    = (s.sws.txns?.length||0) + (s.sws.counts?.length||0);
    const psmPending    = s.psm.prs?.length  || 0;
    const pltPending    = (s.plt.assigns?.length||0) + (s.plt.deliveries?.length||0);
    const almsPending   = (s.alms.maint?.length||0) + (s.alms.repairs?.length||0);
    const dtrsPending   = (s.dtrs.docs?.length||0)  + (s.dtrs.routes?.length||0);
    const total         = swsPending + psmPending + pltPending + almsPending + dtrsPending;

    const overdue = [
        ...(s.alms.maint?.filter(x=>x.status==='Overdue')||[]),
        ...(s.plt.assigns?.filter(x=>x.status==='Overdue')||[]),
    ].length;

    document.getElementById('kpiBar').innerHTML = `
        <div class="kpi-card kc-grn">
            <div class="kpi-label">Active Tasks</div>
            <div class="kpi-val">${total}</div>
            <div class="kpi-sub">
                ${overdue > 0 ? `<span class="chip c-red">${overdue} Overdue</span>` : ''}
                <span class="chip c-grn">Live from DB</span>
            </div>
        </div>
        <div class="kpi-card kc-blu">
            <div class="kpi-label">MTD Transactions</div>
            <div class="kpi-val">${MTD.total_mtd||0}</div>
            <div class="kpi-sub"><span class="chip c-blu">${MTD.sws_mtd||0} SWS</span><span class="chip c-amb">${MTD.alms_mtd||0} ALMS</span><span class="chip c-tel">${MTD.dtrs_mtd||0} DTRS</span></div>
        </div>
        <div class="kpi-card kc-tel">
            <div class="kpi-label">My Zone</div>
            <div class="kpi-val sm">${esc(MY_ZONE.split('—')[0].trim())}</div>
            <div class="kpi-sub"><i class="bx bx-map-pin" style="font-size:12px;color:var(--tel)"></i> ${esc(MY_ZONE)}</div>
        </div>
        <div class="kpi-card kc-amb">
            <div class="kpi-label">SWS Pending</div>
            <div class="kpi-val">${swsPending}</div>
            <div class="kpi-sub"><span class="chip c-blu">${s.sws.txns?.length||0} TXN</span><span class="chip c-amb">${s.sws.counts?.length||0} Counts</span></div>
        </div>
        <div class="kpi-card kc-pur">
            <div class="kpi-label">ALMS Tasks</div>
            <div class="kpi-val">${almsPending}</div>
            <div class="kpi-sub"><span class="chip c-amb">${s.alms.maint?.length||0} Maint</span><span class="chip c-red">${s.alms.repairs?.length||0} Repairs</span></div>
        </div>`;
}

// ── MODULE ACTIVITY STRIP ─────────────────────────────────────────────────────
function renderModStrip() {
    const s = SUMMARY;
    const mods = [
        {id:'SWS',  cnt: (s.sws.txns?.length||0)+(s.sws.counts?.length||0), sub:'Transactions & Counts'},
        {id:'PSM',  cnt: s.psm.prs?.length||0, sub:'PR Drafts Pending'},
        {id:'PLT',  cnt: (s.plt.assigns?.length||0)+(s.plt.deliveries?.length||0), sub:'Assignments & Deliveries'},
        {id:'ALMS', cnt: (s.alms.maint?.length||0)+(s.alms.repairs?.length||0), sub:'Maintenance & Repairs'},
        {id:'DTRS', cnt: (s.dtrs.docs?.length||0)+(s.dtrs.routes?.length||0), sub:'Documents & Routes'},
    ];
    const maxCnt = Math.max(...mods.map(m=>m.cnt), 1);

    document.getElementById('modStrip').innerHTML = mods.map(m => {
        const badgeCls = m.cnt === 0 ? 'c-grn' : m.cnt >= 5 ? 'c-red' : 'c-amb';
        const badgeLabel = m.cnt === 0 ? 'Clear' : m.cnt + ' Pending';
        return `
        <div class="mac" id="mac-${m.id}" onclick="filterToModule('${m.id}')">
            <div class="mac-top">
                <div class="mac-ic ${MOD_IC_CLS[m.id]}"><i class="bx ${MOD_IC[m.id]}"></i></div>
                <span class="chip ${badgeCls}" style="font-size:10px">${badgeLabel}</span>
            </div>
            <div class="mac-name">${m.id}</div>
            <div class="mac-stat">${m.cnt}</div>
            <div class="mac-sub">${esc(m.sub)}</div>
            <div class="mac-prog"><div class="mac-prog-bar" style="width:${Math.round(m.cnt/maxCnt*100)}%;background:${m.cnt===0?'#22C55E':m.cnt>=5?'var(--red)':'var(--amb)'};"></div></div>
        </div>`;
    }).join('');
}

// ── TASK QUEUE — built from real SUMMARY data ─────────────────────────────────
function buildTasks() {
    if (!SUMMARY) return [];
    const tasks = [];
    const s = SUMMARY;

    // SWS Transactions
    (s.sws.txns || []).forEach(t => {
        const due = t.date_time ? new Date(t.date_time) : null;
        const isPast = due && due < new Date();
        tasks.push({
            id: t.txn_id,
            desc: (t.type==='in'?'Stock In':'Stock Out') + ' — ' + (t.item_name||'Item'),
            mod: 'SWS',
            pri: isPast ? 'red' : 'amb',
            urg: isPast ? 'red' : 'yellow',
            status: t.status === 'Processing' ? 'In Progress' : 'Pending',
            due: fDT(t.date_time),
            action: 'complete-txn',
            actionId: t.txn_id,
            actionLabel: 'Complete',
        });
    });

    // SWS Cycle Counts
    (s.sws.counts || []).forEach(c => {
        tasks.push({
            id: c.record_no,
            desc: 'Cycle Count — ' + (c.item_name||'Item'),
            mod: 'SWS',
            pri: 'amb',
            urg: 'yellow',
            status: 'Pending',
            due: fD(c.count_date),
            action: null,
            actionId: null,
            actionLabel: null,
        });
    });

    // PSM Purchase Requests
    (s.psm.prs || []).forEach(r => {
        const isPast = r.date_needed && r.date_needed < today();
        tasks.push({
            id: r.pr_number,
            desc: 'PR Draft — ' + (r.purpose || r.pr_number),
            mod: 'PSM',
            pri: isPast ? 'red' : 'amb',
            urg: isPast ? 'red' : 'yellow',
            status: r.status === 'Returned' ? 'Returned' : 'Draft',
            due: fD(r.date_needed),
            action: 'submit-pr',
            actionId: r.id,
            actionLabel: 'Submit',
        });
    });

    // PLT Assignments
    (s.plt.assigns || []).forEach(a => {
        const isPast = a.due_date && a.due_date < today();
        tasks.push({
            id: a.assignment_id,
            desc: a.task,
            mod: 'PLT',
            pri: isPast||a.status==='Overdue' ? 'red' : a.priority==='Critical'||a.priority==='High' ? 'amb' : 'grn',
            urg: isPast ? 'red' : 'yellow',
            status: a.status,
            due: fD(a.due_date),
            action: null,
            actionId: null,
            actionLabel: null,
        });
    });

    // PLT Deliveries
    (s.plt.deliveries || []).forEach(d => {
        const isPast = d.expected_date && d.expected_date < today();
        tasks.push({
            id: d.delivery_id,
            desc: 'Delivery — ' + d.supplier + ' · ' + d.po_ref,
            mod: 'PLT',
            pri: isPast||d.status==='Delayed' ? 'red' : 'amb',
            urg: isPast ? 'red' : 'yellow',
            status: d.status,
            due: fD(d.expected_date),
            action: null,
            actionId: null,
            actionLabel: null,
        });
    });

    // ALMS Maintenance
    (s.alms.maint || []).forEach(m => {
        const isPast = m.next_due && m.next_due < today();
        tasks.push({
            id: m.schedule_id,
            desc: m.type + ' — ' + m.asset_name,
            mod: 'ALMS',
            pri: m.status==='Overdue'||isPast ? 'red' : 'amb',
            urg: m.status==='Overdue' ? 'red' : 'yellow',
            status: m.status,
            due: fD(m.next_due),
            action: m.status === 'In Progress' ? 'complete-maint' : 'start-maint',
            actionId: m.id,
            actionLabel: m.status === 'In Progress' ? 'Complete' : 'Start',
        });
    });

    // ALMS Repairs
    (s.alms.repairs || []).forEach(r => {
        tasks.push({
            id: r.log_id,
            desc: 'Repair — ' + r.asset_name + ': ' + (r.issue||'').substring(0,40),
            mod: 'ALMS',
            pri: r.status==='Escalated' ? 'red' : 'amb',
            urg: r.status==='Escalated' ? 'red' : 'yellow',
            status: r.status,
            due: fD(r.date_reported),
            action: null,
            actionId: null,
            actionLabel: null,
        });
    });

    // DTRS Routes
    (s.dtrs.routes || []).forEach(r => {
        const isPast = r.due_date && r.due_date < today();
        tasks.push({
            id: r.route_id,
            desc: r.route_type + ' — ' + r.doc_name,
            mod: 'DTRS',
            pri: isPast||r.priority==='Urgent'||r.priority==='Rush' ? 'red' : 'grn',
            urg: isPast ? 'red' : 'green',
            status: r.status,
            due: r.due_date ? fD(r.due_date) : 'No deadline',
            action: 'complete-route',
            actionId: r.id,
            actionLabel: 'Complete',
        });
    });

    // DTRS Documents (no action — staff just processes them in DTRS module)
    (s.dtrs.docs || []).forEach(d => {
        tasks.push({
            id: d.doc_id,
            desc: (d.doc_type||'Document') + ' — ' + d.title,
            mod: 'DTRS',
            pri: d.priority==='Urgent'||d.priority==='High Value' ? 'red' : d.needs_validation ? 'amb' : 'grn',
            urg: d.needs_validation ? 'yellow' : 'green',
            status: d.needs_validation ? 'Needs Validation' : d.status,
            due: '—',
            action: null,
            actionId: null,
            actionLabel: null,
        });
    });

    // Sort: red first, then amb, then grn
    const priOrder = {red:0, amb:1, grn:2, blu:3};
    return tasks.sort((a,b)=>(priOrder[a.pri]||3)-(priOrder[b.pri]||3));
}

function renderTasks() {
    const fMod  = document.getElementById('taskModFilter')?.value||'';
    const fPri  = document.getElementById('taskPriFilter')?.value||'';
    let tasks = buildTasks();
    if (fMod) tasks = tasks.filter(t => t.mod === fMod);
    if (fPri) tasks = tasks.filter(t => t.pri === fPri);

    const PRI_CLS = {red:'tp-r', amb:'tp-a', grn:'tp-g', blu:'tp-b'};
    const PRI_IC  = {red:'bx-error-circle', amb:'bx-time-five', grn:'bx-check-circle', blu:'bx-info-circle'};

    if (!tasks.length) {
        document.getElementById('taskQueue').innerHTML = `<div style="text-align:center;padding:40px 20px;color:var(--t3)"><i class="bx bx-check-circle" style="font-size:42px;display:block;margin-bottom:10px;color:#22C55E"></i>All clear! No pending tasks.</div>`;
        return;
    }

    document.getElementById('taskQueue').innerHTML = tasks.map(t => `
        <div class="task-item urg-${t.urg}">
            <div class="task-pri ${PRI_CLS[t.pri]||'tp-g'}"><i class="bx ${PRI_IC[t.pri]||'bx-check-circle'}"></i></div>
            <div class="task-body">
                <div class="tt" title="${esc(t.desc)}">${esc(t.desc)}</div>
                <div class="ts"><span style="font-family:'IBM Plex Mono',monospace">${esc(t.id)}</span> · Due: ${esc(t.due)}</div>
                <div class="task-meta">
                    <span class="chip ${MOD_CLS[t.mod]||'c-gry'}">${esc(t.mod)}</span>
                    <span class="chip ${STAT_CLS[t.status]||'c-gry'}">${esc(t.status)}</span>
                </div>
            </div>
            <div class="task-acts">
                ${t.action && t.actionId ? `<button class="btn btn-primary btn-xs" onclick="doTaskAction('${esc(t.action)}',${JSON.stringify(t.actionId)},${JSON.stringify(t.id)},${JSON.stringify(t.desc)})"><i class="bx bx-play"></i> ${esc(t.actionLabel)}</button>` : ''}
            </div>
        </div>`).join('');
}

function filterToModule(mod) {
    document.querySelectorAll('.mac').forEach(m => m.classList.remove('active'));
    const el = document.getElementById('mac-' + mod);
    if (el) el.classList.add('active');
    const sel = document.getElementById('taskModFilter');
    if (sel) { sel.value = mod; renderTasks(); }
    document.getElementById('taskQueue').scrollIntoView({behavior:'smooth',block:'nearest'});
}

// ── ALERTS (derived from tasks) ────────────────────────────────────────────────
function renderAlerts() {
    const tasks = buildTasks();
    const overdue = tasks.filter(t => t.urg === 'red');
    const urgent  = tasks.filter(t => t.urg === 'yellow').slice(0, 3);
    const alerts  = [...overdue.slice(0,3), ...urgent.slice(0, Math.max(0,4-overdue.length))];

    document.getElementById('alertCount').textContent = `${overdue.length} Active`;
    document.getElementById('alertCount').className   = `chip ${overdue.length > 0 ? 'c-red' : 'c-grn'}`;

    if (!alerts.length) {
        document.getElementById('alertPanel').innerHTML = `<div style="text-align:center;padding:24px;color:var(--t3)"><i class="bx bx-check-shield" style="font-size:36px;display:block;margin-bottom:8px;color:#22C55E"></i>No active alerts.</div>`;
        return;
    }

    document.getElementById('alertPanel').innerHTML = alerts.map(a => `
        <div class="alert-item">
            <div class="alert-dot ${a.urg==='red'?'tp-r':'tp-a'}"><i class="bx ${a.urg==='red'?'bx-error-circle':'bx-time-five'}"></i></div>
            <div class="alert-body">
                <div class="ab">${esc(a.desc.substring(0,60))}</div>
                <div class="at"><span style="background:#F3F4F6;padding:1px 5px;border-radius:4px">${a.mod}</span> · Due: ${esc(a.due)}</div>
            </div>
            <div class="alert-ts">${esc(a.status)}</div>
        </div>`).join('') +
        `<div style="padding-top:12px;border-top:1px solid var(--bd);margin-top:4px">
            <button class="btn btn-warn btn-sm" style="width:100%;justify-content:center" onclick="openEscal()"><i class="bx bx-up-arrow-circle"></i> Escalate to Manager</button>
        </div>`;
}

// ── PERFORMANCE METRICS ────────────────────────────────────────────────────────
function renderPerf() {
    const tasks   = buildTasks();
    const total   = tasks.length;
    const inProg  = tasks.filter(t => t.status==='In Progress').length;
    const overdue = tasks.filter(t => t.urg==='red').length;
    const target  = Math.max(total + 2, 10);

    const metrics = [
        {label:'Pending Tasks',       val:total,       max:target,      col:'var(--grn)',  unit:''},
        {label:'In Progress',         val:inProg,      max:Math.max(total,1), col:'var(--blu)', unit:''},
        {label:'MTD Transactions',    val:MTD.total_mtd||0, max:80,     col:'var(--tel)', unit:''},
        {label:'Overdue Items',       val:overdue,     max:Math.max(total,1), col:'var(--red)', unit:'', inv:true},
    ];

    document.getElementById('perfPanel').innerHTML = metrics.map(m => {
        const pct = m.max > 0 ? Math.min(100, Math.round(m.val / m.max * 100)) : 0;
        const bar = m.inv ? Math.max(0, 100 - pct) : pct;
        return `<div class="perf-row">
            <div class="perf-label">${m.label}</div>
            <div class="perf-track"><div class="perf-bar" style="width:${bar}%;background:${m.col}"></div></div>
            <div class="perf-num" style="color:${m.col}">${m.val}</div>
        </div>`;
    }).join('');
}

// ── BAR CHART ──────────────────────────────────────────────────────────────────
function renderChart() {
    const daily = MTD.daily || [];
    if (!daily.length) return;
    const maxV = Math.max(...daily.map(d=>d.count), 1);
    const total = daily.reduce((s,d)=>s+d.count,0);

    document.getElementById('mtdTotal').textContent = total + ' transactions';
    document.getElementById('mtdTotal').className   = 'chip c-grn';

    document.getElementById('barChart').innerHTML = daily.map(d => `
        <div class="bc-col">
            <div class="bc-val">${d.count > 0 ? d.count : ''}</div>
            <div class="bc-bar" style="height:${Math.max(4,Math.round(d.count/maxV*88))}px;background:${d.date===today()?'var(--grn)':'rgba(46,125,50,.4)'};" title="${d.date}: ${d.count} transactions" onclick="toast('${d.date}: ${d.count} transactions','s')"></div>
        </div>`).join('');

    document.getElementById('chartLabels').innerHTML = daily.map(d =>
        `<span style="font-size:9px;color:var(--t3);text-align:center;flex:1;font-family:'IBM Plex Mono',monospace">${d.label}</span>`
    ).join('');
}

// ── HISTORY (from real API) ────────────────────────────────────────────────────
async function loadHistory() {
    const mod = document.getElementById('histModFilter')?.value || '';
    document.getElementById('histTbody').innerHTML = `<tr><td colspan="5" style="padding:24px;text-align:center;color:var(--t3)">Loading…</td></tr>`;
    try {
        HISTORY = await apiGet(API + '?api=history&limit=30' + (mod ? '&module='+mod : ''));
        renderHistory();
    } catch(e) {
        document.getElementById('histTbody').innerHTML = `<tr><td colspan="5" style="padding:24px;text-align:center;color:var(--red)">${esc(e.message)}</td></tr>`;
    }
}

function renderHistory() {
    if (!HISTORY.length) {
        document.getElementById('histTbody').innerHTML = `<tr><td colspan="5" style="padding:24px;text-align:center;color:var(--t3)">No history found.</td></tr>`;
        return;
    }
    document.getElementById('histTbody').innerHTML = HISTORY.map(r => `
        <tr>
            <td style="font-family:'IBM Plex Mono',monospace;font-weight:600;color:var(--grn);font-size:11.5px">${esc(r.id)}</td>
            <td style="font-weight:500;color:var(--t1);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.desc)}">${esc(r.desc)}</td>
            <td><span class="chip ${MOD_CLS[r.module]||'c-gry'}">${esc(r.module)}</span></td>
            <td style="font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--t3)">${fDT(r.done||r.started)}</td>
            <td><span class="chip ${STAT_CLS[r.status]||'c-gry'}">${esc(r.status)}</span></td>
        </tr>`).join('');
}

// ── MODULE CONTRIBUTIONS ───────────────────────────────────────────────────────
function renderContrib() {
    if (!SUMMARY) return;
    const s = SUMMARY;
    const data = [
        {mod:'SWS',  cnt:(s.sws.txns?.length||0)+(s.sws.counts?.length||0)+(MTD.sws_mtd||0),  done:MTD.sws_mtd||0},
        {mod:'PSM',  cnt:(s.psm.prs?.length||0),                                               done:0},
        {mod:'PLT',  cnt:(s.plt.assigns?.length||0)+(s.plt.deliveries?.length||0),             done:0},
        {mod:'ALMS', cnt:(s.alms.maint?.length||0)+(s.alms.repairs?.length||0)+(MTD.alms_mtd||0), done:MTD.alms_mtd||0},
        {mod:'DTRS', cnt:(s.dtrs.docs?.length||0)+(s.dtrs.routes?.length||0)+(MTD.dtrs_mtd||0),  done:MTD.dtrs_mtd||0},
    ];
    const maxCnt = Math.max(...data.map(d=>d.cnt), 1);
    document.getElementById('contribTable').innerHTML = `
        <table class="sa-tbl">
            <thead><tr><th>Module</th><th>Active Tasks</th><th>Completed MTD</th><th>Completion Rate</th><th>Volume Bar</th></tr></thead>
            <tbody>${data.map(d => {
                const rate = d.cnt > 0 ? Math.round(d.done/(d.cnt)*100) : 0;
                return `<tr>
                    <td><span class="chip ${MOD_CLS[d.mod]||'c-gry'}">${d.mod}</span></td>
                    <td style="font-family:'IBM Plex Mono',monospace;font-weight:600">${d.cnt}</td>
                    <td style="font-family:'IBM Plex Mono',monospace">${d.done}</td>
                    <td><div style="display:flex;align-items:center;gap:7px">
                        <div style="width:50px;height:5px;background:#E5E7EB;border-radius:3px;overflow:hidden">
                            <div style="height:100%;border-radius:3px;width:${Math.min(rate,100)}%;background:${rate>=70?'var(--grn)':rate>=40?'var(--amb)':'var(--red)'}"></div>
                        </div>
                        <span style="font-family:'IBM Plex Mono',monospace;font-size:11px;font-weight:600">${rate}%</span>
                    </div></td>
                    <td><div style="width:${Math.round(d.cnt/maxCnt*120)}px;height:7px;background:${MOD_COLORS[d.mod]||'#6B7280'};border-radius:4px;transition:width .5s ease"></div></td>
                </tr>`;
            }).join('')}</tbody>
        </table>`;
}
const MOD_COLORS = {SWS:'#2563EB',PSM:'#0D9488',PLT:'#7C3AED',ALMS:'#D97706',DTRS:'#DC2626'};

// ── REPORT TABS ────────────────────────────────────────────────────────────────
function setRptTab(name, el) {
    document.querySelectorAll('.rpt-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.rpt-panel').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('rpt-' + name).classList.add('active');
    if (name === 'contrib') renderContrib();
}

// ── TASK ACTIONS (calls real API) ──────────────────────────────────────────────
function doTaskAction(action, id, label, desc) {
    const titles = {
        'start-maint'   : 'Start Maintenance Task',
        'complete-maint': 'Complete Maintenance Task',
        'complete-txn'  : 'Complete Transaction',
        'submit-pr'     : 'Submit Purchase Request',
        'complete-route': 'Complete Routing Task',
    };
    const subs = {
        'start-maint'   : 'Task will be marked as In Progress and logged.',
        'complete-maint': 'Task will be marked Completed. Next due date auto-calculated.',
        'complete-txn'  : 'Transaction completed. Inventory stock will be updated.',
        'submit-pr'     : 'PR will be submitted for Manager review.',
        'complete-route': 'Route will be marked Completed. History step logged.',
    };
    document.getElementById('tmTitle').textContent = titles[action] || 'Confirm Action';
    document.getElementById('tmSub').textContent   = subs[action]  || '';
    document.getElementById('tmInfo').innerHTML    = `<i class="bx bx-info-circle"></i>&nbsp; <strong>${esc(String(label))}</strong> — ${esc(desc||'').substring(0,60)}`;
    document.getElementById('tmNote').value        = '';

    pendingAction = {action, id};
    document.getElementById('taskModal').classList.add('on');
}

document.getElementById('tmConfirm').addEventListener('click', async () => {
    if (!pendingAction) return;
    const btn  = document.getElementById('tmConfirm');
    const note = document.getElementById('tmNote').value.trim();
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner"></div> Working…';

    const {action, id} = pendingAction;
    const apiMap = {
        'start-maint'   : {path:'start-maint',   body:{scheduleId:id, note}},
        'complete-maint': {path:'complete-maint', body:{scheduleId:id, note}},
        'complete-txn'  : {path:'complete-txn',   body:{txnId:id,      note}},
        'submit-pr'     : {path:'submit-pr',      body:{prId:id,       note}},
        'complete-route': {path:'complete-route', body:{routeId:id,    note}},
    };
    const cfg = apiMap[action];
    if (!cfg) { closeModal('taskModal'); return; }

    try {
        await apiPost(API + '?api=' + cfg.path, cfg.body);
        toast('Action completed successfully.', 's');
        closeModal('taskModal');
        pendingAction = null;
        // Reload data
        [SUMMARY, MTD] = await Promise.all([
            apiGet(API + '?api=summary'),
            apiGet(API + '?api=mtd-stats'),
        ]);
        renderKPIs(); renderModStrip(); renderTasks(); renderAlerts(); renderPerf(); renderChart();
        loadHistory();
    } catch(e) {
        toast(e.message, 'd');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bx bx-check"></i> Confirm';
    }
});

// ── ESCALATION ─────────────────────────────────────────────────────────────────
function openEscal() { document.getElementById('escalModal').classList.add('on'); }
document.getElementById('escalBtn').addEventListener('click', openEscal);

document.getElementById('escalConfirm').addEventListener('click', async () => {
    const desc = document.getElementById('escalDesc').value.trim();
    if (!desc) { toast('Please describe the issue.', 'w'); return; }
    const btn = document.getElementById('escalConfirm');
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner"></div> Submitting…';

    try {
        await apiPost(API + '?api=escalate', {
            issueType: document.getElementById('escalType').value,
            description: desc,
        });
        toast('Escalation submitted. Your Manager has been notified.', 's');
        closeModal('escalModal');
        document.getElementById('escalDesc').value = '';
    } catch(e) {
        toast(e.message, 'd');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bx bx-up-arrow-circle"></i> Submit Escalation';
    }
});

// ── EXPORT ─────────────────────────────────────────────────────────────────────
function exportCSV() {
    if (!HISTORY.length) { toast('No history to export.', 'w'); return; }
    const hdrs = ['Record ID','Description','Module','Date','Status'];
    const rows = [hdrs.join(','), ...HISTORY.map(r =>
        [r.id, `"${(r.desc||'').replace(/"/g,'""')}"`, r.module, r.done||r.started, r.status].join(',')
    )];
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([rows.join('\n')], {type:'text/csv'}));
    a.download = 'my_task_history.csv';
    a.click();
    toast('CSV exported.', 's');
}
function exportMyReport() { exportCSV(); }

// ── MODAL HELPERS ──────────────────────────────────────────────────────────────
function closeModal(id) {
    document.getElementById(id)?.classList.remove('on');
    if (id === 'taskModal') pendingAction = null;
}
['taskModal','escalModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});

// ── TOAST ──────────────────────────────────────────────────────────────────────
function toast(msg, type='s') {
    const ic = {s:'bx-check-circle', w:'bx-error', d:'bx-error-circle'};
    const el = document.createElement('div');
    el.className = 'toast t' + type;
    el.innerHTML = `<i class="bx ${ic[type]}" style="font-size:17px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 320); }, 3500);
}

// ── INIT ───────────────────────────────────────────────────────────────────────
loadAll();
</script>
</body>
</html>