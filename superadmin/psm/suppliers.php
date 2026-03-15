<?php
$root = dirname(__DIR__, 2);

require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── ROLE RESOLUTION (mirrors superadmin_sidebar.php) ─────────────────────────
function _resolve_role(): string {
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

$roleName = _resolve_role();
$roleRank = match($roleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1, // Staff / User
};

// ── PERMISSION HELPERS ───────────────────────────────────────────────────────
function can(int $min): bool { global $roleRank; return $roleRank >= $min; }

// Specific capability gates
$CAN_ADD_EDIT       = can(4); // Super Admin only
$CAN_DEACTIVATE     = can(4); // Super Admin only
$CAN_BLACKLIST      = can(4); // Super Admin only
$CAN_ACCREDIT       = can(4); // Super Admin only
$CAN_MERGE          = can(4); // Super Admin only
$CAN_WEIGHTS        = can(4); // Super Admin only
$CAN_EVALUATE       = can(3); // Admin+
$CAN_VIEW_HISTORY   = can(3); // Admin+
$CAN_EXPORT_ZONE    = can(3); // Admin+
$CAN_FLAG_REVIEW    = can(3); // Admin+
$CAN_VIEW_METRICS   = can(3); // Admin+ (Managers see basic only via JS)
$CAN_VIEW_FULL_PERF = can(4); // Super Admin sees all perf columns; Admin sees zone subset

// ── SUPABASE REST BACKEND FOR SUPPLIERS ──────────────────────────────
function supp_backend_error(string $message, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function supp_backend_ok($payload): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}

function supp_backend_read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        supp_backend_error('Invalid JSON body', 400);
    }
    return is_array($data) ? $data : [];
}

function supp_sb_rest(string $table, string $method = 'GET', array $query = [], $body = null, array $extra = []): array {
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
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false || $res === '') {
        if ($code >= 400) supp_backend_error('Supabase request failed (empty response)', 500);
        return [];
    }
    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = is_array($data) ? ($data['message'] ?? $data['hint'] ?? $res) : $res;
        supp_backend_error('Supabase: ' . $msg, 400);
    }
    return is_array($data) ? $data : [];
}

