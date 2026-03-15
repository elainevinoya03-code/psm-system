<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE & SCOPE (mirrors includes/superadmin_sidebar.php) ─────────────────────
function rlm_resolve_role(): string {
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

$rlmRoleName = rlm_resolve_role();
$rlmRoleRank = match($rlmRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,
};
$rlmUserZone = $_SESSION['zone'] ?? '';
$rlmUserId   = $_SESSION['user_id'] ?? null;

/**
 * Apply role-based scope to document list: filter by zone (Admin/Manager) or by ownership (User).
 * Returns array of rows filtered in PHP when Supabase query cannot express scope (e.g. OR ownership).
 */
function rlm_scope_documents(array $rows): array {
    global $rlmRoleName, $rlmUserZone, $rlmUserId;
    if ($rlmRoleName === 'Super Admin') return $rows;
    if ($rlmRoleName === 'Admin' || $rlmRoleName === 'Manager') {
        $zone = trim((string)$rlmUserZone);
        if ($zone === '') return $rows;
        return array_values(array_filter($rows, fn($r) => ($r['department'] ?? '') === $zone));
    }
    // User (Staff): my documents only (created_by or assigned_to)
    $uid = $rlmUserId !== null ? (string)$rlmUserId : '';
    if ($uid === '') return [];
    return array_values(array_filter($rows, function($r) use ($uid) {
        $created = isset($r['created_by']) ? (string)$r['created_by'] : '';
        $assigned = isset($r['assigned_to']) ? (string)$r['assigned_to'] : '';
        return $created === $uid || $assigned === $uid;
    }));
}

// ── HELPERS ──────────────────────────────────────────────────────────────────
function rlm_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function rlm_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function rlm_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/**
 * Supabase REST helper — identical pattern to dc_sb() in capture.php
 */
function rlm_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
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

/**
 * Build a clean document object from a raw DB row — mirrors dc_build() in capture.php
 */
function rlm_build(array $row): array {
    return [
        'id'              => (int)$row['id'],
        'docId'           => $row['doc_id']          ?? '',
        'title'           => $row['title']            ?? '',
        'refNumber'       => $row['ref_number']       ?? '',
        'docType'         => $row['doc_type']         ?? '',
        'category'        => $row['category']         ?? '',
        'department'      => $row['department']       ?? '',
        'direction'       => $row['direction']        ?? '',
        'sender'          => $row['sender']           ?? '',
        'recipient'       => $row['recipient']        ?? '',
        'assignedTo'      => $row['assigned_to']      ?? '',
        'docDate'         => $row['doc_date']         ?? '',
        'priority'        => $row['priority']         ?? 'Normal',
        'retention'       => $row['retention']        ?? '1 Year',
        'retentionStage'  => $row['retention_stage']  ?? 'Active',
        'accessLevel'     => $row['access_level']     ?? 'Internal',
        'notes'           => $row['notes']            ?? '',
        'captureMode'     => $row['capture_mode']     ?? 'physical',
        'fileName'        => $row['file_name']        ?? '',
        'fileSizeKb'      => (float)($row['file_size_kb'] ?? 0),
        'fileExt'         => $row['file_ext']         ?? '',
        'filePath'        => $row['file_path']        ?? '',
        'aiConfidence'    => (int)($row['ai_confidence']    ?? 0),
        'needsValidation' => (bool)($row['needs_validation'] ?? false),
        'status'          => $row['status']           ?? 'Registered',
        'disposedAt'      => $row['disposed_at']      ?? null,
        'disposedBy'      => $row['disposed_by']      ?? null,
        'disposalReason'  => $row['disposal_reason']  ?? null,
        'retentionExtended' => (bool)($row['retention_extended'] ?? false),
        'yearsActive'     => isset($row['doc_date'])
            ? round((time() - strtotime($row['doc_date'])) / 31536000, 1)
            : 0,
        'createdBy'       => $row['created_by']       ?? '',
        'createdAt'       => $row['created_at']       ?? '',
        'updatedAt'       => $row['updated_at']       ?? '',
    ];
}

// ── RETENTION STAGE LOGIC ─────────────────────────────────────────────────────
// Maps years-active to a lifecycle stage string.
// Rule: Active → 0–3 yrs | Archive → 3–7 yrs | Review → 7+ yrs | Disposed → explicit
function rlm_compute_stage(array $row): string {
    if (!empty($row['disposed_at'])) return 'Disposed';
    if (!empty($row['retention_stage'])) return $row['retention_stage'];
    $years = isset($row['doc_date'])
        ? (time() - strtotime($row['doc_date'])) / 31536000
        : 0;
    if ($years >= 7) return 'Review';
    if ($years >= 3) return 'Archive';
    return 'Active';
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
    $isSa   = isset($_SESSION['role']) && $_SESSION['role'] === 'Super Admin';

    try {

        // ── GET /api=stats ────────────────────────────────────────────────────
        // Returns aggregate counts for the stats row (role-scoped).
        if ($api === 'stats' && $method === 'GET') {
            $all = rlm_sb('dtrs_documents', 'GET', [
                'select' => 'id,doc_date,retention_stage,disposed_at,department,created_by,assigned_to',
            ]);
            $all = rlm_scope_documents($all);

            $counts = ['total' => 0, 'active' => 0, 'archive' => 0, 'review' => 0,
                       'disposed' => 0, 'nearThreshold' => 0];
            $counts['total'] = count($all);

            foreach ($all as $row) {
                $stage = rlm_compute_stage($row);
                $years = isset($row['doc_date'])
                    ? (time() - strtotime($row['doc_date'])) / 31536000
                    : 0;
                switch ($stage) {
                    case 'Active':   $counts['active']++;   break;
                    case 'Archive':  $counts['archive']++;  break;
                    case 'Review':   $counts['review']++;   break;
                    case 'Disposed': $counts['disposed']++; break;
                }
                // Near-threshold: within 6 months of 3-yr or 7-yr boundary
                $nearArchive  = ($years >= 2.5 && $years < 3   && $stage === 'Active');
                $nearReview   = ($years >= 6.5 && $years < 7   && $stage === 'Archive');
                if ($nearArchive || $nearReview) $counts['nearThreshold']++;
            }
            rlm_ok($counts);
        }

        // ── GET /api=list ─────────────────────────────────────────────────────
        // Full paginated document list with filters.
        if ($api === 'list' && $method === 'GET') {
            $page    = max(1, (int)($_GET['page']     ?? 1));
            $perPage = max(1, min(50, (int)($_GET['per']  ?? 8)));
            $search  = trim($_GET['q']       ?? '');
            $cat     = trim($_GET['cat']     ?? '');
            $stage   = trim($_GET['stage']   ?? '');
            $access  = trim($_GET['access']  ?? '');
            $from    = trim($_GET['from']    ?? '');
            $to      = trim($_GET['to']      ?? '');

            // Build Supabase query — use raw URL string to avoid PHP array key collision
            // when both date bounds are present (two 'doc_date' params needed).
            $baseUrl = SUPABASE_URL . '/rest/v1/dtrs_documents';
            $parts   = ['select=*', 'order=created_at.desc'];

            if ($cat)    $parts[] = 'category=eq.'    . urlencode($cat);
            if ($access) $parts[] = 'access_level=eq.' . urlencode($access);
            if ($from)   $parts[] = 'doc_date=gte.'   . urlencode($from);
            if ($to)     $parts[] = 'doc_date=lte.'   . urlencode($to);
            if ($search) {
                $s = urlencode("(title.ilike.*{$search}*,doc_id.ilike.*{$search}*,ref_number.ilike.*{$search}*,sender.ilike.*{$search}*,department.ilike.*{$search}*)");
                $parts[] = 'or=' . $s;
            }

            $url = $baseUrl . '?' . implode('&', $parts);
            $headers = [
                'Content-Type: application/json',
                'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
                'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
                'Prefer: return=representation',
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 400) {
                $err = json_decode($res, true);
                throw new RuntimeException($err['message'] ?? 'List query failed HTTP ' . $code);
            }
            $rows = json_decode($res, true) ?: [];
            $rows = rlm_scope_documents($rows);

            // Compute stages and filter by stage if requested
            $processed = [];
            foreach ($rows as $row) {
                $computed = rlm_compute_stage($row);
                if ($stage && $computed !== $stage) continue;
                $built = rlm_build($row);
                $built['retentionStage'] = $computed;
                $built['yearsActive']    = isset($row['doc_date'])
                    ? round((time() - strtotime($row['doc_date'])) / 31536000, 1)
                    : 0;
                $processed[] = $built;
            }

            $total  = count($processed);
            $offset = ($page - 1) * $perPage;
            $slice  = array_slice($processed, $offset, $perPage);

            rlm_ok([
                'items'    => array_values($slice),
                'total'    => $total,
                'page'     => $page,
                'perPage'  => $perPage,
                'pages'    => max(1, (int)ceil($total / $perPage)),
            ]);
        }

        // ── GET /api=compliance-queue ─────────────────────────────────────────
        // Returns documents whose computed stage = 'Review' (7+ years, no disposal).
        // Admin/Manager: zone-scoped; User: no access (empty).
        if ($api === 'compliance-queue' && $method === 'GET') {
            if ($rlmRoleRank <= 1) {
                rlm_ok([]);
            }
            $url = SUPABASE_URL . '/rest/v1/dtrs_documents?select=*&disposed_at=is.null&order=doc_date.asc';
            $headers = [
                'Content-Type: application/json',
                'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
                'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
                'Prefer: return=representation',
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $rows = ($code < 400) ? (json_decode($res, true) ?: []) : [];
            $rows = rlm_scope_documents($rows);
            $queue = [];
            foreach ($rows as $row) {
                if (rlm_compute_stage($row) !== 'Review') continue;
                $built = rlm_build($row);
                $built['retentionStage'] = 'Review';
                $built['yearsActive'] = isset($row['doc_date'])
                    ? round((time() - strtotime($row['doc_date'])) / 31536000, 1) : 0;
                $queue[] = $built;
            }
            rlm_ok(array_values($queue));
        }

        // ── GET /api=disposal-log ─────────────────────────────────────────────
        // Returns all disposed documents (disposed_at IS NOT NULL). Super Admin only (Secure Disposal Log).
        if ($api === 'disposal-log' && $method === 'GET') {
            if ($rlmRoleRank < 4) rlm_err('Not authorized to access disposal log', 403);
            $url = SUPABASE_URL . '/rest/v1/dtrs_documents?select=*&disposed_at=not.is.null&order=disposed_at.desc';
            $headers = [
                'Content-Type: application/json',
                'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
                'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
                'Prefer: return=representation',
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $rows = ($code < 400) ? (json_decode($res, true) ?: []) : [];
            rlm_ok(array_map('rlm_build', $rows));
        }

        // ── GET /api=monitor ─────────────────────────────────────────────────
        // Returns stage counts, near-threshold alerts, and category breakdowns (role-scoped).
        if ($api === 'monitor' && $method === 'GET') {
            $rows = rlm_sb('dtrs_documents', 'GET', [
                'select' => 'id,doc_date,category,retention_stage,disposed_at,department,created_by,assigned_to',
            ]);
            $rows = rlm_scope_documents($rows);

            $stageCounts  = ['Active' => 0, 'Archive' => 0, 'Review' => 0, 'Disposed' => 0];
            $alerts       = [];
            $catBreakdown = [];

            foreach ($rows as $row) {
                $stage = rlm_compute_stage($row);
                $years = isset($row['doc_date'])
                    ? (time() - strtotime($row['doc_date'])) / 31536000
                    : 0;
                $stageCounts[$stage] = ($stageCounts[$stage] ?? 0) + 1;

                $cat = $row['category'] ?? 'Uncategorized';
                $catBreakdown[$cat] = ($catBreakdown[$cat] ?? 0) + 1;

                // Near-threshold alerts
                if ($years >= 2.5 && $years < 3 && $stage === 'Active') {
                    $alerts[] = [
                        'level'   => 'warning',
                        'message' => "Document approaching 3-year archive threshold",
                        'sub'     => $row['doc_id'] ?? '' . ' — review classification',
                    ];
                }
                if ($years >= 6.5 && $years < 7 && $stage === 'Archive') {
                    $alerts[] = [
                        'level'   => 'warning',
                        'message' => "Document approaching 7-year compliance review threshold",
                        'sub'     => $row['doc_id'] ?? '' . ' — prepare review',
                    ];
                }
                if ($stage === 'Review') {
                    $alerts[] = [
                        'level'   => 'danger',
                        'message' => "Document requires immediate compliance review",
                        'sub'     => ($row['doc_id'] ?? '') . ' — Super Admin sign-off required',
                    ];
                }
            }

            // Deduplicate summary-level alerts (collapse per-doc into summary)
            $summaryAlerts = [];
            $near3 = array_filter($rows, fn($r) => rlm_compute_stage($r) === 'Active'
                && (time() - strtotime($r['doc_date'] ?? 'now')) / 31536000 >= 2.5
                && (time() - strtotime($r['doc_date'] ?? 'now')) / 31536000 < 3);
            $near7 = array_filter($rows, fn($r) => rlm_compute_stage($r) === 'Archive'
                && (time() - strtotime($r['doc_date'] ?? 'now')) / 31536000 >= 6.5
                && (time() - strtotime($r['doc_date'] ?? 'now')) / 31536000 < 7);
            $atReview = array_filter($rows, fn($r) => rlm_compute_stage($r) === 'Review');

            if (count($near3)) $summaryAlerts[] = ['level' => 'warning',
                'message' => count($near3) . ' document(s) approaching 3-year archive threshold',
                'sub'     => 'Will move to Archive — review classification'];
            if (count($near7)) $summaryAlerts[] = ['level' => 'warning',
                'message' => count($near7) . ' document(s) approaching 7-year compliance review threshold',
                'sub'     => 'Prepare compliance review actions'];
            if (count($atReview)) $summaryAlerts[] = ['level' => 'danger',
                'message' => count($atReview) . ' document(s) require immediate compliance review',
                'sub'     => '7-year threshold reached — Super Admin sign-off required'];

            rlm_ok([
                'stageCounts'  => $stageCounts,
                'alerts'       => $summaryAlerts,
                'catBreakdown' => $catBreakdown,
            ]);
        }

        // ── GET /api=policies ────────────────────────────────────────────────
        // Returns the dtrs_retention_policies table (or seeds defaults if empty)
        if ($api === 'policies' && $method === 'GET') {
            $rows = rlm_sb('dtrs_retention_policies', 'GET', [
                'select' => '*',
                'order'  => 'category.asc',
            ]);
            rlm_ok(array_values($rows));
        }

        // ── POST /api=save-policies ───────────────────────────────────────────
        // Super Admin only: upsert retention policy rules.
        if ($api === 'save-policies' && $method === 'POST') {
            if ($rlmRoleRank < 4) rlm_err('Not authorized to modify retention rules', 403);
            $b = rlm_body();
            $rules = $b['rules'] ?? [];
            if (empty($rules) || !is_array($rules)) rlm_err('No rules provided', 400);

            foreach ($rules as $rule) {
                $cat = trim($rule['category'] ?? '');
                if (!$cat) continue;
                $now = date('Y-m-d H:i:s');
                // Use PATCH by category (upsert pattern via Supabase REST)
                // Try PATCH first; if 0 rows matched, fall back to POST
                $patched = rlm_sb('dtrs_retention_policies', 'PATCH',
                    ['category' => 'eq.' . $cat],
                    [
                        'active_years'   => max(1, (int)($rule['active_years']  ?? 3)),
                        'archive_years'  => max(1, (int)($rule['archive_years'] ?? 3)),
                        'review_years'   => max(1, (int)($rule['review_years']  ?? 7)),
                        'default_action' => $rule['default_action'] ?? 'Compliance Review',
                        'updated_by'     => $actor,
                        'updated_at'     => $now,
                    ]
                );
                if (empty($patched)) {
                    // Row didn't exist — insert it
                    rlm_sb('dtrs_retention_policies', 'POST', [], [[
                        'category'       => $cat,
                        'active_years'   => max(1, (int)($rule['active_years']  ?? 3)),
                        'archive_years'  => max(1, (int)($rule['archive_years'] ?? 3)),
                        'review_years'   => max(1, (int)($rule['review_years']  ?? 7)),
                        'default_action' => $rule['default_action'] ?? 'Compliance Review',
                        'updated_by'     => $actor,
                        'updated_at'     => $now,
                    ]]);
                }
            }
            rlm_ok(['saved' => count($rules)]);
        }

        // ── GET /api=audit ────────────────────────────────────────────────────
        // Returns audit trail for a single document; document must be in scope for role.
        if ($api === 'audit' && $method === 'GET') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) rlm_err('Missing id', 400);
            $docRows = rlm_sb('dtrs_documents', 'GET', ['id' => 'eq.' . $id, 'select' => 'id,department,created_by,assigned_to', 'limit' => '1']);
            if (empty($docRows)) rlm_err('Document not found', 404);
            if (empty(rlm_scope_documents($docRows))) rlm_err('Not authorized to view this document', 403);
            $rows = rlm_sb('dtrs_audit_log', 'GET', [
                'select'  => 'id,action_label,actor_name,actor_role,note,icon,css_class,is_super_admin,ip_address,occurred_at',
                'doc_id'  => 'eq.' . $id,
                'order'   => 'occurred_at.asc',
            ]);
            rlm_ok($rows);
        }

        // ── GET /api=signed-url ───────────────────────────────────────────────
        // Returns a signed storage URL; document must be in scope (optional id in body to verify).
        if ($api === 'signed-url' && $method === 'POST') {
            $b    = rlm_body();
            $path = trim($b['path'] ?? '');
            if (!$path) rlm_err('Missing path', 400);
            $docId = isset($b['id']) ? (int)$b['id'] : 0;
            if ($rlmRoleRank < 4 && $docId) {
                $docRows = rlm_sb('dtrs_documents', 'GET', ['id' => 'eq.' . $docId, 'select' => 'id,department,created_by,assigned_to', 'limit' => '1']);
                if (empty($docRows) || empty(rlm_scope_documents($docRows))) rlm_err('Not authorized to access this document', 403);
            }

            $ch = curl_init(SUPABASE_URL . '/storage/v1/object/sign/dtrs-documents/' . $path);
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

            if ($code >= 400) rlm_err('Could not generate signed URL', 502);
            $data      = json_decode($res, true);
            $signedUrl = SUPABASE_URL . '/storage/v1' . ($data['signedURL'] ?? '');
            rlm_ok(['signedUrl' => $signedUrl]);
        }

        // ── POST /api=update-stage ────────────────────────────────────────────
        // Super Admin only: manually override retention stage (modify retention rules).
        // Payload: { id, stage, note }
        if ($api === 'update-stage' && $method === 'POST') {
            if ($rlmRoleRank < 4) rlm_err('Not authorized to modify retention stage', 403);
            $b     = rlm_body();
            $id    = (int)($b['id']    ?? 0);
            $stage = trim($b['stage']  ?? '');
            $note  = trim($b['note']   ?? '');
            if (!$id)    rlm_err('Missing id', 400);
            if (!$stage) rlm_err('Missing stage', 400);
            $allowed = ['Active', 'Archive', 'Review'];
            if (!in_array($stage, $allowed, true)) rlm_err('Invalid stage', 400);

            $now = date('Y-m-d H:i:s');
            rlm_sb('dtrs_documents', 'PATCH', ['id' => 'eq.' . $id], [
                'retention_stage' => $stage,
                'updated_at'      => $now,
            ]);

            // Audit log
            rlm_sb('dtrs_audit_log', 'POST', [], [[
                'doc_id'        => $id,
                'action_label'  => 'Stage Updated — ' . $stage,
                'actor_name'    => $actor,
                'actor_role'    => $isSa ? 'Super Admin' : 'Admin',
                'note'          => $note ?: 'Retention stage manually set to ' . $stage,
                'icon'          => 'bx-transfer',
                'css_class'     => 'dc-s',
                'is_super_admin'=> $isSa,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            rlm_ok(['updated' => true]);
        }

        // ── POST /api=extend-retention ────────────────────────────────────────
        // Super Admin only: extends retention (modify retention rules).
        // Payload: { id, note }
        if ($api === 'extend-retention' && $method === 'POST') {
            if ($rlmRoleRank < 4) rlm_err('Not authorized to extend retention', 403);
            $b    = rlm_body();
            $id   = (int)($b['id']   ?? 0);
            $note = trim($b['note']  ?? '');
            if (!$id) rlm_err('Missing id', 400);

            // Fetch current doc
            $rows = rlm_sb('dtrs_documents', 'GET', ['id' => 'eq.' . $id, 'select' => '*', 'limit' => '1']);
            if (empty($rows)) rlm_err('Document not found', 404);
            $doc  = $rows[0];

            $currentStage = rlm_compute_stage($doc);
            $newStage = match($currentStage) {
                'Review'  => 'Archive',
                'Archive' => 'Active',
                default   => 'Active',
            };

            // Push doc_date forward 1 year to effectively extend retention window
            $newDocDate = date('Y-m-d H:i:sP', strtotime(($doc['doc_date'] ?? 'now') . ' +1 year'));
            $now = date('Y-m-d H:i:s');

            rlm_sb('dtrs_documents', 'PATCH', ['id' => 'eq.' . $id], [
                'retention_stage'    => $newStage,
                'doc_date'           => $newDocDate,
                'retention_extended' => true,
                'updated_at'         => $now,
            ]);

            // Audit log
            rlm_sb('dtrs_audit_log', 'POST', [], [[
                'doc_id'        => $id,
                'action_label'  => 'Retention Extended — moved to ' . $newStage,
                'actor_name'    => $actor,
                'actor_role'    => $isSa ? 'Super Admin' : 'Admin',
                'note'          => $note ?: 'Retention period extended by 1 year. Stage rolled back from ' . $currentStage . ' to ' . $newStage . '.',
                'icon'          => 'bx-time-five',
                'css_class'     => 'dc-s',
                'is_super_admin'=> $isSa,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            rlm_ok(['updated' => true, 'newStage' => $newStage]);
        }

        // ── POST /api=approve-disposal ────────────────────────────────────────
        // Super Admin only: permanently marks a document as Disposed.
        // Payload: { id, reason, note }
        if ($api === 'approve-disposal' && $method === 'POST') {
            if ($rlmRoleRank < 4) rlm_err('Not authorized to approve disposal', 403);
            $b      = rlm_body();
            $id     = (int)($b['id']     ?? 0);
            $reason = trim($b['reason']  ?? '');
            $note   = trim($b['note']    ?? '');
            if (!$id)     rlm_err('Missing id', 400);
            if (!$reason) rlm_err('Disposal reason is required', 400);

            $now = date('Y-m-d H:i:s');

            rlm_sb('dtrs_documents', 'PATCH', ['id' => 'eq.' . $id], [
                'retention_stage' => 'Disposed',
                'disposed_at'     => $now,
                'disposed_by'     => $actor,
                'disposal_reason' => $reason,
                'status'          => 'Archived',
                'updated_at'      => $now,
            ]);

            // Immutable audit log entry — is_super_admin=TRUE enforced here
            rlm_sb('dtrs_audit_log', 'POST', [], [[
                'doc_id'        => $id,
                'action_label'  => 'Document Disposed — Approved by Super Admin',
                'actor_name'    => $actor,
                'actor_role'    => 'Super Admin',
                'note'          => 'Reason: ' . $reason . ($note ? ' · Notes: ' . $note : ''),
                'icon'          => 'bx-check-shield',
                'css_class'     => 'dc-r',
                'is_super_admin'=> true,
                'ip_address'    => $ip,
                'occurred_at'   => $now,
            ]]);

            rlm_ok(['disposed' => true]);
        }

        // ── GET /api=export ─────────────────────────────────────────────────
        // Returns documents as flat array for CSV export (role-scoped).
        if ($api === 'export' && $method === 'GET') {
            $rows = rlm_sb('dtrs_documents', 'GET', [
                'select' => '*',
                'order'  => 'created_at.desc',
            ]);
            $rows = rlm_scope_documents($rows);
            $out = array_map(function($row) {
                $built = rlm_build($row);
                $built['retentionStage'] = rlm_compute_stage($row);
                return $built;
            }, $rows);
            rlm_ok($out);
        }

        rlm_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        rlm_err('Server error: ' . $e->getMessage(), 500);
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
<title>Records Lifecycle Management — DTRS</title>
<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --primary:#2E7D32;--primary-dark:#1B5E20;--primary-light:#388E3C;
  --surface:#FFFFFF;--bg:#F5F7F5;
  --border:rgba(46,125,50,.14);--border-mid:rgba(46,125,50,.22);
  --text-1:#1A2B1C;--text-2:#5D6F62;--text-3:#9EB0A2;
  --hover-s:rgba(46,125,50,.05);
  --shadow-sm:0 1px 4px rgba(46,125,50,.08);
  --shadow-md:0 4px 16px rgba(46,125,50,.12);
  --shadow-xl:0 20px 60px rgba(0,0,0,.22);
  --danger:#DC2626;--warning:#D97706;--info:#2563EB;--success:#2E7D32;
  --gold:#B45309;--gold-bg:#FEF3C7;
  --radius:12px;--tr:all .18s ease;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text-1);}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-thumb{background:rgba(46,125,50,.22);border-radius:4px}

/* PAGE */
.page{max-width:1600px;margin:0 auto;padding:0 0 3rem}
.po-ph{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:20px;animation:fadeUp .4s both;flex-wrap:wrap;}
.po-ph .eyebrow{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--primary);margin-bottom:2px;}
.po-ph h1{font-size:26px;font-weight:800;color:var(--text-1);line-height:1.15;}
.po-acts{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-p{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3);}
.btn-p:hover{background:var(--primary-dark);transform:translateY(-1px);}
.btn-g{background:var(--surface);color:var(--text-2);border:1px solid var(--border-mid);}
.btn-g:hover{background:var(--hover-s);color:var(--text-1);}
.btn-s{font-size:12px;padding:7px 14px;}
.btn-danger{background:var(--danger);color:#fff;box-shadow:0 2px 8px rgba(220,38,38,.3);}
.btn-danger:hover{background:#B91C1C;transform:translateY(-1px);}
.btn-warn{background:var(--warning);color:#fff;}
.btn-warn:hover{background:#B45309;transform:translateY(-1px);}
.btn-info{background:var(--info);color:#fff;}
.btn-info:hover{background:#1D4ED8;transform:translateY(-1px);}
.btn-gold{background:var(--gold);color:#fff;box-shadow:0 2px 8px rgba(180,83,9,.3);}
.btn-gold:hover{background:#92400E;transform:translateY(-1px);}
.btn:disabled{opacity:.45;pointer-events:none;}

/* STATS */
.rlm-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:20px;}
.po-stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:11px 12px;box-shadow:var(--shadow-sm);display:flex;align-items:center;gap:8px;animation:fadeUp .4s both;min-width:0;}
.stat-ic{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.ic-g{background:#E8F5E9;color:var(--primary);}
.ic-o{background:#FEF3C7;color:var(--warning);}
.ic-r{background:#FEE2E2;color:var(--danger);}
.ic-b{background:#EFF6FF;color:var(--info);}
.ic-gold{background:#FEF3C7;color:var(--gold);}
.ic-gy{background:#F3F4F6;color:#6B7280;}
.stat-body{min-width:0;flex:1;}
.stat-v{font-size:18px;font-weight:800;line-height:1;}
.stat-l{font-size:10px;color:var(--text-2);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

/* TABS */
.nav-bar{display:flex;align-items:center;gap:3px;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:5px;margin-bottom:10px;width:fit-content;animation:fadeUp .4s .05s both;}
.tab-btn{font-family:'Inter',sans-serif;font-size:12px;font-weight:600;padding:7px 14px;border-radius:9px;border:none;cursor:pointer;transition:var(--tr);color:var(--text-2);background:transparent;display:flex;align-items:center;gap:6px;white-space:nowrap;}
.tab-btn.active{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3);}
.tab-btn:not(.active):hover{background:var(--hover-s);color:var(--text-1);}
.tab-panel{display:none;}
.tab-panel.active{display:block;}

/* FILTER BAR */
.filter-bar{display:flex;align-items:center;gap:6px;margin-bottom:14px;animation:fadeUp .4s .12s both;flex-wrap:wrap;}
.filter-group{display:flex;align-items:center;gap:6px;width:100%;flex-wrap:wrap;}
.sw{position:relative;flex:0 0 200px;}
.sw i{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--text-3);pointer-events:none;}
.sinput{width:100%;padding:7px 10px 7px 30px;font-family:'Inter',sans-serif;font-size:12px;border:1px solid var(--border-mid);border-radius:9px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);height:34px;}
.sinput:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12);}
.fsel{font-family:'Inter',sans-serif;font-size:12px;padding:0 26px 0 10px;border:1px solid var(--border-mid);border-radius:9px;background:var(--surface);color:var(--text-1);cursor:pointer;outline:none;transition:var(--tr);appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 8px center;flex-shrink:0;height:34px;}
.fsel:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12);}
.filter-pill{display:inline-flex;align-items:stretch;background:var(--surface);border:1px solid var(--border-mid);border-radius:9px;overflow:hidden;flex-shrink:0;height:34px;}
.filter-pill .pill-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);padding:0 8px;white-space:nowrap;background:var(--bg);border-right:1px solid var(--border-mid);display:flex;align-items:center;line-height:1;}
.filter-pill input[type=date]{font-family:'Inter',sans-serif;font-size:12px;border:none;outline:none;background:transparent;color:var(--text-1);padding:0 5px;height:100%;width:118px;cursor:pointer;appearance:none;}
.filter-pill .pill-sep{font-size:11px;color:var(--text-3);padding:0 2px;display:flex;align-items:center;flex-shrink:0;}
.clear-filters{font-size:11px;font-weight:600;color:var(--text-3);background:none;border:1px solid var(--border-mid);cursor:pointer;padding:0 12px;border-radius:9px;transition:var(--tr);white-space:nowrap;display:flex;align-items:center;gap:4px;flex-shrink:0;height:34px;}
.clear-filters:hover{color:var(--danger);background:#FEE2E2;border-color:#FECACA;}

/* TABLE CARD */
.po-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-md);animation:fadeUp .4s .15s both;}
.po-table-wrap{overflow-x:auto;}
.po-table{width:100%;min-width:700px;border-collapse:collapse;font-size:12px;}
.po-table thead th{font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-2);padding:10px 12px;text-align:left;background:var(--bg);border-bottom:1px solid var(--border);white-space:nowrap;}
.po-table thead th:first-child{padding-left:16px;}
.po-table tbody tr{border-bottom:1px solid var(--border);transition:background .15s;cursor:pointer;}
.po-table tbody tr:last-child{border-bottom:none;}
.po-table tbody tr:hover{background:var(--hover-s);}
.po-table tbody td{padding:11px 12px;vertical-align:middle;}
.po-table tbody td:first-child{padding-left:16px;}

/* CELLS */
.doc-id{font-family:'DM Mono',monospace;font-size:12px;font-weight:600;color:var(--primary);}
.doc-sub{font-size:10px;color:var(--text-3);margin-top:2px;}
.doc-title{font-size:12px;font-weight:600;color:var(--text-1);}
.doc-meta{font-size:10px;color:var(--text-3);margin-top:2px;}
.date-val{font-size:12px;color:var(--text-2);}
.access-badge{display:inline-flex;align-items:center;gap:3px;font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;}
.ab-Public{background:#DCFCE7;color:#166534;}
.ab-Internal{background:#EFF6FF;color:var(--info);}
.ab-Restricted{background:#FEF3C7;color:var(--gold);}
.ab-Confidential{background:#FEE2E2;color:var(--danger);}
.ret-stage{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;}
.rs-Active  {background:#E8F5E9;color:var(--primary);}
.rs-Archive {background:#EFF6FF;color:var(--info);}
.rs-Review  {background:#FEE2E2;color:var(--danger);}
.rs-Disposed{background:#F3F4F6;color:#6B7280;text-decoration:line-through;}
.ret-bar-wrap{display:flex;align-items:center;gap:6px;}
.ret-bar{flex:1;height:4px;background:var(--border);border-radius:4px;overflow:hidden;min-width:60px;}
.ret-fill{height:100%;border-radius:4px;}
.rf-safe{background:var(--primary);}
.rf-warn{background:var(--warning);}
.rf-danger{background:var(--danger);}
.ret-yr{font-size:10px;font-weight:700;color:var(--text-3);white-space:nowrap;}
.row-acts{display:flex;align-items:center;gap:3px;}
.icon-btn{width:26px;height:26px;border-radius:7px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:13px;color:var(--text-2);transition:var(--tr);}
.icon-btn:hover{background:var(--hover-s);border-color:var(--primary);color:var(--primary);}
.icon-btn.danger:hover{background:#FEE2E2;border-color:#FECACA;color:var(--danger);}

/* TABLE FOOTER */
.po-card-ft{padding:12px 20px;border-top:1px solid var(--border);background:linear-gradient(135deg,rgba(46,125,50,.03),var(--bg));display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.ft-info{font-size:12px;color:var(--text-2);}
.pbtns{display:flex;gap:5px;}
.pb{width:30px;height:30px;border-radius:7px;border:1px solid var(--border-mid);background:var(--surface);font-family:'Inter',sans-serif;font-size:12px;font-weight:500;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--text-1);}
.pb:hover{background:var(--hover-s);border-color:var(--primary);color:var(--primary);}
.pb.active{background:var(--primary);border-color:var(--primary);color:#fff;}
.pb:disabled{opacity:.4;pointer-events:none;}

/* EMPTY */
.po-empty{padding:60px 20px;text-align:center;color:var(--text-3);}
.po-empty i{font-size:48px;display:block;margin-bottom:10px;color:#C8E6C9;}

/* RETENTION MONITOR */
.ret-monitor{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;animation:fadeUp .4s .1s both;}
.ret-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:20px;box-shadow:var(--shadow-sm);}
.ret-card-title{font-size:13px;font-weight:700;color:var(--text-1);margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.ret-card-title i{font-size:17px;color:var(--primary);}
.stage-bars{display:flex;flex-direction:column;gap:12px;}
.stage-bar-item{display:flex;align-items:center;gap:12px;}
.stage-bar-label{font-size:11px;font-weight:600;color:var(--text-2);width:130px;flex-shrink:0;}
.stage-bar-outer{flex:1;height:10px;background:var(--bg);border-radius:10px;overflow:hidden;border:1px solid var(--border);}
.stage-bar-inner{height:100%;border-radius:10px;transition:width .6s ease;}
.sbi-g{background:var(--primary);}
.sbi-b{background:var(--info);}
.sbi-r{background:var(--danger);}
.sbi-gy{background:#9CA3AF;}
.stage-bar-count{font-size:11px;font-weight:700;color:var(--text-2);width:24px;text-align:right;}

/* ALERTS */
.alert-list{display:flex;flex-direction:column;gap:8px;}
.alert-item{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-radius:10px;border:1px solid;}
.alert-warn{background:#FEF3C7;border-color:rgba(180,83,9,.2);}
.alert-danger{background:#FEE2E2;border-color:rgba(220,38,38,.2);}
.alert-item i{font-size:16px;flex-shrink:0;margin-top:1px;}
.alert-warn i{color:var(--gold);}
.alert-danger i{color:var(--danger);}
.alert-text{font-size:12px;font-weight:500;}
.alert-warn .alert-text{color:var(--gold);}
.alert-danger .alert-text{color:var(--danger);}
.alert-sub{font-size:11px;color:var(--text-3);margin-top:2px;}

/* POLICY TABLE */
.policy-table{width:100%;border-collapse:collapse;font-size:12px;}
.policy-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-2);padding:8px 12px;background:var(--bg);border-bottom:1px solid var(--border);text-align:left;}
.policy-table td{padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:middle;}
.policy-table tr:last-child td{border-bottom:none;}
.policy-edit-input{font-family:'Inter',sans-serif;font-size:12px;padding:4px 8px;border:1px solid var(--border-mid);border-radius:7px;width:60px;text-align:center;outline:none;}
.policy-edit-input:focus{border-color:var(--primary);}

/* QUEUE ITEM */
.queue-item{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:10px;box-shadow:var(--shadow-sm);}
.queue-hd{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px;}
.queue-id{font-family:'DM Mono',monospace;font-size:12px;font-weight:600;color:var(--danger);}
.queue-name{font-size:13px;font-weight:700;color:var(--text-1);margin-top:2px;}
.queue-meta{font-size:11px;color:var(--text-3);margin-top:2px;}
.queue-actions{display:flex;gap:8px;align-items:center;flex-shrink:0;}
.queue-notes{width:100%;font-family:'Inter',sans-serif;font-size:12px;padding:8px 10px;border:1px solid var(--border-mid);border-radius:8px;background:var(--bg);color:var(--text-1);resize:none;outline:none;min-height:48px;}
.queue-notes:focus{border-color:var(--primary);background:var(--surface);}
.queue-sign{font-size:11px;color:var(--text-3);margin-top:6px;display:flex;align-items:center;gap:4px;}
.queue-sign i{color:var(--gold);}

/* LOADING SKELETON */
.skeleton{background:linear-gradient(90deg,var(--bg) 25%,rgba(46,125,50,.07) 50%,var(--bg) 75%);background-size:400% 100%;animation:shimmer 1.4s infinite;border-radius:8px;}
@keyframes shimmer{0%{background-position:100% 50%}100%{background-position:0% 50%}}

/* INFO BOXES */
.sa-banner{background:linear-gradient(135deg,rgba(27,94,32,.04),rgba(46,125,50,.07));border:1px solid rgba(46,125,50,.2);border-radius:12px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;}
.sa-banner i{color:var(--primary);font-size:18px;flex-shrink:0;}
.sa-banner span{font-size:12px;font-weight:600;color:var(--primary);}
.warn-box{background:#FEF3C7;border:1px solid rgba(180,83,9,.25);border-radius:12px;padding:12px 16px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start;}
.warn-box i{color:var(--gold);font-size:17px;flex-shrink:0;margin-top:1px;}
.danger-box{background:#FEE2E2;border:1px solid rgba(220,38,38,.2);border-radius:12px;padding:12px 16px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start;}
.danger-box i{color:var(--danger);font-size:17px;flex-shrink:0;margin-top:1px;}
.danger-box p,.warn-box p{font-size:13px;font-weight:500;color:inherit;}
.danger-box p{color:var(--danger);}
.warn-box p{color:var(--gold);}

/* INFO GRID */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;}
.info-item label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);display:block;margin-bottom:3px;}
.info-item .v{font-size:13px;font-weight:500;color:var(--text-1);}
.info-item .v.mono{font-family:'DM Mono',monospace;}

/* OVERLAY / MODALS */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1100;opacity:0;pointer-events:none;transition:opacity .25s;}
.overlay.show{opacity:1;pointer-events:all;}
.modal-base{position:fixed;inset:0;z-index:1200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;}
.modal-base.show{opacity:1;pointer-events:all;}
.mbox-sm{background:var(--surface);border-radius:20px;width:540px;max-width:100%;box-shadow:var(--shadow-xl);transform:scale(.96);transition:transform .25s;overflow:hidden;}
.mbox-lg{background:var(--surface);border-radius:20px;width:900px;max-width:100%;max-height:92vh;display:flex;flex-direction:column;box-shadow:var(--shadow-xl);transform:scale(.96);transition:transform .25s;overflow:hidden;}
.modal-base.show .mbox-sm,.modal-base.show .mbox-lg{transform:scale(1);}
.m-hd{padding:20px 26px 16px;border-bottom:1px solid var(--border);background:var(--bg);display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-shrink:0;}
.m-hd-ic{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.m-hd-title{font-size:16px;font-weight:700;color:var(--text-1);}
.m-hd-sub{font-size:12px;color:var(--text-2);margin-top:3px;}
.m-cl{width:32px;height:32px;border-radius:8px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:18px;color:var(--text-2);transition:var(--tr);flex-shrink:0;}
.m-cl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA;}
.m-body{flex:1;overflow-y:auto;padding:22px 26px;}
.m-body::-webkit-scrollbar{width:4px;}
.m-body::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px;}
.m-ft{padding:14px 26px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:10px;justify-content:flex-end;flex-shrink:0;}
.m-body-p{padding:20px 26px;}

/* FORM */
.fg{display:flex;flex-direction:column;gap:6px;margin-bottom:14px;}
.fl{font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.06em;}
.fl span{color:var(--danger);margin-left:2px;}
.fi,.fs,.fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--border-mid);border-radius:10px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);width:100%;}
.fi:focus,.fs:focus,.fta:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.12);}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:30px;}
.fta{resize:vertical;min-height:72px;line-height:1.6;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.full{grid-column:1/-1;}
.sdv{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-3);display:flex;align-items:center;gap:10px;margin:4px 0 14px;}
.sdv::after{content:'';flex:1;height:1px;background:var(--border);}

/* AUDIT TIMELINE */
.audit-timeline{display:flex;flex-direction:column;gap:0;position:relative;}
.audit-timeline::before{content:'';position:absolute;left:15px;top:0;bottom:0;width:2px;background:var(--border);}
.audit-item{display:flex;gap:14px;padding:10px 0;position:relative;}
.audit-dot{width:14px;height:14px;border-radius:50%;flex-shrink:0;margin-top:3px;z-index:1;border:2px solid var(--surface);}
.audit-dot.dc-c{background:var(--primary);}
.audit-dot.dc-s{background:var(--info);}
.audit-dot.dc-a{background:#059669;}
.audit-dot.dc-r{background:var(--danger);}
.audit-dot.dc-t{background:var(--warning);}
.audit-dot.dc-x{background:#6B7280;}
.audit-dot.dc-o{background:#7C3AED;}
.audit-content{flex:1;min-width:0;}
.audit-label{font-size:12px;font-weight:700;color:var(--text-1);}
.audit-note{font-size:11px;color:var(--text-2);margin-top:2px;line-height:1.5;}
.audit-meta{font-size:10px;color:var(--text-3);margin-top:3px;display:flex;gap:8px;flex-wrap:wrap;}
.audit-sa-badge{font-size:9px;background:#FEF3C7;color:var(--gold);border:1px solid rgba(180,83,9,.2);padding:1px 5px;border-radius:4px;font-weight:700;}

/* TOAST */
#tw{position:fixed;bottom:28px;right:28px;display:flex;flex-direction:column;gap:10px;z-index:9999;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:11px 16px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow-xl);pointer-events:all;min-width:220px;animation:toastIn .3s ease;}
.toast.success{background:var(--primary);}
.toast.warning{background:var(--warning);}
.toast.danger{background:var(--danger);}
.toast.info{background:var(--info);}
.toast.out{animation:toastOut .3s ease forwards;}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastOut{from{opacity:1}to{opacity:0;transform:translateY(12px)}}
@keyframes shake{0%,100%{transform:translateX(0)}25%,75%{transform:translateX(-5px)}50%{transform:translateX(5px)}}
@media(max-width:1400px){.rlm-stats{grid-template-columns:repeat(3,minmax(0,1fr));}}
@media(max-width:1200px){.ret-monitor{grid-template-columns:1fr;}}
@media(max-width:768px){.rlm-stats{grid-template-columns:repeat(2,minmax(0,1fr));}.form-grid{grid-template-columns:1fr;}.info-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="page">

  <!-- PAGE HEADER -->
  <div class="po-ph">
    <div>
      <p class="eyebrow">DTRS · Document &amp; Records Management</p>
      <h1>Records Lifecycle Management</h1>
    </div>
    <div class="po-acts">
      <button class="btn btn-g" id="exportBtn"><i class='bx bx-export'></i> Export CSV</button>
      <button class="btn btn-g" id="policyBtn"><i class='bx bx-shield-quarter'></i> Retention Policies</button>
    </div>
  </div>

  <!-- STATS ROW -->
  <div class="rlm-stats" id="statsRow">
    <!-- skeleton -->
    <div class="po-stat"><div class="stat-ic skeleton" style="width:32px;height:32px"></div><div class="stat-body"><div class="skeleton" style="height:14px;width:40px;margin-bottom:4px"></div><div class="skeleton" style="height:10px;width:70px"></div></div></div>
    <div class="po-stat"><div class="stat-ic skeleton" style="width:32px;height:32px"></div><div class="stat-body"><div class="skeleton" style="height:14px;width:40px;margin-bottom:4px"></div><div class="skeleton" style="height:10px;width:70px"></div></div></div>
    <div class="po-stat"><div class="stat-ic skeleton" style="width:32px;height:32px"></div><div class="stat-body"><div class="skeleton" style="height:14px;width:40px;margin-bottom:4px"></div><div class="skeleton" style="height:10px;width:70px"></div></div></div>
    <div class="po-stat"><div class="stat-ic skeleton" style="width:32px;height:32px"></div><div class="stat-body"><div class="skeleton" style="height:14px;width:40px;margin-bottom:4px"></div><div class="skeleton" style="height:10px;width:70px"></div></div></div>
    <div class="po-stat"><div class="stat-ic skeleton" style="width:32px;height:32px"></div><div class="stat-body"><div class="skeleton" style="height:14px;width:40px;margin-bottom:4px"></div><div class="skeleton" style="height:10px;width:70px"></div></div></div>
    <div class="po-stat"><div class="stat-ic skeleton" style="width:32px;height:32px"></div><div class="stat-body"><div class="skeleton" style="height:14px;width:40px;margin-bottom:4px"></div><div class="skeleton" style="height:10px;width:70px"></div></div></div>
  </div>

  <!-- TABS -->
  <div class="nav-bar">
    <button class="tab-btn active" data-tab="search"><i class='bx bx-search-alt'></i> Smart Search</button>
    <button class="tab-btn" data-tab="monitor"><i class='bx bx-bar-chart-alt-2'></i> Retention Monitor</button>
    <button class="tab-btn" data-tab="queue"><i class='bx bx-list-check'></i> Compliance Queue <span id="queueBadge" style="background:#DC2626;color:#fff;border-radius:20px;font-size:9px;padding:1px 6px;margin-left:2px;display:none"></span></button>
    <button class="tab-btn" data-tab="disposal"><i class='bx bx-trash'></i> Disposal Log</button>
  </div>

  <!-- FILTER BAR -->
  <div class="filter-bar" id="searchFilters">
    <div class="filter-group">
      <div class="sw">
        <i class='bx bx-search'></i>
        <input type="text" class="sinput" id="srch" placeholder="Search title, Doc ID, sender…">
      </div>
      <select class="fsel" id="fCat">
        <option value="">All Categories</option>
        <option>Financial</option><option>Legal</option><option>Operational</option>
        <option>HR</option><option>Procurement</option><option>Compliance</option><option>Administrative</option>
      </select>
      <select class="fsel" id="fStage">
        <option value="">All Stages</option>
        <option value="Active">Active Storage</option>
        <option value="Archive">Archive</option>
        <option value="Review">Compliance Review</option>
        <option value="Disposed">Disposed</option>
      </select>
      <select class="fsel" id="fAccess">
        <option value="">All Access Levels</option>
        <option value="Public">Public</option>
        <option value="Internal">Internal</option>
        <option value="Restricted">Restricted</option>
        <option value="Confidential">Confidential</option>
      </select>
      <div class="filter-pill">
        <span class="pill-label">Date</span>
        <input type="date" id="fDateFrom">
        <span class="pill-sep">—</span>
        <input type="date" id="fDateTo">
      </div>
      <button class="clear-filters" id="clearFilters"><i class='bx bx-x'></i> Clear</button>
    </div>
  </div>

  <!-- ── TAB: SMART SEARCH ── -->
  <div class="tab-panel active" id="tab-search">
    <div class="po-card">
      <div class="po-table-wrap">
        <table class="po-table">
          <thead>
            <tr>
              <th>Document ID</th>
              <th>Document Name</th>
              <th>Category</th>
              <th>Date Created</th>
              <th>Access Level</th>
              <th>Retention Stage</th>
              <th>Years Active</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="docTableBody">
            <tr><td colspan="8" style="padding:28px;text-align:center"><div class="skeleton" style="height:14px;width:60%;margin:0 auto"></div></td></tr>
          </tbody>
        </table>
        <div id="docEmpty" class="po-empty" style="display:none">
          <i class='bx bx-folder-open'></i>
          <p>No documents match your filters.</p>
        </div>
      </div>
      <div class="po-card-ft">
        <div class="ft-info" id="ftInfo"></div>
        <div class="pbtns" id="pagBtns"></div>
      </div>
    </div>
  </div>

  <!-- ── TAB: RETENTION MONITOR ── -->
  <div class="tab-panel" id="tab-monitor">
    <div class="ret-monitor">
      <div class="ret-card">
        <div class="ret-card-title"><i class='bx bx-bar-chart-alt-2'></i> Documents per Retention Stage</div>
        <div class="stage-bars" id="stageBars"><div class="skeleton" style="height:10px;margin-bottom:10px"></div><div class="skeleton" style="height:10px;margin-bottom:10px"></div><div class="skeleton" style="height:10px;margin-bottom:10px"></div></div>
      </div>
      <div class="ret-card">
        <div class="ret-card-title"><i class='bx bx-bell'></i> Threshold Alerts</div>
        <div id="alertList"><div class="skeleton" style="height:56px;border-radius:10px"></div></div>
      </div>
    </div>
    <!-- Policy Rules -->
    <div class="po-card" style="animation:fadeUp .4s .15s both">
      <div style="padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg);display:flex;align-items:center;justify-content:space-between;gap:10px;">
        <div style="display:flex;align-items:center;gap:8px"><i class='bx bx-cog' style="color:var(--primary);font-size:18px"></i><span style="font-size:13px;font-weight:700;color:var(--text-1)">Retention Policy Rules by Category</span></div>
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:11px;color:var(--text-3);display:flex;align-items:center;gap:4px"><i class='bx bx-shield-quarter' style="color:var(--gold)"></i> Editable by Super Admin only</span>
          <button class="btn btn-gold btn-s" id="savePoliciesBtn"><i class='bx bx-save'></i> Save Changes</button>
        </div>
      </div>
      <div class="po-table-wrap">
        <table class="policy-table">
          <thead><tr><th>Category</th><th>Active (yrs)</th><th>Archive (yrs)</th><th>Review (yrs)</th><th>Default Action</th><th>Last Updated</th></tr></thead>
          <tbody id="policyBody"><tr><td colspan="6" style="padding:20px;text-align:center"><div class="skeleton" style="height:12px;width:50%;margin:0 auto"></div></td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── TAB: COMPLIANCE QUEUE ── -->
  <div class="tab-panel" id="tab-queue">
    <div class="sa-banner" style="animation:fadeUp .4s both"><i class='bx bx-shield-quarter'></i><span>Super Admin Sign-off Required — Documents at 7 years must be reviewed before disposal action.</span></div>
    <div id="complianceQueue" style="animation:fadeUp .4s .1s both">
      <div class="skeleton" style="height:100px;border-radius:12px;margin-bottom:10px"></div>
      <div class="skeleton" style="height:100px;border-radius:12px"></div>
    </div>
  </div>

  <!-- ── TAB: DISPOSAL LOG ── -->
  <div class="tab-panel" id="tab-disposal">
    <div class="po-card" style="animation:fadeUp .4s both">
      <div style="padding:14px 20px;border-bottom:1px solid var(--border);background:var(--bg);display:flex;align-items:center;gap:8px">
        <i class='bx bx-lock' style="color:var(--primary);font-size:17px"></i>
        <span style="font-size:13px;font-weight:700">Secure Disposal Log</span>
        <span style="font-size:11px;color:var(--text-3);margin-left:4px">— Permanent read-only record</span>
      </div>
      <div class="po-table-wrap">
        <table class="po-table">
          <thead><tr><th>Document ID</th><th>Title</th><th>Category</th><th>Disposal Date</th><th>Approved By</th><th>Reason</th></tr></thead>
          <tbody id="disposalBody"></tbody>
        </table>
        <div id="disposalEmpty" class="po-empty" style="display:none"><i class='bx bx-check-circle'></i><p>No disposals recorded yet.</p></div>
      </div>
    </div>
  </div>

</div><!-- .page -->
</main>

<!-- ── OVERLAY ── -->
<div class="overlay" id="ov"></div>

<!-- ── PREVIEW MODAL ── -->
<div class="modal-base" id="previewModal">
  <div class="mbox-lg">
    <div class="m-hd">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="m-hd-ic" style="background:#E8F5E9;color:var(--primary)"><i class='bx bx-file'></i></div>
        <div>
          <div class="m-hd-title" id="previewTitle">Document Preview</div>
          <div class="m-hd-sub" id="previewSub">Full document view</div>
        </div>
      </div>
      <button class="m-cl" id="previewCl"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body" id="previewBody"></div>
    <div class="m-ft">
      <button class="btn btn-g btn-s" onclick="closeModal('previewModal')">Close</button>
      <button class="btn btn-g btn-s" id="previewViewFile"><i class='bx bx-link-external'></i> View File</button>
      <button class="btn btn-p btn-s" id="previewAuditBtn"><i class='bx bx-history'></i> Audit Trail</button>
    </div>
  </div>
</div>

<!-- ── AUDIT TRAIL MODAL ── -->
<div class="modal-base" id="auditModal">
  <div class="mbox-sm">
    <div class="m-hd">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="m-hd-ic" style="background:#EFF6FF;color:var(--info)"><i class='bx bx-history'></i></div>
        <div>
          <div class="m-hd-title">Audit Trail</div>
          <div class="m-hd-sub" id="auditModalSub">Complete action history</div>
        </div>
      </div>
      <button class="m-cl" onclick="closeModal('auditModal')"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body">
      <div class="audit-timeline" id="auditTimeline"></div>
    </div>
    <div class="m-ft"><button class="btn btn-g btn-s" onclick="closeModal('auditModal')">Close</button></div>
  </div>
</div>

<!-- ── DISPOSAL MODAL ── -->
<div class="modal-base" id="disposalModal">
  <div class="mbox-sm">
    <div class="m-hd">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="m-hd-ic" style="background:#FEE2E2;color:var(--danger)"><i class='bx bx-trash'></i></div>
        <div>
          <div class="m-hd-title" id="disposalTitle">Approve Disposal</div>
          <div class="m-hd-sub">Super Admin sign-off required</div>
        </div>
      </div>
      <button class="m-cl" id="disposalCl"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body-p">
      <div class="sa-banner"><i class='bx bx-shield-quarter'></i><span>Disposal is permanent and will be recorded in the Secure Disposal Log.</span></div>
      <div class="danger-box"><i class='bx bx-error'></i><p>Once approved for disposal, this document will be permanently marked. This action cannot be undone.</p></div>
      <div class="info-grid" id="disposalInfo"></div>
      <div class="fg">
        <label class="fl">Reviewer Notes</label>
        <textarea class="fta" id="disposalNotes" placeholder="Enter compliance review notes…" style="min-height:60px"></textarea>
      </div>
      <div class="fg">
        <label class="fl">Disposal Reason <span>*</span></label>
        <select class="fs" id="disposalReason">
          <option value="">— Select Reason —</option>
          <option>Retention period expired — no legal hold</option>
          <option>Superseded by updated document</option>
          <option>Duplicate record confirmed</option>
          <option>Regulatory clearance granted</option>
        </select>
      </div>
    </div>
    <div class="m-ft">
      <button class="btn btn-g btn-s" onclick="closeModal('disposalModal')">Cancel</button>
      <button class="btn btn-warn btn-s" id="extendRetBtn"><i class='bx bx-time-five'></i> Extend Retention</button>
      <button class="btn btn-danger btn-s" id="approveDisposalBtn"><i class='bx bx-check-shield'></i> Approve Disposal</button>
    </div>
  </div>
</div>

<!-- ── POLICY MODAL ── -->
<div class="modal-base" id="policyModal">
  <div class="mbox-sm">
    <div class="m-hd">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="m-hd-ic" style="background:#FEF3C7;color:var(--gold)"><i class='bx bx-shield-quarter'></i></div>
        <div>
          <div class="m-hd-title">Retention Policy Editor</div>
          <div class="m-hd-sub">Super Admin — Modify schedule rules</div>
        </div>
      </div>
      <button class="m-cl" onclick="closeModal('policyModal')"><i class='bx bx-x'></i></button>
    </div>
    <div class="m-body-p">
      <div class="sa-banner"><i class='bx bx-shield-quarter'></i><span>Changes apply to future classification decisions. Existing documents retain their current schedule.</span></div>
      <p style="font-size:12px;color:var(--text-2);line-height:1.7">Use the <strong>Retention Monitor</strong> tab to edit policy rules per category. All changes are logged in the audit trail.</p>
    </div>
    <div class="m-ft">
      <button class="btn btn-g btn-s" onclick="closeModal('policyModal')">Close</button>
      <button class="btn btn-p btn-s" onclick="closeModal('policyModal');switchTab('monitor')"><i class='bx bx-bar-chart-alt-2'></i> Go to Monitor</button>
    </div>
  </div>
</div>

<div id="tw"></div>

<script>
const API = '<?= htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES) ?>';
const ROLE = '<?= addslashes($rlmRoleName) ?>';
const ROLE_RANK = <?= (int)$rlmRoleRank ?>;
const USER_ZONE = '<?= addslashes($rlmUserZone ?? '') ?>';
const USER_ID = <?= json_encode($rlmUserId) ?>;

// ── API ───────────────────────────────────────────────────────────────────────
async function apiFetch(path, opts = {}) {
    const r = await fetch(path, { headers: { 'Content-Type': 'application/json' }, ...opts });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p  => apiFetch(p);
const apiPost = (p, b) => apiFetch(p, { method: 'POST', body: JSON.stringify(b) });

// ── STATE ─────────────────────────────────────────────────────────────────────
let currentPage     = 1;
const PER_PAGE      = 8;
let activeDocId     = null;   // numeric DB id for modal actions
let activeDocRow    = null;   // full row object for modal context
let policyRules     = [];     // loaded from backend

// ── HELPERS ──────────────────────────────────────────────────────────────────
const esc     = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'2-digit' }) : '—';
const todayStr = () => new Date().toISOString().split('T')[0];

// ── ROLE-BASED UI ─────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(b => {
    if (b.dataset.tab === 'disposal' && ROLE_RANK < 4) b.style.display = 'none';
    if (b.dataset.tab === 'queue'     && ROLE_RANK <= 1) b.style.display = 'none';
});
if (ROLE_RANK < 4) {
    const pb = document.getElementById('policyBtn');
    if (pb) pb.style.display = 'none';
    const sp = document.getElementById('savePoliciesBtn');
    if (sp) sp.style.display = 'none';
    const ext = document.getElementById('extendRetBtn');
    const app = document.getElementById('approveDisposalBtn');
    if (ext) ext.style.display = 'none';
    if (app) app.style.display = 'none';
}

// ── INIT ──────────────────────────────────────────────────────────────────────
loadStats();
loadTable();

// ── STATS ─────────────────────────────────────────────────────────────────────
async function loadStats() {
    try {
        const d = await apiGet(API + '?api=stats');
        document.getElementById('statsRow').innerHTML = `
          <div class="po-stat" style="animation-delay:.05s"><div class="stat-ic ic-g"><i class='bx bx-folder'></i></div><div class="stat-body"><div class="stat-v">${d.total}</div><div class="stat-l">Total Records</div></div></div>
          <div class="po-stat" style="animation-delay:.08s"><div class="stat-ic ic-g"><i class='bx bx-check-circle'></i></div><div class="stat-body"><div class="stat-v">${d.active}</div><div class="stat-l">Active Storage</div></div></div>
          <div class="po-stat" style="animation-delay:.11s"><div class="stat-ic ic-b"><i class='bx bx-archive'></i></div><div class="stat-body"><div class="stat-v">${d.archive}</div><div class="stat-l">Archived</div></div></div>
          <div class="po-stat" style="animation-delay:.14s"><div class="stat-ic ic-r"><i class='bx bx-error'></i></div><div class="stat-body"><div class="stat-v">${d.review}</div><div class="stat-l">Compliance Review</div></div></div>
          <div class="po-stat" style="animation-delay:.17s"><div class="stat-ic ic-gold"><i class='bx bx-bell'></i></div><div class="stat-body"><div class="stat-v">${d.nearThreshold}</div><div class="stat-l">Near Threshold</div></div></div>
          <div class="po-stat" style="animation-delay:.2s"><div class="stat-ic ic-gy"><i class='bx bx-trash'></i></div><div class="stat-body"><div class="stat-v">${d.disposed}</div><div class="stat-l">Disposed</div></div></div>`;
        // update queue badge
        if (d.review > 0) {
            const b = document.getElementById('queueBadge');
            b.textContent = d.review;
            b.style.display = 'inline';
        }
    } catch(e) { toast('Failed to load stats: ' + e.message, 'danger'); }
}

// ── TABLE ─────────────────────────────────────────────────────────────────────
async function loadTable() {
    const q   = document.getElementById('srch').value.trim();
    const cat = document.getElementById('fCat').value;
    const stg = document.getElementById('fStage').value;
    const acc = document.getElementById('fAccess').value;
    const df  = document.getElementById('fDateFrom').value;
    const dt  = document.getElementById('fDateTo').value;

    const params = new URLSearchParams({
        api: 'list', page: currentPage, per: PER_PAGE,
        ...(q   && { q }),
        ...(cat && { cat }),
        ...(stg && { stage: stg }),
        ...(acc && { access: acc }),
        ...(df  && { from: df }),
        ...(dt  && { to: dt }),
    });

    try {
        const d = await apiGet(API + '?' + params.toString());
        renderTable(d);
    } catch(e) {
        toast('Failed to load documents: ' + e.message, 'danger');
        document.getElementById('docTableBody').innerHTML =
            `<tr><td colspan="8" style="padding:24px;text-align:center;color:var(--danger);font-size:12px">Error loading data. Please refresh.</td></tr>`;
    }
}

function renderTable(d) {
    const tbody = document.getElementById('docTableBody');
    const empty = document.getElementById('docEmpty');

    if (!d.items.length) {
        tbody.innerHTML = '';
        empty.style.display = 'block';
        document.getElementById('ftInfo').textContent = '';
        document.getElementById('pagBtns').innerHTML = '';
        return;
    }
    empty.style.display = 'none';

    tbody.innerHTML = d.items.map(doc => {
        const yrs = doc.yearsActive;
        const barPct   = Math.min(100, Math.round(yrs / 7 * 100));
        const barClass = yrs >= 7 ? 'rf-danger' : yrs >= 3 ? 'rf-warn' : 'rf-safe';
        const stgLabel = doc.retentionStage === 'Review' ? 'Compliance Review'
                       : doc.retentionStage === 'Active' ? 'Active Storage'
                       : doc.retentionStage;
        return `<tr onclick="openPreview(${doc.id})">
          <td>
            <div class="doc-id">${esc(doc.docId)}</div>
            <div class="doc-sub">${esc(doc.category)}</div>
          </td>
          <td>
            <div class="doc-title">${esc(doc.title)}</div>
            <div class="doc-meta">${esc(doc.department)} · ${esc(doc.direction)}</div>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <div style="width:27px;height:27px;border-radius:7px;background:rgba(46,125,50,.1);display:inline-flex;align-items:center;justify-content:center"><i class='bx bx-folder' style="color:var(--primary);font-size:13px"></i></div>
              <span style="font-size:12px;font-weight:600;color:var(--text-1)">${esc(doc.category)}</span>
            </div>
          </td>
          <td><div class="date-val">${fmtDate(doc.docDate)}</div></td>
          <td><span class="access-badge ab-${esc(doc.accessLevel || 'Internal')}">${esc(doc.accessLevel || 'Internal')}</span></td>
          <td><span class="ret-stage rs-${esc(doc.retentionStage)}">${esc(stgLabel)}</span></td>
          <td>
            <div class="ret-bar-wrap">
              <div class="ret-bar"><div class="ret-fill ${barClass}" style="width:${barPct}%"></div></div>
              <div class="ret-yr">${yrs.toFixed(1)} yr</div>
            </div>
          </td>
          <td onclick="event.stopPropagation()">
            <div class="row-acts">
              <button class="icon-btn" onclick="openPreview(${doc.id})" title="Preview"><i class='bx bx-show'></i></button>
              <button class="icon-btn" onclick="openAudit(${doc.id}, '${esc(doc.docId)}')" title="Audit Trail"><i class='bx bx-history'></i></button>
              ${doc.retentionStage === 'Review' ? `<button class="icon-btn danger" onclick="openDisposal(${doc.id})" title="Disposal Review (SA)"><i class='bx bx-check-shield'></i></button>` : ''}
              ${doc.retentionStage === 'Active' || doc.retentionStage === 'Archive' ? `<button class="icon-btn" onclick="quickExtend(${doc.id})" title="Extend Retention" style="color:var(--warning)"><i class='bx bx-time-five'></i></button>` : ''}
            </div>
          </td>
        </tr>`;
    }).join('');

    const s = (d.page - 1) * d.perPage + 1;
    const e = Math.min(d.page * d.perPage, d.total);
    document.getElementById('ftInfo').textContent = `Showing ${s}–${e} of ${d.total} records`;
    renderPagination(d.pages, d.page);
}

function renderPagination(pages, cur) {
    let btns = '';
    for (let i = 1; i <= pages; i++) {
        if (i === 1 || i === pages || (i >= cur - 1 && i <= cur + 1))
            btns += `<button class="pb${i === cur ? ' active' : ''}" onclick="goPg(${i})">${i}</button>`;
        else if (i === cur - 2 || i === cur + 2)
            btns += `<button class="pb" disabled>…</button>`;
    }
    document.getElementById('pagBtns').innerHTML =
        `<button class="pb" onclick="goPg(${cur-1})" ${cur<=1?'disabled':''}><i class='bx bx-chevron-left'></i></button>
         ${btns}
         <button class="pb" onclick="goPg(${cur+1})" ${cur>=pages?'disabled':''}><i class='bx bx-chevron-right'></i></button>`;
}
window.goPg = p => { currentPage = p; loadTable(); };

// ── FILTER EVENTS ─────────────────────────────────────────────────────────────
['srch','fCat','fStage','fAccess','fDateFrom','fDateTo'].forEach(id => {
    document.getElementById(id).addEventListener('input', () => { currentPage = 1; loadTable(); });
});
document.getElementById('clearFilters').addEventListener('click', () => {
    ['srch','fDateFrom','fDateTo'].forEach(id => document.getElementById(id).value = '');
    ['fCat','fStage','fAccess'].forEach(id => document.getElementById(id).selectedIndex = 0);
    currentPage = 1; loadTable();
});

// ── MONITOR TAB ───────────────────────────────────────────────────────────────
async function loadMonitor() {
    try {
        const d = await apiGet(API + '?api=monitor');
        const { stageCounts, alerts } = d;
        const maxC = Math.max(...Object.values(stageCounts), 1);

        document.getElementById('stageBars').innerHTML = [
            ['Active Storage', stageCounts.Active || 0, 'sbi-g'],
            ['Archive',        stageCounts.Archive || 0, 'sbi-b'],
            ['Compliance Review', stageCounts.Review || 0, 'sbi-r'],
            ['Disposed',       stageCounts.Disposed || 0, 'sbi-gy'],
        ].map(([label, count, cls]) => `
          <div class="stage-bar-item">
            <div class="stage-bar-label">${label}</div>
            <div class="stage-bar-outer"><div class="stage-bar-inner ${cls}" style="width:${count/maxC*100}%"></div></div>
            <div class="stage-bar-count">${count}</div>
          </div>`).join('');

        if (!alerts.length) {
            document.getElementById('alertList').innerHTML =
                '<div style="padding:20px;text-align:center;color:var(--text-3);font-size:12px"><i class=\'bx bx-check-circle\' style="font-size:28px;display:block;margin-bottom:6px;color:#C8E6C9"></i>No threshold alerts at this time</div>';
        } else {
            document.getElementById('alertList').innerHTML = alerts.map(a => `
              <div class="alert-item alert-${a.level}">
                <i class='bx ${a.level === 'danger' ? 'bx-error' : 'bx-time-five'}'></i>
                <div><div class="alert-text">${esc(a.message)}</div><div class="alert-sub">${esc(a.sub)}</div></div>
              </div>`).join('');
        }
    } catch(e) { toast('Monitor load error: ' + e.message, 'danger'); }

    loadPolicies();
}

// ── POLICIES ──────────────────────────────────────────────────────────────────
async function loadPolicies() {
    try {
        const rows = await apiGet(API + '?api=policies');
        policyRules = rows;
        renderPolicies();
    } catch(e) {
        // If table doesn't exist yet, seed default display
        policyRules = [
            {category:'Financial',  active_years:3, archive_years:3, review_years:7, default_action:'Compliance Review', updated_at: null},
            {category:'Legal',      active_years:5, archive_years:5, review_years:10, default_action:'Compliance Review', updated_at: null},
            {category:'HR',         active_years:3, archive_years:3, review_years:7,  default_action:'Extend / Dispose', updated_at: null},
            {category:'Compliance', active_years:3, archive_years:3, review_years:7,  default_action:'Compliance Review', updated_at: null},
            {category:'Procurement',active_years:3, archive_years:3, review_years:7,  default_action:'Dispose', updated_at: null},
            {category:'Administrative', active_years:2, archive_years:2, review_years:5, default_action:'Dispose', updated_at: null},
        ];
        renderPolicies();
    }
}

function renderPolicies() {
    document.getElementById('policyBody').innerHTML = policyRules.map((r, i) => `
      <tr>
        <td style="font-weight:600;color:var(--text-1)">${esc(r.category)}</td>
        <td><input type="number" class="policy-edit-input" value="${r.active_years}" min="1" max="10" data-idx="${i}" data-field="active_years"></td>
        <td><input type="number" class="policy-edit-input" value="${r.archive_years}" min="1" max="15" data-idx="${i}" data-field="archive_years"></td>
        <td><input type="number" class="policy-edit-input" value="${r.review_years}" min="1" max="30" data-idx="${i}" data-field="review_years"></td>
        <td><span style="font-size:12px;color:var(--text-2)">${esc(r.default_action)}</span></td>
        <td><span style="font-size:11px;color:var(--text-3);font-family:'DM Mono',monospace">${r.updated_at ? fmtDate(r.updated_at) : '—'}</span></td>
      </tr>`).join('');

    // Live update local policyRules when inputs change
    document.querySelectorAll('.policy-edit-input').forEach(inp => {
        inp.addEventListener('change', () => {
            const idx   = +inp.dataset.idx;
            const field = inp.dataset.field;
            policyRules[idx][field] = +inp.value;
        });
    });
}

document.getElementById('savePoliciesBtn').addEventListener('click', async () => {
    const btn = document.getElementById('savePoliciesBtn');
    btn.disabled = true;
    btn.innerHTML = `<i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i> Saving…`;
    try {
        await apiPost(API + '?api=save-policies', { rules: policyRules });
        toast('Retention policies saved', 'success');
    } catch(e) {
        toast('Save failed: ' + e.message, 'danger');
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<i class='bx bx-save'></i> Save Changes`;
    }
});

// ── COMPLIANCE QUEUE ──────────────────────────────────────────────────────────
async function loadQueue() {
    try {
        const items = await apiGet(API + '?api=compliance-queue');
        const el = document.getElementById('complianceQueue');
        if (!items.length) {
            el.innerHTML = '<div class="po-empty"><i class=\'bx bx-check-circle\'></i><p>No documents currently in compliance review queue.</p></div>';
            return;
        }
        el.innerHTML = items.map(d => `
          <div class="queue-item">
            <div class="queue-hd">
              <div>
                <div class="queue-id">${esc(d.docId)} · ${d.yearsActive.toFixed(1)} yrs</div>
                <div class="queue-name">${esc(d.title)}</div>
                <div class="queue-meta">${esc(d.category)} · ${esc(d.department)} · Created ${fmtDate(d.docDate)}</div>
              </div>
              <div class="queue-actions">
                <button class="btn btn-g btn-s" onclick="openPreview(${d.id})"><i class='bx bx-show'></i> Preview</button>
                <button class="btn btn-warn btn-s" onclick="quickExtend(${d.id})"><i class='bx bx-time-five'></i> Extend</button>
                <button class="btn btn-danger btn-s" onclick="openDisposal(${d.id})"><i class='bx bx-check-shield'></i> Dispose</button>
              </div>
            </div>
            <textarea class="queue-notes" id="qnotes-${d.id}" placeholder="Reviewer notes for ${esc(d.docId)}…"></textarea>
            <div class="queue-sign"><i class='bx bx-shield-quarter'></i> Requires Super Admin sign-off before disposal action is applied</div>
          </div>`).join('');
    } catch(e) { toast('Queue load error: ' + e.message, 'danger'); }
}

// ── DISPOSAL LOG ──────────────────────────────────────────────────────────────
async function loadDisposalLog() {
    try {
        const rows = await apiGet(API + '?api=disposal-log');
        const empty = document.getElementById('disposalEmpty');
        if (!rows.length) {
            document.getElementById('disposalBody').innerHTML = '';
            empty.style.display = 'block'; return;
        }
        empty.style.display = 'none';
        document.getElementById('disposalBody').innerHTML = rows.map(d => `
          <tr>
            <td><span class="doc-id">${esc(d.docId)}</span></td>
            <td><span class="doc-title">${esc(d.title)}</span></td>
            <td><span style="font-size:11px;font-weight:600;color:var(--text-3)">${esc(d.category)}</span></td>
            <td><div class="date-val">${fmtDate(d.disposedAt)}</div></td>
            <td><span style="font-size:12px;font-weight:600;color:var(--primary)">${esc(d.disposedBy || '—')}</span></td>
            <td><span style="font-size:11px;color:var(--text-2)">${esc(d.disposalReason || '—')}</span></td>
          </tr>`).join('');
    } catch(e) { toast('Disposal log error: ' + e.message, 'danger'); }
}

// ── PREVIEW MODAL ─────────────────────────────────────────────────────────────
window.openPreview = async id => {
    showModal('previewModal');
    document.getElementById('previewBody').innerHTML =
        '<div class="skeleton" style="height:14px;width:60%;margin-bottom:10px"></div><div class="skeleton" style="height:14px;width:80%;margin-bottom:10px"></div>';

    try {
        const rows = await apiFetch(API + '?api=list&page=1&per=200');
        const doc  = rows.items.find(d => d.id === id);
        if (!doc) { toast('Document not found', 'danger'); closeModal('previewModal'); return; }
        activeDocId  = id;
        activeDocRow = doc;

        document.getElementById('previewTitle').textContent = doc.title;
        document.getElementById('previewSub').textContent   = `${doc.docId} · ${doc.category} · ${doc.department}`;

        document.getElementById('previewBody').innerHTML = `
          <div class="info-grid">
            <div class="info-item"><label>Document ID</label><div class="v mono">${esc(doc.docId)}</div></div>
            <div class="info-item"><label>Reference No.</label><div class="v mono">${esc(doc.refNumber || '—')}</div></div>
            <div class="info-item"><label>Category</label><div class="v">${esc(doc.category)}</div></div>
            <div class="info-item"><label>Department</label><div class="v">${esc(doc.department)}</div></div>
            <div class="info-item"><label>Direction</label><div class="v">${esc(doc.direction)}</div></div>
            <div class="info-item"><label>Capture Mode</label><div class="v">${esc(doc.captureMode)}</div></div>
            <div class="info-item"><label>Sender</label><div class="v">${esc(doc.sender)}</div></div>
            <div class="info-item"><label>Recipient</label><div class="v">${esc(doc.recipient)}</div></div>
            <div class="info-item"><label>Assigned To</label><div class="v">${esc(doc.assignedTo)}</div></div>
            <div class="info-item"><label>Priority</label><div class="v">${esc(doc.priority)}</div></div>
            <div class="info-item"><label>Access Level</label><div class="v"><span class="access-badge ab-${esc(doc.accessLevel || 'Internal')}">${esc(doc.accessLevel || 'Internal')}</span></div></div>
            <div class="info-item"><label>Retention Stage</label><div class="v"><span class="ret-stage rs-${esc(doc.retentionStage)}">${esc(doc.retentionStage)}</span></div></div>
            <div class="info-item"><label>Retention Period</label><div class="v">${esc(doc.retention)}</div></div>
            <div class="info-item"><label>Years Active</label><div class="v mono">${doc.yearsActive.toFixed(1)} yrs</div></div>
            <div class="info-item"><label>AI Confidence</label><div class="v">${doc.aiConfidence ? doc.aiConfidence + '%' : 'N/A'}</div></div>
            <div class="info-item"><label>Needs Validation</label><div class="v">${doc.needsValidation ? '<span style="color:var(--warning);font-weight:700">Yes</span>' : 'No'}</div></div>
          </div>
          ${doc.notes ? `<div class="sdv">Notes</div><div style="font-size:13px;color:var(--text-2);line-height:1.7;padding:12px;background:var(--bg);border-radius:10px;border:1px solid var(--border)">${esc(doc.notes)}</div>` : ''}
          ${doc.filePath ? `<div class="sdv" style="margin-top:14px">Attached File</div><div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;font-size:13px"><i class='bx bx-file' style="color:var(--primary);font-size:18px"></i><div><div style="font-weight:600">${esc(doc.fileName)}</div><div style="font-size:11px;color:var(--text-3)">${doc.fileExt} · ${doc.fileSizeKb.toFixed(1)} KB</div></div></div>` : ''}`;

        // Wire up view file button
        document.getElementById('previewViewFile').onclick = async () => {
            if (!doc.filePath) { toast('No file attached to this document', 'warning'); return; }
            try {
                const d = await apiPost(API + '?api=signed-url', { path: doc.filePath, id: id });
                window.open(d.signedUrl, '_blank');
            } catch(e) { toast('Could not generate file URL: ' + e.message, 'danger'); }
        };
        document.getElementById('previewAuditBtn').onclick = () => {
            closeModal('previewModal');
            openAudit(id, doc.docId);
        };

    } catch(e) {
        toast('Error loading preview: ' + e.message, 'danger');
        closeModal('previewModal');
    }
};
document.getElementById('previewCl').addEventListener('click', () => closeModal('previewModal'));

// ── AUDIT TRAIL MODAL ─────────────────────────────────────────────────────────
window.openAudit = async (id, docId) => {
    document.getElementById('auditModalSub').textContent = `${docId} — action history`;
    document.getElementById('auditTimeline').innerHTML =
        '<div class="skeleton" style="height:60px;border-radius:8px;margin-left:20px"></div>';
    showModal('auditModal');
    try {
        const rows = await apiGet(API + `?api=audit&id=${id}`);
        if (!rows.length) {
            document.getElementById('auditTimeline').innerHTML =
                '<div style="padding:20px;text-align:center;color:var(--text-3);font-size:12px;margin-left:20px">No audit entries found.</div>';
            return;
        }
        document.getElementById('auditTimeline').innerHTML = rows.map(r => `
          <div class="audit-item">
            <div class="audit-dot ${r.css_class || 'dc-s'}"></div>
            <div class="audit-content">
              <div class="audit-label">${esc(r.action_label)} ${r.is_super_admin ? '<span class="audit-sa-badge">SA</span>' : ''}</div>
              ${r.note ? `<div class="audit-note">${esc(r.note)}</div>` : ''}
              <div class="audit-meta">
                <span>${esc(r.actor_name)}</span>
                <span>${esc(r.actor_role)}</span>
                <span>${r.ip_address || ''}</span>
                <span>${r.occurred_at ? new Date(r.occurred_at).toLocaleString('en-PH') : ''}</span>
              </div>
            </div>
          </div>`).join('');
    } catch(e) { toast('Audit trail error: ' + e.message, 'danger'); }
};

// ── DISPOSAL MODAL ────────────────────────────────────────────────────────────
window.openDisposal = async dbId => {
    // Find doc from last known table state by querying API
    let doc = activeDocRow && activeDocRow.id === dbId ? activeDocRow : null;
    if (!doc) {
        try {
            const d = await apiGet(API + `?api=list&page=1&per=200`);
            doc = d.items.find(x => x.id === dbId);
        } catch(e) {}
    }
    activeDocId = dbId;

    document.getElementById('disposalTitle').textContent = `Approve Disposal — ${doc ? doc.docId : '#' + dbId}`;
    document.getElementById('disposalInfo').innerHTML = doc ? `
      <div class="info-item"><label>Document ID</label><div class="v mono">${esc(doc.docId)}</div></div>
      <div class="info-item"><label>Years Active</label><div class="v mono">${doc.yearsActive.toFixed(1)} yrs</div></div>
      <div class="info-item"><label>Title</label><div class="v">${esc(doc.title)}</div></div>
      <div class="info-item"><label>Category</label><div class="v">${esc(doc.category)}</div></div>` : '';
    document.getElementById('disposalNotes').value  = doc && document.getElementById(`qnotes-${dbId}`)
        ? document.getElementById(`qnotes-${dbId}`).value : '';
    document.getElementById('disposalReason').value = '';
    showModal('disposalModal');
};
document.getElementById('disposalCl').addEventListener('click', () => { closeModal('disposalModal'); activeDocId = null; });

document.getElementById('approveDisposalBtn').addEventListener('click', async () => {
    const reason = document.getElementById('disposalReason').value;
    const note   = document.getElementById('disposalNotes').value.trim();
    if (!reason) { shk('disposalReason'); return toast('Select a disposal reason', 'danger'); }
    if (!activeDocId) return;

    const btn = document.getElementById('approveDisposalBtn');
    btn.disabled = true;
    btn.innerHTML = `<i class='bx bx-loader-alt' style="animation:spin .8s linear infinite"></i> Processing…`;

    try {
        await apiPost(API + '?api=approve-disposal', { id: activeDocId, reason, note });
        toast('Document approved for disposal — logged in Secure Disposal Log', 'warning');
        closeModal('disposalModal');
        activeDocId = null;
        refresh();
    } catch(e) {
        toast('Disposal failed: ' + e.message, 'danger');
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<i class='bx bx-check-shield'></i> Approve Disposal`;
    }
});

document.getElementById('extendRetBtn').addEventListener('click', async () => {
    if (!activeDocId) return;
    closeModal('disposalModal');
    await quickExtend(activeDocId);
    activeDocId = null;
});

window.quickExtend = async id => {
    const note = document.getElementById(`qnotes-${id}`)?.value.trim() || '';
    try {
        const d = await apiPost(API + '?api=extend-retention', { id, note });
        toast(`Retention extended — moved to ${d.newStage}`, 'info');
        refresh();
    } catch(e) { toast('Extend failed: ' + e.message, 'danger'); }
};

// ── EXPORT ────────────────────────────────────────────────────────────────────
document.getElementById('exportBtn').addEventListener('click', async () => {
    try {
        const rows = await apiGet(API + '?api=export');
        const cols = ['docId','title','category','department','direction','docDate','accessLevel','retentionStage','yearsActive','priority','status','createdBy','createdAt'];
        const lines = [cols.join(',')];
        rows.forEach(r => lines.push(cols.map(c => `"${String(r[c]??'').replace(/"/g,'""')}"`).join(',')));
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
        a.download = `dtrs_lifecycle_${todayStr()}.csv`;
        a.click();
        toast('Exported successfully', 'success');
    } catch(e) { toast('Export failed: ' + e.message, 'danger'); }
});

document.getElementById('policyBtn').addEventListener('click', () => showModal('policyModal'));

// ── TABS ──────────────────────────────────────────────────────────────────────
function switchTab(name) {
    document.querySelectorAll('.tab-btn').forEach(b  => b.classList.toggle('active', b.dataset.tab === name));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + name));
    document.getElementById('searchFilters').style.display = name === 'search' ? 'flex' : 'none';
    if (name === 'monitor')  loadMonitor();
    if (name === 'queue')    loadQueue();
    if (name === 'disposal') loadDisposalLog();
}
document.querySelectorAll('.tab-btn').forEach(b => b.addEventListener('click', () => switchTab(b.dataset.tab)));

// ── MODAL HELPERS ─────────────────────────────────────────────────────────────
function showModal(id)  { document.getElementById(id).classList.add('show'); document.getElementById('ov').classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); document.getElementById('ov').classList.remove('show'); }
document.getElementById('ov').addEventListener('click', () => {
    ['previewModal','disposalModal','policyModal','auditModal'].forEach(closeModal);
});
// ── REFRESH ALL ───────────────────────────────────────────────────────────────
function refresh() { loadStats(); loadTable(); }

// ── TOAST ─────────────────────────────────────────────────────────────────────
function toast(msg, type = 'success') {
    const icons = { success: 'bx-check-circle', danger: 'bx-error-circle', warning: 'bx-error', info: 'bx-info-circle' };
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<i class='bx ${icons[type] || 'bx-check-circle'}' style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
    document.getElementById('tw').appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 300); }, 3200);
}

// ── SHAKE ─────────────────────────────────────────────────────────────────────
function shk(id) {
    const el = document.getElementById(id); if (!el) return;
    el.style.borderColor = '#DC2626';
    el.style.animation   = 'shake .3s ease';
    setTimeout(() => { el.style.borderColor = ''; el.style.animation = ''; }, 600);
}
</script>
</body>
</html>