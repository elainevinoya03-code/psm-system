<?php
declare(strict_types=1);

ob_start();

$root = dirname(__DIR__, 2);

// Force correct pooler connection (aws-1, session pooler, port 5432)
if (!defined('PG_DSN'))         define('PG_DSN',         'pgsql:host=aws-1-ap-northeast-1.pooler.supabase.com;port=5432;dbname=postgres;sslmode=require');
if (!defined('PG_DB_USER'))     define('PG_DB_USER',     'postgres.fnpxtquhvlflyjibuwlx');
if (!defined('PG_DB_PASSWORD')) define('PG_DB_PASSWORD', '0ltvCJjD0CkZoBpX');

ini_set('display_errors', '0');
ini_set('log_errors',     '1');

require_once $root . '/config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = [
    'user_id'   => $_SESSION['user_id']   ?? null,
    'full_name' => $_SESSION['full_name'] ?? ($_SESSION['name'] ?? 'Super Admin'),
    'email'     => $_SESSION['email']     ?? '',
    'roles'     => $_SESSION['roles']     ?? ['Super Admin'],
];

// ── Role gate: only Super Admin / Admin / Manager may access POs ─────────────
$rolesArr    = (array)($currentUser['roles'] ?? []);
$isSuperAdmin= in_array('Super Admin', $rolesArr, true);
$isAdmin     = in_array('Admin', $rolesArr, true);
$isManager   = in_array('Manager', $rolesArr, true);
$hasPoAccess = $isSuperAdmin || $isAdmin || $isManager;