// ── API ROUTES ───────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = $_GET['api'];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $currentUserId   = isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null;
    $currentUserName = $_SESSION['user_name'] ?? 'Super Admin';
    $nowTs = date('Y-m-d H:i:s');

    try {
        // ── LIST: all roles can read ──────────────────────────────────────────
        if ($api === 'list' && $method === 'GET') {
            $suppliers   = supp_sb_rest('psm_suppliers', 'GET', ['order' => 'name.asc']);
            $metricsList = supp_sb_rest('psm_supplier_metrics', 'GET', []);
            $auditList   = supp_sb_rest('psm_supplier_audit_log', 'GET', ['order' => 'occurred_at.desc']);

            $metricsMap = [];
            foreach ($metricsList as $m) $metricsMap[$m['supplier_id']] = $m;

            $auditMap = [];
            foreach ($auditList as $a) {
                if (!isset($auditMap[$a['supplier_id']])) $auditMap[$a['supplier_id']] = [];
                $auditMap[$a['supplier_id']][] = [
                    'dot' => $a['dot_class'],
                    'txt' => $a['action_label'] . ($a['remarks'] ? ' — ' . $a['remarks'] : ''),
                    'ts'  => date('M d, Y · h:i A', strtotime($a['occurred_at'])),
                    'rawTs' => $a['occurred_at']
                ];
            }

            $outSuppliers = [];
            $outPerf      = [];
            $outHist      = [];

            foreach ($suppliers as $s) {
                $id = (int)$s['id'];

                // Staff/User: only show Active suppliers
                if ($roleRank <= 1 && $s['status'] !== 'Active') continue;

                $outSuppliers[] = [
                    'id'            => $id,
                    'name'          => $s['name'],
                    'contact'       => $s['contact_person'] ?? '',
                    'email'         => $s['email'] ?? '',
                    'phone'         => $s['phone'] ?? '',
                    'category'      => $s['category'],
                    'status'        => $s['status'],
                    'accreditation' => $s['accreditation'],
                    'rating'        => (float)$s['rating'],
                    'dateAdded'     => substr($s['created_at'], 0, 10),
                    'address'       => $s['address'] ?? '',
                    'notes'         => $s['notes'] ?? '',
                    'flagged'       => (bool)$s['is_flagged'],
                    'web'           => $s['website'] ?? ''
                ];

                $m = $metricsMap[$id] ?? null;
                if ($m) {
                    $outPerf[] = [
                        'supId'     => $id,
                        'totalPOs'  => (int)$m['total_pos'],
                        'completed' => (int)$m['completed'],
                        'onTime'    => (float)$m['on_time_pct'],
                        'quality'   => (float)$m['quality_avg'],
                        'issues'    => (int)$m['issue_count'],
                        'overall'   => (float)$m['overall_rating'],
                        'branch'    => 'Head Office'
                    ];
                }

                // History only for Admin+ 
                if ($roleRank >= 3 && isset($auditMap[$id])) {
                    $outHist[$id] = $auditMap[$id];
                }
            }

            supp_backend_ok(['suppliers' => $outSuppliers, 'perf' => $outPerf, 'hist' => $outHist]);
        }

        // ── SAVE: Super Admin only ────────────────────────────────────────────
        if ($api === 'save' && $method === 'POST') {
            if (!$CAN_ADD_EDIT) supp_backend_error('Insufficient permissions', 403);

            $body = supp_backend_read_json_body();
            $id   = $body['id'] ?? null;
            $name = trim($body['name'] ?? '');
            $category = trim($body['category'] ?? '');
            
            if (!$name) supp_backend_error('Supplier name is required');
            
            $data = [
                'name'           => $name,
                'category'       => $category,
                'contact_person' => trim($body['contact'] ?? ''),
                'phone'          => trim($body['phone'] ?? ''),
                'email'          => trim($body['email'] ?? ''),
                'website'        => trim($body['web'] ?? ''),
                'address'        => trim($body['address'] ?? ''),
                'status'         => trim($body['status'] ?? 'Active'),
                'accreditation'  => trim($body['accreditation'] ?? 'Pending'),
                'notes'          => trim($body['notes'] ?? ''),
                'is_flagged'     => (bool)($body['flagged'] ?? false),
                'rating'         => 0.0
            ];

            if ($id) {
                supp_sb_rest('psm_suppliers', 'PATCH', ['id' => 'eq.' . $id], $data);
                if (isset($body['overReason']) && trim($body['overReason'])) {
                    supp_sb_rest('psm_supplier_audit_log', 'POST', [], [[
                        'supplier_id'  => $id,
                        'action_label' => 'Record Updated',
                        'actor_name'   => $currentUserName,
                        'remarks'      => trim($body['overReason']),
                        'dot_class'    => 'hd-b',
                        'occurred_at'  => $nowTs
                    ]]);
                }
                supp_backend_ok(['id' => $id]);
            } else {
                $data['created_user_id'] = $currentUserId;
                $inserted = supp_sb_rest('psm_suppliers', 'POST', [], [$data]);
                if (empty($inserted)) supp_backend_error('Failed to create supplier');
                $newId = $inserted[0]['id'];
                
                supp_sb_rest('psm_supplier_metrics', 'POST', [], [[
                    'supplier_id'    => $newId,
                    'overall_rating' => 0.0
                ]]);
                supp_sb_rest('psm_supplier_audit_log', 'POST', [], [[
                    'supplier_id'  => $newId,
                    'action_label' => 'Supplier record created',
                    'actor_name'   => $currentUserName,
                    'remarks'      => '',
                    'dot_class'    => 'hd-b',
                    'occurred_at'  => $nowTs
                ]]);
                
                supp_backend_ok(['id' => $newId]);
            }
        }

        // ── ACTION: Super Admin only (deactivate/reactivate/accredit/blacklist) ─
        // Flag-for-review: Admin+ ──────────────────────────────────────────────
        if ($api === 'action' && $method === 'POST') {
            $body   = supp_backend_read_json_body();
            $id     = $body['id'] ?? null;
            $type   = $body['type'] ?? '';
            $reason = trim($body['reason'] ?? '');

            if (!$id || !$type) supp_backend_error('Missing id or type');

            // Permission gate per action type
            $saOnly    = ['deactivate', 'reactivate', 'accredit', 'blacklist'];
            $adminOnly = ['flag'];

            if (in_array($type, $saOnly)   && !$CAN_BLACKLIST)    supp_backend_error('Insufficient permissions', 403);
            if (in_array($type, $adminOnly) && !$CAN_FLAG_REVIEW)  supp_backend_error('Insufficient permissions', 403);

            $patch       = [];
            $auditAction = '';
            $auditDot    = 'hd-b';

            if ($type === 'deactivate') {
                $patch       = ['status' => 'Inactive'];
                $auditAction = 'Deactivated by Super Admin';
                $auditDot    = 'hd-o';
            } elseif ($type === 'reactivate') {
                $cur   = supp_sb_rest('psm_suppliers', 'GET', ['id' => 'eq.' . $id, 'select' => 'accreditation']);
                $patch = ['status' => 'Active', 'is_flagged' => false];
                if (!empty($cur) && $cur[0]['accreditation'] === 'Expired') {
                    $patch['accreditation'] = 'Pending';
                }
                $auditAction = 'Reactivated by Super Admin';
                $auditDot    = 'hd-g';
            } elseif ($type === 'accredit') {
                $patch       = ['status' => 'Active', 'accreditation' => 'Accredited'];
                $auditAction = 'Supplier accreditation renewed/approved';
                $auditDot    = 'hd-b';
            } elseif ($type === 'blacklist') {
                $patch       = ['status' => 'Blacklisted', 'is_flagged' => true];
                $auditAction = 'Blacklisted by Super Admin';
                $auditDot    = 'hd-r';
            } elseif ($type === 'flag') {
                $patch       = ['is_flagged' => true];
                $auditAction = 'Flagged for Review by ' . $roleName;
                $auditDot    = 'hd-o';
            } else {
                supp_backend_error('Invalid action');
            }

            supp_sb_rest('psm_suppliers', 'PATCH', ['id' => 'eq.' . $id], $patch);
            supp_sb_rest('psm_supplier_audit_log', 'POST', [], [[
                'supplier_id'  => $id,
                'action_label' => $auditAction,
                'actor_name'   => $currentUserName,
                'remarks'      => $reason,
                'dot_class'    => $auditDot,
                'occurred_at'  => $nowTs
            ]]);

            supp_backend_ok([]);
        }

        // ── EVALUATE: Admin+ ──────────────────────────────────────────────────
        if ($api === 'evaluate' && $method === 'POST') {
            if (!$CAN_EVALUATE) supp_backend_error('Insufficient permissions', 403);

            $body = supp_backend_read_json_body();
            $id   = $body['id'] ?? null;
            if (!$id) supp_backend_error('Supplier ID required');

            $po       = trim($body['po'] ?? '');
            $isOnTime = ($body['onTime'] ?? 'Yes') === 'Yes';
            $quality  = (int)($body['quality'] ?? 5);
            $issues   = trim($body['issues'] ?? '');
            $remarks  = trim($body['remarks'] ?? '');

            supp_sb_rest('psm_supplier_evaluations', 'POST', [], [[
                'supplier_id'  => $id,
                'po_reference' => $po,
                'branch'       => 'Head Office',
                'on_time'      => $isOnTime,
                'quality'      => $quality,
                'issues'       => $issues,
                'remarks'      => $remarks,
                'evaluated_by' => $currentUserName,
                'evaluated_at' => $nowTs
            ]]);

            $evals      = supp_sb_rest('psm_supplier_evaluations', 'GET', ['supplier_id' => 'eq.' . $id]);
            $totalPOs   = count($evals);
            $completed  = 0;
            $sumQuality = 0;
            $issueCount = 0;
            
            foreach ($evals as $e) {
                if ($e['on_time']) $completed++;
                $sumQuality += $e['quality'];
                if (trim($e['issues'] ?? '')) $issueCount++;
            }
            
            $onTimePct  = $totalPOs > 0 ? round(($completed / $totalPOs) * 100, 2) : 0;
            $qualityAvg = $totalPOs > 0 ? round($sumQuality / $totalPOs, 1) : 0;
            $overall    = $totalPOs > 0
                ? max(1.0, min(5.0, round(($qualityAvg + ($onTimePct / 20)) / 2, 1)))
                : 0.0;

            supp_sb_rest('psm_supplier_metrics', 'PATCH', ['supplier_id' => 'eq.' . $id], [
                'total_pos'      => $totalPOs,
                'completed'      => $completed,
                'on_time_pct'    => $onTimePct,
                'quality_avg'    => $qualityAvg,
                'issue_count'    => $issueCount,
                'overall_rating' => $overall,
                'updated_at'     => $nowTs
            ]);
            supp_sb_rest('psm_suppliers', 'PATCH', ['id' => 'eq.' . $id], ['rating' => $overall]);

            $auditTxt = "$po evaluated — Quality: $quality, On-Time: " . ($isOnTime ? 'Yes' : 'No');
            if ($issues) $auditTxt .= " (issue logged)";
            supp_sb_rest('psm_supplier_audit_log', 'POST', [], [[
                'supplier_id'  => $id,
                'action_label' => $auditTxt,
                'actor_name'   => $currentUserName,
                'remarks'      => $remarks,
                'dot_class'    => 'hd-g',
                'occurred_at'  => $nowTs
            ]]);

            supp_backend_ok([]);
        }

        // ── MERGE: Super Admin only ───────────────────────────────────────────
        if ($api === 'merge' && $method === 'POST') {
            if (!$CAN_MERGE) supp_backend_error('Insufficient permissions', 403);

            $body   = supp_backend_read_json_body();
            $pId    = (int)($body['primary']   ?? 0);
            $dId    = (int)($body['duplicate'] ?? 0);
            $reason = trim($body['reason'] ?? '');
            
            if (!$pId || !$dId) supp_backend_error('Primary and duplicate IDs required');
            if ($pId === $dId)  supp_backend_error('Cannot merge record into itself');
            
            $dSupArr = supp_sb_rest('psm_suppliers', 'GET', ['id' => 'eq.' . $dId]);
            if (empty($dSupArr)) supp_backend_error('Duplicate record not found');
            $dSupName = $dSupArr[0]['name'];

            $pMet = supp_sb_rest('psm_supplier_metrics', 'GET', ['supplier_id' => 'eq.' . $pId]);
            $dMet = supp_sb_rest('psm_supplier_metrics', 'GET', ['supplier_id' => 'eq.' . $dId]);
            
            if (!empty($pMet) && !empty($dMet)) {
                $pm = $pMet[0]; $dm = $dMet[0];
                supp_sb_rest('psm_supplier_metrics', 'PATCH', ['supplier_id' => 'eq.' . $pId], [
                    'total_pos'      => $pm['total_pos']     + $dm['total_pos'],
                    'completed'      => $pm['completed']     + $dm['completed'],
                    'issue_count'    => $pm['issue_count']   + $dm['issue_count'],
                    'on_time_pct'    => round(($pm['on_time_pct']  + $dm['on_time_pct'])  / 2, 2),
                    'quality_avg'    => round(($pm['quality_avg']   + $dm['quality_avg'])   / 2, 1),
                    'overall_rating' => round(($pm['overall_rating'] + $dm['overall_rating']) / 2, 1),
                ]);
            }
            
            supp_sb_rest('psm_supplier_evaluations', 'PATCH', ['supplier_id' => 'eq.' . $dId], ['supplier_id' => $pId]);
            supp_sb_rest('psm_supplier_audit_log',   'PATCH', ['supplier_id' => 'eq.' . $dId], ['supplier_id' => $pId]);
            supp_sb_rest('psm_suppliers', 'DELETE', ['id' => 'eq.' . $dId]);

            supp_sb_rest('psm_supplier_audit_log', 'POST', [], [[
                'supplier_id'  => $pId,
                'action_label' => "Merged with duplicate \"$dSupName\"",
                'actor_name'   => $currentUserName,
                'remarks'      => $reason,
                'dot_class'    => 'hd-pu',
                'occurred_at'  => $nowTs
            ]]);
            
            supp_backend_ok([]);
        }

        supp_backend_error('Unsupported API route', 404);
    } catch (Throwable $e) {
        supp_backend_error('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ── NORMAL PAGE RENDER (HTML) ────────────────────────────────────────────────
include $root . '/includes/superadmin_sidebar.php';
include $root . '/includes/header.php';

// Pass role capabilities to JS
$jsRole = json_encode([
    'name'           => $roleName,
    'rank'           => $roleRank,
    'canAddEdit'     => $CAN_ADD_EDIT,
    'canDeactivate'  => $CAN_DEACTIVATE,
    'canBlacklist'   => $CAN_BLACKLIST,
    'canAccredit'    => $CAN_ACCREDIT,
    'canMerge'       => $CAN_MERGE,
    'canWeights'     => $CAN_WEIGHTS,
    'canEvaluate'    => $CAN_EVALUATE,
    'canViewHistory' => $CAN_VIEW_HISTORY,
    'canExportZone'  => $CAN_EXPORT_ZONE,
    'canFlagReview'  => $CAN_FLAG_REVIEW,
    'canViewMetrics' => $CAN_VIEW_METRICS,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Management &mdash; PSM</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/base.css">
    <link rel="stylesheet" href="/css/sidebar.css">
    <link rel="stylesheet" href="/css/header.css">
    <style>

:root {
  --primary:#2E7D32; --primary-dark:#1B5E20; --primary-light:#E8F5E9; --primary-mid:rgba(46,125,50,.18);
  --danger:#DC2626; --warning:#D97706; --info:#2563EB; --success:#059669; --purple:#7C3AED;
  --bg:#F4F7F4; --surface:#FFFFFF; --border:rgba(46,125,50,.12); --border-mid:rgba(46,125,50,.22);
  --text-1:#0A1F0D; --text-2:#5D6F62; --text-3:#9EB0A2;
  --shadow-sm:0 1px 4px rgba(0,0,0,.06); --shadow-md:0 4px 16px rgba(46,125,50,.12); --shadow-xl:0 20px 60px rgba(0,0,0,.22);
  --radius:12px; --tr:all .18s ease; --font:'Sora',sans-serif; --mono:'DM Mono',monospace;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

/* ── Page ── */
.page{padding:0 0 60px}
.ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:28px;animation:fadeUp .4s both}
.eyebrow{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--primary);margin-bottom:4px}
.ph h1{font-size:26px;font-weight:800;color:var(--text-1);line-height:1.15}
.ph-acts{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

/* ── Access Banner ── */
.access-banner{display:flex;align-items:flex-start;gap:10px;padding:10px 16px;border-radius:10px;font-size:12px;margin-bottom:16px;animation:fadeUp .4s both}
.ab-info{background:#EFF6FF;border:1px solid #BFDBFE;color:var(--info)}
.ab-warn{background:#FEF3C7;border:1px solid #FDE68A;color:var(--warning)}
.access-banner i{font-size:16px;flex-shrink:0;margin-top:1px}

/* ── Tabs ── */
.tabs{display:flex;gap:4px;margin-bottom:24px;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:4px;width:fit-content;animation:fadeUp .4s .05s both}
.tab-btn{font-family:var(--font);font-size:13px;font-weight:600;padding:8px 20px;border-radius:9px;border:none;cursor:pointer;transition:var(--tr);color:var(--text-2);background:transparent;display:flex;align-items:center;gap:7px}
.tab-btn:hover{color:var(--text-1);background:var(--primary-light)}
.tab-btn.active{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3)}
.tab-pane{display:none}.tab-pane.active{display:block}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:7px;font-family:var(--font);font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap}
.btn-p{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3)}.btn-p:hover{background:var(--primary-dark);transform:translateY(-1px)}
.btn-g{background:var(--surface);color:var(--text-2);border:1px solid var(--border-mid)}.btn-g:hover{background:#F0FBF1;color:var(--text-1)}
.btn-s{font-size:12px;padding:7px 14px}
.btn-warn{background:var(--warning);color:#fff}.btn-warn:hover{background:#B45309;transform:translateY(-1px)}
.btn-danger{background:var(--danger);color:#fff}.btn-danger:hover{background:#B91C1C;transform:translateY(-1px)}
.btn-info{background:var(--info);color:#fff}.btn-info:hover{background:#1D4ED8;transform:translateY(-1px)}
.btn-sa{background:#1B5E20;color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.35)}.btn-sa:hover{background:#14531c;transform:translateY(-1px)}
.btn-purple{background:var(--purple);color:#fff}.btn-purple:hover{background:#6D28D9;transform:translateY(-1px)}
.btn-orange{background:var(--warning);color:#fff}.btn-orange:hover{background:#B45309;transform:translateY(-1px)}
.btn:disabled{opacity:.4;pointer-events:none}

/* ── Stats ── */
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:14px;margin-bottom:22px}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;box-shadow:var(--shadow-sm);display:flex;align-items:center;gap:12px;animation:fadeUp .4s both}
.stat:nth-child(1){animation-delay:.05s}.stat:nth-child(2){animation-delay:.1s}.stat:nth-child(3){animation-delay:.15s}.stat:nth-child(4){animation-delay:.2s}.stat:nth-child(5){animation-delay:.25s}.stat:nth-child(6){animation-delay:.3s}
.stat-ic{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.ic-g{background:#E8F5E9;color:var(--primary)}.ic-o{background:#FEF3C7;color:var(--warning)}.ic-b{background:#EFF6FF;color:var(--info)}.ic-r{background:#FEE2E2;color:var(--danger)}.ic-t{background:#CCFBF1;color:#0D9488}.ic-pu{background:#EDE9FE;color:var(--purple)}

.stat-v{font-size:22px;font-weight:800;line-height:1}.stat-l{font-size:11px;color:var(--text-2);margin-top:2px}

/* ── Toolbar ── */
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;animation:fadeUp .4s .1s both}
.sw{position:relative;flex:1;min-width:200px}
.sw i{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:18px;color:var(--text-3);pointer-events:none}
.sin{width:100%;padding:9px 12px 9px 38px;font-family:var(--font);font-size:13px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr)}
.sin:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
.sin::placeholder{color:var(--text-3)}
.fsel{font-family:var(--font);font-size:13px;padding:9px 30px 9px 12px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);cursor:pointer;outline:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;transition:var(--tr)}
.fsel:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
.date-range{display:flex;align-items:center;gap:6px}
.date-range label{font-size:12px;color:var(--text-2);font-weight:500;white-space:nowrap}
.date-in{font-family:var(--font);font-size:13px;padding:9px 12px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr)}
.date-in:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}

/* ── Card Table ── */
.tcard{background:var(--surface);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-md);animation:fadeUp .4s .15s both}
.tcard-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;width:100%}
.tcard-scroll::-webkit-scrollbar{height:5px}
.tcard-scroll::-webkit-scrollbar-track{background:var(--bg)}
.tcard-scroll::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px}
.tcard table{width:100%;border-collapse:collapse;font-size:13px;min-width:960px}
.tcard thead th{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-2);padding:10px 10px;text-align:left;background:var(--bg);border-bottom:1px solid var(--border);white-space:nowrap;user-select:none}
.tcard thead th:first-child{padding-left:14px}.tcard thead th.sortable{cursor:pointer}.tcard thead th.sortable:hover{color:var(--primary)}
.tcard thead th .si{margin-left:4px;opacity:.4;font-size:13px;vertical-align:middle}
.tcard thead th.sorted .si{opacity:1;color:var(--primary)}
.tcard tbody tr{border-bottom:1px solid var(--border);transition:background .12s;cursor:pointer}
.tcard tbody tr:last-child{border-bottom:none}
.tcard tbody tr:hover{background:#F7FBF7}
.tcard tbody td{padding:11px 10px;vertical-align:middle}
.tcard tbody td:first-child{padding-left:14px}.tcard tbody td:last-child{padding-right:14px}
.cell-trunc{max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pag{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--border);background:var(--bg);font-size:13px;color:var(--text-2)}
.pbtns{display:flex;gap:6px}
.pb{width:32px;height:32px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);font-family:var(--font);font-size:13px;font-weight:500;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--text-1)}
.pb:hover{background:#F0FBF1;border-color:var(--primary);color:var(--primary)}.pb.active{background:var(--primary);border-color:var(--primary);color:#fff}.pb:disabled{opacity:.4;pointer-events:none}

/* ── Sup Avatar ── */
.sup-av{width:32px;height:32px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;color:#fff;flex-shrink:0}
.sup-cell{display:flex;align-items:center;gap:8px}
.sup-name{font-size:13px;font-weight:600;color:var(--text-1)}.sup-sub{font-size:11px;color:var(--text-3);margin-top:2px}

/* ── Chips ── */
.chip{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px}
.chip::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}
.ch-active{background:#E8F5E9;color:var(--primary)}.ch-inactive{background:#F3F4F6;color:#6B7280}
.ch-blacklisted{background:#FEE2E2;color:var(--danger)}.ch-pending{background:#FEF3C7;color:var(--warning)}
.ch-accredited{background:#EFF6FF;color:var(--info)}.ch-flagged{background:#FEE2E2;color:var(--danger)}

/* Rating stars */
.stars{display:inline-flex;gap:2px;align-items:center}
.star{font-size:13px;color:#E5E7EB}.star.on{color:#F59E0B}
.rating-num{font-family:var(--mono);font-size:12px;font-weight:600;color:var(--text-1);margin-left:5px}

/* Performance bar */
.perf-bar-bg{width:70px;height:6px;background:#E5E7EB;border-radius:6px;overflow:hidden;display:inline-block;vertical-align:middle}
.perf-bar-fill{height:100%;border-radius:6px;transition:width .5s ease}

/* SA banner */
.sa-banner{display:flex;align-items:flex-start;gap:10px;padding:10px 16px;background:#F0FBF1;border:1px solid rgba(46,125,50,.2);border-radius:10px;font-size:12px;color:var(--primary);margin-bottom:16px}
.sa-banner i{font-size:16px;flex-shrink:0;margin-top:1px}

/* Mono */
.mono{font-family:var(--mono);font-size:12px}

/* ── Empty ── */
.empty{padding:56px 20px;text-align:center;color:var(--text-3)}
.empty i{font-size:44px;display:block;margin-bottom:10px;color:#C8E6C9}
.empty p{font-size:13px}

/* ── Sliding Panel ── */
.ov{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1200;opacity:0;pointer-events:none;transition:opacity .25s}
.ov.show{opacity:1;pointer-events:all}
#panel{position:fixed;top:0;right:0;bottom:0;width:560px;max-width:94vw;background:var(--surface);box-shadow:-4px 0 40px rgba(0,0,0,.18);z-index:1201;transform:translateX(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden}
#panel.open{transform:translateX(0)}
.p-hd{display:flex;align-items:center;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--border);background:var(--bg);flex-shrink:0}
.p-t{font-size:17px;font-weight:700;color:var(--text-1)}.p-s{font-size:12px;color:var(--text-2);margin-top:2px}
.p-cl{width:36px;height:36px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--text-2);transition:var(--tr)}
.p-cl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA}
.p-body{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:16px}
.p-body::-webkit-scrollbar{width:4px}.p-body::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px}
.p-ft{padding:16px 24px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0}

/* ── Form ── */
.fg{display:flex;flex-direction:column;gap:5px}
.fr{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.fl{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-2)}
.fl span{color:var(--danger)}
.fi,.fs,.fta{font-family:var(--font);font-size:13px;padding:9px 12px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);width:100%}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12)}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:32px}
.fta{resize:vertical;min-height:70px}
.sdv{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);display:flex;align-items:center;gap:10px;margin:4px 0}
.sdv::after{content:'';flex:1;height:1px;background:var(--border)}
.sa-section{background:#F0FBF1;border:1px solid rgba(46,125,50,.2);border-radius:12px;padding:16px}
.sa-section-hd{display:flex;align-items:center;gap:8px;margin-bottom:12px}
.sa-section-hd i{color:var(--primary);font-size:16px}
.sa-section-hd span{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--primary)}

/* ── Modals ── */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1300;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s}
.modal-bg.show{opacity:1;pointer-events:all}
.mbox{background:var(--surface);border-radius:20px;width:520px;max-width:100%;max-height:88vh;display:flex;flex-direction:column;box-shadow:var(--shadow-xl);transform:scale(.96);transition:transform .25s;overflow:hidden}
.mbox-lg{width:780px}
.modal-bg.show .mbox{transform:scale(1)}
.m-hd{padding:22px 26px 16px;border-bottom:1px solid var(--border);background:var(--bg);display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-shrink:0}
.m-hd-ti{display:flex;align-items:center;gap:12px}
.m-hd-ic{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.m-hd-nm{font-size:17px;font-weight:700;color:var(--text-1)}.m-hd-sub{font-size:12px;color:var(--text-2);margin-top:3px}
.m-cl{width:34px;height:34px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--text-2);transition:var(--tr)}
.m-cl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA}
.m-body{flex:1;overflow-y:auto;padding:22px 26px}
.m-body::-webkit-scrollbar{width:4px}.m-body::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px}
.m-ft{padding:14px 26px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap}

/* M-Tabs */
.m-tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:16px}
.m-tab{font-size:13px;font-weight:600;padding:8px 14px;border-radius:8px 8px 0 0;cursor:pointer;transition:var(--tr);color:var(--text-2);border:none;background:transparent}
.m-tab:hover{background:var(--primary-light)}.m-tab.active{background:var(--primary);color:#fff}
.m-tp{display:none}.m-tp.active{display:block}

/* Scorecard grid */
.sc-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.sc-box{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px 16px}
.sc-v{font-size:18px;font-weight:800;line-height:1;color:var(--text-1)}.sc-l{font-size:11px;color:var(--text-2);margin-top:2px}

/* Weights form */
.weight-row{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--border)}
.weight-row:last-child{border-bottom:none}
.weight-lbl{font-size:13px;font-weight:500;color:var(--text-1);flex:1}
.weight-slider{flex:1;appearance:none;height:5px;border-radius:5px;background:#E5E7EB;outline:none;cursor:pointer}
.weight-slider::-webkit-slider-thumb{appearance:none;width:16px;height:16px;border-radius:50%;background:var(--primary);border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:pointer}
.weight-disp{font-family:var(--mono);font-size:13px;font-weight:700;color:var(--primary);min-width:36px;text-align:right}

/* history timeline */
.hist-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)}
.hist-item:last-child{border-bottom:none}
.hist-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px}
.hd-g{background:var(--primary)}.hd-b{background:var(--info)}.hd-o{background:var(--warning)}.hd-r{background:var(--danger)}.hd-pu{background:var(--purple)}
.hist-txt{font-size:13px;font-weight:500;color:var(--text-1)}
.hist-ts{font-size:11px;color:var(--text-3);margin-top:2px;font-family:var(--mono)}