if (!$hasPoAccess) {
    if (isset($_GET['action'])) {
        if (ob_get_length()) {
            ob_clean();
        }
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized to access purchase orders.']);
    } else {
        header('Location: /user_dashboard.php');
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// API
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {

    // Ensure any previous buffered output (warnings/notices) does not break JSON
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');

    function getPDO(): PDO {
        static $pdo = null;
        if ($pdo) return $pdo;
        $pdo = new PDO(PG_DSN, PG_DB_USER, PG_DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    }

    function ok(mixed $data = null, string $message = 'OK'): void {
        // Remove any buffered output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
        exit;
    }
    function fail(string $message, int $code = 400, mixed $errors = null): void {
        http_response_code($code);
        // Remove any buffered output before sending JSON
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['success' => false, 'message' => $message, 'errors' => $errors]);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $action = trim($_GET['action']);
    $body   = [];
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
    }

    // ── LIST ───────────────────────────────────────────────────────────────
    function actionList(): void {
        $pdo      = getPDO();
        $search   = trim($_GET['search']    ?? '');
        $status   = trim($_GET['status']    ?? '');
        $supplier = trim($_GET['supplier']  ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo   = trim($_GET['date_to']   ?? '');
        $amtMin   = isset($_GET['amt_min']) && $_GET['amt_min'] !== '' ? (float)$_GET['amt_min'] : null;
        $amtMax   = isset($_GET['amt_max']) && $_GET['amt_max'] !== '' ? (float)$_GET['amt_max'] : null;
        $page     = max(1, (int)($_GET['page']     ?? 1));
        $perPage  = min(100, max(1, (int)($_GET['per_page'] ?? 15)));

        $where = ['1=1']; $params = [];
        if ($search !== '') {
            $where[] = "(po.po_number ILIKE :search OR po.pr_reference ILIKE :search
                         OR po.supplier_name ILIKE :search OR po.issued_by ILIKE :search)";
            $params[':search'] = "%$search%";
        }
        if ($status   !== '') { $where[] = 'po.status = :status';          $params[':status']   = $status;   }
        if ($supplier !== '') { $where[] = 'po.supplier_name = :supplier'; $params[':supplier'] = $supplier; }
        if ($dateFrom !== '') { $where[] = 'po.date_issued >= :date_from'; $params[':date_from'] = $dateFrom; }
        if ($dateTo   !== '') { $where[] = 'po.date_issued <= :date_to';   $params[':date_to']   = $dateTo;   }
        if ($amtMin !== null) { $where[] = 'po.total_amount >= :amt_min';  $params[':amt_min']  = $amtMin;   }
        if ($amtMax !== null) { $where[] = 'po.total_amount <= :amt_max';  $params[':amt_max']  = $amtMax;   }

        $whereSQL = implode(' AND ', $where);

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM psm_purchase_orders po WHERE $whereSQL");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("
            SELECT po.id, po.po_number, po.pr_reference, po.supplier_name, po.supplier_category,
                   po.issued_by, po.date_issued, po.delivery_date, po.payment_terms,
                   po.status, po.fulfill_pct, po.total_amount, po.remarks, po.created_at, po.updated_at,
                   (SELECT COUNT(*) FROM psm_po_items i WHERE i.po_id = po.id) AS item_count
            FROM psm_purchase_orders po
            WHERE $whereSQL
            ORDER BY po.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['total_amount'] = (float)$r['total_amount'];
            $r['fulfill_pct']  = (int)$r['fulfill_pct'];
            $r['item_count']   = (int)$r['item_count'];
        }

        ok([
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int)ceil($total / $perPage),
            'stats'     => getStats($pdo),
            'filters'   => [
                'suppliers' => getDistinct($pdo, 'supplier_name'),
            ],
        ]);
    }

    function getStats(PDO $pdo): array {
        $row = $pdo->query("
            SELECT
                COUNT(*)                                                         AS total,
                COUNT(*) FILTER (WHERE status = 'Draft')                         AS draft,
                COUNT(*) FILTER (WHERE status = 'Sent')                          AS sent,
                COUNT(*) FILTER (WHERE status = 'Confirmed')                     AS confirmed,
                COUNT(*) FILTER (WHERE status = 'Partially Fulfilled')           AS partial,
                COUNT(*) FILTER (WHERE status = 'Fulfilled')                     AS fulfilled,
                COUNT(*) FILTER (WHERE status IN ('Cancelled','Voided'))         AS cancelled_voided,
                COALESCE(SUM(total_amount) FILTER (WHERE status NOT IN ('Cancelled','Voided')),0) AS active_value
            FROM psm_purchase_orders
        ")->fetch();
        return array_map(fn($v) => is_numeric($v) ? (float)$v : $v, $row);
    }

    function getDistinct(PDO $pdo, string $col): array {
        if (!in_array($col, ['supplier_name'], true)) return [];
        return $pdo->query("SELECT DISTINCT $col AS val FROM psm_purchase_orders
                            WHERE $col IS NOT NULL ORDER BY val")
                   ->fetchAll(PDO::FETCH_COLUMN);
    }

    // ── GET ────────────────────────────────────────────────────────────────
    function actionGet(): void {
        $pdo = getPDO();
        $id  = (int)($_GET['id'] ?? 0);
        if (!$id) fail('Missing id');

        $s = $pdo->prepare("SELECT * FROM psm_purchase_orders WHERE id = :id");
        $s->execute([':id' => $id]);
        $po = $s->fetch();
        if (!$po) fail('Purchase order not found', 404);

        $po['total_amount'] = (float)$po['total_amount'];
        $po['fulfill_pct']  = (int)$po['fulfill_pct'];

        $s = $pdo->prepare("SELECT * FROM psm_po_items WHERE po_id = :id ORDER BY line_no");
        $s->execute([':id' => $id]);
        $po['items'] = $s->fetchAll();
        foreach ($po['items'] as &$it) {
            $it['quantity']   = (float)$it['quantity'];
            $it['unit_price'] = (float)$it['unit_price'];
            $it['line_total'] = (float)$it['line_total'];
        }

        $s = $pdo->prepare("SELECT * FROM psm_po_audit_log WHERE po_id = :id ORDER BY occurred_at DESC");
        $s->execute([':id' => $id]);
        $po['audit'] = $s->fetchAll();

        $s = $pdo->prepare("SELECT * FROM psm_po_approvals WHERE po_id = :id ORDER BY level");
        $s->execute([':id' => $id]);
        $po['approvals'] = $s->fetchAll();

        ok($po);
    }

    // ── CREATE ─────────────────────────────────────────────────────────────
    function actionCreate(): void {
        global $currentUser, $body;
        $pdo = getPDO();

        $v = validatePoPayload($body);
        if ($v !== true) fail('Validation failed', 422, $v);

        $status = in_array($body['status'] ?? '', ['Draft','Sent'], true) ? $body['status'] : 'Draft';

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO psm_purchase_orders
                    (po_number, pr_reference, supplier_name, supplier_category,
                     issued_by, date_issued, delivery_date, payment_terms, remarks,
                     status, fulfill_pct, total_amount, created_user_id, created_by)
                VALUES
                    (:po_number, :pr_reference, :supplier_name, :supplier_category,
                     :issued_by, :date_issued, :delivery_date, :payment_terms, :remarks,
                     :status, 0, :total_amount, :created_user_id, :created_by)
                RETURNING id, po_number
            ");
            $stmt->execute([
                ':po_number'         => strtoupper(trim($body['po_number'])),
                ':pr_reference'      => strtoupper(trim($body['pr_reference'])),
                ':supplier_name'     => trim($body['supplier_name']),
                ':supplier_category' => trim($body['supplier_category'] ?? 'General'),
                ':issued_by'         => trim($body['issued_by']),
                ':date_issued'       => $body['date_issued'],
                ':delivery_date'     => $body['delivery_date'] ?? null,
                ':payment_terms'     => trim($body['payment_terms'] ?? 'Net 30'),
                ':remarks'           => trim($body['remarks'] ?? ''),
                ':status'            => $status,
                ':total_amount'      => computeTotal($body['items']),
                ':created_user_id'   => $currentUser['user_id'] ?? null,
                ':created_by'        => $currentUser['full_name'] ?? trim($body['issued_by']),
            ]);
            $po   = $stmt->fetch();
            $poId = (int)$po['id'];

            insertItems($pdo, $poId, $body['items']);

            $levels = [1 => 'Procurement Officer', 2 => 'Dept. Manager', 3 => 'Finance Head'];
            $ap = $pdo->prepare("INSERT INTO psm_po_approvals (po_id, level, role_label, approved_by, approved_at, is_done)
                                  VALUES (:po_id, :level, :role, :by, :at, :done)");
            foreach ($levels as $lvl => $role) {
                $done = $lvl === 1 && $status !== 'Draft';
                $ap->execute([
                    ':po_id' => $poId, ':level' => $lvl, ':role' => $role,
                    ':by'    => $done ? ($currentUser['full_name'] ?? trim($body['issued_by'])) : null,
                    ':at'    => $done ? date('Y-m-d H:i:s') : null,
                    ':done'  => $done ? 'true' : 'false',
                ]);
            }

            $label = $status === 'Draft' ? 'PO Draft Created' : 'PO Generated & Submitted';
            insertPoAudit($pdo, $poId, $label,
                          $currentUser['full_name'] ?? trim($body['issued_by']),
                          'dot-g', isSuperAdmin($currentUser));

            $pdo->commit();
            ok(['id' => $poId, 'po_number' => $po['po_number']], "PO {$po['po_number']} created");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if (str_contains($e->getMessage(), '23505')) fail('PO Number already exists', 409);
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── UPDATE ─────────────────────────────────────────────────────────────
    function actionUpdate(): void {
        global $currentUser, $body;
        $pdo = getPDO();

        $id = (int)($body['id'] ?? 0);
        if (!$id) fail('Missing PO id');

        $po = fetchPo($pdo, $id);
        if (!$po)                      fail('Purchase order not found', 404);
        if ($po['status'] !== 'Draft') fail('Only Draft POs can be edited', 403);

        $v = validatePoPayload($body);
        if ($v !== true) fail('Validation failed', 422, $v);

        $status = in_array($body['status'] ?? '', ['Draft','Sent'], true) ? $body['status'] : $po['status'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE psm_purchase_orders SET
                    pr_reference     = :pr_reference,
                    supplier_name    = :supplier_name,
                    supplier_category= :supplier_category,
                    issued_by        = :issued_by,
                    date_issued      = :date_issued,
                    delivery_date    = :delivery_date,
                    payment_terms    = :payment_terms,
                    remarks          = :remarks,
                    status           = :status,
                    total_amount     = :total_amount,
                    updated_at       = NOW()
                WHERE id = :id
            ")->execute([
                ':pr_reference'      => strtoupper(trim($body['pr_reference'])),
                ':supplier_name'     => trim($body['supplier_name']),
                ':supplier_category' => trim($body['supplier_category'] ?? 'General'),
                ':issued_by'         => trim($body['issued_by']),
                ':date_issued'       => $body['date_issued'],
                ':delivery_date'     => $body['delivery_date'] ?? null,
                ':payment_terms'     => trim($body['payment_terms'] ?? 'Net 30'),
                ':remarks'           => trim($body['remarks'] ?? ''),
                ':status'            => $status,
                ':total_amount'      => computeTotal($body['items']),
                ':id'                => $id,
            ]);

            $pdo->prepare("DELETE FROM psm_po_items WHERE po_id = :id")->execute([':id' => $id]);
            insertItems($pdo, $id, $body['items']);

            insertPoAudit($pdo, $id, "PO Edited — status: $status",
                          $currentUser['full_name'] ?? trim($body['issued_by']),
                          'dot-b', isSuperAdmin($currentUser));

            $pdo->commit();
            ok(['id' => $id], 'PO updated');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── SEND ───────────────────────────────────────────────────────────────
    // ── EMAIL HELPER ───────────────────────────────────────────────────────
    function sendPoEmail(string $to, string $toName, array $po, array $items,
                         string $coverNote, string $sentBy): bool {
        $poNum    = htmlspecialchars($po['po_number']);
        $supplier = htmlspecialchars($po['supplier_name']);
        $issued   = date('F j, Y', strtotime($po['date_issued']));
        $delivery = $po['delivery_date'] ? date('F j, Y', strtotime($po['delivery_date'])) : 'TBD';
        $terms    = htmlspecialchars($po['payment_terms'] ?? 'Net 30');
        $total    = '₱' . number_format((float)$po['total_amount'], 2);
        $note     = nl2br(htmlspecialchars($coverNote));
        $sentByH  = htmlspecialchars($sentBy);

        // Build line items rows
        $itemRows = '';
        $grandTotal = 0;
        foreach ($items as $i => $it) {
            $lineTotal   = (float)$it['quantity'] * (float)$it['unit_price'];
            $grandTotal += $lineTotal;
            $itemRows .= sprintf(
                '<tr>
                  <td style="padding:10px 14px;border-bottom:1px solid #E5E7EB;font-size:13px">%d</td>
                  <td style="padding:10px 14px;border-bottom:1px solid #E5E7EB;font-size:13px;font-weight:600">%s</td>
                  <td style="padding:10px 14px;border-bottom:1px solid #E5E7EB;font-size:13px;text-align:center">%s</td>
                  <td style="padding:10px 14px;border-bottom:1px solid #E5E7EB;font-size:13px;text-align:center">%s</td>
                  <td style="padding:10px 14px;border-bottom:1px solid #E5E7EB;font-size:13px;text-align:right;font-family:monospace">₱%s</td>
                  <td style="padding:10px 14px;border-bottom:1px solid #E5E7EB;font-size:13px;text-align:right;font-family:monospace;font-weight:700">₱%s</td>
                </tr>',
                $i + 1,
                htmlspecialchars($it['item_name']),
                htmlspecialchars($it['unit']),
                number_format((float)$it['quantity'], 2),
                number_format((float)$it['unit_price'], 2),
                number_format($lineTotal, 2)
            );
        }

        // Pre-compute conditional blocks (no expressions allowed inside heredoc)
        $grandTotalFmt = number_format($grandTotal, 2);
        $coverNoteBlock = $note
            ? '<div style="background:#F0FDF4;border-left:4px solid #2E7D32;border-radius:0 8px 8px 0;padding:14px 18px;margin-bottom:24px;font-size:13px;color:#374151;line-height:1.7">' . $note . '</div>'
            : '';
        $remarksBlock = !empty($po['remarks'])
            ? '<div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:12px 16px;font-size:13px;color:#92400E;margin-bottom:24px"><strong>Remarks:</strong> ' . htmlspecialchars($po['remarks']) . '</div>'
            : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F3F4F6;font-family:'Segoe UI',Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F6;padding:32px 0">
  <tr><td align="center">
    <table width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%">

      <!-- Header -->
      <tr><td style="background:#1B5E20;border-radius:12px 12px 0 0;padding:28px 32px">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td>
              <div style="font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#A5D6A7;margin-bottom:4px">Purchase Order</div>
              <div style="font-size:26px;font-weight:800;color:#ffffff;font-family:monospace">{$poNum}</div>
            </td>
            <td align="right">
              <div style="background:rgba(255,255,255,.15);border-radius:8px;padding:10px 16px;text-align:right">
                <div style="font-size:11px;color:#A5D6A7;margin-bottom:2px">Total Amount</div>
                <div style="font-size:22px;font-weight:800;color:#ffffff;font-family:monospace">{$total}</div>
              </div>
            </td>
          </tr>
        </table>
      </td></tr>

      <!-- Body -->
      <tr><td style="background:#ffffff;padding:28px 32px">

        {$coverNoteBlock}

        <!-- PO Details grid -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px">
          <tr>
            <td width="50%" style="padding-bottom:14px">
              <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9CA3AF;margin-bottom:3px">Supplier</div>
              <div style="font-size:14px;font-weight:600;color:#111827">{$supplier}</div>
            </td>
            <td width="50%" style="padding-bottom:14px">
              <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9CA3AF;margin-bottom:3px">Date Issued</div>
              <div style="font-size:14px;font-weight:600;color:#111827">{$issued}</div>
            </td>
          </tr>
          <tr>
            <td width="50%" style="padding-bottom:14px">
              <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9CA3AF;margin-bottom:3px">Delivery Date</div>
              <div style="font-size:14px;font-weight:600;color:#111827">{$delivery}</div>
            </td>
            <td width="50%" style="padding-bottom:14px">
              <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9CA3AF;margin-bottom:3px">Payment Terms</div>
              <div style="font-size:14px;font-weight:600;color:#111827">{$terms}</div>
            </td>
          </tr>
        </table>

        <!-- Line items -->
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6B7280;margin-bottom:10px">Line Items</div>
        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #E5E7EB;border-radius:10px;overflow:hidden;margin-bottom:24px">
          <thead>
            <tr style="background:#F9FAFB">
              <th style="padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6B7280;text-align:left">#</th>
              <th style="padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6B7280;text-align:left">Item</th>
              <th style="padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6B7280;text-align:center">Unit</th>
              <th style="padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6B7280;text-align:center">Qty</th>
              <th style="padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6B7280;text-align:right">Unit Price</th>
              <th style="padding:10px 14px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6B7280;text-align:right">Total</th>
            </tr>
          </thead>
          <tbody>{$itemRows}</tbody>
          <tfoot>
            <tr style="background:#F0FDF4">
              <td colspan="5" style="padding:12px 14px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6B7280;text-align:right">Grand Total</td>
              <td style="padding:12px 14px;font-size:16px;font-weight:800;color:#1B5E20;font-family:monospace;text-align:right">&#8369;{$grandTotalFmt}</td>
            </tr>
          </tfoot>
        </table>

        {$remarksBlock}

        <!-- Sent by -->
        <div style="border-top:1px solid #E5E7EB;padding-top:18px;font-size:12px;color:#6B7280">
          This Purchase Order was issued by <strong style="color:#111827">{$sentByH}</strong>.<br>
          Please confirm receipt and provide your acknowledgement reference at your earliest convenience.
        </div>
      </td></tr>

      <!-- Footer -->
      <tr><td style="background:#F9FAFB;border:1px solid #E5E7EB;border-top:none;border-radius:0 0 12px 12px;padding:16px 32px;text-align:center">
        <div style="font-size:11px;color:#9CA3AF">This is an automated message from the PSM Procurement System. Do not reply to this email.</div>
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

        $subject  = "Purchase Order {$po['po_number']} — {$po['supplier_name']}";
        $toHeader = $toName ? "{$toName} <{$to}>" : $to;
        $headers  = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: PSM Procurement <no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>',
            'Reply-To: ' . ($sentBy ?: 'no-reply@localhost'),
            'X-Mailer: PSM/1.0',
        ]);

        // PHPMailer via composer autoload
        $root     = dirname(__DIR__, 2);
        $autoload = $root . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            error_log('PSM sendPoEmail: vendor/autoload.php not found — run: composer require phpmailer/phpmailer');
            return false;
        }
        require_once $autoload;

        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->SMTPDebug  = 0;
            $mailer->isSMTP();
            $mailer->Host       = 'smtp.gmail.com';
            $mailer->SMTPAuth   = true;
            $mailer->Username   = 'noreply.microfinancial@gmail.com';
            $mailer->Password   = 'dpjdwwlopkzdyfnk';
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->Port       = 587;

            $mailer->setFrom('noreply.microfinancial@gmail.com', 'MicroFinancial');
            $mailer->addAddress($to, $toName);

            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body    = $html;
            $mailer->AltBody = strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $html));

            $mailer->send();
            return true;
        } catch (\Throwable $e) {
            error_log('PSM sendPoEmail error: ' . $e->getMessage());
            return false;
        }
    }

    function actionSend(): void {
        global $currentUser, $body;
        $pdo = getPDO();
        $id = (int)($body['id'] ?? 0);
        if (!$id) fail('Missing PO id');
        $po = fetchPo($pdo, $id);
        if (!$po)                       fail('Purchase order not found', 404);
        if ($po['status'] !== 'Draft')  fail("Cannot send a PO with status: {$po['status']}", 422);
        $email     = trim($body['email']   ?? '');
        $msg       = trim($body['message'] ?? '');
        $emailSent = false;

        // Fetch line items for email
        $items = [];
        if ($email) {
            $s = $pdo->prepare("SELECT * FROM psm_po_items WHERE po_id = :id ORDER BY line_no");
            $s->execute([':id' => $id]);
            $items = $s->fetchAll();
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE psm_purchase_orders SET status='Sent', updated_at=NOW() WHERE id=:id")
                ->execute([':id' => $id]);

            // Send email if address provided
            if ($email) {
                $sentBy = $currentUser['full_name'] ?? $po['issued_by'];
                $emailSent = sendPoEmail($email, $po['supplier_name'], $po, $items, $msg, $sentBy);
            }

            $detail = $email ? " → {$email}" . ($emailSent ? ' ✓' : ' (email failed)') : '';
            insertPoAudit($pdo, $id, "PO Sent to {$po['supplier_name']}{$detail}",
                          $currentUser['full_name'] ?? $po['issued_by'], 'dot-b', false);

            if ($email) {
                try {
                    $pdo->prepare("INSERT INTO psm_po_send_log (po_id, recipient_email, message, sent_by)
                                   VALUES (:po_id, :email, :msg, :by)")
                        ->execute([':po_id' => $id, ':email' => $email, ':msg' => $msg,
                                   ':by' => $currentUser['full_name'] ?? $po['issued_by']]);
                } catch (\Throwable) {}
            }

            $pdo->commit();

            $successMsg = "PO {$po['po_number']} sent to {$po['supplier_name']}";
            if ($email && $emailSent)  $successMsg .= " — email delivered to {$email}";
            if ($email && !$emailSent) $successMsg .= " — status updated but email could not be delivered";

            ok(['email_sent' => $emailSent], $successMsg);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── CONFIRM ────────────────────────────────────────────────────────────
    function actionConfirm(): void {
        global $currentUser, $body;
        $pdo = getPDO();
        $id = (int)($body['id'] ?? 0);
        if (!$id) fail('Missing PO id');
        $po = fetchPo($pdo, $id);
        if (!$po)                     fail('Purchase order not found', 404);
        if ($po['status'] !== 'Sent') fail("Only Sent POs can be confirmed. Current: {$po['status']}", 422);
        $supplierRef  = trim($body['supplier_ref']  ?? '');
        $deliveryDate = trim($body['delivery_date'] ?? '');
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE psm_purchase_orders
                SET status='Confirmed', delivery_date=COALESCE(:delivery_date, delivery_date), updated_at=NOW()
                WHERE id=:id
            ")->execute([':delivery_date' => $deliveryDate ?: null, ':id' => $id]);
            $detail = $supplierRef ? " (Ref: $supplierRef)" : '';
            insertPoAudit($pdo, $id, "PO Confirmed by supplier$detail",
                          $currentUser['full_name'] ?? 'System', 'dot-b', false);
            $pdo->commit();
            ok(null, "PO {$po['po_number']} confirmed");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── CANCEL ─────────────────────────────────────────────────────────────
    function actionCancel(): void {
        global $currentUser, $body;
        $pdo = getPDO();
        $id     = (int)($body['id']    ?? 0);
        $reason = trim($body['reason'] ?? '');
        if (!$id)     fail('Missing PO id');
        if (!$reason) fail('Cancellation reason is required', 422);
        $po = fetchPo($pdo, $id);
        if (!$po) fail('Purchase order not found', 404);
        if (in_array($po['status'], ['Cancelled','Voided','Fulfilled'], true))
            fail("Cannot cancel a PO with status: {$po['status']}", 422);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE psm_purchase_orders
                SET status='Cancelled',
                    remarks=CONCAT(COALESCE(remarks,''), ' | Cancelled: ' || :reason),
                    updated_at=NOW()
                WHERE id=:id
            ")->execute([':reason' => $reason, ':id' => $id]);
            insertPoAudit($pdo, $id, "PO Cancelled — $reason",
                          $currentUser['full_name'] ?? $po['issued_by'], 'dot-r', isSuperAdmin($currentUser));
            $pdo->commit();
            ok(null, "PO {$po['po_number']} cancelled");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── VOID ───────────────────────────────────────────────────────────────
    function actionVoid(): void {
        global $currentUser, $body;
        requireSuperAdmin($currentUser);
        $pdo = getPDO();
        $id      = (int)($body['id']      ?? 0);
        $reason  = trim($body['reason']   ?? '');
        $authRef = trim($body['auth_ref'] ?? '');
        if (!$id)     fail('Missing PO id');
        if (!$reason) fail('Void reason is required', 422);
        $po = fetchPo($pdo, $id);
        if (!$po)                          fail('Purchase order not found', 404);
        if ($po['status'] !== 'Fulfilled') fail('Only Fulfilled POs can be voided', 422);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE psm_purchase_orders SET status='Voided', updated_at=NOW() WHERE id=:id")
                ->execute([':id' => $id]);
            $label = "PO Voided (SA) — $reason" . ($authRef ? " | Auth: $authRef" : '');
            insertPoAudit($pdo, $id, $label, $currentUser['email'] ?? 'superadmin', 'dot-o', true);
            $pdo->commit();
            ok(null, "PO {$po['po_number']} voided");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── REASSIGN ───────────────────────────────────────────────────────────
    function actionReassign(): void {
        global $currentUser, $body;
        requireSuperAdmin($currentUser);
        $pdo = getPDO();
        $id     = (int)($body['id']    ?? 0);
        $toUser = trim($body['to']     ?? '');
        $reason = trim($body['reason'] ?? '');
        if (!$id)     fail('Missing PO id');
        if (!$toUser) fail('Target officer is required', 422);
        $po = fetchPo($pdo, $id);
        if (!$po) fail('Purchase order not found', 404);
        if (in_array($po['status'], ['Cancelled','Voided'], true))
            fail("Cannot reassign a PO with status: {$po['status']}", 422);
        $from = $po['issued_by'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE psm_purchase_orders SET issued_by=:to, updated_at=NOW() WHERE id=:id")
                ->execute([':to' => $toUser, ':id' => $id]);
            $label = "PO Reassigned (SA): $from → $toUser" . ($reason ? " | $reason" : '');
            insertPoAudit($pdo, $id, $label, $currentUser['email'] ?? 'superadmin', 'dot-o', true);
            $pdo->commit();
            ok(null, "PO reassigned to $toUser");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── FULFILL ────────────────────────────────────────────────────────────
    function actionFulfill(): void {
        global $currentUser, $body;
        $pdo = getPDO();
        $id  = (int)($body['id']  ?? 0);
        $pct = (int)($body['pct'] ?? 0);
        if (!$id)                   fail('Missing PO id');
        if ($pct < 0 || $pct > 100) fail('Fulfillment % must be 0–100', 422);
        $po = fetchPo($pdo, $id);
        if (!$po) fail('Purchase order not found', 404);
        if (!in_array($po['status'], ['Confirmed','Partially Fulfilled','Fulfilled'], true))
            fail("PO must be Confirmed or in fulfillment to update. Current: {$po['status']}", 422);
        $newStatus = match(true) {
            $pct === 100 => 'Fulfilled',
            $pct > 0     => 'Partially Fulfilled',
            default      => $po['status'],
        };
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE psm_purchase_orders
                SET fulfill_pct=:pct, status=:status, updated_at=NOW()
                WHERE id=:id
            ")->execute([':pct' => $pct, ':status' => $newStatus, ':id' => $id]);
            $label = $pct === 100 ? 'PO Fulfilled — all items received' : "Partial fulfillment update — {$pct}%";
            $dot   = $pct === 100 ? 'dot-g' : 'dot-o';
            insertPoAudit($pdo, $id, $label, $currentUser['full_name'] ?? $po['issued_by'], $dot, false);
            $pdo->commit();
            ok(['status' => $newStatus, 'fulfill_pct' => $pct], $label);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fail('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ── AUDIT GLOBAL ───────────────────────────────────────────────────────
    function actionAuditGlobal(): void {
        $pdo    = getPDO();
        $limit  = min(200, max(1, (int)($_GET['limit']  ?? 50)));
        $offset = max(0,            (int)($_GET['offset'] ?? 0));
        $stmt = $pdo->prepare("
            SELECT al.id, al.po_id, po.po_number, al.action_label,
                   al.actor_name, al.dot_class, al.is_super_admin, al.ip_address, al.occurred_at
            FROM psm_po_audit_log al
            JOIN psm_purchase_orders po ON po.id = al.po_id
            ORDER BY al.occurred_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $total = (int)$pdo->query("SELECT COUNT(*) FROM psm_po_audit_log")->fetchColumn();
        ok(['rows' => $stmt->fetchAll(), 'total' => $total]);
    }

    // ── EXPORT CSV ─────────────────────────────────────────────────────────
    function actionExport(): void {
        $pdo  = getPDO();
        $rows = $pdo->query("
            SELECT po.po_number, po.pr_reference, po.supplier_name, po.supplier_category,
                   po.issued_by, po.date_issued, po.delivery_date,
                   po.payment_terms, po.status, po.fulfill_pct, po.total_amount, po.remarks,
                   (SELECT COUNT(*) FROM psm_po_items i WHERE i.po_id = po.id) AS item_count
            FROM psm_purchase_orders po
            ORDER BY po.created_at DESC
        ")->fetchAll();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="purchase_orders_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['PO Number','PR Reference','Supplier','Category','Issued By',
                       'Date Issued','Delivery Date','Payment Terms','Status','Fulfill %',
                       'Total Amount','Items','Remarks']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['po_number'], $r['pr_reference'], $r['supplier_name'], $r['supplier_category'],
                $r['issued_by'], $r['date_issued'], $r['delivery_date'] ?? '',
                $r['payment_terms'], $r['status'], $r['fulfill_pct'], $r['total_amount'],
                $r['item_count'], $r['remarks'],
            ]);
        }
        fclose($out);
        exit;
    }

    // ── SHARED HELPERS ─────────────────────────────────────────────────────
    /** @return array|false */
    function fetchPo(PDO $pdo, int $id) {
        $s = $pdo->prepare("SELECT * FROM psm_purchase_orders WHERE id = :id");
        $s->execute([':id' => $id]);
        return $s->fetch();
    }
    function insertItems(PDO $pdo, int $poId, array $items): void {
        $stmt = $pdo->prepare("
            INSERT INTO psm_po_items (po_id, line_no, item_name, unit, quantity, unit_price, line_total)
            VALUES (:po_id, :line_no, :item_name, :unit, :qty, :unit_price, :line_total)
        ");
        foreach ($items as $i => $it) {
            $qty   = (float)($it['quantity']   ?? $it['qty'] ?? 0);
            $price = (float)($it['unit_price'] ?? 0);
            $stmt->execute([
                ':po_id'      => $poId,
                ':line_no'    => $i + 1,
                ':item_name'  => trim($it['item_name'] ?? $it['desc'] ?? ''),
                ':unit'       => trim($it['unit'] ?? 'pcs'),
                ':qty'        => $qty,
                ':unit_price' => $price,
                ':line_total' => $qty * $price,
            ]);
        }
    }
    function insertPoAudit(PDO $pdo, int $poId, string $label, string $actor,
                           string $dotClass, bool $isSa): void {
        $pdo->prepare("
            INSERT INTO psm_po_audit_log (po_id, action_label, actor_name, dot_class, is_super_admin, ip_address)
            VALUES (:po_id, :label, :actor, :dot, :is_sa, :ip)
        ")->execute([
            ':po_id' => $poId, ':label' => $label, ':actor' => $actor,
            ':dot'   => $dotClass, ':is_sa' => $isSa ? 'true' : 'false',
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
    function computeTotal(array $items): float {
        return array_sum(array_map(
            fn($it) => (float)($it['quantity'] ?? $it['qty'] ?? 0) * (float)($it['unit_price'] ?? 0),
            $items
        ));
    }
    /** @return true|array */
    function validatePoPayload(array $b) {
        $errors = [];
        if (empty(trim($b['po_number']     ?? ''))) $errors['po_number']    = 'Required';
        if (empty(trim($b['pr_reference']  ?? ''))) $errors['pr_reference'] = 'Required';
        if (empty(trim($b['supplier_name'] ?? ''))) $errors['supplier_name']= 'Required';
        if (empty(trim($b['issued_by']     ?? ''))) $errors['issued_by']    = 'Required';
        if (empty($b['date_issued']))               $errors['date_issued']  = 'Required';
        if (empty($b['items']) || !is_array($b['items'])) {
            $errors['items'] = 'At least one line item is required';
        } else {
            $has = false;
            foreach ($b['items'] as $it)
                if (!empty(trim($it['item_name'] ?? $it['desc'] ?? ''))) { $has = true; break; }
            if (!$has) $errors['items'] = 'At least one named line item is required';
        }
        return $errors ?: true;
    }
    function isSuperAdmin(array $user): bool {
        return in_array('Super Admin', $user['roles'] ?? [], true);
    }
    function requireSuperAdmin(array $user): void {
        if (!isSuperAdmin($user)) fail('Super Admin access required', 403);
    }

    // ── LOOKUP SUPPLIER ────────────────────────────────────────────────────
    function actionLookupSupplier(): void {
        $pdo = getPDO();
        $q   = trim($_GET['q'] ?? '');
        $stmt = $pdo->prepare("
            SELECT
                s.id,
                s.name,
                s.category,
                s.contact_person,
                s.email,
                s.phone,
                s.status,
                s.accreditation,
                s.rating
            FROM psm_suppliers s
            WHERE s.status = 'Active'
              AND (
                s.name     ILIKE :q
                OR s.category ILIKE :q
                OR s.contact_person ILIKE :q
              )
            ORDER BY s.name ASC
            LIMIT 20
        ");
        $stmt->execute([':q' => '%' . $q . '%']);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['id']     = (int)$r['id'];
            $r['rating'] = (float)$r['rating'];
        }
        ok($rows);
    }

    // ── LOOKUP PR ──────────────────────────────────────────────────────────
    function actionLookupPr(): void {
        $pdo = getPDO();
        $q   = trim($_GET['q'] ?? '');
        $stmt = $pdo->prepare("
            SELECT
                pr.id,
                pr.pr_number,
                pr.requestor_name,
                pr.department,
                pr.date_needed,
                pr.status,
                pr.total_amount,
                pr.item_count,
                pr.purpose
            FROM psm_purchase_requests pr
            WHERE pr.status IN ('Approved','Pending Approval')
              AND (
                pr.pr_number      ILIKE :q
                OR pr.requestor_name ILIKE :q
                OR pr.department     ILIKE :q
              )
            ORDER BY pr.date_filed DESC
            LIMIT 15
        ");
        $stmt->execute([':q' => '%' . $q . '%']);
        $rows = $stmt->fetchAll();

        // Fetch line items for each PR
        $itemStmt = $pdo->prepare("
            SELECT line_no, item_name, specification, unit, quantity, unit_price
            FROM psm_pr_items
            WHERE pr_id = :pr_id
            ORDER BY line_no ASC
        ");

        foreach ($rows as &$r) {
            $r['id']           = (int)$r['id'];
            $r['total_amount'] = (float)$r['total_amount'];
            $r['item_count']   = (int)$r['item_count'];
            $itemStmt->execute([':pr_id' => $r['id']]);
            $items = $itemStmt->fetchAll();
            $r['items'] = array_map(function($it) {
                return [
                    'name' => $it['item_name'],
                    'spec' => $it['specification'] ?? '',
                    'unit' => $it['unit'],
                    'qty'  => (float)$it['quantity'],
                    'up'   => (float)$it['unit_price'],
                ];
            }, $items);
        }
        ok($rows);
    }

    // ── Router ─────────────────────────────────────────────────────────────
    try {
        match ($action) {
            'list'         => actionList(),
            'get'          => actionGet(),
            'create'       => actionCreate(),
            'update'       => actionUpdate(),
            'send'         => actionSend(),
            'confirm'      => actionConfirm(),
            'cancel'       => actionCancel(),
            'void'         => actionVoid(),
            'reassign'     => actionReassign(),
            'fulfill'      => actionFulfill(),
            'audit_global' => actionAuditGlobal(),
            'export'       => actionExport(),
            'lookup_pr'       => actionLookupPr(),
            'lookup_supplier' => actionLookupSupplier(),
            default           => fail('Unsupported API route', 404),
        };
    } catch (\Throwable $e) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'trace' => $e->getFile() . ':' . $e->getLine()]);
        exit;
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// PAGE
// ═══════════════════════════════════════════════════════════════════════════
$API_URL = '?';

include $root . '/includes/superadmin_sidebar.php';
include $root . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Orders — PSM</title>
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/base.css">
  <link rel="stylesheet" href="/css/sidebar.css">
  <link rel="stylesheet" href="/css/header.css">
  <style>
/* ── TOKENS ─────────────────────────────────────────────── */
#mainContent, #poSlider, #slOverlay, #actionModal, #viewModal, .po-toasts {
  --s:#fff; --bd:rgba(46,125,50,.13); --bdm:rgba(46,125,50,.26);
  --t1:var(--text-primary); --t2:var(--text-secondary); --t3:#9EB0A2;
  --hbg:var(--hover-bg-light); --bg:var(--bg-color);
  --grn:var(--primary-color); --gdk:var(--primary-dark);
  --red:#DC2626; --amb:#D97706; --blu:#2563EB; --tel:#0D9488;
  --pur:#7C3AED;
  --shmd:0 4px 20px rgba(46,125,50,.12); --shlg:0 24px 60px rgba(0,0,0,.22);
  --rad:12px; --tr:var(--transition);
}
#mainContent *, #poSlider *, #slOverlay *, #actionModal *, #viewModal *, .po-toasts * { box-sizing:border-box; }

/* ── PAGE ──────────────────────────────────────────────────── */
.po-wrap { max-width:1600px; margin:0 auto; padding:0 0 4rem; }
.po-ph { display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:26px; animation:UP .4s both; }
.po-ph .ey { font-size:11px; font-weight:600; letter-spacing:.14em; text-transform:uppercase; color:var(--grn); margin-bottom:4px; }
.po-ph h1  { font-size:26px; font-weight:800; color:var(--t1); line-height:1.15; }
.po-ph-r   { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

/* ── BUTTONS ─────────────────────────────────────────────── */
.btn { display:inline-flex; align-items:center; gap:7px; font-family:'Inter',sans-serif; font-size:13px; font-weight:600; padding:9px 18px; border-radius:10px; border:none; cursor:pointer; transition:var(--tr); white-space:nowrap; }
.btn-primary { background:var(--grn); color:#fff; box-shadow:0 2px 8px rgba(46,125,50,.32); }
.btn-primary:hover { background:var(--gdk); transform:translateY(-1px); }
.btn-ghost   { background:var(--s); color:var(--t2); border:1px solid var(--bdm); }
.btn-ghost:hover { background:var(--hbg); color:var(--t1); }
.btn-info    { background:var(--blu); color:#fff; }
.btn-info:hover { background:#1D4ED8; transform:translateY(-1px); }
.btn-warn    { background:var(--amb); color:#fff; }
.btn-warn:hover { background:#B45309; transform:translateY(-1px); }
.btn-danger  { background:var(--red); color:#fff; }
.btn-danger:hover { background:#B91C1C; transform:translateY(-1px); }
.btn-gold    { background:#B45309; color:#fff; }
.btn-gold:hover { background:#92400E; transform:translateY(-1px); }
.btn-purple  { background:var(--pur); color:#fff; }
.btn-purple:hover { background:#6D28D9; transform:translateY(-1px); }
.btn-green-soft { background:#DCFCE7; color:#166534; border:1px solid #BBF7D0; }
.btn-green-soft:hover { background:#BBF7D0; }
.btn-sm  { font-size:12px; padding:7px 14px; }
.btn-xs  { font-size:11px; padding:4px 9px; border-radius:7px; }
.btn.ionly { width:26px; height:26px; padding:0; justify-content:center; font-size:13px; flex-shrink:0; border-radius:6px; }
.btn:disabled { opacity:.45; pointer-events:none; }

/* ── STATS ─────────────────────────────────────────────────── */
.po-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:22px; animation:UP .4s .05s both; }
.sc { background:var(--s); border:1px solid var(--bd); border-radius:var(--rad); padding:14px 16px; box-shadow:0 1px 4px rgba(46,125,50,.07); display:flex; align-items:center; gap:12px; }
.sc-ic { width:38px; height:38px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:18px; }
.ic-b{background:#EFF6FF;color:var(--blu)} .ic-a{background:#FEF3C7;color:var(--amb)}
.ic-g{background:#DCFCE7;color:#166534}    .ic-r{background:#FEE2E2;color:var(--red)}
.ic-t{background:#CCFBF1;color:var(--tel)} .ic-gy{background:#F3F4F6;color:#6B7280}
.ic-gold{background:#FEF3C7;color:#B45309}
.sc-v { font-size:22px; font-weight:800; color:var(--t1); line-height:1; }
.sc-l { font-size:11px; color:var(--t2); margin-top:2px; }

/* ── TOOLBAR ─────────────────────────────────────────────── */
.po-tb { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:18px; animation:UP .4s .1s both; }
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
.amt-pill { display:flex; align-items:center; background:var(--s); border:1px solid var(--bdm); border-radius:10px; overflow:hidden; height:38px; }
.amt-pill .pill-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--t3); padding:0 9px; white-space:nowrap; background:var(--bg); border-right:1px solid var(--bdm); align-self:stretch; display:flex; align-items:center; }
.amt-pill input { font-family:'Inter',sans-serif; font-size:12px; border:none; outline:none; background:transparent; color:var(--t1); padding:0 8px; width:68px; }
.amt-pill .sep { font-size:11px; color:var(--t3); padding:0 1px; flex-shrink:0; }
.clear-btn { font-size:12px; font-weight:600; color:var(--t3); background:none; border:1px solid var(--bdm); cursor:pointer; padding:7px 11px; border-radius:9px; transition:var(--tr); white-space:nowrap; display:flex; align-items:center; gap:4px; flex-shrink:0; }
.clear-btn:hover { color:var(--red); background:#FEE2E2; border-color:#FECACA; }

/* ── BULK BAR ────────────────────────────────────────────── */
.bulk-bar { display:none; align-items:center; gap:10px; padding:10px 16px; background:linear-gradient(135deg,#F0FDF4,#DCFCE7); border:1px solid rgba(46,125,50,.22); border-radius:12px; margin-bottom:14px; flex-wrap:wrap; animation:UP .25s both; }
.bulk-bar.on { display:flex; }
.bulk-count { font-size:13px; font-weight:700; color:#166534; }
.bulk-sep { width:1px; height:22px; background:rgba(46,125,50,.25); }
.sa-exclusive { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; background:linear-gradient(135deg,#FEF3C7,#FDE68A); color:#92400E; border:1px solid #FCD34D; border-radius:6px; padding:2px 7px; }

/* ── TABLE ─────────────────────────────────────────────────── */
.po-card { background:var(--s); border:1px solid var(--bd); border-radius:16px; overflow:hidden; box-shadow:var(--shmd); animation:UP .4s .13s both; }
.po-tbl { width:100%; border-collapse:collapse; font-size:12.5px; table-layout:fixed; }
.po-tbl col.col-cb    { width:38px; }
.po-tbl col.col-po    { width:130px; }
.po-tbl col.col-pr    { width:110px; }
.po-tbl col.col-sup   { width:190px; }
.po-tbl col.col-items { width:60px; }
.po-tbl col.col-amt   { width:130px; }
.po-tbl col.col-by    { width:120px; }
.po-tbl col.col-date  { width:100px; }
.po-tbl col.col-stat  { width:150px; }
.po-tbl col.col-ful   { width:110px; }
.po-tbl col.col-act   { width:160px; }
.po-tbl thead th { font-size:10.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--t2); padding:10px 10px; text-align:left; background:var(--bg); border-bottom:1px solid var(--bd); white-space:nowrap; cursor:pointer; user-select:none; overflow:hidden; }
.po-tbl thead th.no-sort { cursor:default; }
.po-tbl thead th:hover:not(.no-sort) { color:var(--grn); }
.po-tbl thead th.sorted { color:var(--grn); }
.po-tbl thead th .sic { margin-left:3px; opacity:.4; font-size:12px; vertical-align:middle; }
.po-tbl thead th.sorted .sic { opacity:1; }
.po-tbl thead th:first-child,
.po-tbl tbody td:first-child { padding-left:12px; padding-right:4px; }
.po-tbl tbody tr { border-bottom:1px solid var(--bd); transition:background .13s; }
.po-tbl tbody tr:last-child { border-bottom:none; }
.po-tbl tbody tr:hover { background:var(--hbg); }
.po-tbl tbody tr.row-selected { background:#F0FDF4; }
.po-tbl tbody td { padding:12px 10px; vertical-align:middle; cursor:pointer; max-width:0; overflow:hidden; text-overflow:ellipsis; }
.po-tbl tbody td:first-child { cursor:default; }
.po-tbl tbody td:last-child { white-space:nowrap; cursor:default; overflow:visible; padding:10px 8px; max-width:none; }
.cb-wrap { display:flex; align-items:center; justify-content:center; }
input[type=checkbox].cb { width:15px; height:15px; accent-color:var(--grn); cursor:pointer; border-radius:4px; }
.po-num  { font-family:'DM Mono',monospace; font-size:11.5px; font-weight:600; color:var(--grn); }
.pr-ref  { font-family:'DM Mono',monospace; font-size:11px; color:var(--t2); white-space:nowrap; }
.sup-cell { display:flex; align-items:center; gap:8px; min-width:0; }
.sup-av   { width:28px; height:28px; border-radius:7px; font-size:10px; font-weight:700; color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.sup-name { font-weight:600; color:var(--t1); font-size:12.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sup-cat  { font-size:10px; color:var(--t3); }
.items-badge { display:inline-flex; align-items:center; gap:3px; font-size:11px; font-weight:600; background:#E8F5E9; color:var(--grn); padding:3px 8px; border-radius:20px; white-space:nowrap; }
.amt-val  { font-family:'DM Mono',monospace; font-weight:700; font-size:12px; color:var(--t1); white-space:nowrap; }
.by-name  { font-size:12px; font-weight:500; color:var(--t1); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.date-val { font-size:12px; color:var(--t2); white-space:nowrap; }
.fulfill-wrap { display:flex; align-items:center; gap:6px; }
.fulfill-bar  { flex:1; height:4px; background:var(--bd); border-radius:4px; overflow:hidden; min-width:44px; }
.fulfill-fill { height:100%; border-radius:4px; background:var(--grn); transition:width .4s; }
.fulfill-fill.warn { background:var(--amb); }
.fulfill-pct  { font-size:11px; font-weight:700; color:var(--t2); white-space:nowrap; }
.act-cell { display:flex; gap:4px; align-items:center; flex-wrap:nowrap; }

/* ── BADGES ─────────────────────────────────────────────── */
.badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; white-space:nowrap; }
.badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }
.b-draft      { background:#F3F4F6; color:#6B7280; }
.b-sent       { background:#EFF6FF; color:var(--blu); }
.b-confirmed  { background:#D1FAE5; color:#065F46; }
.b-partial    { background:#FEF3C7; color:#92400E; }
.b-fulfilled  { background:#DCFCE7; color:#166534; }
.b-cancelled  { background:#FEE2E2; color:#991B1B; }
.b-voided     { background:#F3F4F6; color:#9CA3AF; text-decoration:line-through; }

/* ── PAGINATION ─────────────────────────────────────────── */
.po-pager { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:14px 20px; border-top:1px solid var(--bd); background:var(--bg); font-size:13px; color:var(--t2); }
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
  width:820px; max-width:100%; max-height:92vh;
  display:flex; flex-direction:column;
  box-shadow:0 20px 60px rgba(0,0,0,.22);
  overflow:hidden;
}
.vm-hd { padding:24px 28px 0; border-bottom:1px solid rgba(46,125,50,.14); background:var(--bg-color); flex-shrink:0; }
.vm-top { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:16px; }
.vm-si  { display:flex; align-items:center; gap:16px; }
.vm-av  { width:56px; height:56px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:20px; color:#fff; flex-shrink:0; }
.vm-nm  { font-size:20px; font-weight:800; color:var(--text-primary); font-family:'DM Mono',monospace; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.vm-id  { font-size:12px; color:var(--text-secondary); margin-top:4px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.vm-cl  { width:36px; height:36px; border-radius:8px; border:1px solid rgba(46,125,50,.22); background:#fff; cursor:pointer; display:grid; place-content:center; font-size:20px; color:var(--text-secondary); transition:all .15s; flex-shrink:0; }
.vm-cl:hover { background:#FEE2E2; color:#DC2626; border-color:#FECACA; }
.vm-chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
.vm-chip { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--text-secondary); background:#fff; border:1px solid rgba(46,125,50,.14); border-radius:8px; padding:5px 10px; }
.vm-chip i { font-size:14px; color:var(--primary-color); }
.vm-tabs { display:flex; gap:4px; }
.vm-tab { font-family:'Inter',sans-serif; font-size:13px; font-weight:600; padding:8px 16px; border-radius:8px 8px 0 0; cursor:pointer; transition:all .15s; color:var(--text-secondary); border:none; background:transparent; display:flex; align-items:center; gap:6px; white-space:nowrap; }
.vm-tab:hover { background:var(--hover-bg-light); color:var(--text-primary); }
.vm-tab.active { background:var(--primary-color); color:#fff; }
.vm-bd { flex:1; overflow-y:auto; padding:24px 28px; background:#fff; }
.vm-bd::-webkit-scrollbar { width:4px; }
.vm-bd::-webkit-scrollbar-thumb { background:rgba(46,125,50,.22); border-radius:4px; }
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
.vm-rmk { border-radius:10px; padding:12px 16px; font-size:12.5px; line-height:1.65; background:#FFFBEB; color:#92400E; }
.vm-rmk .rml { font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; margin-bottom:4px; opacity:.7; }
.sa-note { display:flex; align-items:flex-start; gap:8px; background:#FFFBEB; border:1px solid #FCD34D; border-radius:10px; padding:10px 14px; font-size:12px; color:#92400E; }
.sa-note i { font-size:15px; flex-shrink:0; margin-top:1px; }
.vm-txnt { width:100%; border-collapse:collapse; font-size:13px; }
.vm-txnt thead th { font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--text-secondary); padding:10px 12px; text-align:left; background:var(--bg-color); border-bottom:1px solid rgba(46,125,50,.14); }
.vm-txnt thead th:last-child { text-align:right; }
.vm-txnt tbody tr { border-bottom:1px solid rgba(46,125,50,.14); transition:background .12s; }
.vm-txnt tbody tr:last-child { border-bottom:none; }
.vm-txnt tbody tr:hover { background:var(--hover-bg-light); }
.vm-txnt tbody td { padding:11px 12px; }
.vm-txnt tbody td:last-child { text-align:right; }
.vm-txnt tfoot td { padding:11px 12px; font-weight:700; border-top:2px solid rgba(46,125,50,.14); background:var(--bg-color); }
.vm-txnt tfoot td:last-child { text-align:right; color:var(--primary-color); font-family:'DM Mono',monospace; font-size:14px; }
.vm-ta { font-family:'DM Mono',monospace; font-size:12px; font-weight:600; color:var(--primary-color); }
.li-name { font-weight:600; color:var(--text-primary); }
.vm-audit-item { display:flex; gap:12px; padding:12px 0; border-bottom:1px solid rgba(46,125,50,.14); }
.vm-audit-item:last-child { border-bottom:none; padding-bottom:0; }
.vm-audit-dot { width:28px; height:28px; border-radius:7px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:13px; }
.dot-g-ic{background:#DCFCE7;color:#166534} .dot-b-ic{background:#EFF6FF;color:var(--blu)}
.dot-o-ic{background:#FEF3C7;color:var(--amb)} .dot-r-ic{background:#FEE2E2;color:var(--red)}
.dot-gy-ic{background:#F3F4F6;color:#6B7280}
.vm-audit-body { flex:1; min-width:0; }
.vm-audit-body .au { font-size:13px; font-weight:500; color:var(--text-primary); }
.vm-audit-body .at { font-size:11px; color:#9EB0A2; margin-top:3px; font-family:'DM Mono',monospace; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.vm-audit-note { font-size:11.5px; color:#6B7280; margin-top:3px; font-style:italic; }
.vm-audit-ip { font-family:'DM Mono',monospace; font-size:10px; color:#9CA3AF; background:#F3F4F6; border-radius:4px; padding:1px 6px; }
.vm-audit-ts { font-family:'DM Mono',monospace; font-size:10px; color:#9EB0A2; flex-shrink:0; margin-left:auto; padding-left:8px; white-space:nowrap; }
.sa-tag { font-size:10px; font-weight:700; background:#FEF3C7; color:#92400E; border-radius:4px; padding:1px 5px; border:1px solid #FCD34D; }
.vm-ft { padding:16px 28px; border-top:1px solid rgba(46,125,50,.14); background:var(--bg-color); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; flex-wrap:wrap; }
/* Approval chain in view modal */
.approval-chain { display:flex; flex-direction:column; gap:0; }
.approval-step { display:flex; gap:14px; position:relative; }
.approval-step:not(:last-child)::before { content:''; position:absolute; left:15px; top:36px; bottom:0; width:2px; background:var(--bd); }
.appr-dot { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; z-index:1; }
.dot-done    { background:#E8F5E9; color:var(--grn); border:2px solid var(--grn); }
.dot-current { background:var(--grn); color:#fff; border:2px solid var(--grn); }
.dot-pending { background:var(--bg); color:var(--t3); border:2px solid var(--bdm); }
.appr-info   { padding:4px 0 20px; }
.appr-role   { font-size:13px; font-weight:600; color:var(--t1); }
.appr-by     { font-size:12px; color:var(--t2); margin-top:2px; }
.appr-ts     { font-size:11px; color:var(--t3); font-family:'DM Mono',monospace; margin-top:2px; }

/* ── SLIDE-OVER ─────────────────────────────────────────── */
#slOverlay { position:fixed; inset:0; background:rgba(0,0,0,.52); z-index:9000; opacity:0; pointer-events:none; transition:opacity .25s; }
#slOverlay.on { opacity:1; pointer-events:all; }
#poSlider { position:fixed; top:0; right:-620px; bottom:0; width:580px; max-width:100vw; background:var(--s); z-index:9001; transition:right .3s cubic-bezier(.4,0,.2,1); display:flex; flex-direction:column; overflow:hidden; box-shadow:-4px 0 40px rgba(0,0,0,.18); }
#poSlider.on { right:0; }
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
.fi, .fs, .fta { font-family:'Inter',sans-serif; font-size:13px; padding:10px 12px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; transition:var(--tr); width:100%; }
.fi:focus, .fs:focus, .fta:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.11); }
.fs { appearance:none; cursor:pointer; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; padding-right:30px; }
.fta { resize:vertical; min-height:70px; }
.fd { font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--t3); display:flex; align-items:center; gap:10px; }
.fd::after { content:''; flex:1; height:1px; background:var(--bd); }
.li-rows  { display:flex; flex-direction:column; gap:10px; }
.li-row   { background:var(--bg); border:1px solid var(--bd); border-radius:10px; padding:13px 14px 13px; display:flex; flex-direction:column; gap:9px; position:relative; }
.li-rr1   { display:grid; grid-template-columns:2fr 1fr 1fr 1fr; gap:10px; }
.li-rm    { position:absolute; top:10px; right:10px; width:24px; height:24px; border-radius:6px; border:1px solid #FECACA; background:#FEE2E2; cursor:pointer; display:grid; place-content:center; font-size:14px; color:var(--red); transition:var(--tr); }
.li-rm:hover { background:#FCA5A5; }
.li-sub   { font-family:'DM Mono',monospace; font-size:12px; font-weight:700; color:var(--grn); text-align:right; padding:6px 0 0; border-top:1px dashed var(--bd); }
.add-li   { display:flex; align-items:center; justify-content:center; gap:7px; padding:10px; border:1.5px dashed var(--bdm); border-radius:10px; cursor:pointer; font-size:13px; font-weight:600; color:var(--t3); background:transparent; transition:var(--tr); font-family:'Inter',sans-serif; width:100%; }
.add-li:hover { border-color:var(--grn); color:var(--grn); background:#F0FAF0; }
.total-row { display:flex; align-items:center; justify-content:space-between; background:#E8F5E9; border:1px solid rgba(46,125,50,.2); border-radius:10px; padding:12px 16px; font-size:13px; font-weight:700; color:var(--grn); }
.total-row span:last-child { font-size:17px; font-family:'DM Mono',monospace; }
.po-num-wrap { position:relative; }
.po-num-wrap .auto-hint { position:absolute; right:10px; top:50%; transform:translateY(-50%); font-size:11px; font-weight:600; color:var(--grn); background:#E8F5E9; border:1px solid rgba(46,125,50,.2); border-radius:5px; padding:2px 7px; cursor:pointer; transition:var(--tr); }
.po-num-wrap .auto-hint:hover { background:#C8E6C9; }

/* ── ACTION MODAL ─────────────────────────────────────────── */
#actionModal { position:fixed; inset:0; background:rgba(0,0,0,.52); z-index:9100; display:grid; place-content:center; opacity:0; pointer-events:none; transition:opacity .2s; padding:20px; }
#actionModal.on { opacity:1; pointer-events:all; }
.am-box   { background:var(--s); border-radius:16px; padding:28px 28px 24px; width:440px; max-width:100%; box-shadow:var(--shlg); }
.am-icon  { font-size:44px; margin-bottom:10px; line-height:1; }
.am-title { font-size:18px; font-weight:700; color:var(--t1); margin-bottom:6px; }
.am-body  { font-size:13px; color:var(--t2); line-height:1.6; margin-bottom:16px; }
.am-fg    { display:flex; flex-direction:column; gap:5px; margin-bottom:18px; }
.am-fg label { font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--t2); }
.am-fg textarea { font-family:'Inter',sans-serif; font-size:13px; padding:10px 12px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; resize:vertical; min-height:72px; width:100%; transition:var(--tr); }
.am-fg textarea:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.11); }
.am-fg input { font-family:'Inter',sans-serif; font-size:13px; padding:10px 12px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; width:100%; transition:var(--tr); }
.am-fg input:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.11); }
.am-acts  { display:flex; gap:10px; justify-content:flex-end; }
.am-sa-note { display:flex; align-items:flex-start; gap:8px; background:#FFFBEB; border:1px solid #FCD34D; border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:12px; color:#92400E; }
.am-sa-note i { font-size:15px; flex-shrink:0; margin-top:1px; }
/* Fulfill slider inside modal */
.fulfill-slider-wrap { display:flex; flex-direction:column; gap:10px; margin-bottom:14px; }
.fulfill-slider-wrap input[type=range] { width:100%; accent-color:var(--grn); }
.fulfill-slider-val { font-family:'DM Mono',monospace; font-size:28px; font-weight:800; color:var(--grn); text-align:center; }
.fulfill-status-preview { text-align:center; font-size:12px; font-weight:600; color:var(--t2); }

/* ── TOAST ─────────────────────────────────────────────── */
.po-toasts { position:fixed; bottom:28px; right:28px; z-index:9999; display:flex; flex-direction:column; gap:10px; pointer-events:none; }
.toast { background:#0A1F0D; color:#fff; padding:12px 18px; border-radius:10px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:10px; box-shadow:var(--shlg); pointer-events:all; min-width:220px; animation:TIN .3s ease; }
.toast.ts { background:var(--grn); } .toast.tw { background:var(--amb); } .toast.td { background:var(--red); } .toast.ti { background:var(--blu); }
.toast.out { animation:TOUT .3s ease forwards; }

@keyframes UP   { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
@keyframes TIN  { from{opacity:0;transform:translateY(8px)}  to{opacity:1;transform:translateY(0)} }
@keyframes TOUT { from{opacity:1;transform:translateY(0)}    to{opacity:0;transform:translateY(8px)} }
@keyframes SHK  { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-5px)} 40%,80%{transform:translateX(5px)} }

@media(max-width:1200px) { .po-tbl col.col-pr, .po-tbl col.col-by { display:none; } }
@media(max-width:768px) {
  #poSlider { width:100vw; }
  .fr, .li-rr1 { grid-template-columns:1fr; }
  .po-stats { grid-template-columns:repeat(2,1fr); }
  .vm-sbs { grid-template-columns:repeat(2,1fr); }
  .vm-ig  { grid-template-columns:1fr; }
}
  </style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="po-wrap">

  <!-- PAGE HEADER -->
  <div class="po-ph">
    <div>
      <p class="ey">PSM · Procurement &amp; Sourcing Management</p>
      <h1>Purchase Orders</h1>
    </div>
    <div class="po-ph-r">
      <button class="btn btn-ghost" id="auditBtn"><i class='bx bx-history'></i> Audit Trail</button>
      <button class="btn btn-ghost" id="expBtn"><i class='bx bx-export'></i> Export CSV</button>
      <button class="btn btn-primary" id="genBtn"><i class='bx bx-plus'></i> Generate PO</button>
    </div>
  </div>

  <!-- STATS -->
  <div class="po-stats" id="statsBar"></div>

  <!-- TOOLBAR -->
  <div class="po-tb">
    <div class="sw">
      <i class='bx bx-search'></i>
      <input type="text" class="si" id="srch" placeholder="Search PO, PR ref, supplier, officer…">
    </div>
    <select class="sel" id="fStatus">
      <option value="">All Statuses</option>
      <option>Draft</option><option>Sent</option><option>Confirmed</option>
      <option>Partially Fulfilled</option><option>Fulfilled</option>
      <option>Cancelled</option><option>Voided</option>
    </select>
    <select class="sel" id="fSupplierF"><option value="">All Suppliers</option></select>
    <div class="date-range-wrap">
      <input type="date" class="fi-date" id="fDateFrom" title="Date From">
      <span>–</span>
      <input type="date" class="fi-date" id="fDateTo" title="Date To">
    </div>
    <div class="amt-pill">
      <span class="pill-lbl">₱ Amount</span>
      <input type="number" id="fAmtMin" placeholder="Min" min="0">
      <span class="sep">—</span>
      <input type="number" id="fAmtMax" placeholder="Max" min="0">
    </div>
    <button class="clear-btn" id="clearFilters"><i class='bx bx-x'></i> Clear</button>
  </div>

  <!-- BULK BAR -->
  <div class="bulk-bar" id="bulkBar">
    <span class="bulk-count" id="bulkCount">0 selected</span>
    <div class="bulk-sep"></div>
    <button class="btn btn-sm" style="background:#FEE2E2;color:var(--red);border:1px solid #FECACA" id="batchCancelBtn"><i class='bx bx-x-circle'></i> Batch Cancel</button>
    <button class="btn btn-info btn-sm" id="batchSendBtn"><i class='bx bx-send'></i> Batch Send</button>
    <button class="btn btn-ghost btn-sm" id="clearSelBtn"><i class='bx bx-x-circle'></i> Clear</button>
    <span class="sa-exclusive" style="margin-left:auto"><i class='bx bx-shield-quarter'></i> Super Admin Exclusive</span>
  </div>

  <!-- TABLE -->
  <div class="po-card">
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
      <table class="po-tbl" id="tbl">
        <colgroup>
          <col class="col-cb">
          <col class="col-po">
          <col class="col-pr">
          <col class="col-sup">
          <col class="col-items">
          <col class="col-amt">
          <col class="col-by">
          <col class="col-date">
          <col class="col-stat">
          <col class="col-ful">
          <col class="col-act">
        </colgroup>
        <thead>
          <tr>
            <th class="no-sort"><div class="cb-wrap"><input type="checkbox" class="cb" id="checkAll" title="Select all"></div></th>
            <th data-col="po_number">PO Number <i class='bx bx-sort sic'></i></th>
            <th data-col="pr_reference">PR Ref <i class='bx bx-sort sic'></i></th>
            <th data-col="supplier_name">Supplier <i class='bx bx-sort sic'></i></th>
            <th data-col="item_count" class="no-sort">Items</th>
            <th data-col="total_amount">Total Amount <i class='bx bx-sort sic'></i></th>
            <th data-col="issued_by">Issued By <i class='bx bx-sort sic'></i></th>
            <th data-col="date_issued">Date <i class='bx bx-sort sic'></i></th>
            <th data-col="status">Status <i class='bx bx-sort sic'></i></th>
            <th class="no-sort">Fulfillment</th>
            <th class="no-sort">Actions</th>
          </tr>
        </thead>
        <tbody id="tbody"></tbody>
      </table>
    </div>
    <div class="po-pager" id="pager"></div>
  </div>

</div>
</main>

<!-- TOAST CONTAINER -->
<div class="po-toasts" id="toastWrap"></div>

<!-- ════════════════════════════════════════
     GENERATE / EDIT PO SLIDE-OVER
     ════════════════════════════════════════ -->
<div id="slOverlay">
<div id="poSlider">
  <div class="sl-hdr">
    <div>
      <div class="sl-title" id="slTitle">Generate Purchase Order</div>
      <div class="sl-subtitle" id="slSub">Fill in all required fields below</div>
    </div>
    <button class="sl-close" id="slClose"><i class='bx bx-x'></i></button>
  </div>
  <div class="sl-body">
    <!-- PO & PR Numbers -->
    <div class="fr">
      <div class="fg">
        <label class="fl">PO Number <span>*</span></label>
        <div class="po-num-wrap">
          <input type="text" class="fi" id="fPoNo" placeholder="e.g. PO-2025-0001" style="padding-right:70px">
          <span class="auto-hint" id="autoGenHint" title="Auto-generate PO number">Auto</span>
        </div>
      </div>
      <div class="fg" style="position:relative">
        <label class="fl">PR Reference <span>*</span></label>
        <div style="position:relative">
          <i class='bx bx-search' style="position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:16px;color:var(--t3);pointer-events:none;z-index:1"></i>
          <input type="text" class="fi" id="fPrRef" placeholder="Search PR number, requestor…"
                 autocomplete="off" spellcheck="false" style="padding-left:34px">
        </div>
        <div id="prSuggest" style="
          display:none;position:absolute;left:0;right:0;top:100%;z-index:9999;
          background:#fff;border:1px solid rgba(46,125,50,.26);border-radius:10px;
          box-shadow:0 8px 24px rgba(0,0,0,.14);overflow:hidden;
          max-height:280px;overflow-y:auto;margin-top:2px;
        "></div>
      </div>
    </div>
    <!-- Supplier search spanning full width -->
    <div class="fg" style="position:relative">
      <label class="fl">Supplier <span>*</span></label>
      <div style="position:relative">
        <i class='bx bx-search' style="position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:16px;color:var(--t3);pointer-events:none;z-index:1"></i>
        <input type="text" class="fi" id="fSupplier" placeholder="Search supplier name or category…"
               autocomplete="off" spellcheck="false" style="padding-left:34px">
      </div>
      <input type="hidden" id="fSupplierEmail">
      <div id="supSuggest" style="
        display:none;position:absolute;left:0;right:0;top:100%;z-index:9999;
        background:#fff;border:1px solid rgba(46,125,50,.26);border-radius:10px;
        box-shadow:0 8px 24px rgba(0,0,0,.14);overflow:hidden;
        max-height:300px;overflow-y:auto;margin-top:2px;
      "></div>
    </div>
    <!-- Category auto-filled, editable -->
    <div class="fr">
      <div class="fg">
        <label class="fl">Supplier Category</label>
        <input type="text" class="fi" id="fSupCat" placeholder="Auto-filled from supplier" readonly
               style="background:var(--bg);color:var(--t2)">
      </div>
      <div class="fg">
        <label class="fl">Contact Person</label>
        <input type="text" class="fi" id="fSupContact" placeholder="Auto-filled from supplier" readonly
               style="background:var(--bg);color:var(--t2)">
      </div>
    </div>
    <div class="fr">

      <div class="fg">
        <label class="fl">Issued By <span>*</span></label>
        <input type="text" class="fi" id="fIssuedBy" placeholder="Officer name">
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Date Issued</label>
        <input type="date" class="fi" id="fDateIssued">
      </div>
      <div class="fg">
        <label class="fl">Delivery Date</label>
        <input type="date" class="fi" id="fDeliveryDate">
      </div>
    </div>
    <div class="fr">
      <div class="fg">
        <label class="fl">Payment Terms</label>
        <select class="fs" id="fPayTerms">
          <option>Net 30</option><option>Net 15</option><option>Net 60</option>
          <option>Upon Delivery</option><option>50% DP, 50% Upon Delivery</option>
        </select>
      </div>
      <div class="fg">
        <label class="fl">Status</label>
        <select class="fs" id="fStatusSl">
          <option value="Draft">Save as Draft</option>
          <option value="Sent">Submit &amp; Send to Supplier</option>
        </select>
      </div>
    </div>
    <div class="fg">
      <label class="fl">Remarks / Notes</label>
      <textarea class="fta" id="fRemarks" placeholder="Special instructions, delivery notes…"></textarea>
    </div>

    <div class="fd">Line Items</div>
    <div class="li-rows" id="liRows"></div>
    <button class="add-li" id="addLiBtn" type="button"><i class='bx bx-plus'></i> Add Line Item</button>
    <div class="total-row">
      <span>Grand Total</span>
      <span id="grandTotal">₱0.00</span>
    </div>
  </div>
  <div class="sl-foot">
    <button class="btn btn-ghost btn-sm" id="slCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="slSubmit"><i class='bx bx-send'></i> Submit PO</button>
  </div>
</div>
</div>

<!-- ════════════════════════════════════════
     VIEW PO MODAL
     ════════════════════════════════════════ -->
<div id="viewModal">
  <div class="vm-box">
    <div class="vm-hd">
      <div class="vm-top">
        <div class="vm-si">
          <div class="vm-av" id="vmAvatar" style="background:var(--grn)"><i class='bx bx-file' style="font-size:24px"></i></div>
          <div>
            <div class="vm-nm" id="vmName">—</div>
            <div class="vm-id" id="vmId">—</div>
          </div>
        </div>
        <button class="vm-cl" id="vmClose"><i class='bx bx-x'></i></button>
      </div>
      <div class="vm-chips" id="vmChips"></div>
      <div class="vm-tabs">
        <button class="vm-tab active" data-t="ov"><i class='bx bx-grid-alt'></i> Overview</button>
        <button class="vm-tab" data-t="li"><i class='bx bx-list-ul'></i> Line Items</button>
        <button class="vm-tab" data-t="ap"><i class='bx bx-git-branch'></i> Approvals</button>
        <button class="vm-tab" data-t="au"><i class='bx bx-shield-quarter'></i> Audit Trail</button>
      </div>
    </div>
    <div class="vm-bd" id="vmBody">
      <div class="vm-tp active" id="vt-ov"></div>
      <div class="vm-tp"        id="vt-li"></div>
      <div class="vm-tp"        id="vt-ap"></div>
      <div class="vm-tp"        id="vt-au"></div>
    </div>
    <div class="vm-ft" id="vmFoot"></div>
  </div>
</div>

<!-- ════════════════════════════════════════
     ACTION CONFIRM MODAL (generic)
     ════════════════════════════════════════ -->
<div id="actionModal">
  <div class="am-box">
    <div class="am-icon" id="amIcon">✅</div>
    <div class="am-title" id="amTitle">Confirm Action</div>
    <div class="am-body" id="amBody"></div>
    <div class="am-sa-note" id="amSaNote" style="display:none">
      <i class='bx bx-shield-quarter'></i>
      <span id="amSaText"></span>
    </div>
    <div id="amFields"></div>
    <div class="am-fg">
      <label id="amRmkLabel">Remarks / Notes</label>
      <textarea id="amRemarks" placeholder="Add remarks…"></textarea>
    </div>
    <div class="am-acts">
      <button class="btn btn-ghost btn-sm" id="amCancel">Cancel</button>
      <button class="btn btn-sm" id="amConfirm">Confirm</button>
    </div>
  </div>
</div>

<script>

const API_URL = '<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>';

/* ── Helpers ──────────────────────────────────────────────────────── */
const COLS = ['#2E7D32','#1B5E20','#388E3C','#0D9488','#2563EB','#7C3AED','#D97706','#DC2626','#0891B2','#059669'];
const gc   = n => { let h=0; for(const c of String(n)) h=(h*31+c.charCodeAt(0))%COLS.length; return COLS[h]; };
const ini  = n => String(n||'').split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
const esc  = s => String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fD   = d => { if(!d) return '—'; return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'}); };
const fM   = v => v!=null ? '₱'+Number(v).toLocaleString('en-PH',{minimumFractionDigits:2}) : '—';
const today = () => new Date().toISOString().split('T')[0];

/* ── Badge ─────────────────────────────────────────────────────────── */
function badge(s) {
  const m = {
    'Draft':'b-draft','Sent':'b-sent','Confirmed':'b-confirmed',
    'Partially Fulfilled':'b-partial','Fulfilled':'b-fulfilled',
    'Cancelled':'b-cancelled','Voided':'b-voided'
  };
  return `<span class="badge ${m[s]||'b-draft'}">${esc(s)}</span>`;
}
function dotIconClass(dc) {
  const m = {'dot-g':'dot-g-ic','dot-b':'dot-b-ic','dot-o':'dot-o-ic','dot-r':'dot-r-ic','dot-gy':'dot-gy-ic'};
  return m[dc] || 'dot-gy-ic';
}
function dotIcon(dc) {
  const m = {'dot-g':'bx-check-circle','dot-b':'bx-send','dot-o':'bx-shield-quarter','dot-r':'bx-x-circle','dot-gy':'bx-time'};
  return m[dc] || 'bx-time';
}

/* ── API wrapper ─────────────────────────────────────────────────── */
async function api(action, params={}, body=null) {
  const url = new URL(API_URL, location.origin);
  url.searchParams.set('action', action);
  for(const [k,v] of Object.entries(params))
    if(v!==''&&v!==null&&v!==undefined) url.searchParams.set(k,v);
  const opts = { headers:{'Content-Type':'application/json'} };
  if(body) { opts.method='POST'; opts.body=JSON.stringify(body); }
  const res  = await fetch(url, opts);
  const json = await res.json();
  if(!json.success) throw new Error(json.message ?? 'API error');
  return json.data;
}

/* ── State ──────────────────────────────────────────────────────── */
let rows = [], totalRows = 0, lastPage = 1;
let pg = 1, PP = 15;
let sortCol = 'created_at', sortDir = 'desc';
let selectedIds = new Set();
let editId = null, lineItems = [];
let actionTarget = null, actionKey = null;

/* ── Action predicates ──────────────────────────────────────────── */
const canEdit    = p => p.status === 'Draft';
const canSend    = p => p.status === 'Draft';
const canConfirm = p => p.status === 'Sent';
const canFulfill = p => ['Confirmed','Partially Fulfilled'].includes(p.status);
const canCancel  = p => !['Cancelled','Voided','Fulfilled'].includes(p.status);
const canVoid    = p => p.status === 'Fulfilled';

/* ── Filters ─────────────────────────────────────────────────────── */
function getFilters() {
  return {
    search:   document.getElementById('srch').value.trim(),
    status:   document.getElementById('fStatus').value,
    supplier: document.getElementById('fSupplierF').value,
    date_from:document.getElementById('fDateFrom').value,
    date_to:  document.getElementById('fDateTo').value,
    amt_min:  document.getElementById('fAmtMin').value,
    amt_max:  document.getElementById('fAmtMax').value,
    page: pg, per_page: PP
  };
}

/* ── Main render ─────────────────────────────────────────────────── */
async function render() {
  try {
    const data = await api('list', getFilters());
    rows      = data.rows     || [];
    totalRows = data.total    || 0;
    lastPage  = data.last_page|| 1;
    rStats(data.stats);
    rDropdowns(data.filters);
    rTable();
  } catch(e) { toast(e.message,'d'); }
}

function rStats(s) {
  document.getElementById('statsBar').innerHTML = `
    <div class="sc"><div class="sc-ic ic-b"><i class='bx bx-file'></i></div><div><div class="sc-v">${s.total|0}</div><div class="sc-l">Total POs</div></div></div>
    <div class="sc"><div class="sc-ic ic-gy"><i class='bx bx-pencil'></i></div><div><div class="sc-v">${s.draft|0}</div><div class="sc-l">Draft</div></div></div>
    <div class="sc"><div class="sc-ic ic-b"><i class='bx bx-send'></i></div><div><div class="sc-v">${s.sent|0}</div><div class="sc-l">Sent</div></div></div>
    <div class="sc"><div class="sc-ic ic-g"><i class='bx bx-check-double'></i></div><div><div class="sc-v">${s.confirmed|0}</div><div class="sc-l">Confirmed</div></div></div>
    <div class="sc"><div class="sc-ic ic-a"><i class='bx bx-package'></i></div><div><div class="sc-v">${s.partial|0}</div><div class="sc-l">Partial</div></div></div>
    <div class="sc"><div class="sc-ic ic-g"><i class='bx bx-check-circle'></i></div><div><div class="sc-v">${s.fulfilled|0}</div><div class="sc-l">Fulfilled</div></div></div>
    <div class="sc"><div class="sc-ic ic-r"><i class='bx bx-x-circle'></i></div><div><div class="sc-v">${s.cancelled_voided|0}</div><div class="sc-l">Cancelled/Voided</div></div></div>
    <div class="sc"><div class="sc-ic ic-t"><i class='bx bx-money-withdraw'></i></div><div><div class="sc-v" style="font-size:13px">${fM(s.active_value)}</div><div class="sc-l">Active Value</div></div></div>`;
}

function rDropdowns({suppliers=[]}) {

  const sEl = document.getElementById('fSupplierF'), sv = sEl.value;
  sEl.innerHTML = '<option value="">All Suppliers</option>' +
    suppliers.map(s=>`<option ${s===sv?'selected':''}>${esc(s)}</option>`).join('');
}

function rTable() {
  // Sort headers
  document.querySelectorAll('#tbl thead th[data-col]').forEach(th => {
    const c = th.dataset.col;
    th.classList.toggle('sorted', c === sortCol);
    const ic = th.querySelector('.sic');
    if(ic) ic.className = `bx ${c===sortCol?(sortDir==='asc'?'bx-sort-up':'bx-sort-down'):'bx-sort'} sic`;
  });

  const tb = document.getElementById('tbody');
  if(!rows.length) {
    tb.innerHTML = `<tr><td colspan="11"><div class="empty"><i class='bx bx-file'></i><p>No purchase orders match your filters.</p></div></td></tr>`;
    document.getElementById('pager').innerHTML = '';
    return;
  }

  tb.innerHTML = rows.map(p => {
    const pct = p.fulfill_pct, fillWarn = pct > 0 && pct < 100;
    const clr = gc(p.supplier_name);
    const chk = selectedIds.has(String(p.id));
    return `<tr class="${chk?'row-selected':''}">
      <td onclick="event.stopPropagation()">
        <div class="cb-wrap"><input type="checkbox" class="cb row-cb" data-id="${p.id}" ${chk?'checked':''}></div>
      </td>
      <td onclick="openView(${p.id})">
        <div class="po-num">${esc(p.po_number)}</div>
      </td>
      <td onclick="openView(${p.id})"><span class="pr-ref">${esc(p.pr_reference)}</span></td>
      <td onclick="openView(${p.id})">
        <div class="sup-cell">
          <div class="sup-av" style="background:${clr}">${ini(p.supplier_name)}</div>
          <div>
            <div class="sup-name">${esc(p.supplier_name)}</div>
            <div class="sup-cat">${esc(p.supplier_category)}</div>
          </div>
        </div>
      </td>
      <td onclick="openView(${p.id})"><span class="items-badge"><i class='bx bx-list-ul' style="font-size:12px"></i>${p.item_count}</span></td>
      <td onclick="openView(${p.id})"><span class="amt-val">${fM(p.total_amount)}</span></td>
      <td onclick="openView(${p.id})"><span class="by-name">${esc(p.issued_by)}</span></td>
      <td onclick="openView(${p.id})"><span class="date-val">${fD(p.date_issued)}</span></td>
      <td onclick="openView(${p.id})">${badge(p.status)}</td>
      <td onclick="openView(${p.id})">
        <div class="fulfill-wrap">
          <div class="fulfill-bar"><div class="fulfill-fill${fillWarn?' warn':''}" style="width:${pct}%"></div></div>
          <div class="fulfill-pct">${pct}%</div>
        </div>
      </td>
      <td onclick="event.stopPropagation()">
        <div class="act-cell">
          <button class="btn btn-ghost ionly" onclick="openView(${p.id})" title="View"><i class='bx bx-show'></i></button>
          ${canEdit(p)    ? `<button class="btn btn-ghost ionly" onclick="openEdit(${p.id})" title="Edit"><i class='bx bx-edit'></i></button>` : ''}
          ${canSend(p)    ? `<button class="btn btn-ghost ionly" title="Send" onclick="promptAct(${p.id},'send')"><i class='bx bx-send'></i></button>` : ''}
          ${canConfirm(p) ? `<button class="btn btn-ghost ionly" title="Confirm" onclick="promptAct(${p.id},'confirm')"><i class='bx bx-check-double'></i></button>` : ''}
          ${canFulfill(p) ? `<button class="btn btn-ghost ionly" title="Update Fulfillment" onclick="promptAct(${p.id},'fulfill')"><i class='bx bx-package'></i></button>` : ''}
          ${canCancel(p)  ? `<button class="btn btn-ghost ionly" title="Cancel" onclick="promptAct(${p.id},'cancel')" style="color:var(--red)"><i class='bx bx-x-circle'></i></button>` : ''}
          ${canVoid(p)    ? `<button class="btn btn-ghost ionly" title="Void (SA)" onclick="promptAct(${p.id},'void')" style="color:var(--amb)"><i class='bx bx-shield-quarter'></i></button>` : ''}
          <button class="btn btn-ghost ionly" title="Reassign (SA)" onclick="promptAct(${p.id},'reassign')" style="color:var(--pur)"><i class='bx bx-transfer'></i></button>
        </div>
      </td>
    </tr>`;
  }).join('');

  // Row checkbox events
  document.querySelectorAll('.row-cb').forEach(cb => {
    cb.addEventListener('change', function() {
      const id = this.dataset.id;
      if(this.checked) selectedIds.add(id); else selectedIds.delete(id);
      this.closest('tr').classList.toggle('row-selected', this.checked);
      updateBulkBar(); syncCheckAll();
    });
  });

  syncCheckAll();
  rPager();
}

function syncCheckAll() {
  const ca = document.getElementById('checkAll');
  const pageIds = rows.map(p => String(p.id));
  const allChecked = pageIds.length > 0 && pageIds.every(id => selectedIds.has(id));
  const someChecked = pageIds.some(id => selectedIds.has(id));
  ca.checked = allChecked;
  ca.indeterminate = !allChecked && someChecked;
}

function rPager() {
  const s = (pg-1)*PP+1, e = Math.min(pg*PP, totalRows);
  let btns = '';
  for(let i=1; i<=lastPage; i++) {
    if(i===1||i===lastPage||(i>=pg-2&&i<=pg+2)) btns+=`<button class="pgb ${i===pg?'active':''}" onclick="goPage(${i})">${i}</button>`;
    else if(i===pg-3||i===pg+3) btns+=`<button class="pgb" disabled>…</button>`;
  }
  document.getElementById('pager').innerHTML = `
    <span>${totalRows===0?'No results':`Showing ${s}–${e} of ${totalRows} purchase orders`}</span>
    <div class="pg-btns">
      <button class="pgb" onclick="goPage(${pg-1})" ${pg<=1?'disabled':''}><i class='bx bx-chevron-left'></i></button>
      ${btns}
      <button class="pgb" onclick="goPage(${pg+1})" ${pg>=lastPage?'disabled':''}><i class='bx bx-chevron-right'></i></button>
    </div>`;
}
window.goPage = p => { pg=p; render(); };

/* ── Sort headers ───────────────────────────────────────────────── */
document.querySelectorAll('#tbl thead th[data-col]').forEach(th => {
  th.addEventListener('click', () => {
    const c = th.dataset.col;
    sortDir = sortCol===c ? (sortDir==='asc'?'desc':'asc') : 'desc';
    sortCol = c; pg = 1; render();
  });
});

/* ── Filter events ──────────────────────────────────────────────── */
['srch','fStatus','fSupplierF','fDateFrom','fDateTo','fAmtMin','fAmtMax'].forEach(id =>
  document.getElementById(id).addEventListener('input', () => { pg=1; render(); })
);
document.getElementById('clearFilters').addEventListener('click', () => {
  ['srch','fDateFrom','fDateTo','fAmtMin','fAmtMax'].forEach(id => document.getElementById(id).value='');
  ['fStatus','fSupplierF'].forEach(id => document.getElementById(id).selectedIndex=0);
  pg=1; render();
});

/* ── Check All ─────────────────────────────────────────────────── */
document.getElementById('checkAll').addEventListener('change', function() {
  rows.forEach(p => { if(this.checked) selectedIds.add(String(p.id)); else selectedIds.delete(String(p.id)); });
  rTable(); updateBulkBar();
});

/* ── Bulk Bar ──────────────────────────────────────────────────── */
function updateBulkBar() {
  const n = selectedIds.size;
  document.getElementById('bulkBar').classList.toggle('on', n > 0);
  document.getElementById('bulkCount').textContent = n===1 ? '1 selected' : `${n} selected`;
}
document.getElementById('clearSelBtn').addEventListener('click', () => { selectedIds.clear(); rTable(); updateBulkBar(); });

document.getElementById('batchCancelBtn').addEventListener('click', () => {
  const cancellable = [...selectedIds].filter(id => {
    const p = rows.find(r=>String(r.id)===id);
    return p && canCancel(p);
  });
  if(!cancellable.length) return toast('No cancellable POs in selection','w');
  window._batchIds = cancellable;
  actionKey = 'batch-cancel'; actionTarget = null;
  showActionModal('⛔',`Batch Cancel ${cancellable.length} PO(s)`,
    `Cancel <strong>${cancellable.length}</strong> PO(s). This will notify suppliers.`,
    true,'Super Admin batch cancel.',
    'btn-danger','<i class="bx bx-x-circle"></i> Batch Cancel',
    [{id:'amRemarks',label:'Cancellation Reason',type:'textarea',required:true}]);
});
document.getElementById('batchSendBtn').addEventListener('click', () => {
  const sendable = [...selectedIds].filter(id => {
    const p = rows.find(r=>String(r.id)===id);
    return p && canSend(p);
  });
  if(!sendable.length) return toast('No Draft POs in selection','w');
  window._batchIds = sendable;
  actionKey = 'batch-send'; actionTarget = null;
  showActionModal('📤',`Batch Send ${sendable.length} PO(s)`,
    `Mark <strong>${sendable.length}</strong> Draft PO(s) as Sent.`,
    false,'','btn-info','<i class="bx bx-send"></i> Batch Send', []);
});

/* ── VIEW MODAL ─────────────────────────────────────────────────── */
window.openView = async id => {
  try {
    const p = await api('get', {id});
    renderDetail(p);
    setVmTab('ov');
    document.getElementById('viewModal').classList.add('on');
  } catch(e) { toast(e.message,'d'); }
};

function closeView() { document.getElementById('viewModal').classList.remove('on'); }
document.getElementById('vmClose').addEventListener('click', closeView);
document.getElementById('viewModal').addEventListener('click', function(e) { if(e.target===this) closeView(); });
document.querySelectorAll('.vm-tab').forEach(t => t.addEventListener('click', () => setVmTab(t.dataset.t)));
function setVmTab(name) {
  document.querySelectorAll('.vm-tab').forEach(t => t.classList.toggle('active', t.dataset.t===name));
  document.querySelectorAll('.vm-tp').forEach(p => p.classList.toggle('active', p.id==='vt-'+name));
}

function renderDetail(p) {
  const clr = gc(p.supplier_name);

  // Header
  document.getElementById('vmAvatar').innerHTML  = `<span style="font-size:14px;font-weight:800">${ini(p.supplier_name)}</span>`;
  document.getElementById('vmAvatar').style.background = clr;
  document.getElementById('vmName').innerHTML    = esc(p.po_number);
  document.getElementById('vmId').innerHTML      =
    `${esc(p.supplier_name)} &nbsp;·&nbsp; ${badge(p.status)}`;
  document.getElementById('vmChips').innerHTML   = `
    <div class="vm-chip"><i class='bx bx-calendar'></i>Issued ${fD(p.date_issued)}</div>
    <div class="vm-chip"><i class='bx bx-alarm'></i>Delivery ${fD(p.delivery_date)}</div>
    <div class="vm-chip"><i class='bx bx-list-ul'></i>${(p.items||[]).length} Items</div>
    <div class="vm-chip"><i class='bx bx-money-withdraw'></i>${fM(p.total_amount)}</div>
    <div class="vm-chip"><i class='bx bx-package'></i>${p.fulfill_pct}% fulfilled</div>`;

  // Footer actions
  const ft = document.getElementById('vmFoot');
  const btns = [`<button class="btn btn-ghost btn-sm" onclick="closeView()">Close</button>`];
  if(canEdit(p))    btns.push(`<button class="btn btn-ghost btn-sm" onclick="closeView();openEdit(${p.id})"><i class='bx bx-edit'></i> Edit</button>`);
  if(canSend(p))    btns.push(`<button class="btn btn-info btn-sm" onclick="closeView();promptAct(${p.id},'send')"><i class='bx bx-send'></i> Send</button>`);
  if(canConfirm(p)) btns.push(`<button class="btn btn-green-soft btn-sm" onclick="closeView();promptAct(${p.id},'confirm')"><i class='bx bx-check-double'></i> Confirm</button>`);
  if(canFulfill(p)) btns.push(`<button class="btn btn-primary btn-sm" onclick="closeView();promptAct(${p.id},'fulfill')"><i class='bx bx-package'></i> Fulfillment</button>`);
  if(canCancel(p))  btns.push(`<button class="btn btn-danger btn-sm" onclick="closeView();promptAct(${p.id},'cancel')"><i class='bx bx-x-circle'></i> Cancel</button>`);
  if(canVoid(p))    btns.push(`<button class="btn btn-gold btn-sm" onclick="closeView();promptAct(${p.id},'void')"><i class='bx bx-shield-quarter'></i> Void</button>`);
  ft.innerHTML = btns.join('');

  // Overview tab
  document.getElementById('vt-ov').innerHTML = `
    <div class="vm-sbs">
      <div class="vm-sb"><div class="sbv">${(p.items||[]).length}</div><div class="sbl">Line Items</div></div>
      <div class="vm-sb"><div class="sbv mono">${fM(p.total_amount)}</div><div class="sbl">Total Amount</div></div>
      <div class="vm-sb"><div class="sbv">${p.fulfill_pct}%</div><div class="sbl">Fulfillment</div></div>
      <div class="vm-sb"><div class="sbv" style="font-size:13px">${esc(p.payment_terms||'—')}</div><div class="sbl">Payment Terms</div></div>
    </div>
    <div class="vm-ig">
      <div class="vm-ii"><label>PO Number</label><div class="v" style="font-family:'DM Mono',monospace">${esc(p.po_number)}</div></div>
      <div class="vm-ii"><label>PR Reference</label><div class="v" style="font-family:'DM Mono',monospace">${esc(p.pr_reference)}</div></div>
      <div class="vm-ii"><label>Supplier</label><div class="v">${esc(p.supplier_name)}</div></div>
      <div class="vm-ii"><label>Category</label><div class="v muted">${esc(p.supplier_category)}</div></div>
      <div class="vm-ii"><label>Issued By</label><div class="v">${esc(p.issued_by)}</div></div>
      <div class="vm-ii"><label>Date Issued</label><div class="v muted">${fD(p.date_issued)}</div></div>
      <div class="vm-ii"><label>Delivery Date</label><div class="v muted">${fD(p.delivery_date)}</div></div>
      ${p.remarks ? `<div class="vm-ii vm-full"><label>Remarks</label><div class="vm-rmk">${esc(p.remarks)}</div></div>` : ''}
    </div>`;

  // Line Items tab
  const items = p.items || [];
  document.getElementById('vt-li').innerHTML = `
    <table class="vm-txnt">
      <thead><tr>
        <th style="width:28px">#</th>
        <th>Item Name</th>
        <th style="text-align:right">Qty</th>
        <th>Unit</th>
        <th style="text-align:right">Unit Price</th>
        <th style="text-align:right">Line Total</th>
      </tr></thead>
      <tbody>
        ${items.map((it,i) => `<tr>
          <td style="color:#9CA3AF;font-size:11px;font-weight:600">${i+1}</td>
          <td><div class="li-name">${esc(it.item_name)}</div></td>
          <td style="text-align:right;font-weight:700">${Number(it.quantity).toLocaleString()}</td>
          <td style="font-size:12px;color:#6B7280">${esc(it.unit)}</td>
          <td class="vm-ta">${fM(it.unit_price)}</td>
          <td style="font-family:'DM Mono',monospace;font-size:12px;font-weight:700">${fM(it.line_total)}</td>
        </tr>`).join('')}
      </tbody>
      <tfoot><tr>
        <td colspan="5" style="text-align:right;color:#9CA3AF;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">Grand Total</td>
        <td>${fM(p.total_amount)}</td>
      </tr></tfoot>
    </table>`;

  // Approvals tab
  const approvals = p.approvals || [];
  document.getElementById('vt-ap').innerHTML = `
    <div class="sa-note"><i class='bx bx-shield-quarter'></i><span>Super Admin View — full approval chain visibility.</span></div>
    <div class="approval-chain">
      ${approvals.map((a,i) => {
        const allPrev = approvals.slice(0,i).every(x=>x.is_done);
        const dc = a.is_done ? 'dot-done' : allPrev ? 'dot-current' : 'dot-pending';
        return `<div class="approval-step">
          <div class="appr-dot ${dc}"><i class='bx ${a.is_done?"bx-check":"bx-time"}'></i></div>
          <div class="appr-info">
            <div class="appr-role">${esc(a.role_label)}</div>
            <div class="appr-by">${a.approved_by ? esc(a.approved_by) : '<span style="color:var(--t3);font-style:italic">Awaiting</span>'}</div>
            ${a.approved_at ? `<div class="appr-ts">${fD(a.approved_at)}</div>` : ''}
          </div>
        </div>`;
      }).join('')}
    </div>`;

  // Audit trail tab
  const audit = p.audit || [];
  document.getElementById('vt-au').innerHTML = `
    <div class="sa-note"><i class='bx bx-shield-quarter'></i><span>Immutable audit trail — Super Admin visible only. Timestamps and IPs are read-only.</span></div>
    <div>
      ${audit.map(a => `
        <div class="vm-audit-item">
          <div class="vm-audit-dot ${dotIconClass(a.dot_class)}"><i class='bx ${dotIcon(a.dot_class)}'></i></div>
          <div class="vm-audit-body">
            <div class="au">${esc(a.action_label)} ${a.is_super_admin?'<span class="sa-tag">Super Admin</span>':''}</div>
            <div class="at">
              <i class='bx bx-user' style="font-size:11px"></i>${esc(a.actor_name)}
              ${a.ip_address?`<span class="vm-audit-ip"><i class='bx bx-desktop' style="font-size:10px;margin-right:2px"></i>${esc(a.ip_address)}</span>`:''}
            </div>
          </div>
          <div class="vm-audit-ts">${esc(new Date(a.occurred_at).toLocaleString('en-PH'))}</div>
        </div>`).join('')}
    </div>`;
}

/* ── ACTION MODAL ────────────────────────────────────────────────── */
function showActionModal(icon, title, body, sa, saText, btnClass, btnLabel, extraFields=[]) {
  document.getElementById('amIcon').textContent  = icon;
  document.getElementById('amTitle').textContent = title;
  document.getElementById('amBody').innerHTML    = body;
  const saNote = document.getElementById('amSaNote');
  if(sa) { saNote.style.display='flex'; document.getElementById('amSaText').textContent=saText; }
  else   { saNote.style.display='none'; }
  // Extra fields
  const container = document.getElementById('amFields');
  container.innerHTML = extraFields.filter(f=>f.id!=='amRemarks').map(f => `
    <div class="am-fg">
      <label>${f.label}</label>
      ${f.type==='textarea'
        ? `<textarea id="${f.id}" placeholder="${f.placeholder||''}"></textarea>`
        : `<input type="${f.type||'text'}" id="${f.id}" placeholder="${f.placeholder||''}">`
      }
    </div>`).join('');
  document.getElementById('amRemarks').value = '';
  document.getElementById('amRmkLabel').textContent = 'Remarks / Notes';
  const cb = document.getElementById('amConfirm');
  cb.className = `btn btn-sm ${btnClass}`;
  cb.innerHTML = btnLabel;
  document.getElementById('actionModal').classList.add('on');
}

function promptAct(id, type) {
  const p = rows.find(r=>r.id===id); if(!p) return;
  actionTarget = id; actionKey = type;

  const poLabel = `PO <strong>${esc(p.po_number)}</strong> — ${esc(p.supplier_name)} · ${fM(p.total_amount)}`;

  const cfg = {
    send: {
      icon:'📤', title:`Send PO — ${p.po_number}`, body:poLabel,
      sa:false, saText:'',
      btn:'btn-info', label:'<i class="bx bx-send"></i> Send PO',
      fields:[
        {id:'amEmail', label:'Supplier Email (optional)', type:'email', placeholder:'supplier@example.com'},
      ],
      afterRender: () => {
        const email = document.getElementById('fSupplierEmail')?.value || '';
        const el = document.getElementById('amEmail');
        if(el && email) el.value = email;
      }
    },
    confirm: {
      icon:'✅', title:`Confirm PO — ${p.po_number}`, body:poLabel,
      sa:false, saText:'',
      btn:'btn-green-soft', label:'<i class="bx bx-check-double"></i> Confirm',
      fields:[
        {id:'amSupRef',    label:'Supplier Reference No.',     type:'text',  placeholder:'Supplier SO or acknowledgement ref'},
        {id:'amDelivery',  label:'Expected Delivery Date',     type:'date',  placeholder:''},
      ]
    },
    fulfill: {
      icon:'📦', title:`Update Fulfillment — ${p.po_number}`, body:poLabel,
      sa:false, saText:'',
      btn:'btn-primary', label:'<i class="bx bx-package"></i> Update',
      fields:[] // rendered specially below
    },
    cancel: {
      icon:'⛔', title:`Cancel PO — ${p.po_number}`, body:poLabel,
      sa:false, saText:'',
      btn:'btn-danger', label:'<i class="bx bx-x-circle"></i> Cancel PO',
      fields:[]
    },
    void: {
      icon:'🛑', title:`Void PO — ${p.po_number}`, body:poLabel,
      sa:true, saText:'Super Admin exclusive — voiding a Fulfilled PO is irreversible.',
      btn:'btn-gold', label:'<i class="bx bx-shield-quarter"></i> Apply Void',
      fields:[{id:'amAuthRef', label:'Authorization Reference', type:'text', placeholder:'e.g. GM Approval Memo ref'}]
    },
    reassign: {
      icon:'🔄', title:`Reassign PO — ${p.po_number}`, body:`Current officer: <strong>${esc(p.issued_by)}</strong>`,
      sa:true, saText:'Super Admin exclusive — transfer PO ownership.',
      btn:'btn-purple', label:'<i class="bx bx-transfer"></i> Reassign',
      fields:[
        {id:'amReassignTo', label:'Reassign To *', type:'text', placeholder:'Target officer name'},
      ]
    },
  };

  const c = cfg[type];
  showActionModal(c.icon, c.title, c.body, c.sa, c.saText, c.btn, c.label, c.fields||[]);
  if (c.afterRender) setTimeout(c.afterRender, 30);

  // Special: fulfillment slider
  if(type === 'fulfill') {
    document.getElementById('amFields').innerHTML = `
      <div class="fulfill-slider-wrap">
        <div class="fulfill-slider-val" id="fulfillVal">${p.fulfill_pct}%</div>
        <input type="range" min="0" max="100" step="5" value="${p.fulfill_pct}" id="fulfillRange"
               oninput="
                 document.getElementById('fulfillVal').textContent=this.value+'%';
                 const st=+this.value===100?'Fulfilled':+this.value>0?'Partially Fulfilled':'—';
                 document.getElementById('fulfillStatPrev').textContent='→ Status: '+st;
               ">
        <div class="fulfill-status-preview" id="fulfillStatPrev">→ Status: ${p.fulfill_pct===100?'Fulfilled':p.fulfill_pct>0?'Partially Fulfilled':'—'}</div>
      </div>`;
    document.getElementById('amRmkLabel').textContent = 'Notes';
  }
  // Special: confirm — pre-fill delivery date
  if(type === 'confirm') {
    setTimeout(() => {
      const dEl = document.getElementById('amDelivery');
      if(dEl && p.delivery_date) dEl.value = p.delivery_date;
    }, 50);
  }
}

document.getElementById('amConfirm').addEventListener('click', async () => {
  const rmk   = document.getElementById('amRemarks').value.trim();
  const btn   = document.getElementById('amConfirm');
  const orig  = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Please wait…';

  try {
    // Batch actions
    if(actionKey === 'batch-cancel') {
      if(!rmk) { toast('Cancellation reason is required','w'); btn.disabled=false; btn.innerHTML=orig; return; }
      const ids = window._batchIds || [];
      await Promise.all(ids.map(id => api('cancel',{},{id:+id,reason:rmk}).catch(()=>null)));
      selectedIds.clear();
      document.getElementById('actionModal').classList.remove('on');
      toast(`${ids.length} PO(s) cancelled`,'w');
      await render(); updateBulkBar();
      return;
    }
    if(actionKey === 'batch-send') {
      const ids = window._batchIds || [];
      await Promise.all(ids.map(id => api('send',{},{id:+id,email:'',message:rmk}).catch(()=>null)));
      selectedIds.clear();
      document.getElementById('actionModal').classList.remove('on');
      toast(`${ids.length} PO(s) sent`,'i');
      await render(); updateBulkBar();
      return;
    }

    // Single actions
    let result;
    if(actionKey === 'send') {
      const email = document.getElementById('amEmail')?.value.trim() || '';
      result = await api('send',{},{id:actionTarget,email,message:rmk});
      if (result && result.email_sent === true) {
        toast(email ? `PO sent — email delivered to ${email}` : 'PO status updated','s');
      } else if (result && result.email_sent === false && email) {
        toast('PO status updated but email could not be delivered. Check SMTP config.','w');
      } else {
        toast('PO sent to supplier','i');
      }
    } else if(actionKey === 'confirm') {
      const supRef   = document.getElementById('amSupRef')?.value.trim() || '';
      const delivery = document.getElementById('amDelivery')?.value || '';
      result = await api('confirm',{},{id:actionTarget,supplier_ref:supRef,delivery_date:delivery,notes:rmk});
      toast('PO confirmed','s');
    } else if(actionKey === 'fulfill') {
      const pct = +(document.getElementById('fulfillRange')?.value || 0);
      result = await api('fulfill',{},{id:actionTarget,pct,notes:rmk});
      toast(`Fulfillment updated to ${pct}%`,'s');
    } else if(actionKey === 'cancel') {
      if(!rmk) { toast('Cancellation reason is required','w'); btn.disabled=false; btn.innerHTML=orig; return; }
      result = await api('cancel',{},{id:actionTarget,reason:rmk});
      toast('PO cancelled','w');
    } else if(actionKey === 'void') {
      if(!rmk) { toast('Void reason is required','w'); btn.disabled=false; btn.innerHTML=orig; return; }
      const authRef = document.getElementById('amAuthRef')?.value.trim() || '';
      result = await api('void',{},{id:actionTarget,reason:rmk,auth_ref:authRef});
      toast('PO voided','w');
    } else if(actionKey === 'reassign') {
      const to = document.getElementById('amReassignTo')?.value.trim() || '';
      if(!to) { toast('Target officer is required','w'); btn.disabled=false; btn.innerHTML=orig; return; }
      result = await api('reassign',{},{id:actionTarget,to,reason:rmk});
      toast(`PO reassigned to ${to}`,'s');
    }

    document.getElementById('actionModal').classList.remove('on');
    await render();
  } catch(e) {
    toast(e.message,'d');
  } finally {
    btn.disabled = false; btn.innerHTML = orig;
  }
});

document.getElementById('amCancel').addEventListener('click', () => document.getElementById('actionModal').classList.remove('on'));
document.getElementById('actionModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('on'); });

/* ── SLIDE-OVER ──────────────────────────────────────────────────── */
window.openEdit = async id => {
  try {
    const p = await api('get', {id});
    editId = id;
    document.getElementById('slTitle').textContent = `Edit PO — ${p.po_number}`;
    document.getElementById('slSub').textContent   = `${p.supplier_name}`;
    document.getElementById('fPoNo').value         = p.po_number;
    document.getElementById('fPoNo').readOnly      = true; // PO number locked on edit
    document.getElementById('autoGenHint').style.display = 'none';
    document.getElementById('fPrRef').value        = p.pr_reference;
    document.getElementById('fSupplier').value     = p.supplier_name;
    document.getElementById('fSupCat').value       = p.supplier_category || '';
    document.getElementById('fSupContact').value   = '';
    document.getElementById('fSupplierEmail').value = '';
    document.getElementById('fIssuedBy').value     = p.issued_by;
    document.getElementById('fDateIssued').value   = p.date_issued;
    document.getElementById('fDeliveryDate').value = p.delivery_date || '';
    document.getElementById('fPayTerms').value     = p.payment_terms;
    document.getElementById('fStatusSl').value     = p.status === 'Draft' ? 'Draft' : 'Sent';
    document.getElementById('fRemarks').value      = p.remarks || '';
    lineItems = (p.items||[]).map(it => ({_id:Date.now()+Math.random(), name:it.item_name, spec:'', unit:it.unit, qty:it.quantity, up:it.unit_price}));
    renderLineItems();
    openSlider();
  } catch(e) { toast(e.message,'d'); }
};

document.getElementById('genBtn').addEventListener('click', () => {
  editId = null;
  document.getElementById('slTitle').textContent = 'Generate Purchase Order';
  document.getElementById('slSub').textContent   = 'Fill in all required fields below';
  ['fPrRef','fIssuedBy','fRemarks'].forEach(id => document.getElementById(id).value='');
  document.getElementById('fPoNo').value         = '';
  document.getElementById('fPoNo').readOnly      = false;
  document.getElementById('autoGenHint').style.display = '';
  document.getElementById('fSupplier').value     = '';
  document.getElementById('fSupCat').value       = '';
  document.getElementById('fSupContact').value   = '';
  document.getElementById('fSupplierEmail').value = '';
  document.getElementById('fDateIssued').value   = today();
  document.getElementById('fDeliveryDate').value = '';
  document.getElementById('fPayTerms').selectedIndex = 0;
  document.getElementById('fStatusSl').value     = 'Draft';
  lineItems = [{_id:Date.now(), name:'', unit:'pcs', qty:1, up:0}];
  renderLineItems();
  openSlider();
});

// Auto-generate PO number
document.getElementById('autoGenHint').addEventListener('click', () => {
  const y = new Date().getFullYear();
  const rand = String(Math.floor(Math.random()*9000)+1000);
  document.getElementById('fPoNo').value = `PO-${y}-${rand}`;
});

/* ── PR REFERENCE AUTOCOMPLETE ───────────────────────────────────────── */
(function () {
  const fPrRef   = document.getElementById('fPrRef');
  const suggest  = document.getElementById('prSuggest');
  let _debounce  = null;
  let _selected  = null;

  function hideSuggest() {
    suggest.style.display = 'none';
    suggest.innerHTML = '';
  }

  function showSuggestions(rows) {
    if (!rows.length) {
      suggest.innerHTML = '<div style="padding:12px 14px;font-size:12px;color:var(--t3)">No approved PRs found</div>';
      suggest.style.display = 'block';
      return;
    }
    suggest.innerHTML = rows.map(r => `
      <div class="pr-sug-item" data-pr='${JSON.stringify(r).replace(/'/g,"&#39;")}' style="
        padding:10px 14px;cursor:pointer;
        border-bottom:1px solid rgba(46,125,50,.1);
        transition:background .12s;
      " onmouseenter="this.style.background='#F0FDF4'"
         onmouseleave="this.style.background=''">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
          <span style="font-family:'DM Mono',monospace;font-size:12px;font-weight:700;color:var(--grn)">${r.pr_number}</span>
          <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;background:${r.status==='Approved'?'#DCFCE7':'#FEF3C7'};color:${r.status==='Approved'?'#166534':'#92400E'}">${r.status}</span>
        </div>
        <div style="font-size:12px;font-weight:600;color:var(--t1);margin-top:2px">${r.requestor_name}</div>
        <div style="display:flex;align-items:center;gap:10px;margin-top:2px">
          <span style="font-size:11px;color:var(--t3)">${r.department}</span>
          <span style="font-size:11px;color:var(--t3)">·</span>
          <span style="font-size:11px;color:var(--t2);font-family:'DM Mono',monospace">${'₱'+Number(r.total_amount).toLocaleString('en-PH',{minimumFractionDigits:2})}</span>
          <span style="font-size:11px;color:var(--t3)">·</span>
          <span style="font-size:11px;color:var(--t3)">${r.item_count} item${r.item_count!==1?'s':''}</span>
        </div>
        ${r.purpose ? `<div style="font-size:11px;color:var(--t3);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${r.purpose}</div>` : ''}
      </div>`).join('');

    suggest.querySelectorAll('.pr-sug-item').forEach(item => {
      item.addEventListener('mousedown', e => {
        e.preventDefault();
        const r = JSON.parse(item.dataset.pr);
        _selected = r;
        fPrRef.value = r.pr_number;
        hideSuggest();
        fPrRef.focus();

        // Auto-fill line items from PR
        if (r.items && r.items.length > 0) {
          // Only auto-fill if items are currently empty or all blank
          const hasContent = lineItems.some(l => l.name.trim() !== '');
          if (!hasContent) {
            lineItems = r.items.map(it => ({
              _id: Date.now() + Math.random(),
              name: it.name,
              spec: it.spec || '',
              unit: it.unit || 'pcs',
              qty:  it.qty  || 1,
              up:   it.up   || 0,
            }));
            renderLineItems();
            toast(`${r.items.length} line item${r.items.length!==1?'s':''} imported from ${r.pr_number}`, 's');
          } else {
            // Ask via a subtle notice
            if (confirm(`Replace current line items with ${r.items.length} item(s) from ${r.pr_number}?`)) {
              lineItems = r.items.map(it => ({
                _id: Date.now() + Math.random(),
                name: it.name,
                spec: it.spec || '',
                unit: it.unit || 'pcs',
                qty:  it.qty  || 1,
                up:   it.up   || 0,
              }));
              renderLineItems();
              toast(`${r.items.length} line item${r.items.length!==1?'s':''} imported from ${r.pr_number}`, 's');
            }
          }
        }
      });
    });
    suggest.style.display = 'block';
  }

  async function fetchPrs(q) {
    try {
      const data = await api('lookup_pr', {q});
      showSuggestions(Array.isArray(data) ? data : []);
    } catch(e) {
      hideSuggest();
    }
  }

  fPrRef.addEventListener('focus', () => {
    if (fPrRef.value.trim().length === 0) fetchPrs('');
  });

  fPrRef.addEventListener('input', () => {
    clearTimeout(_debounce);
    _selected = null;
    const q = fPrRef.value.trim();
    _debounce = setTimeout(() => fetchPrs(q), 220);
  });

  fPrRef.addEventListener('blur', () => {
    setTimeout(hideSuggest, 180);
  });

  fPrRef.addEventListener('keydown', e => {
    if (e.key === 'Escape') hideSuggest();
  });
})();

/* ── SUPPLIER AUTOCOMPLETE ───────────────────────────────────────────────── */
(function () {
  const fSup     = document.getElementById('fSupplier');
  const suggest  = document.getElementById('supSuggest');
  let _debounce  = null;

  const RATINGS = ['','⭐','⭐⭐','⭐⭐⭐','⭐⭐⭐⭐','⭐⭐⭐⭐⭐'];
  const ACCR_COLOR = {Accredited:'#166534', Pending:'#92400E', Blacklisted:'#991B1B'};

  function hideSuggest() {
    suggest.style.display = 'none';
    suggest.innerHTML = '';
  }

  function showSuggestions(rows) {
    if (!rows.length) {
      suggest.innerHTML = '<div style="padding:12px 14px;font-size:12px;color:var(--t3)">No active suppliers found</div>';
      suggest.style.display = 'block';
      return;
    }
    suggest.innerHTML = rows.map(r => {
      const stars = r.rating >= 1 ? '★'.repeat(Math.round(r.rating)) : '';
      const accrColor = ACCR_COLOR[r.accreditation] || '#6B7280';
      return `<div class="sup-sug-item" data-sup='${JSON.stringify(r).replace(/'/g,"&#39;")}' style="
        padding:10px 14px;cursor:pointer;
        border-bottom:1px solid rgba(46,125,50,.1);
        transition:background .12s;
      " onmouseenter="this.style.background='#F0FDF4'"
         onmouseleave="this.style.background=''">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
          <span style="font-size:13px;font-weight:700;color:var(--t1)">${r.name}</span>
          <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;
                       background:${accrColor}22;color:${accrColor};white-space:nowrap">
            ${r.accreditation}
          </span>
        </div>
        <div style="display:flex;align-items:center;gap:10px;margin-top:3px;flex-wrap:wrap">
          <span style="font-size:11px;color:var(--t3);background:var(--bg);border-radius:5px;padding:1px 7px">${r.category||'General'}</span>
          ${r.contact_person ? `<span style="font-size:11px;color:var(--t2)"><i class='bx bx-user' style="font-size:11px;vertical-align:middle"></i> ${r.contact_person}</span>` : ''}
          ${r.email ? `<span style="font-size:11px;color:var(--t3)"><i class='bx bx-envelope' style="font-size:11px;vertical-align:middle"></i> ${r.email}</span>` : ''}
          ${r.rating ? `<span style="font-size:11px;color:#D97706">${stars} ${r.rating}</span>` : ''}
        </div>
      </div>`;
    }).join('');

    suggest.querySelectorAll('.sup-sug-item').forEach(item => {
      item.addEventListener('mousedown', e => {
        e.preventDefault();
        const r = JSON.parse(item.dataset.sup);
        fSup.value = r.name;
        document.getElementById('fSupCat').value      = r.category    || '';
        document.getElementById('fSupContact').value  = r.contact_person || '';
        document.getElementById('fSupplierEmail').value = r.email     || '';
        hideSuggest();
        fSup.focus();
      });
    });
    suggest.style.display = 'block';
  }

  async function fetchSuppliers(q) {
    try {
      const data = await api('lookup_supplier', {q});
      showSuggestions(Array.isArray(data) ? data : []);
    } catch(e) { hideSuggest(); }
  }

  fSup.addEventListener('focus', () => {
    if (fSup.value.trim().length === 0) fetchSuppliers('');
  });
  fSup.addEventListener('input', () => {
    clearTimeout(_debounce);
    const q = fSup.value.trim();
    _debounce = setTimeout(() => fetchSuppliers(q), 220);
  });
  fSup.addEventListener('blur', () => { setTimeout(hideSuggest, 180); });
  fSup.addEventListener('keydown', e => { if(e.key==='Escape') hideSuggest(); });
})();

function openSlider() {
  document.getElementById('poSlider').classList.add('on');
  document.getElementById('slOverlay').classList.add('on');
}
function closeSlider() {
  document.getElementById('poSlider').classList.remove('on');
  document.getElementById('slOverlay').classList.remove('on');
  editId = null;
}
document.getElementById('slOverlay').addEventListener('click', function(e){ if(e.target===this) closeSlider(); });
document.getElementById('slClose').addEventListener('click', closeSlider);
document.getElementById('slCancel').addEventListener('click', closeSlider);

/* ── Line items ─────────────────────────────────────────────────── */
document.getElementById('addLiBtn').addEventListener('click', () => {
  lineItems.push({_id:Date.now(), name:'', unit:'pcs', qty:1, up:0});
  renderLineItems();
});

function renderLineItems() {
  document.getElementById('liRows').innerHTML = lineItems.map((l, i) => `
    <div class="li-row" id="lr${l._id}">
      ${lineItems.length>1 ? `<button class="li-rm" onclick="removeLi(${l._id})"><i class='bx bx-trash' style="font-size:13px"></i></button>` : ''}
      <div style="font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.06em">Item ${i+1}</div>
      <div class="li-rr1">
        <div class="fg"><label class="fl">Item Name <span>*</span></label>
          <input type="text" class="fi" placeholder="Item description" value="${esc(l.name)}"
                 oninput="updLi(${l._id},'name',this.value)"></div>
        <div class="fg"><label class="fl">Qty <span>*</span></label>
          <input type="number" class="fi" min="1" value="${l.qty}"
                 oninput="updLi(${l._id},'qty',+this.value)"></div>
        <div class="fg"><label class="fl">Unit</label>
          <select class="fs" onchange="updLi(${l._id},'unit',this.value)">
            ${['pcs','sets','boxes','rolls','bags','liters','kg','meters','pairs','reams','cans'].map(u=>`<option ${l.unit===u?'selected':''}>${u}</option>`).join('')}
          </select></div>
        <div class="fg"><label class="fl">Unit Price (₱)</label>
          <input type="number" class="fi" min="0" step="0.01" value="${l.up||''}" placeholder="0.00"
                 oninput="updLi(${l._id},'up',+this.value)"></div>
      </div>
      ${l.spec ? `<div style="font-size:11px;color:var(--t3);padding:2px 0 4px;display:flex;align-items:center;gap:5px"><i class='bx bx-info-circle' style="font-size:12px"></i><span>${esc(l.spec)}</span></div>` : ''}
      <div class="li-sub" id="ls${l._id}">Subtotal: ${fM(l.qty*l.up)}</div>
    </div>`).join('');
  updGrand();
}
window.removeLi  = id => { lineItems = lineItems.filter(l=>l._id!==id); renderLineItems(); };
window.updLi     = (id, k, v) => {
  const l = lineItems.find(x=>x._id===id); if(!l) return;
  l[k] = v;
  const sub = document.getElementById(`ls${id}`);
  if(sub) sub.textContent = 'Subtotal: ' + fM(l.qty*l.up);
  updGrand();
};
function updGrand() {
  document.getElementById('grandTotal').textContent = fM(lineItems.reduce((s,l)=>s+l.qty*l.up,0));
}

function collectItems() {
  return lineItems.filter(l=>l.name.trim()).map(l=>({
    item_name:l.name, quantity:l.qty, unit:l.unit, unit_price:l.up
  }));
}

document.getElementById('slSubmit').addEventListener('click', async () => {
  const po_number   = document.getElementById('fPoNo').value.trim();
  const pr_ref      = document.getElementById('fPrRef').value.trim();
  const supplier    = document.getElementById('fSupplier').value;
  const issued_by   = document.getElementById('fIssuedBy').value.trim();
  const statusChoice= document.getElementById('fStatusSl').value;

  if(!po_number) { shk('fPoNo');     return toast('PO Number is required','w'); }
  if(!pr_ref)    { shk('fPrRef');    return toast('PR Reference is required','w'); }
  if(!supplier)  { shk('fSupplier'); return toast('Supplier is required','w'); }
  if(!issued_by) { shk('fIssuedBy');return toast('Issued By is required','w'); }

  const items = collectItems();
  if(!items.length) return toast('Add at least one line item','w');

  const payload = {
    id:           editId,
    po_number,
    pr_reference: pr_ref,
    supplier_name:supplier,
    supplier_category: document.getElementById('fSupCat').value || 'General',
    issued_by,
    date_issued:  document.getElementById('fDateIssued').value,
    delivery_date:document.getElementById('fDeliveryDate').value || null,
    payment_terms:document.getElementById('fPayTerms').value,
    remarks:      document.getElementById('fRemarks').value.trim(),
    status:       statusChoice,
    items,
  };

  const btn = document.getElementById('slSubmit');
  const orig = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Saving…';

  try {
    await api(editId ? 'update' : 'create', {}, payload);
    toast(editId ? `PO ${po_number} updated` : `PO ${po_number} created`, 's');
    closeSlider();
    await render();
  } catch(e) {
    toast(e.message,'d');
  } finally {
    btn.disabled = false; btn.innerHTML = orig;
  }
});

/* ── GLOBAL AUDIT TRAIL ──────────────────────────────────────────── */
document.getElementById('auditBtn').addEventListener('click', async () => {
  try {
    const data = await api('audit_global', {limit:50});
    // Reuse action modal as viewer
    document.getElementById('amIcon').textContent  = '📋';
    document.getElementById('amTitle').textContent = 'Global PO Audit Trail';
    document.getElementById('amBody').innerHTML    = `<span style="font-size:12px;color:var(--t3)">${data.total} entries system-wide</span>`;
    document.getElementById('amSaNote').style.display = 'flex';
    document.getElementById('amSaText').textContent = 'Super Admin View — complete PO activity log.';
    document.getElementById('amFields').innerHTML  = `
      <div style="max-height:340px;overflow-y:auto;border:1px solid var(--bd);border-radius:10px;padding:4px 0">
        ${(data.rows||[]).map(a=>`
          <div style="display:flex;gap:10px;padding:10px 14px;border-bottom:1px solid var(--bd)">
            <div class="vm-audit-dot ${dotIconClass(a.dot_class)}" style="flex-shrink:0"><i class='bx ${dotIcon(a.dot_class)}'></i></div>
            <div style="flex:1;min-width:0">
              <div style="font-size:13px;font-weight:600;color:var(--t1)">${esc(a.action_label)}</div>
              <div style="font-size:11px;color:var(--t3);font-family:'DM Mono',monospace">${esc(a.po_number)} · ${esc(a.actor_name)} · ${new Date(a.occurred_at).toLocaleString('en-PH')}</div>
            </div>
          </div>`).join('')}
      </div>`;
    document.getElementById('amRemarks').style.display = 'none';
    document.getElementById('amRmkLabel').style.display = 'none';
    const cb = document.getElementById('amConfirm');
    cb.className = 'btn btn-ghost btn-sm'; cb.innerHTML = 'Close';
    cb.onclick = () => { document.getElementById('actionModal').classList.remove('on'); cb.onclick=null; restoreAmRemarks(); };
    document.getElementById('amCancel').style.display = 'none';
    document.getElementById('actionModal').classList.add('on');
  } catch(e) { toast(e.message,'d'); }
});

function restoreAmRemarks() {
  const r = document.getElementById('amRemarks');
  const l = document.getElementById('amRmkLabel');
  r.style.display=''; l.style.display='';
  document.getElementById('amCancel').style.display='';
  document.getElementById('amConfirm').onclick = null;
}

/* ── EXPORT ──────────────────────────────────────────────────────── */
document.getElementById('expBtn').addEventListener('click', () => {
  const url = new URL(API_URL, location.origin);
  url.searchParams.set('action','export');
  window.open(url.toString(),'_blank');
  toast('Export started — CSV downloading…','s');
});

/* ── Utilities ───────────────────────────────────────────────────── */
function shk(id) {
  const el = document.getElementById(id);
  el.style.borderColor='var(--red)'; el.style.animation='none';
  el.offsetHeight; el.style.animation='SHK .3s ease';
  setTimeout(()=>{ el.style.borderColor=''; el.style.animation=''; },600);
}
function toast(msg, type='s') {
  const ic = {s:'bx-check-circle',w:'bx-error',d:'bx-error-circle',i:'bx-info-circle'};
  const el = document.createElement('div');
  el.className = `toast t${type}`;
  el.innerHTML = `<i class='bx ${ic[type]||"bx-check-circle"}' style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(()=>{ el.classList.add('out'); setTimeout(()=>el.remove(),320); },3200);
}

/* ── Init ────────────────────────────────────────────────────────── */
render();
</script>
</body>
</html>