/* Toast */
#tw{position:fixed;bottom:28px;right:28px;display:flex;flex-direction:column;gap:10px;z-index:9999;pointer-events:none}
.toast{background:#0A1F0D;color:#fff;padding:11px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow-xl);pointer-events:all;min-width:210px;animation:tIn .3s ease}
.toast.success{background:var(--primary)}.toast.warning{background:var(--warning)}.toast.danger{background:var(--danger)}.toast.info{background:var(--info)}.toast.out{animation:tOut .3s ease forwards}

/* Confirm */
#cfmModal{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2000;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s}
#cfmModal.show{opacity:1;pointer-events:all}
.cfm-box{background:#fff;border-radius:20px;width:440px;max-width:calc(100vw - 40px);box-shadow:var(--shadow-xl);transform:scale(.94);transition:transform .22s;overflow:hidden}
#cfmModal.show .cfm-box{transform:scale(1)}
.cfm-hd{padding:22px 24px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:14px;background:#FAFAFA}
.cfm-hd-ic{width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.cfm-hd-title{font-size:16px;font-weight:700;color:var(--text-1)}.cfm-hd-sub{font-size:12px;color:var(--text-2);margin-top:3px}
.cfm-body{padding:20px 24px;font-size:13.5px;color:#374151;line-height:1.65;background:#fff}
.cfm-body strong{color:var(--text-1)}
.cfm-reason-wrap{padding:0 24px 4px;background:#fff;display:none}
.cfm-ft{padding:14px 24px 18px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;background:#FAFAFA}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes tIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@keyframes tOut{from{opacity:1}to{opacity:0;transform:translateY(10px)}}
@keyframes shake{0%,100%{transform:translateX(0)}25%,75%{transform:translateX(-5px)}50%{transform:translateX(5px)}}

@media(max-width:768px){
  .page{padding:18px 14px 40px}
  .fr{grid-template-columns:1fr}
  .sc-grid{grid-template-columns:1fr 1fr}
  #panel{width:100vw;max-width:100vw}
  .mbox-lg{width:100%}
}
    </style>
</head>
<body>
    <main class="main-content" id="mainContent">
<div class="page">

  <!-- Page Header -->
  <div class="ph">
    <div>
      <p class="eyebrow">PSM · Procurement &amp; Sourcing Management</p>
      <h1>Supplier Management</h1>
    </div>
    <div class="ph-acts">
      <?php if ($CAN_EXPORT_ZONE): ?>
      <button class="btn btn-g" id="expBtn"><i class='bx bx-export'></i> Export</button>
      <?php endif; ?>
      <?php if ($CAN_MERGE): ?>
      <button class="btn btn-sa" id="mergeBtn"><i class='bx bx-git-merge'></i> Merge Duplicates</button>
      <?php endif; ?>
      <?php if ($CAN_ADD_EDIT): ?>
      <button class="btn btn-p" id="addBtn"><i class='bx bx-plus'></i> Add Supplier</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Role-based access info banner -->
  <?php if ($roleName === 'Admin'): ?>
  <div class="access-banner ab-info"><i class='bx bx-info-circle'></i><div>You have <strong>Admin access</strong>. You can view suppliers, flag for review, add evaluations, and export zone reports. Add/Edit/Deactivate/Blacklist actions require Super Admin.</div></div>
  <?php elseif ($roleName === 'Manager'): ?>
  <div class="access-banner ab-warn"><i class='bx bx-lock-open-alt'></i><div>You have <strong>Manager access</strong> — view only. Contact an Admin to make changes to supplier records.</div></div>
  <?php elseif ($roleRank <= 1): ?>
  <div class="access-banner ab-warn"><i class='bx bx-lock-open-alt'></i><div>You have <strong>Staff access</strong>. Showing active suppliers only. View supplier details for purchasing purposes.</div></div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="tabs">
    <button class="tab-btn active" data-tab="dir"><i class='bx bx-buildings'></i> Directory</button>
    <button class="tab-btn" data-tab="perf"><i class='bx bx-bar-chart-alt-2'></i> Performance Scorecard</button>
  </div>

  <!-- ══ DIRECTORY TAB ══ -->
  <div class="tab-pane active" id="tab-dir">

    <div class="stats-row" id="dirStats"></div>

    <div class="toolbar">
      <div class="sw"><i class='bx bx-search'></i><input type="text" class="sin" id="dirSrch" placeholder="Search supplier name, contact, email…"></div>
      <select class="fsel" id="dirCat"><option value="">All Categories</option></select>
      <?php if ($roleRank >= 3): // Admin+ see status filter ?>
      <select class="fsel" id="dirStatus"><option value="">All Statuses</option><option>Active</option><option>Inactive</option><option>Blacklisted</option><option>Pending Accreditation</option></select>
      <?php endif; ?>
      <?php if ($roleRank >= 3): // Admin+ see rating filter ?>
      <select class="fsel" id="dirRating"><option value="">All Ratings</option><option value="5">5 Stars</option><option value="4">4+ Stars</option><option value="3">3+ Stars</option><option value="low">Below 3</option></select>
      <?php endif; ?>
      <?php if ($CAN_ADD_EDIT): // SA gets date-added filter ?>
      <div class="date-range"><label>Date Added:</label><input type="date" class="date-in" id="dirFrom"><span style="font-size:12px;color:var(--text-3)">to</span><input type="date" class="date-in" id="dirTo"></div>
      <button class="btn btn-g btn-s" id="dirClearDates"><i class='bx bx-x'></i> Clear</button>
      <?php endif; ?>
    </div>

    <div class="tcard">
      <div class="tcard-scroll">
        <table>
          <thead>
            <tr>
              <th style="width:36px">#</th>
              <th class="sortable" data-col="name">Supplier Name <i class='bx bx-sort si'></i></th>
              <?php if ($roleRank >= 3): // Admin+ see contact/email/phone columns ?>
              <th>Contact Person</th>
              <th>Email / Phone</th>
              <?php elseif ($roleRank <= 1): // Staff see contact info ?>
              <th>Contact Info</th>
              <?php endif; ?>
              <th class="sortable" data-col="category">Category <i class='bx bx-sort si'></i></th>
              <?php if ($roleRank >= 3): // Admin+ see full accreditation & status ?>
              <th class="sortable" data-col="accreditation">Accreditation <i class='bx bx-sort si'></i></th>
              <th>Status</th>
              <?php else: // Manager/Staff see accreditation status only ?>
              <th class="sortable" data-col="accreditation">Accreditation Status <i class='bx bx-sort si'></i></th>
              <?php endif; ?>
              <th class="sortable" data-col="rating">Performance Rating <i class='bx bx-sort si'></i></th>
              <?php if ($CAN_ADD_EDIT): ?>
              <th class="sortable" data-col="dateAdded">Date Added <i class='bx bx-sort si'></i></th>
              <?php endif; ?>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="dirTb"></tbody>
        </table>
      </div>
      <div class="pag" id="dirPag"></div>
    </div>
  </div>

  <!-- ══ PERFORMANCE TAB ══ -->
  <div class="tab-pane" id="tab-perf">

    <div class="stats-row" id="perfStats"></div>

    <div class="toolbar">
      <div class="sw"><i class='bx bx-search'></i><input type="text" class="sin" id="perfSrch" placeholder="Search supplier…"></div>
      <?php if ($roleRank >= 3): ?>
      <select class="fsel" id="perfSupFilter"><option value="">All Suppliers</option></select>
      <select class="fsel" id="perfRating"><option value="">All Ratings</option><option value="5">Excellent (5★)</option><option value="4">Good (4+★)</option><option value="3">Average (3+★)</option><option value="low">Poor (below 3)</option></select>
      <select class="fsel" id="perfBranch"><option value="">Head Office</option></select>
      <div class="date-range"><label>Date Range:</label><input type="date" class="date-in" id="perfFrom"><span style="font-size:12px;color:var(--text-3)">to</span><input type="date" class="date-in" id="perfTo"></div>
      <button class="btn btn-g btn-s" id="perfClear"><i class='bx bx-x'></i> Clear</button>
      <?php endif; ?>
      <?php if ($CAN_WEIGHTS): ?>
      <button class="btn btn-sa btn-s" id="weightsBtn"><i class='bx bx-slider'></i> Scoring Weights</button>
      <?php endif; ?>
    </div>

    <div class="tcard">
      <div class="tcard-scroll">
        <table>
          <thead>
            <tr>
              <th style="width:36px">#</th>
              <th class="sortable" data-pcol="name">Supplier Name <i class='bx bx-sort si'></i></th>
              <?php if ($roleRank >= 3): // Admin+ see full perf columns ?>
              <th class="sortable" data-pcol="totalPOs">Total POs <i class='bx bx-sort si'></i></th>
              <th class="sortable" data-pcol="completed">Completed <i class='bx bx-sort si'></i></th>
              <?php endif; ?>
              <th class="sortable" data-pcol="onTime">On-Time Delivery <i class='bx bx-sort si'></i></th>
              <th class="sortable" data-pcol="quality">Quality Score <i class='bx bx-sort si'></i></th>
              <?php if ($roleRank >= 3): // Admin+ see issue count ?>
              <th class="sortable" data-pcol="issues">Issue Count <i class='bx bx-sort si'></i></th>
              <?php endif; ?>
              <th class="sortable" data-pcol="overall">Overall Rating <i class='bx bx-sort si'></i></th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="perfTb"></tbody>
        </table>
      </div>
      <div class="pag" id="perfPag"></div>
    </div>
  </div>

</div>

<!-- ══ PANEL (Add/Edit Supplier) — Super Admin only ══ -->
<?php if ($CAN_ADD_EDIT): ?>
<div class="ov" id="ov"></div>
<div id="panel">
  <div class="p-hd">
    <div><div class="p-t" id="pT">Add Supplier</div><div class="p-s" id="pS">Fill in supplier details</div></div>
    <button class="p-cl" id="pCl"><i class='bx bx-x'></i></button>
  </div>
  <div class="p-body">
    <div class="sdv">Basic Information</div>
    <div class="fr">
      <div class="fg"><label class="fl">Supplier Name <span>*</span></label><input type="text" class="fi" id="fName" placeholder="e.g. SafePro Industries Inc."></div>
      <div class="fg"><label class="fl">Category <span>*</span></label>
        <select class="fs" id="fCat"><option value="">Select…</option><option>PPE</option><option>Tools</option><option>Electrical</option><option>Materials</option><option>Fasteners</option><option>Chemicals</option><option>Packaging</option><option>Stationery</option><option>Abrasives</option><option>Equipment</option><option>Hardware</option><option>Safety</option><option>IT Equipment</option><option>Furniture</option></select>
      </div>
    </div>
    <div class="fr">
      <div class="fg"><label class="fl">Contact Person <span>*</span></label><input type="text" class="fi" id="fContact" placeholder="e.g. Juan dela Cruz"></div>
      <div class="fg"><label class="fl">Phone <span>*</span></label><input type="text" class="fi" id="fPhone" placeholder="+63 9XX XXX XXXX"></div>
    </div>
    <div class="fr">
      <div class="fg"><label class="fl">Email <span>*</span></label><input type="text" class="fi" id="fEmail" placeholder="supplier@email.com"></div>
      <div class="fg"><label class="fl">Website</label><input type="text" class="fi" id="fWeb" placeholder="https://"></div>
    </div>
    <div class="fg"><label class="fl">Address</label><input type="text" class="fi" id="fAddr" placeholder="Full address"></div>
    <div class="sdv">Status & Accreditation</div>
    <div class="fr">
      <div class="fg"><label class="fl">Status <span>*</span></label>
        <select class="fs" id="fStatus"><option value="Active">Active</option><option value="Inactive">Inactive</option><option value="Pending Accreditation">Pending Accreditation</option></select>
      </div>
      <div class="fg"><label class="fl">Accreditation</label>
        <select class="fs" id="fAccred"><option value="Pending">Pending</option><option value="Accredited">Accredited</option><option value="Expired">Expired</option><option value="Not Required">Not Required</option></select>
      </div>
    </div>
    <div class="fg"><label class="fl">Notes / Remarks</label><textarea class="fta" id="fNotes" placeholder="Any additional remarks…"></textarea></div>
    <div class="sa-section">
      <div class="sa-section-hd"><i class='bx bx-shield-quarter'></i><span>Super Admin Controls</span></div>
      <div class="fr">
        <div class="fg"><label class="fl">Override Status</label>
          <select class="fs" id="fOverStatus"><option value="">No override</option><option value="Blacklisted">Force Blacklist</option><option value="Active">Force Activate</option></select>
        </div>
        <div class="fg"><label class="fl">Compliance Flag</label>
          <select class="fs" id="fFlag"><option value="">None</option><option value="flagged">Flag for Review</option><option value="cleared">Clear Flag</option></select>
        </div>
      </div>
      <div class="fg" style="margin-top:10px"><label class="fl">Override Reason</label><input type="text" class="fi" id="fOverReason" placeholder="State reason for override…"></div>
    </div>
  </div>
  <div class="p-ft">
    <button class="btn btn-g" id="pCa">Cancel</button>
    <button class="btn btn-p" id="pSv"><i class='bx bx-check'></i> Save Supplier</button>
  </div>
</div>
<?php endif; ?>

<!-- ══ VIEW SUPPLIER MODAL ══ -->
<div class="modal-bg" id="viewModal">
  <div class="mbox mbox-lg">
    <div class="m-hd">
      <div class="m-hd-ti">
        <div class="m-hd-ic" style="background:#E8F5E9;color:var(--primary)" id="vmIc"></div>
        <div><div class="m-hd-nm" id="vmNm"></div><div class="m-hd-sub" id="vmSub"></div></div>
      </div>
      <button class="m-cl" id="vmCl"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body">
      <div class="m-tabs">
        <button class="m-tab active" data-mt="info">Info</button>
        <?php if ($CAN_VIEW_METRICS): ?>
        <button class="m-tab" data-mt="perf">Performance</button>
        <?php endif; ?>
        <?php if ($CAN_VIEW_HISTORY): ?>
        <button class="m-tab" data-mt="history">Transaction History</button>
        <?php endif; ?>
      </div>
      <div class="m-tp active" id="mt-info"></div>
      <?php if ($CAN_VIEW_METRICS): ?>
      <div class="m-tp" id="mt-perf"></div>
      <?php endif; ?>
      <?php if ($CAN_VIEW_HISTORY): ?>
      <div class="m-tp" id="mt-history"></div>
      <?php endif; ?>
    </div>
    <div class="m-ft">
      <?php if ($CAN_ADD_EDIT): ?>
      <button class="btn btn-g btn-s" id="vmEdit"><i class='bx bx-edit-alt'></i> Edit</button>
      <?php endif; ?>
      <?php if ($CAN_BLACKLIST): ?>
      <button class="btn btn-danger btn-s" id="vmBlacklist"><i class='bx bx-block'></i> Blacklist</button>
      <?php endif; ?>
      <?php if ($CAN_FLAG_REVIEW): ?>
      <button class="btn btn-orange btn-s" id="vmFlag"><i class='bx bx-flag'></i> Flag for Review</button>
      <?php endif; ?>
      <button class="btn btn-g btn-s" id="vmClose"><i class='bx bx-x'></i> Close</button>
    </div>
  </div>
</div>

<!-- ══ EVALUATION MODAL — Admin+ only ══ -->
<?php if ($CAN_EVALUATE): ?>
<div class="modal-bg" id="evalModal">
  <div class="mbox">
    <div class="m-hd">
      <div class="m-hd-ti">
        <div class="m-hd-ic" style="background:#EFF6FF;color:var(--info)"><i class='bx bx-edit-alt'></i></div>
        <div><div class="m-hd-nm">Add Evaluation</div><div class="m-hd-sub" id="evalSub">Per-transaction evaluation</div></div>
      </div>
      <button class="m-cl" id="evalCl"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
        <div class="fg"><label class="fl">PO Reference <span>*</span></label><input type="text" class="fi" id="evalPO" placeholder="e.g. PO-2025-0042"></div>
        <div class="fg"><label class="fl">Branch <span>*</span></label>
          <select class="fs" id="evalBranch"><option value="">Select…</option><option>Head Office</option><option>Branch – Makati</option><option>Branch – Cebu</option><option>Branch – Davao</option><option>Branch – Iloilo</option></select>
        </div>
        <div class="fg"><label class="fl">On-Time Delivery <span>*</span></label>
          <select class="fs" id="evalOnTime"><option value="">Select…</option><option value="Yes">Yes — Delivered on time</option><option value="No">No — Late delivery</option></select>
        </div>
        <div class="fg"><label class="fl">Quality Score (1–5) <span>*</span></label>
          <select class="fs" id="evalQuality"><option value="">Select…</option><option value="5">5 — Excellent</option><option value="4">4 — Good</option><option value="3">3 — Average</option><option value="2">2 — Below Average</option><option value="1">1 — Poor</option></select>
        </div>
        <div class="fg" style="grid-column:1/-1"><label class="fl">Issues Encountered</label><textarea class="fta" id="evalIssues" placeholder="Describe any issues, e.g. damaged items, wrong quantity…" style="min-height:60px"></textarea></div>
        <div class="fg" style="grid-column:1/-1"><label class="fl">Remarks</label><input type="text" class="fi" id="evalRemarks" placeholder="Additional evaluation notes"></div>
      </div>
    </div>
    <div class="m-ft">
      <button class="btn btn-g btn-s" id="evalCancelBtn">Cancel</button>
      <button class="btn btn-info btn-s" id="evalSaveBtn"><i class='bx bx-check'></i> Save Evaluation</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══ WEIGHTS MODAL — Super Admin only ══ -->
<?php if ($CAN_WEIGHTS): ?>
<div class="modal-bg" id="weightsModal">
  <div class="mbox">
    <div class="m-hd">
      <div class="m-hd-ti">
        <div class="m-hd-ic" style="background:#E8F5E9;color:var(--primary)"><i class='bx bx-slider'></i></div>
        <div><div class="m-hd-nm">Scoring Weights &amp; Criteria</div><div class="m-hd-sub">Super Admin — adjust how overall rating is computed</div></div>
      </div>
      <button class="m-cl" id="wtCl"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body">
      <div class="sa-banner" style="margin-bottom:16px"><i class='bx bx-shield-quarter'></i><div>Changes affect all supplier overall ratings system-wide. Current total must equal 100%.</div></div>
      <div id="weightRows"></div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;padding:10px 0;border-top:1px solid var(--border)">
        <span style="font-size:13px;font-weight:600;color:var(--text-2)">Total Weight</span>
        <span id="wtTotal" style="font-family:var(--mono);font-size:15px;font-weight:800;color:var(--primary)">100%</span>
      </div>
    </div>
    <div class="m-ft">
      <button class="btn btn-g btn-s" id="wtCancel">Cancel</button>
      <button class="btn btn-sa btn-s" id="wtSave"><i class='bx bx-check'></i> Apply Weights</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══ MERGE MODAL — Super Admin only ══ -->
<?php if ($CAN_MERGE): ?>
<div class="modal-bg" id="mergeModal">
  <div class="mbox">
    <div class="m-hd">
      <div class="m-hd-ti">
        <div class="m-hd-ic" style="background:#EDE9FE;color:var(--purple)"><i class='bx bx-git-merge'></i></div>
        <div><div class="m-hd-nm">Merge Duplicate Suppliers</div><div class="m-hd-sub">Super Admin — merge two supplier records into one</div></div>
      </div>
      <button class="m-cl" id="mergeCl"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body">
      <div class="sa-banner" style="margin-bottom:16px"><i class='bx bx-shield-quarter'></i><div>The <strong>Primary</strong> record is kept. The <strong>Duplicate</strong> is merged into it and removed. All transactions are transferred to the primary record.</div></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="fg"><label class="fl">Primary Record <span>*</span></label>
          <select class="fs" id="mergePrimary"><option value="">Select primary…</option></select>
        </div>
        <div class="fg"><label class="fl">Duplicate to Merge <span>*</span></label>
          <select class="fs" id="mergeDuplicate"><option value="">Select duplicate…</option></select>
        </div>
      </div>
      <div class="fg" style="margin-top:14px"><label class="fl">Reason <span>*</span></label><textarea class="fta" id="mergeReason" placeholder="State reason for merge (e.g. duplicate entry, rebranded supplier)…" style="min-height:60px"></textarea></div>
    </div>
    <div class="m-ft">
      <button class="btn btn-g btn-s" id="mergeCancelBtn">Cancel</button>
      <button class="btn btn-purple btn-s" id="mergeConfirmBtn"><i class='bx bx-git-merge'></i> Merge Records</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══ CONFIRM MODAL ══ -->
<div id="cfmModal">
  <div class="cfm-box">
    <div class="cfm-hd"><div class="cfm-hd-ic" id="cfmIc"></div><div><div class="cfm-hd-title" id="cfmTitle"></div><div class="cfm-hd-sub" id="cfmSub"></div></div></div>
    <div class="cfm-body" id="cfmBody"></div>
    <div class="cfm-reason-wrap" id="cfmReasonWrap"><label class="fl" style="color:var(--text-2);font-size:11px">Reason <span style="color:var(--danger)">*</span></label><textarea class="fta" id="cfmReason" placeholder="State your reason…" style="margin-top:6px;min-height:72px"></textarea></div>
    <div class="cfm-ft"><button class="btn btn-g btn-s" id="cfmCancel">Cancel</button><button class="btn btn-s" id="cfmOk">Confirm</button></div>
  </div>
</div>

<div id="tw"></div>

<script>
// ── ROLE CAPABILITIES (from PHP) ─────────────────────────────────────────────
const ROLE = <?= $jsRole ?>;

// ── COLORS & HELPERS ──────────────────────────────────────────────────
const COLS=['#2E7D32','#1B5E20','#388E3C','#0D9488','#2563EB','#7C3AED','#D97706','#DC2626','#0891B2','#059669'];
const gc=n=>{let h=0;for(const c of n)h=(h*31+c.charCodeAt(0))%COLS.length;return COLS[h]};
const ini=n=>n.split(' ').map(w=>w[0]).join('').substring(0,2).toUpperCase();
const esc=s=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmtDate=d=>d?new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'}):'—';

// ── DATA ──────────────────────────────────────────────────────────────
let SUPPLIERS=[];
let PERF=[];
let HIST={};
let WEIGHTS={onTime:30,quality:40,completion:20,issues:10};

// ── STATE ──────────────────────────────────────────────────────────────
let dirSort='name',dirDir='asc',dirPg=1,PG=8;
let perfSort='overall',perfDir='desc',perfPg=1;
let editId=null,viewId=null,evalTargetId=null,cfmCb=null;

// ── HELPERS ──────────────────────────────────────────────────────────────
const supById=id=>SUPPLIERS.find(s=>s.id===id);
const perfById=id=>PERF.find(p=>p.supId===id);
const chipStatus=s=>({Active:'ch-active',Inactive:'ch-inactive',Blacklisted:'ch-blacklisted','Pending Accreditation':'ch-pending'}[s]||'ch-inactive');
const chipAccred=s=>({Accredited:'ch-accredited',Pending:'ch-pending',Expired:'ch-inactive','Not Required':'ch-inactive'}[s]||'ch-inactive');
const chip=(s,cls)=>`<span class="chip ${cls}">${s}</span>`;
const starsHtml=r=>{let h='<span class="stars">';for(let i=1;i<=5;i++)h+=`<i class="star bx bxs-star${i<=Math.round(r)?' on':''}"></i>`;return h+`<span class="rating-num">${r.toFixed(1)}</span></span>`};
const ratingColor=r=>r>=4.5?'#2E7D32':r>=3.5?'#D97706':'#DC2626';
const perfBarColor=v=>v>=85?'#2E7D32':v>=65?'#D97706':'#DC2626';

// ── DIRECTORY FILTER ──────────────────────────────────────────────────
function getDirFiltered(){
  const q=(document.getElementById('dirSrch')?.value||'').trim().toLowerCase();
  const cat=document.getElementById('dirCat')?.value||'';
  const st=document.getElementById('dirStatus')?.value||'';
  const rt=document.getElementById('dirRating')?.value||'';
  const df=document.getElementById('dirFrom')?.value||'';
  const dt=document.getElementById('dirTo')?.value||'';
  return SUPPLIERS.filter(s=>{
    if(q&&!s.name.toLowerCase().includes(q)&&!s.contact.toLowerCase().includes(q)&&!s.email.toLowerCase().includes(q))return false;
    if(cat&&s.category!==cat)return false;
    if(st&&s.status!==st)return false;
    if(rt){if(rt==='low'&&s.rating>=3)return false;else if(rt!=='low'&&s.rating<parseFloat(rt))return false;}
    if(df&&s.dateAdded<df)return false;
    if(dt&&s.dateAdded>dt)return false;
    return true;
  });
}
function getDirSorted(list){
  return[...list].sort((a,b)=>{
    let va=a[dirSort]??'',vb=b[dirSort]??'';
    if(typeof va==='number')return dirDir==='asc'?va-vb:vb-va;
    return dirDir==='asc'?String(va).localeCompare(String(vb)):String(vb).localeCompare(String(va));
  });
}

// ── PERF FILTER ──────────────────────────────────────────────────
function getPerfFiltered(){
  const q=(document.getElementById('perfSrch')?.value||'').trim().toLowerCase();
  const sup=document.getElementById('perfSupFilter')?.value||'';
  const rt=document.getElementById('perfRating')?.value||'';
  const br=document.getElementById('perfBranch')?.value||'';
  const df=document.getElementById('perfFrom')?.value||'';
  const dt=document.getElementById('perfTo')?.value||'';
  return PERF.filter(p=>{
    const s=supById(p.supId);if(!s)return false;
    if(q&&!s.name.toLowerCase().includes(q))return false;
    if(sup&&String(p.supId)!==sup)return false;
    if(rt){if(rt==='low'&&p.overall>=3)return false;else if(rt!=='low'&&p.overall<parseFloat(rt))return false;}
    if(br&&p.branch!==br)return false;
    if(df&&s.dateAdded<df)return false;
    if(dt&&s.dateAdded>dt)return false;
    return true;
  });
}
function getPerfSorted(list){
  return[...list].sort((a,b)=>{
    const getSup=x=>supById(x.supId);
    let va,vb;
    if(perfSort==='name'){va=getSup(a)?.name||'';vb=getSup(b)?.name||'';}
    else{va=a[perfSort]??0;vb=b[perfSort]??0;}
    if(typeof va==='number')return perfDir==='asc'?va-vb:vb-va;
    return perfDir==='asc'?String(va).localeCompare(String(vb)):String(vb).localeCompare(String(va));
  });
}

// ── RENDER ──────────────────────────────────────────────────────────────
function render(){rDirStats();rDirDropdowns();rDirTable();rPerfStats();rPerfDropdowns();rPerfTable();}

function rDirStats(){
  const all=SUPPLIERS;
  // Staff/User: simplified stats (only active shown)
  if(ROLE.rank<=1){
    document.getElementById('dirStats').innerHTML=`
      <div class="stat"><div class="stat-ic ic-g"><i class='bx bx-buildings'></i></div><div><div class="stat-v">${all.length}</div><div class="stat-l">Active Suppliers</div></div></div>
      <div class="stat"><div class="stat-ic ic-b"><i class='bx bx-shield-quarter'></i></div><div><div class="stat-v">${all.filter(s=>s.accreditation==='Accredited').length}</div><div class="stat-l">Accredited</div></div></div>
      <div class="stat"><div class="stat-ic ic-o"><i class='bx bx-time-five'></i></div><div><div class="stat-v">${all.filter(s=>s.accreditation==='Pending').length}</div><div class="stat-l">Pending Accreditation</div></div></div>`;
    return;
  }
  // Manager: slightly limited stats
  if(ROLE.rank===2){
    document.getElementById('dirStats').innerHTML=`
      <div class="stat"><div class="stat-ic ic-g"><i class='bx bx-buildings'></i></div><div><div class="stat-v">${all.length}</div><div class="stat-l">Total Suppliers</div></div></div>
      <div class="stat"><div class="stat-ic ic-g"><i class='bx bx-check-circle'></i></div><div><div class="stat-v">${all.filter(s=>s.status==='Active').length}</div><div class="stat-l">Active</div></div></div>
      <div class="stat"><div class="stat-ic ic-b"><i class='bx bx-shield-quarter'></i></div><div><div class="stat-v">${all.filter(s=>s.accreditation==='Accredited').length}</div><div class="stat-l">Accredited</div></div></div>
      <div class="stat"><div class="stat-ic ic-o"><i class='bx bx-time-five'></i></div><div><div class="stat-v">${all.filter(s=>s.status==='Pending Accreditation').length}</div><div class="stat-l">Pending</div></div></div>`;
    return;
  }
  // Admin / Super Admin: full stats
  document.getElementById('dirStats').innerHTML=`
    <div class="stat"><div class="stat-ic ic-g"><i class='bx bx-buildings'></i></div><div><div class="stat-v">${all.length}</div><div class="stat-l">Total Suppliers</div></div></div>
    <div class="stat"><div class="stat-ic ic-g"><i class='bx bx-check-circle'></i></div><div><div class="stat-v">${all.filter(s=>s.status==='Active').length}</div><div class="stat-l">Active</div></div></div>
    <div class="stat"><div class="stat-ic ic-o"><i class='bx bx-time-five'></i></div><div><div class="stat-v">${all.filter(s=>s.status==='Pending Accreditation').length}</div><div class="stat-l">Pending Accreditation</div></div></div>
    <div class="stat"><div class="stat-ic ic-r"><i class='bx bx-block'></i></div><div><div class="stat-v">${all.filter(s=>s.status==='Blacklisted').length}</div><div class="stat-l">Blacklisted</div></div></div>
    <div class="stat"><div class="stat-ic ic-b"><i class='bx bx-shield-quarter'></i></div><div><div class="stat-v">${all.filter(s=>s.accreditation==='Accredited').length}</div><div class="stat-l">Accredited</div></div></div>
    <div class="stat"><div class="stat-ic ic-pu"><i class='bx bx-flag'></i></div><div><div class="stat-v">${all.filter(s=>s.flagged).length}</div><div class="stat-l">Compliance Flagged</div></div></div>`;
}

function rDirDropdowns(){
  const cats=[...new Set(SUPPLIERS.map(s=>s.category))].sort();
  const el=document.getElementById('dirCat'),v=el.value;
  el.innerHTML='<option value="">All Categories</option>'+cats.map(c=>`<option${c===v?' selected':''}>${esc(c)}</option>`).join('');
}

function rPerfDropdowns(){
  const el=document.getElementById('perfSupFilter');
  if(!el)return;
  const v=el.value;
  el.innerHTML='<option value="">All Suppliers</option>'+SUPPLIERS.map(s=>`<option value="${s.id}"${String(s.id)===v?' selected':''}>${esc(s.name)}</option>`).join('');
}

function rDirTable(){
  const list=getDirSorted(getDirFiltered()),total=list.length,pages=Math.max(1,Math.ceil(total/PG));
  if(dirPg>pages)dirPg=pages;
  const sl=list.slice((dirPg-1)*PG,dirPg*PG);

  document.getElementById('dirTb').innerHTML=sl.length?sl.map((s,i)=>{
    // Build action buttons based on role
    let actions='<button class="btn btn-g btn-s" style="padding:5px 8px" onclick="oView('+s.id+')" title="View"><i class=\'bx bx-show\'></i></button>';

    if(ROLE.canAddEdit){
      actions+=` <button class="btn btn-g btn-s" style="padding:5px 8px" onclick="oEdit(${s.id})" title="Edit"><i class='bx bx-edit-alt'></i></button>`;
      if(s.status==='Active')
        actions+=` <button class="btn btn-g btn-s" style="padding:5px 8px" onclick="doDeactivate(${s.id})" title="Deactivate"><i class='bx bx-pause-circle'></i></button>`;
      if(s.status==='Inactive')
        actions+=` <button class="btn btn-g btn-s" style="padding:5px 8px" onclick="doReactivate(${s.id})" title="Reactivate"><i class='bx bx-play-circle'></i></button>`;
      if(s.status==='Pending Accreditation')
        actions+=` <button class="btn btn-info btn-s" style="padding:5px 8px" onclick="doAccredit(${s.id})" title="Accredit"><i class='bx bx-shield-check'></i></button>`;
      if(s.status!=='Blacklisted')
        actions+=` <button class="btn btn-danger btn-s" style="padding:5px 8px" onclick="doBlacklist(${s.id})" title="Blacklist"><i class='bx bx-block'></i></button>`;
      else
        actions+=` <button class="btn btn-g btn-s" style="padding:5px 8px" onclick="doReactivate(${s.id})" title="Restore"><i class='bx bx-refresh'></i></button>`;
    } else if(ROLE.canFlagReview && !s.flagged){
      // Admin can flag
      actions+=` <button class="btn btn-orange btn-s" style="padding:5px 8px" onclick="doFlagReview(${s.id})" title="Flag for Review"><i class='bx bx-flag'></i></button>`;
    }

    // Build columns based on role
    const nameCell=`<td>
      <div class="sup-cell">
        <div class="sup-av" style="background:${gc(s.name)}">${ini(s.name)}</div>
        <div>
          <div class="sup-name">${esc(s.name)}${s.flagged?` <span style="font-size:9px;background:#FEE2E2;color:var(--danger);padding:1px 6px;border-radius:20px;font-weight:700;vertical-align:middle">FLAGGED</span>`:''}</div>
          <div class="sup-sub">${esc(s.category)}</div>
        </div>
      </div>
    </td>`;

    let contactCols='';
    if(ROLE.rank>=3){
      // Admin+ full contact columns
      contactCols=`<td><div style="font-size:13px;font-weight:500;white-space:nowrap">${esc(s.contact)}</div></td>
      <td>
        <div class="cell-trunc" style="font-size:12px" title="${esc(s.email)}">${esc(s.email)}</div>
        <div class="sup-sub" style="white-space:nowrap">${esc(s.phone)}</div>
      </td>`;
    } else if(ROLE.rank<=1){
      // Staff: combined contact info
      contactCols=`<td>
        <div style="font-size:12px;font-weight:500">${esc(s.contact)}</div>
        <div class="sup-sub">${esc(s.email)}</div>
        <div class="sup-sub">${esc(s.phone)}</div>
      </td>`;
    }
    // Manager: no contact columns

    const catCell=`<td><div style="font-size:12px;font-weight:500;white-space:nowrap">${esc(s.category)}</div></td>`;

    let statusCols='';
    if(ROLE.rank>=3){
      statusCols=`<td>${chip(s.accreditation,chipAccred(s.accreditation))}</td><td>${chip(s.status,chipStatus(s.status))}</td>`;
    } else {
      statusCols=`<td>${chip(s.accreditation,chipAccred(s.accreditation))}</td>`;
    }

    const ratingCell=`<td>${starsHtml(s.rating)}</td>`;
    const dateCell=ROLE.canAddEdit?`<td><div class="mono" style="white-space:nowrap">${fmtDate(s.dateAdded)}</div></td>`:'';
    const actCell=`<td onclick="event.stopPropagation()" style="white-space:nowrap">${actions}</td>`;

    return `<tr data-id="${s.id}">
      <td style="color:#5D6F62;font-size:12px;font-weight:600">${(dirPg-1)*PG+i+1}</td>
      ${nameCell}${contactCols}${catCell}${statusCols}${ratingCell}${dateCell}${actCell}
    </tr>`;
  }).join(''):`<tr><td colspan="10"><div class="empty"><i class='bx bx-buildings'></i><p>No suppliers match your filters.</p></div></td></tr>`;

  document.querySelectorAll('#tab-dir thead th.sortable').forEach(th=>{
    th.classList.toggle('sorted',th.dataset.col===dirSort);
    th.querySelector('.si').className=`bx ${th.dataset.col===dirSort?(dirDir==='asc'?'bx-sort-up':'bx-sort-down'):'bx-sort'} si`;
  });
  rPag(total,pages,'dir');
}

function rPerfStats(){
  const all=PERF;
  if(ROLE.rank<=1){
    // Staff: basic rating summary only
    document.getElementById('perfStats').innerHTML=`
      <div class="stat"><div class="stat-ic ic-g"><i class='bx bx-trophy'></i></div><div><div class="stat-v">${all.filter(p=>p.overall>=4.5).length}</div><div class="stat-l">Top Rated</div></div></div>
      <div class="stat"><div class="stat-ic ic-o"><i class='bx bx-trending-up'></i></div><div><div class="stat-v">${all.length?+(all.reduce((s,p)=>s+p.overall,0)/all.length).toFixed(1):0}</div><div class="stat-l">Avg Rating</div></div></div>`;
    return;
  }
  // Admin+ full stats
  const avg=all.length?+(all.reduce((s,p)=>s+p.overall,0)/all.length).toFixed(1):0;
  document.getElementById('perfStats').innerHTML=`
    <div class="stat"><div class="stat-ic ic-b"><i class='bx bx-bar-chart-alt-2'></i></div><div><div class="stat-v">${all.length}</div><div class="stat-l">Evaluated</div></div></div>
    <div class="stat"><div class="stat-ic ic-g"><i class='bx bx-trophy'></i></div><div><div class="stat-v">${all.filter(p=>p.overall>=4.5).length}</div><div class="stat-l">Top Performers</div></div></div>
    <div class="stat"><div class="stat-ic ic-o"><i class='bx bx-trending-up'></i></div><div><div class="stat-v">${avg}</div><div class="stat-l">System Avg Rating</div></div></div>
    <div class="stat"><div class="stat-ic ic-r"><i class='bx bx-down-arrow-circle'></i></div><div><div class="stat-v">${all.filter(p=>p.overall<3).length}</div><div class="stat-l">Poor Performers</div></div></div>
    <div class="stat"><div class="stat-ic ic-t"><i class='bx bx-package'></i></div><div><div class="stat-v">${all.reduce((s,p)=>s+p.totalPOs,0)}</div><div class="stat-l">Total POs</div></div></div>
    <div class="stat"><div class="stat-ic ic-pu"><i class='bx bx-error-circle'></i></div><div><div class="stat-v">${all.reduce((s,p)=>s+p.issues,0)}</div><div class="stat-l">Total Issues</div></div></div>`;
}

function rPerfTable(){
  const list=getPerfSorted(getPerfFiltered()),total=list.length,pages=Math.max(1,Math.ceil(total/PG));
  if(perfPg>pages)perfPg=pages;
  const sl=list.slice((perfPg-1)*PG,perfPg*PG);
  const ranked=[...PERF].sort((a,b)=>b.overall-a.overall);
  const rankMap={};ranked.forEach((p,i)=>rankMap[p.supId]=i+1);

  document.getElementById('perfTb').innerHTML=sl.length?sl.map((p,i)=>{
    const s=supById(p.supId);if(!s)return'';
    const rk=rankMap[p.supId]||0;
    const rkBadge=rk&&ROLE.rank>=3?`<span style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;font-size:10px;font-weight:800;background:${rk===1?'#FEF3C7':rk===2?'#F3F4F6':rk===3?'#FEF2F2':'#F9FAFB'};color:${rk===1?'#92400E':rk===2?'#374151':rk===3?'#991B1B':'#9CA3AF'};border:2px solid ${rk===1?'#FDE68A':rk===2?'#D1D5DB':rk===3?'#FECACA':'#E5E7EB'};margin-right:5px;flex-shrink:0">${rk}</span>`:'';
    const cmpPct=Math.round((p.completed/Math.max(p.totalPOs,1))*100);

    // Columns based on role
    const posCols=ROLE.rank>=3?`
      <td><div class="mono" style="font-weight:700">${p.totalPOs}</div></td>
      <td><div class="mono" style="font-weight:700">${p.completed}</div><div style="font-size:10px;color:var(--text-3)">${cmpPct}%</div></td>`:'';
    const issueCols=ROLE.rank>=3?`<td><span style="font-family:var(--mono);font-weight:700;color:${p.issues>5?'var(--danger)':p.issues>2?'var(--warning)':'var(--primary)'}">${p.issues}</span></td>`:'';

    // Actions based on role
    let actions='<button class="btn btn-g btn-s" style="padding:5px 8px" onclick="oView('+p.supId+')" title="View"><i class=\'bx bx-show\'></i></button>';
    if(ROLE.canEvaluate)
      actions+=` <button class="btn btn-g btn-s" style="padding:5px 8px" onclick="oEval(${p.supId})" title="Add Evaluation"><i class='bx bx-edit-alt'></i></button>`;
    if(ROLE.canViewHistory)
      actions+=` <button class="btn btn-g btn-s" style="padding:5px 8px" onclick="oPerfHistory(${p.supId})" title="View History"><i class='bx bx-history'></i></button>`;
    if(ROLE.canExportZone)
      actions+=` <button class="btn btn-g btn-s" style="padding:5px 8px" onclick="exportPerf(${p.supId})" title="Export Zone Report"><i class='bx bx-export'></i></button>`;
    if(ROLE.canBlacklist&&p.overall<3)
      actions+=` <button class="btn btn-danger btn-s" style="padding:5px 8px" onclick="doBlacklist(${p.supId})" title="Flag for Blacklist"><i class='bx bx-flag'></i></button>`;

    return`<tr data-pid="${p.supId}">
      <td style="color:#5D6F62;font-size:12px;font-weight:600">${(perfPg-1)*PG+i+1}</td>
      <td>
        <div class="sup-cell">
          ${rkBadge}
          <div class="sup-av" style="background:${gc(s.name)}">${ini(s.name)}</div>
          <div><div class="sup-name">${esc(s.name)}</div><div class="sup-sub">Head Office</div></div>
        </div>
      </td>
      ${posCols}
      <td>
        <div style="display:flex;align-items:center;gap:6px">
          <span class="perf-bar-bg"><span class="perf-bar-fill" style="width:${p.onTime}%;background:${perfBarColor(p.onTime)}"></span></span>
          <span class="mono" style="font-weight:700;color:${perfBarColor(p.onTime)}">${p.onTime}%</span>
        </div>
      </td>
      <td>${starsHtml(p.quality)}</td>
      ${issueCols}
      <td>${starsHtml(p.overall)}</td>
      <td onclick="event.stopPropagation()" style="white-space:nowrap">${actions}</td>
    </tr>`;
  }).join(''):`<tr><td colspan="9"><div class="empty"><i class='bx bx-bar-chart-alt-2'></i><p>No performance data matches your filters.</p></div></td></tr>`;

  document.querySelectorAll('#tab-perf thead th.sortable').forEach(th=>{
    th.classList.toggle('sorted',th.dataset.pcol===perfSort);
    th.querySelector('.si').className=`bx ${th.dataset.pcol===perfSort?(perfDir==='asc'?'bx-sort-up':'bx-sort-down'):'bx-sort'} si`;
  });
  rPag(total,pages,'perf');
}

function rPag(total,pages,which){
  const pgV=which==='dir'?dirPg:perfPg;
  const s=(pgV-1)*PG+1,e=Math.min(pgV*PG,total);
  let btns='';
  for(let i=1;i<=pages;i++){
    if(i===1||i===pages||(i>=pgV-2&&i<=pgV+2))btns+=`<button class="pb${i===pgV?' active':''}" onclick="${which==='dir'?'dirGo':'perfGo'}(${i})">${i}</button>`;
    else if(i===pgV-3||i===pgV+3)btns+=`<button class="pb" disabled>…</button>`;
  }
  document.getElementById(which+'Pag').innerHTML=`<span>${total?`Showing ${s}–${e} of ${total}`:'No results'}</span><div class="pbtns"><button class="pb" onclick="${which==='dir'?'dirGo':'perfGo'}(${pgV-1})" ${pgV<=1?'disabled':''}><i class='bx bx-chevron-left'></i></button>${btns}<button class="pb" onclick="${which==='dir'?'dirGo':'perfGo'}(${pgV+1})" ${pgV>=pages?'disabled':''}><i class='bx bx-chevron-right'></i></button></div>`;
}
window.dirGo=p=>{dirPg=p;rDirTable()};
window.perfGo=p=>{perfPg=p;rPerfTable()};

// ── SORT ──────────────────────────────────────────────────────────────
document.querySelectorAll('#tab-dir thead th.sortable').forEach(th=>{
  th.addEventListener('click',()=>{const c=th.dataset.col;dirSort===c?(dirDir=dirDir==='asc'?'desc':'asc'):(dirSort=c,dirDir='asc');dirPg=1;rDirTable();});
});
document.querySelectorAll('#tab-perf thead th.sortable').forEach(th=>{
  th.addEventListener('click',()=>{const c=th.dataset.pcol;perfSort===c?(perfDir=perfDir==='asc'?'desc':'asc'):(perfSort=c,perfDir='asc');perfPg=1;rPerfTable();});
});

// ── TABS ──────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-'+btn.dataset.tab).classList.add('active');
  });
});

// ── FILTERS ──────────────────────────────────────────────────────────────
['dirSrch','dirCat','dirStatus','dirRating','dirFrom','dirTo'].forEach(id=>{
  document.getElementById(id)?.addEventListener('input',()=>{dirPg=1;rDirTable();});
});
['perfSrch','perfSupFilter','perfRating','perfBranch','perfFrom','perfTo'].forEach(id=>{
  document.getElementById(id)?.addEventListener('input',()=>{perfPg=1;rPerfTable();});
});
document.getElementById('dirClearDates')?.addEventListener('click',()=>{
  document.getElementById('dirFrom').value='';document.getElementById('dirTo').value='';dirPg=1;rDirTable();
});
document.getElementById('perfClear')?.addEventListener('click',()=>{
  document.getElementById('perfFrom').value='';document.getElementById('perfTo').value='';perfPg=1;rPerfTable();
});

// ── ROW CLICK → VIEW ──────────────────────────────────────────────────────────────
document.getElementById('dirTb').addEventListener('click',function(e){
  const tr=e.target.closest('tr[data-id]');if(!tr||e.target.closest('button'))return;
  oView(parseInt(tr.dataset.id));
});
document.getElementById('perfTb').addEventListener('click',function(e){
  const tr=e.target.closest('tr[data-pid]');if(!tr||e.target.closest('button'))return;
  oView(parseInt(tr.dataset.pid));
});

// ── VIEW MODAL ──────────────────────────────────────────────────────────────
function oView(id){
  const s=supById(id);if(!s)return;viewId=id;
  const p=perfById(id);
  document.getElementById('vmIc').innerHTML=`<span style="font-size:16px;font-weight:700;color:#fff;background:${gc(s.name)};width:100%;height:100%;display:flex;align-items:center;justify-content:center;border-radius:12px">${ini(s.name)}</span>`;
  document.getElementById('vmNm').textContent=s.name;

  let subHtml=`${esc(s.category)} &nbsp;·&nbsp; `;
  // Manager/Staff see limited status info
  if(ROLE.rank>=3){
    subHtml+=`${chip(s.status,chipStatus(s.status))} &nbsp; ${chip(s.accreditation,chipAccred(s.accreditation))}`;
  } else {
    subHtml+=chip(s.accreditation,chipAccred(s.accreditation));
  }
  document.getElementById('vmSub').innerHTML=subHtml;

  // Info tab — columns based on role
  let infoGrid=`
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">`;
  if(ROLE.rank>=3){
    infoGrid+=`
      <div><label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);display:block;margin-bottom:4px">Contact Person</label><div style="font-size:13px;font-weight:500">${esc(s.contact)}</div></div>
      <div><label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);display:block;margin-bottom:4px">Phone</label><div style="font-size:13px;font-family:var(--mono)">${esc(s.phone)}</div></div>
      <div><label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);display:block;margin-bottom:4px">Email</label><div style="font-size:13px">${esc(s.email)}</div></div>
      <div><label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);display:block;margin-bottom:4px">Date Added</label><div style="font-size:13px;font-family:var(--mono)">${fmtDate(s.dateAdded)}</div></div>
      <div style="grid-column:1/-1"><label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);display:block;margin-bottom:4px">Address</label><div style="font-size:13px">${esc(s.address)||'—'}</div></div>
      ${s.notes?`<div style="grid-column:1/-1"><label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);display:block;margin-bottom:4px">Notes</label><div style="font-size:13px;color:var(--text-2)">${esc(s.notes)}</div></div>`:''}
      ${s.flagged?`<div style="grid-column:1/-1;background:#FEE2E2;border:1px solid #FECACA;border-radius:10px;padding:10px 14px;font-size:12px;color:var(--danger);display:flex;align-items:center;gap:8px"><i class='bx bx-flag' style="font-size:16px"></i><strong>Compliance Flag Active</strong> — This supplier is flagged for review.</div>`:''}`;
  } else if(ROLE.rank<=1){
    // Staff: contact info for purchasing
    infoGrid+=`
      <div><label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);display:block;margin-bottom:4px">Contact Person</label><div style="font-size:13px;font-weight:500">${esc(s.contact)}</div></div>
      <div><label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);display:block;margin-bottom:4px">Phone</label><div style="font-size:13px;font-family:var(--mono)">${esc(s.phone)}</div></div>
      <div style="grid-column:1/-1"><label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);display:block;margin-bottom:4px">Email</label><div style="font-size:13px">${esc(s.email)}</div></div>`;
  } else {
    // Manager: name + category + accreditation only
    infoGrid+=`
      <div><label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);display:block;margin-bottom:4px">Category</label><div style="font-size:13px;font-weight:500">${esc(s.category)}</div></div>
      <div><label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);display:block;margin-bottom:4px">Accreditation</label><div>${chip(s.accreditation,chipAccred(s.accreditation))}</div></div>`;
  }
  infoGrid+=`</div>`;
  document.getElementById('mt-info').innerHTML=infoGrid;

  // Performance tab (Admin+ only, rendered in PHP conditionally)
  const perfEl=document.getElementById('mt-perf');
  if(perfEl){
    perfEl.innerHTML=p?`
      <div class="sc-grid">
        <div class="sc-box"><div class="sc-v">${p.totalPOs}</div><div class="sc-l">Total POs</div></div>
        <div class="sc-box"><div class="sc-v">${p.completed}</div><div class="sc-l">Completed</div></div>
        <div class="sc-box"><div class="sc-v" style="color:${perfBarColor(p.onTime)}">${p.onTime}%</div><div class="sc-l">On-Time Delivery</div></div>
        <div class="sc-box"><div class="sc-v" style="color:${ratingColor(p.quality)}">${p.quality}</div><div class="sc-l">Quality Score</div></div>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px;background:var(--primary-light);border:1px solid var(--border-mid);border-radius:10px">
        <div><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-2);margin-bottom:6px">Overall Rating</div>${starsHtml(p.overall)}</div>
        <div style="text-align:right"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-2);margin-bottom:4px">Issue Count</div><span style="font-family:var(--mono);font-size:20px;font-weight:800;color:${p.issues>5?'var(--danger)':p.issues>2?'var(--warning)':'var(--primary)'}">${p.issues}</span></div>
      </div>`:'<div style="padding:24px;text-align:center;color:var(--text-3);font-size:13px"><i class="bx bx-bar-chart-alt-2" style="font-size:36px;display:block;margin-bottom:8px;color:#C8E6C9"></i>No performance data yet.</div>';
  }

  // History tab (Admin+ only, rendered in PHP conditionally)
  const histEl=document.getElementById('mt-history');
  if(histEl){
    const hist=HIST[id]||[{dot:'hd-b',txt:'Supplier record created',ts:fmtDate(s.dateAdded)}];
    histEl.innerHTML=hist.map(h=>`<div class="hist-item"><div class="hist-dot ${h.dot}"></div><div><div class="hist-txt">${esc(h.txt)}</div><div class="hist-ts">${h.ts}</div></div></div>`).join('');
  }

  // Reset tabs
  document.querySelectorAll('.m-tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.m-tp').forEach(p=>p.classList.remove('active'));
  document.querySelector('.m-tab[data-mt="info"]').classList.add('active');
  document.getElementById('mt-info').classList.add('active');

  // Conditional footer buttons
  const blBtn=document.getElementById('vmBlacklist');
  if(blBtn)blBtn.style.display=s.status==='Blacklisted'?'none':'inline-flex';
  const flagBtn=document.getElementById('vmFlag');
  if(flagBtn)flagBtn.style.display=s.flagged?'none':'inline-flex';

  document.getElementById('viewModal').classList.add('show');
}
window.oView=oView;

document.querySelectorAll('.m-tab').forEach(t=>t.addEventListener('click',()=>{
  document.querySelectorAll('.m-tab').forEach(x=>x.classList.remove('active'));
  document.querySelectorAll('.m-tp').forEach(x=>x.classList.remove('active'));
  t.classList.add('active');document.getElementById('mt-'+t.dataset.mt).classList.add('active');
}));
['vmCl','vmClose'].forEach(id=>document.getElementById(id)?.addEventListener('click',()=>{document.getElementById('viewModal').classList.remove('show');viewId=null;}));
document.getElementById('viewModal').addEventListener('click',function(e){if(e.target===this){this.classList.remove('show');viewId=null;}});
document.getElementById('vmEdit')?.addEventListener('click',()=>{const id=viewId;document.getElementById('viewModal').classList.remove('show');oEdit(id);});
document.getElementById('vmBlacklist')?.addEventListener('click',()=>{const id=viewId;document.getElementById('viewModal').classList.remove('show');doBlacklist(id);});
document.getElementById('vmFlag')?.addEventListener('click',()=>{const id=viewId;document.getElementById('viewModal').classList.remove('show');doFlagReview(id);});

// ── PANEL (Add / Edit) — SA only ──────────────────────────────────────────────────────────────
<?php if ($CAN_ADD_EDIT): ?>
function oPn(){document.getElementById('panel').classList.add('open');document.getElementById('ov').classList.add('show');}
function cPn(){document.getElementById('panel').classList.remove('open');document.getElementById('ov').classList.remove('show');editId=null;}
document.getElementById('ov').addEventListener('click',cPn);
['pCl','pCa'].forEach(id=>document.getElementById(id).addEventListener('click',cPn));

document.getElementById('addBtn').addEventListener('click',()=>{
  editId=null;clrF();
  document.getElementById('pT').textContent='Add Supplier';
  document.getElementById('pS').textContent='Fill in supplier details';
  document.getElementById('pSv').innerHTML='<i class="bx bx-plus"></i> Add Supplier';
  oPn();document.getElementById('fName').focus();
});
function oEdit(id){
  const s=supById(id);if(!s)return;editId=id;
  document.getElementById('fName').value=s.name;
  document.getElementById('fCat').value=s.category;
  document.getElementById('fContact').value=s.contact;
  document.getElementById('fPhone').value=s.phone;
  document.getElementById('fEmail').value=s.email;
  document.getElementById('fWeb').value=s.web||'';
  document.getElementById('fAddr').value=s.address||'';
  document.getElementById('fStatus').value=['Active','Inactive','Pending Accreditation'].includes(s.status)?s.status:'Active';
  document.getElementById('fAccred').value=s.accreditation||'Pending';
  document.getElementById('fNotes').value=s.notes||'';
  document.getElementById('fOverStatus').value='';
  document.getElementById('fFlag').value=s.flagged?'flagged':'';
  document.getElementById('fOverReason').value='';
  document.getElementById('pT').textContent='Edit Supplier';
  document.getElementById('pS').textContent=s.name;
  document.getElementById('pSv').innerHTML='<i class="bx bx-check"></i> Save Changes';
  oPn();
}
window.oEdit=oEdit;
function clrF(){['fName','fContact','fPhone','fEmail','fWeb','fAddr','fNotes','fOverReason'].forEach(id=>document.getElementById(id).value='');['fCat','fOverStatus','fFlag'].forEach(id=>document.getElementById(id).value='');document.getElementById('fStatus').value='Active';document.getElementById('fAccred').value='Pending';}
document.getElementById('pSv').addEventListener('click',()=>{
  const name=document.getElementById('fName').value.trim();
  const cat=document.getElementById('fCat').value;
  const contact=document.getElementById('fContact').value.trim();
  const phone=document.getElementById('fPhone').value.trim();
  const email=document.getElementById('fEmail').value.trim();
  if(!name){shk('fName');return toast('Supplier name is required','danger');}
  if(!cat){shk('fCat');return toast('Category is required','danger');}
  if(!contact){shk('fContact');return toast('Contact person is required','danger');}
  if(!phone){shk('fPhone');return toast('Phone is required','danger');}
  if(!email){shk('fEmail');return toast('Email is required','danger');}
  let status=document.getElementById('fStatus').value;
  const ovStatus=document.getElementById('fOverStatus').value;
  if(ovStatus)status=ovStatus;
  const flagVal=document.getElementById('fFlag').value;
  const data={id:editId,name,category:cat,contact,phone,email,web:document.getElementById('fWeb').value.trim(),address:document.getElementById('fAddr').value.trim(),status,accreditation:document.getElementById('fAccred').value,notes:document.getElementById('fNotes').value.trim(),flagged:flagVal==='flagged',overReason:document.getElementById('fOverReason').value.trim()};
  fetch('suppliers.php?api=save',{method:'POST',body:JSON.stringify(data)})
  .then(r=>r.json()).then(res=>{
    if(!res.success)return toast(res.error||'Failed to save supplier','danger');
    toast(`Supplier "${name}" saved successfully`,'success');
    cPn();initLoad(true);
  }).catch(()=>toast('Network error','danger'));
});
<?php else: ?>
function oEdit(id){toast('Insufficient permissions to edit suppliers','danger');}
window.oEdit=oEdit;
<?php endif; ?>

// ── ACTIONS ──────────────────────────────────────────────────────────────
function actionFetch(id, type, reason=''){
  fetch('suppliers.php?api=action',{method:'POST',body:JSON.stringify({id,type,reason})})
  .then(r=>r.json()).then(res=>{
    if(!res.success)return toast(res.error||'Action failed','danger');
    toast('Supplier updated','success');
    initLoad(true);
  }).catch(()=>toast('Network error','danger'));
}

<?php if ($CAN_DEACTIVATE): ?>
window.doDeactivate=id=>openCfm({ic:'bx-pause-circle',bg:'#FEF3C7',fc:'var(--warning)',title:'Deactivate Supplier',sub:`Deactivate ${supById(id)?.name}?`,body:`This supplier will be marked as <strong>Inactive</strong> and excluded from new RFQs.`,btnCls:'btn-warn',btnTxt:'<i class="bx bx-pause-circle"></i> Deactivate',reason:false,cb:()=>actionFetch(id,'deactivate')});
window.doReactivate=id=>openCfm({ic:'bx-play-circle',bg:'#E8F5E9',fc:'var(--primary)',title:'Reactivate Supplier',sub:`Reactivate ${supById(id)?.name}?`,body:`This supplier will be set back to <strong>Active</strong>.`,btnCls:'btn-p',btnTxt:'<i class="bx bx-play-circle"></i> Reactivate',reason:false,cb:()=>actionFetch(id,'reactivate')});
window.doAccredit=id=>openCfm({ic:'bx-shield-check',bg:'#EFF6FF',fc:'var(--info)',title:'Accredit Supplier',sub:`Accredit ${supById(id)?.name}?`,body:`This supplier will be marked as <strong>Accredited</strong> and eligible for all procurement activities.`,btnCls:'btn-info',btnTxt:'<i class="bx bx-shield-check"></i> Accredit',reason:false,cb:()=>actionFetch(id,'accredit')});
window.doBlacklist=id=>openCfm({ic:'bx-block',bg:'#FEE2E2',fc:'var(--danger)',title:'Blacklist Supplier',sub:`Blacklist ${supById(id)?.name} system-wide?`,body:`This supplier will be <strong>Blacklisted</strong> across all branches. They cannot be selected in any future RFQ or PO.`,btnCls:'btn-danger',btnTxt:'<i class="bx bx-block"></i> Blacklist System-wide',reason:true,cb:(reason)=>actionFetch(id,'blacklist',reason)});
<?php else: ?>
window.doDeactivate=id=>toast('Insufficient permissions','danger');
window.doReactivate=id=>toast('Insufficient permissions','danger');
window.doAccredit=id=>toast('Insufficient permissions','danger');
window.doBlacklist=id=>toast('Insufficient permissions','danger');
<?php endif; ?>

<?php if ($CAN_FLAG_REVIEW): ?>
window.doFlagReview=id=>openCfm({ic:'bx-flag',bg:'#FEF3C7',fc:'var(--warning)',title:'Flag for Review',sub:`Flag ${supById(id)?.name} for compliance review?`,body:`This supplier will be flagged and visible to Super Admins for investigation.`,btnCls:'btn-warn',btnTxt:'<i class="bx bx-flag"></i> Flag for Review',reason:true,cb:(reason)=>actionFetch(id,'flag',reason)});
<?php else: ?>
window.doFlagReview=id=>toast('Insufficient permissions','danger');
<?php endif; ?>

// ── EVAL MODAL — Admin+ ──────────────────────────────────────────────────────────────
<?php if ($CAN_EVALUATE): ?>
function oEval(id){
  const s=supById(id);if(!s)return;evalTargetId=id;
  document.getElementById('evalSub').textContent=`${s.name} — per-transaction evaluation`;
  ['evalPO','evalIssues','evalRemarks'].forEach(x=>document.getElementById(x).value='');
  ['evalBranch','evalOnTime','evalQuality'].forEach(x=>document.getElementById(x).value='');
  document.getElementById('evalModal').classList.add('show');
}
window.oEval=oEval;
document.getElementById('evalCl').onclick=()=>document.getElementById('evalModal').classList.remove('show');
document.getElementById('evalCancelBtn').onclick=()=>document.getElementById('evalModal').classList.remove('show');
document.getElementById('evalModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');});
document.getElementById('evalSaveBtn').addEventListener('click',()=>{
  const po=document.getElementById('evalPO').value.trim();
  const onTime=document.getElementById('evalOnTime').value;
  const quality=parseInt(document.getElementById('evalQuality').value);
  if(!po){shk('evalPO');return toast('PO Reference is required','danger');}
  if(!onTime){shk('evalOnTime');return toast('On-Time status is required','danger');}
  if(!quality){shk('evalQuality');return toast('Quality score is required','danger');}
  fetch('suppliers.php?api=evaluate',{method:'POST',body:JSON.stringify({id:evalTargetId,po,branch:document.getElementById('evalBranch').value,onTime,quality,issues:document.getElementById('evalIssues').value.trim(),remarks:document.getElementById('evalRemarks').value.trim()})})
  .then(r=>r.json()).then(res=>{
    if(!res.success)return toast(res.error||'Failed to save evaluation','danger');
    document.getElementById('evalModal').classList.remove('show');
    toast('Evaluation saved','success');initLoad(true);
  }).catch(()=>toast('Network error','danger'));
});
<?php else: ?>
window.oEval=id=>toast('Insufficient permissions to add evaluations','danger');
<?php endif; ?>

// ── PERF HISTORY ──────────────────────────────────────────────────────────────
function oPerfHistory(id){
  const s=supById(id);if(!s)return;viewId=id;oView(id);
  setTimeout(()=>{
    const histTab=document.querySelector('.m-tab[data-mt="history"]');
    if(!histTab){return;}
    document.querySelectorAll('.m-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.m-tp').forEach(p=>p.classList.remove('active'));
    histTab.classList.add('active');
    document.getElementById('mt-history').classList.add('active');
  },50);
}
window.oPerfHistory=oPerfHistory;
<?php if ($CAN_EXPORT_ZONE): ?>
window.exportPerf=id=>{
  const s=supById(id);if(!s)return;
  const p=perfById(id);if(!p)return;
  const rows=['Supplier,TotalPOs,Completed,OnTime%,Quality,Issues,Overall',[`"${s.name}"`,p.totalPOs,p.completed,p.onTime,p.quality,p.issues,p.overall].join(',')];
  const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));
  a.download=`perf_${s.name.replace(/\s+/g,'_')}.csv`;a.click();
  toast(`Performance report exported for ${s.name}`,'success');
};
<?php else: ?>
window.exportPerf=id=>toast('Insufficient permissions to export reports','danger');
<?php endif; ?>

// ── WEIGHTS MODAL — SA only ──────────────────────────────────────────────────────────────
<?php if ($CAN_WEIGHTS): ?>
let tmpWeights={...WEIGHTS};
document.getElementById('weightsBtn').addEventListener('click',()=>{
  tmpWeights={...WEIGHTS};
  renderWeights();
  document.getElementById('weightsModal').classList.add('show');
});
function renderWeights(){
  const entries=[['onTime','On-Time Delivery Rate'],['quality','Quality Score'],['completion','Completion Rate'],['issues','Issue-Free Rate']];
  document.getElementById('weightRows').innerHTML=entries.map(([k,lbl])=>`
    <div class="weight-row">
      <div class="weight-lbl">${lbl}</div>
      <input type="range" class="weight-slider" id="w_${k}" min="0" max="100" value="${tmpWeights[k]}">
      <div class="weight-disp" id="wd_${k}">${tmpWeights[k]}%</div>
    </div>`).join('');
  entries.forEach(([k])=>{
    document.getElementById('w_'+k).addEventListener('input',function(){
      tmpWeights[k]=+this.value;
      document.getElementById('wd_'+k).textContent=this.value+'%';
      const tot=Object.values(tmpWeights).reduce((a,b)=>a+b,0);
      document.getElementById('wtTotal').textContent=tot+'%';
      document.getElementById('wtTotal').style.color=tot===100?'var(--primary)':'var(--danger)';
    });
  });
  const tot=Object.values(tmpWeights).reduce((a,b)=>a+b,0);
  document.getElementById('wtTotal').textContent=tot+'%';
  document.getElementById('wtTotal').style.color=tot===100?'var(--primary)':'var(--danger)';
}
document.getElementById('wtCl').onclick=()=>document.getElementById('weightsModal').classList.remove('show');
document.getElementById('wtCancel').onclick=()=>document.getElementById('weightsModal').classList.remove('show');
document.getElementById('weightsModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');});
document.getElementById('wtSave').addEventListener('click',()=>{
  const tot=Object.values(tmpWeights).reduce((a,b)=>a+b,0);
  if(tot!==100){toast('Weights must total exactly 100%','danger');return;}
  Object.assign(WEIGHTS,tmpWeights);
  document.getElementById('weightsModal').classList.remove('show');
  toast('Scoring weights updated system-wide','success');
});
<?php endif; ?>

// ── MERGE MODAL — SA only ──────────────────────────────────────────────────────────────
<?php if ($CAN_MERGE): ?>
document.getElementById('mergeBtn').addEventListener('click',()=>{
  const opts=SUPPLIERS.filter(s=>s.status!=='Blacklisted').map(s=>`<option value="${s.id}">${esc(s.name)}</option>`).join('');
  document.getElementById('mergePrimary').innerHTML='<option value="">Select primary…</option>'+opts;
  document.getElementById('mergeDuplicate').innerHTML='<option value="">Select duplicate…</option>'+opts;
  document.getElementById('mergeReason').value='';
  document.getElementById('mergeModal').classList.add('show');
});
document.getElementById('mergeCl').onclick=()=>document.getElementById('mergeModal').classList.remove('show');
document.getElementById('mergeCancelBtn').onclick=()=>document.getElementById('mergeModal').classList.remove('show');
document.getElementById('mergeModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');});
document.getElementById('mergeConfirmBtn').addEventListener('click',()=>{
  const pId=parseInt(document.getElementById('mergePrimary').value);
  const dId=parseInt(document.getElementById('mergeDuplicate').value);
  const reason=document.getElementById('mergeReason').value.trim();
  if(!pId){shk('mergePrimary');return toast('Select primary record','danger');}
  if(!dId){shk('mergeDuplicate');return toast('Select duplicate record','danger');}
  if(pId===dId){return toast('Primary and duplicate must be different records','danger');}
  if(!reason){shk('mergeReason');return toast('Reason is required','danger');}
  fetch('suppliers.php?api=merge',{method:'POST',body:JSON.stringify({primary:pId,duplicate:dId,reason})})
  .then(r=>r.json()).then(res=>{
    if(!res.success)return toast(res.error||'Merge failed','danger');
    document.getElementById('mergeModal').classList.remove('show');
    toast('Records merged successfully','success');initLoad(true);
  }).catch(()=>toast('Network error','danger'));
});
<?php endif; ?>

// ── EXPORT — Admin+ ──────────────────────────────────────────────────────────────
<?php if ($CAN_EXPORT_ZONE): ?>
document.getElementById('expBtn')?.addEventListener('click',()=>{
  // Export columns based on role
  const cols=<?= $roleRank >= 4 ? "['name','contact','email','phone','category','status','accreditation','rating','dateAdded','address','notes']" : "['name','category','status','accreditation','rating']" ?>;
  const rows=[cols.join(','),...SUPPLIERS.map(s=>cols.map(c=>`"${String(s[c]??'').replace(/"/g,'""')}"`).join(','))];
  const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([rows.join('\n')],{type:'text/csv'}));a.download='suppliers.csv';a.click();
  toast('Supplier directory exported','success');
});
<?php endif; ?>

// ── CONFIRM MODAL ──────────────────────────────────────────────────────────────
function openCfm({ic,bg,fc,title,sub,body,btnCls,btnTxt,reason,cb}){
  document.getElementById('cfmIc').style.cssText=`background:${bg};color:${fc}`;
  document.getElementById('cfmIc').innerHTML=`<i class='bx ${ic}'></i>`;
  document.getElementById('cfmTitle').textContent=title;
  document.getElementById('cfmSub').textContent=sub;
  document.getElementById('cfmBody').innerHTML=`<div style="line-height:1.65">${body}</div>`;
  const rw=document.getElementById('cfmReasonWrap');rw.style.display=reason?'block':'none';
  document.getElementById('cfmReason').value='';
  const ok=document.getElementById('cfmOk');ok.className=`btn btn-s ${btnCls}`;ok.innerHTML=btnTxt;
  cfmCb=cb;
  document.getElementById('cfmModal').classList.add('show');
}
function closeCfm(){document.getElementById('cfmModal').classList.remove('show');cfmCb=null;}
document.getElementById('cfmCancel').addEventListener('click',closeCfm);
document.getElementById('cfmModal').addEventListener('click',function(e){if(e.target===this)closeCfm();});
document.getElementById('cfmOk').addEventListener('click',()=>{
  const needReason=document.getElementById('cfmReasonWrap').style.display!=='none';
  const reason=document.getElementById('cfmReason').value.trim();
  if(needReason&&!reason){shk('cfmReason');return toast('Please state a reason','danger');}
  const cb=cfmCb;closeCfm();if(cb)cb(reason);
});

// ── TOAST & SHAKE ──────────────────────────────────────────────────────────────
function toast(msg,type='success'){
  const icons={success:'bx-check-circle',danger:'bx-error-circle',warning:'bx-error',info:'bx-info-circle'};
  const el=document.createElement('div');el.className=`toast ${type}`;
  el.innerHTML=`<i class='bx ${icons[type]}' style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
  document.getElementById('tw').appendChild(el);
  setTimeout(()=>{el.classList.add('out');setTimeout(()=>el.remove(),300);},3200);
}
function shk(id){
  const el=document.getElementById(id);if(!el)return;
  el.style.borderColor='#DC2626';el.style.animation='none';el.offsetHeight;el.style.animation='shake .3s ease';
  setTimeout(()=>{el.style.borderColor='';el.style.animation='';},600);
}

// ── LOADING BAR ──────────────────────────────────────────────────────────────
(function(){
  const bar=document.createElement('div');
  bar.id='ldBar';
  bar.style.cssText='position:fixed;top:0;left:0;height:3px;width:0;background:var(--primary);z-index:9999;transition:width .25s ease,opacity .3s ease;pointer-events:none;opacity:0';
  document.body.appendChild(bar);
})();
function ldStart(){const b=document.getElementById('ldBar');b.style.opacity='1';b.style.width='60%';}
function ldDone(){const b=document.getElementById('ldBar');b.style.width='100%';setTimeout(()=>{b.style.opacity='0';setTimeout(()=>{b.style.width='0';},300);},200);}

// ── STATE SNAPSHOT ──────────────────────────────────────────────────────────
function snapState(){
  return{
    tab:document.querySelector('.tab-btn.active')?.dataset.tab||'dir',
    dirSrch:document.getElementById('dirSrch').value,
    dirCat:document.getElementById('dirCat').value,
    dirStatus:document.getElementById('dirStatus')?.value||'',
    dirRating:document.getElementById('dirRating')?.value||'',
    dirFrom:document.getElementById('dirFrom')?.value||'',
    dirTo:document.getElementById('dirTo')?.value||'',
    dirPg,
    perfSrch:document.getElementById('perfSrch').value,
    perfSupFilter:document.getElementById('perfSupFilter')?.value||'',
    perfRating:document.getElementById('perfRating')?.value||'',
    perfFrom:document.getElementById('perfFrom')?.value||'',
    perfTo:document.getElementById('perfTo')?.value||'',
    perfPg,
    scrollY:window.scrollY
  };
}
function restoreState(snap){
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
  const activeTab=document.querySelector(`.tab-btn[data-tab="${snap.tab}"]`);
  if(activeTab){activeTab.classList.add('active');document.getElementById('tab-'+snap.tab)?.classList.add('active');}
  document.getElementById('dirSrch').value=snap.dirSrch||'';
  if(document.getElementById('dirStatus'))document.getElementById('dirStatus').value=snap.dirStatus||'';
  if(document.getElementById('dirRating'))document.getElementById('dirRating').value=snap.dirRating||'';
  if(document.getElementById('dirFrom'))document.getElementById('dirFrom').value=snap.dirFrom||'';
  if(document.getElementById('dirTo'))document.getElementById('dirTo').value=snap.dirTo||'';
  dirPg=snap.dirPg||1;
  document.getElementById('perfSrch').value=snap.perfSrch||'';
  if(document.getElementById('perfRating'))document.getElementById('perfRating').value=snap.perfRating||'';
  if(document.getElementById('perfFrom'))document.getElementById('perfFrom').value=snap.perfFrom||'';
  if(document.getElementById('perfTo'))document.getElementById('perfTo').value=snap.perfTo||'';
  perfPg=snap.perfPg||1;
}

function initLoad(isReload){
  const snap=isReload?snapState():null;
  if(isReload)ldStart();
  fetch('suppliers.php?api=list').then(r=>r.json()).then(res=>{
    if(res.success){
      SUPPLIERS=res.data.suppliers;
      PERF=res.data.perf;
      HIST=res.data.hist;
      if(snap){
        restoreState(snap);
        render();
        document.getElementById('dirCat').value=snap.dirCat||'';
        if(document.getElementById('perfSupFilter'))document.getElementById('perfSupFilter').value=snap.perfSupFilter||'';
        rDirTable();rPerfTable();
        ldDone();
        requestAnimationFrame(()=>window.scrollTo({top:snap.scrollY,behavior:'instant'}));
      }else{
        render();
      }
    }else{
      toast(res.error||'Failed to load suppliers','danger');
      if(isReload)ldDone();
    }
  }).catch(()=>{toast('Network error loading suppliers','danger');if(isReload)ldDone();});
}

initLoad(false);
</script>
    </main>
</body>
</html>