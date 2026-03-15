<?php
/**
 * User Management — Merged Frontend + Backend
 * Stack: Supabase REST API (no PDO/PostgreSQL direct connection needed)
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../vendor/autoload.php';

// ── ROLE GUARD (mirror of sidebar role logic) ───────────────────────────────────
function um_resolve_role(): string {
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

$umRoleName = um_resolve_role();
$umRoleRank = match($umRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,
};

// Only Super Admin may access User Management
if ($umRoleRank < 4) {
    $isApi = isset($_GET['api'])
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

    if ($isApi) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Forbidden — Super Admin only']);
        exit;
    }

    $dashboardUrl = match($umRoleName) {
        'Super Admin' => '/superadmin_dashboard.php',
        'Admin'       => '/admin_dashboard.php',
        'Manager'     => '/manager_dashboard.php',
        default       => '/user_dashboard.php',
    };
    header('Location: ' . $dashboardUrl);
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ─────────────────────────────────────────────────────────────────
// SUPABASE REST API HELPER
// ─────────────────────────────────────────────────────────────────
function sbRest(string $table, string $method = 'GET', array $query = [], $body = null, array $extra = []): array {
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
    if (!$res) return [];
    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = is_array($data) ? ($data['message'] ?? $data['hint'] ?? $res) : $res;
        fail('Supabase: ' . $msg, 400);
    }
    return is_array($data) ? $data : [];
}

// ─────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────
function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
function ok($data = null, string $msg = 'Success'): void {
    jsonResponse(['success' => true, 'message' => $msg, 'data' => $data]);
}
function fail(string $msg, int $code = 400): void {
    jsonResponse(['success' => false, 'message' => $msg], $code);
}
function getInput(): array {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}
function clean(string $v): string {
    return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');
}
function needs(array $d, array $f): void {
    foreach ($f as $k) if (empty($d[$k])) fail("Field '{$k}' is required.", 422);
}
function logAudit(string $uid, string $act, string $by = 'Super Admin', string $rmk = '', bool $sa = true): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    sbRest('audit_logs', 'POST', [], [
        'user_id'      => $uid,
        'action'       => $act,
        'performed_by' => $by,
        'ip_address'   => $ip,
        'remarks'      => $rmk,
        'is_sa'        => $sa,
    ]);
}
function createAuthUser(string $email, string $password): string {
    // Create user in Supabase Auth via Admin API
    $url = SUPABASE_URL . '/auth/v1/admin/users';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: '         . SUPABASE_SERVICE_ROLE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'email'            => $email,
            'password'         => $password,
            'email_confirm'    => true,   // auto-confirm, no verification email needed
        ]),
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = is_array($data) ? ($data['message'] ?? $data['msg'] ?? $res) : $res;
        fail('Auth error: ' . $msg, 400);
    }
    return $data['id'] ?? '';
}

function nextUserId(): string {
    $rows = sbRest('users', 'GET', ['select' => 'user_id', 'order' => 'user_id.desc', 'limit' => '1']);
    if (empty($rows)) return 'USR-1001';
    preg_match('/(\d+)$/', $rows[0]['user_id'] ?? 'USR-1000', $m);
    return 'USR-' . str_pad((int)($m[1] ?? 1000) + 1, 4, '0', STR_PAD_LEFT);
}
function nextEmpId(): string {
    $rows = sbRest('users', 'GET', ['select' => 'emp_id', 'order' => 'emp_id.desc', 'limit' => '1']);
    if (empty($rows) || empty($rows[0]['emp_id'])) return 'EMP-0001';
    preg_match('/(\d+)$/', $rows[0]['emp_id'] ?? 'EMP-0000', $m);
    return 'EMP-' . str_pad((int)($m[1] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
}
function getUserWithRoles(string $uid): array {
    $rows = sbRest('users_with_roles', 'GET', ['user_id' => 'eq.' . $uid, 'select' => '*']);
    if (empty($rows)) fail('User not found.', 404);
    $u = $rows[0];
    $u['permissions'] = parsePgArr($u['permissions'] ?? null);
    $u['roles']       = parsePgArr($u['roles'] ?? null);
    $u['role']        = !empty($u['roles']) ? implode(', ', $u['roles']) : '—';
    // Audit logs
    $logs = sbRest('audit_logs', 'GET', [
        'user_id' => 'eq.' . $uid,
        'order'   => 'created_at.desc',
        'select'  => 'action,performed_by,ip_address,remarks,is_sa,created_at',
    ]);
    foreach ($logs as &$l) {
        $l['ts'] = isset($l['created_at'])
            ? date('M j, Y h:i A', strtotime($l['created_at']))
            : '';
    }
    $u['audit_log'] = $logs;
    return $u;
}
function parsePgArr($v): array {
    if (is_array($v)) return $v;
    if (!$v || $v === '{}') return [];
    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,{}]+)/', trim($v, '{}'), $m);
    return array_values(array_filter(array_map(fn($a,$b) => $a !== '' ? stripslashes($a) : trim($b), $m[1], $m[2])));
}

// ─────────────────────────────────────────────────────────────────
// API HANDLERS
// ─────────────────────────────────────────────────────────────────
function apiNextEmpId(): void {
    ok(['emp_id' => nextEmpId()]);
}

function apiStats(): void {
    $all  = sbRest('users', 'GET', ['select' => 'status']);
    $sas  = sbRest('users_with_roles', 'GET', ['select' => 'user_id,roles']);
    $superCount = 0;
    foreach ($sas as $u) {
        $roles = parsePgArr($u['roles'] ?? null);
        if (in_array('Super Admin', $roles)) $superCount++;
    }
    $stat = [
        'total'        => 0,
        'active'       => 0,
        'inactive'     => 0,
        'suspended'    => 0,
        'locked'       => 0,
        'super_admins' => $superCount,
    ];
    foreach ($all as $u) {
        $stat['total']++;
        $s = $u['status'] ?? '';
        if ($s === 'Active')    $stat['active']++;
        elseif ($s === 'Inactive')  $stat['inactive']++;
        elseif ($s === 'Suspended') $stat['suspended']++;
        elseif ($s === 'Locked')    $stat['locked']++;
    }
    ok((object)$stat);
}

function apiListUsers(): void {
    $search  = $_GET['search']    ?? '';
    $role    = $_GET['role']      ?? '';
    $zone    = $_GET['zone']      ?? '';
    $status  = $_GET['status']    ?? '';
    $df      = $_GET['date_from'] ?? '';
    $dt      = $_GET['date_to']   ?? '';
    $col     = $_GET['sort']      ?? 'created_at';
    $dir     = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 10)));

    $allowed = ['user_id','first_name','last_name','email','zone','created_at','last_login','status'];
    if (!in_array($col, $allowed)) $col = 'created_at';

    $q = ['select' => '*', 'order' => "{$col}.{$dir}"];
    if ($status)  $q['status']    = 'eq.' . $status;
    if ($zone)    $q['zone']      = 'eq.' . $zone;
    if ($df)      $q['last_login'] = 'gte.' . $df;
    if ($dt)      $q['last_login'] = 'lte.' . $dt;
    if ($role)    $q['roles']     = 'cs.{' . $role . '}';

    $allRows = sbRest('users_with_roles', 'GET', $q);

    if ($search) {
        $s = strtolower($search);
        $allRows = array_values(array_filter($allRows, fn($u) =>
            str_contains(strtolower($u['full_name']  ?? ''), $s) ||
            str_contains(strtolower($u['email']      ?? ''), $s) ||
            str_contains(strtolower($u['user_id']    ?? ''), $s) ||
            str_contains(strtolower($u['zone']       ?? ''), $s)
        ));
    }

    $total = count($allRows);
    $slice = array_slice($allRows, ($page - 1) * $perPage, $perPage);

    foreach ($slice as &$u) {
        $u['permissions'] = parsePgArr($u['permissions'] ?? null);
        $u['roles']       = parsePgArr($u['roles'] ?? null);
        $u['role']        = !empty($u['roles']) ? implode(', ', $u['roles']) : '—';
        $u['created']     = isset($u['created_at'])  ? date('Y-m-d', strtotime($u['created_at']))  : null;
        $u['last_login']  = isset($u['last_login'])  ? date('Y-m-d', strtotime($u['last_login']))  : null;
    }

    ok([
        'users'     => $slice,
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'last_page' => (int) ceil($total / $perPage),
    ]);
}

function apiGetUser(string $uid): void {
    ok(getUserWithRoles($uid));
}

function sendWelcomeEmail(string $email, string $firstName, string $empId, string $role, string $zone, string $password): void {
    $appName    = 'MicroFinancial';
    $loginUrl   = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/index.php';
    $subject    = "Welcome to {$appName} — Your Account is Ready";
    $recipientName = htmlspecialchars($firstName);

    $html = "<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width,initial-scale=1.0'>
<title>Welcome to {$appName}</title>
</head>
<body style='margin:0;padding:0;background:#f4f4f4;font-family:Inter,Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f4f4;padding:40px 0;'>
  <tr><td align='center'>
    <table width='580' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);'>

      <!-- Header -->
      <tr>
        <td style='background:#1a4f28;padding:32px 40px;text-align:center;'>
          <h1 style='margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:-0.02em;'>{$appName}</h1>
          <p style='margin:6px 0 0;color:rgba(255,255,255,0.7);font-size:12px;letter-spacing:0.1em;text-transform:uppercase;'>System Administration</p>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style='padding:40px 40px 32px;'>
          <h2 style='margin:0 0 8px;color:#1a1a18;font-size:20px;font-weight:700;'>Welcome, {$recipientName}! 👋</h2>
          <p style='margin:0 0 24px;color:#6b6b64;font-size:14px;line-height:1.6;'>Your account has been created by a Super Admin. You can now sign in to the system using the credentials below.</p>

          <!-- Credentials Box -->
          <table width='100%' cellpadding='0' cellspacing='0' style='background:#f0faf2;border:1px solid #aee6bc;border-radius:12px;margin-bottom:24px;'>
            <tr>
              <td style='padding:20px 24px;'>
                <p style='margin:0 0 14px;font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#2a7d3f;'>Your Login Credentials</p>
                <table width='100%' cellpadding='0' cellspacing='0'>
                  <tr>
                    <td style='padding:6px 0;border-bottom:1px solid rgba(46,125,50,0.12);'>
                      <span style='font-size:11px;color:#6b6b64;text-transform:uppercase;letter-spacing:0.06em;'>Email</span>
                      <p style='margin:2px 0 0;font-size:14px;font-weight:600;color:#1a1a18;font-family:monospace;'>" . htmlspecialchars($email) . "</p>
                    </td>
                  </tr>
                  <tr>
                    <td style='padding:6px 0;border-bottom:1px solid rgba(46,125,50,0.12);'>
                      <span style='font-size:11px;color:#6b6b64;text-transform:uppercase;letter-spacing:0.06em;'>Password</span>
                      <p style='margin:2px 0 0;font-size:14px;font-weight:600;color:#1a1a18;font-family:monospace;'>" . htmlspecialchars($password) . "</p>
                    </td>
                  </tr>
                  <tr>
                    <td style='padding:6px 0;border-bottom:1px solid rgba(46,125,50,0.12);'>
                      <span style='font-size:11px;color:#6b6b64;text-transform:uppercase;letter-spacing:0.06em;'>Employee ID</span>
                      <p style='margin:2px 0 0;font-size:14px;font-weight:600;color:#1a1a18;font-family:monospace;'>" . htmlspecialchars($empId) . "</p>
                    </td>
                  </tr>
                  <tr>
                    <td style='padding:6px 0;border-bottom:1px solid rgba(46,125,50,0.12);'>
                      <span style='font-size:11px;color:#6b6b64;text-transform:uppercase;letter-spacing:0.06em;'>Role</span>
                      <p style='margin:2px 0 0;font-size:14px;font-weight:600;color:#1a1a18;'>" . htmlspecialchars($role) . "</p>
                    </td>
                  </tr>
                  <tr>
                    <td style='padding:6px 0;'>
                      <span style='font-size:11px;color:#6b6b64;text-transform:uppercase;letter-spacing:0.06em;'>Zone / Department</span>
                      <p style='margin:2px 0 0;font-size:14px;font-weight:600;color:#1a1a18;'>" . htmlspecialchars($zone) . "</p>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>

          <!-- Warning -->
          <table width='100%' cellpadding='0' cellspacing='0' style='background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;margin-bottom:28px;'>
            <tr>
              <td style='padding:14px 18px;'>
                <p style='margin:0;font-size:12px;color:#92400e;line-height:1.5;'>
                  ⚠️ <strong>Please change your password</strong> after your first login for security purposes. Do not share your credentials with anyone.
                </p>
              </td>
            </tr>
          </table>

          <!-- CTA Button -->
          <table width='100%' cellpadding='0' cellspacing='0'>
            <tr>
              <td align='center'>
                <a href='{$loginUrl}' style='display:inline-block;background:#1a4f28;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;padding:14px 36px;border-radius:10px;letter-spacing:0.01em;'>Sign In to {$appName}</a>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style='padding:20px 40px;background:#f9fafb;border-top:1px solid #e5e7eb;text-align:center;'>
          <p style='margin:0;font-size:11px;color:#9ca3af;line-height:1.6;'>This is an automated message from {$appName} System Administration.<br>Please do not reply to this email.</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>";

    // ── PHPMailer via Gmail SMTP ──
    $mail = new PHPMailer(true);
    try {
        // SMTP Settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'noreply.microfinancial@gmail.com';
        $mail->Password   = 'dpjdwwlopkzdyfnk';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('noreply.microfinancial@gmail.com', $appName);
        $mail->addAddress($email, $firstName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = "Welcome {$firstName}! Your account is ready.\nEmail: {$email}\nPassword: {$password}\nEmployee ID: {$empId}\nRole: {$role}\nZone: {$zone}\nLogin at: {$loginUrl}";

        $mail->send();
    } catch (Exception $e) {
        // Log silently — don't fail user creation if email fails
        error_log('Welcome email failed for ' . $email . ': ' . $mail->ErrorInfo);
    }
}

function apiCreateUser(): void {
    $d = getInput();
    needs($d, ['first_name','last_name','email','zone','password']);
    if (empty($d['roles']) && empty($d['role'])) fail("Field 'role' is required.", 422);
    if (strlen($d['password']) < 8) fail('Password must be at least 8 characters.', 422);

    $ex = sbRest('users', 'GET', ['email' => 'eq.' . $d['email'], 'select' => 'user_id']);
    if (!empty($ex)) fail('Email already exists.', 409);

    // Create Supabase Auth user first
    $authId = createAuthUser(clean($d['email']), $d['password']);

    $uid   = nextUserId();
    $empId = nextEmpId();
    $perms = isset($d['permissions']) && is_array($d['permissions']) ? $d['permissions'] : [];

    sbRest('users', 'POST', [], [
        'user_id'    => $uid,
        'auth_id'    => $authId,
        'first_name' => clean($d['first_name']),
        'last_name'  => clean($d['last_name']),
        'email'      => clean($d['email']),
        'zone'       => clean($d['zone']),
        'status'     => clean($d['status'] ?? 'Active'),
        'emp_id'     => $empId,
        'phone'      => clean($d['phone'] ?? ''),
        'permissions'=> $perms,
        'remarks'    => clean($d['remarks'] ?? ''),
    ]);

    $roles = isset($d['roles']) && is_array($d['roles']) ? $d['roles'] : [$d['role'] ?? 'Staff'];
    foreach ($roles as $roleName) {
        $rr = sbRest('roles', 'GET', ['name' => 'eq.' . clean($roleName), 'select' => 'id']);
        if (!empty($rr)) {
            sbRest('user_roles', 'POST', [], [
                'user_id'     => $uid,
                'role_id'     => $rr[0]['id'],
                'assigned_by' => 'Super Admin',
            ]);
        }
    }

    // Send welcome email with credentials
    $roleName = isset($roles[0]) ? $roles[0] : ($d['role'] ?? 'Staff');
    sendWelcomeEmail(
        clean($d['email']),
        clean($d['first_name']),
        $empId,
        $roleName,
        clean($d['zone']),
        $d['password']
    );

    logAudit($uid, 'Account created by Super Admin', 'Super Admin', $d['remarks'] ?? '', true);
    ok(getUserWithRoles($uid), 'User created successfully.');
}

function apiUpdateUser(string $uid): void {
    $d = getInput();
    $ex = sbRest('users', 'GET', ['user_id' => 'eq.' . $uid, 'select' => 'user_id']);
    if (empty($ex)) fail('User not found.', 404);

    $allowed = ['first_name','last_name','email','zone','status','emp_id','phone','remarks'];
    $body = [];
    foreach ($allowed as $f) {
        if (isset($d[$f])) $body[$f] = clean($d[$f]);
    }
    if (isset($d['permissions']) && is_array($d['permissions'])) {
        $body['permissions'] = $d['permissions'];
    }
    if (!empty($body)) {
        $body['updated_at'] = date('c');
        sbRest('users', 'PATCH', ['user_id' => 'eq.' . $uid], $body);
    }

    if (isset($d['roles']) && is_array($d['roles'])) {
        sbRest('user_roles', 'DELETE', ['user_id' => 'eq.' . $uid]);
        foreach ($d['roles'] as $roleName) {
            $rr = sbRest('roles', 'GET', ['name' => 'eq.' . clean($roleName), 'select' => 'id']);
            if (!empty($rr)) {
                sbRest('user_roles', 'POST', [], [
                    'user_id'     => $uid,
                    'role_id'     => $rr[0]['id'],
                    'assigned_by' => 'Super Admin',
                ]);
            }
        }
    }

    logAudit($uid, 'Profile updated by Super Admin', 'Super Admin', $d['remarks'] ?? 'User details edited.', true);
    ok(getUserWithRoles($uid), 'User updated successfully.');
}

function apiDeleteUser(string $uid): void {
    $ex = sbRest('users', 'GET', ['user_id' => 'eq.' . $uid, 'select' => 'user_id']);
    if (empty($ex)) fail('User not found.', 404);
    sbRest('audit_logs', 'DELETE', ['user_id' => 'eq.' . $uid]);
    sbRest('users', 'DELETE', ['user_id' => 'eq.' . $uid]);
    ok(null, "User {$uid} deleted.");
}

function apiChangeStatus(string $uid, string $action): void {
    $d   = getInput();
    $ex  = sbRest('users', 'GET', ['user_id' => 'eq.' . $uid, 'select' => 'status']);
    if (empty($ex)) fail('User not found.', 404);
    $map = [
        'deactivate' => ['Inactive',  'Account deactivated by Super Admin'],
        'reactivate' => ['Active',    'Account reactivated by Super Admin'],
        'suspend'    => ['Suspended', 'Account suspended by Super Admin'],
        'unlock'     => ['Active',    'Account unlocked by Super Admin'],
        'lock'       => ['Locked',    'Account locked'],
    ];
    if (!isset($map[$action])) fail('Invalid action.', 400);
    [$ns, $log] = $map[$action];
    sbRest('users', 'PATCH', ['user_id' => 'eq.' . $uid], ['status' => $ns, 'updated_at' => date('c')]);
    logAudit($uid, $log, 'Super Admin', $d['remarks'] ?? '', true);
    ok(['user_id' => $uid, 'status' => $ns], $log . '.');
}

function apiResetPassword(string $uid): void {
    $d  = getInput();
    $ex = sbRest('users', 'GET', ['user_id' => 'eq.' . $uid, 'select' => 'email']);
    if (empty($ex)) fail('User not found.', 404);
    $email = $ex[0]['email'];
    $ch = curl_init(SUPABASE_URL . '/auth/v1/recover');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . SUPABASE_ANON_KEY],
        CURLOPT_POSTFIELDS     => json_encode(['email' => $email]),
    ]);
    curl_exec($ch); curl_close($ch);
    logAudit($uid, 'Password reset triggered by Super Admin', 'Super Admin', $d['remarks'] ?? '', true);
    ok(['email' => $email], 'Password reset email sent.');
}

function apiBatchDeactivate(): void {
    $d = getInput();
    if (empty($d['ids']) || !is_array($d['ids'])) fail('No IDs provided.', 422);
    $n = 0;
    foreach ($d['ids'] as $id) {
        $ex = sbRest('users', 'GET', ['user_id' => 'eq.' . clean($id), 'status' => 'eq.Active', 'select' => 'user_id']);
        if (empty($ex)) continue;
        sbRest('users', 'PATCH', ['user_id' => 'eq.' . clean($id)], ['status' => 'Inactive', 'updated_at' => date('c')]);
        logAudit(clean($id), 'Batch deactivated by Super Admin', 'Super Admin', $d['remarks'] ?? '', true);
        $n++;
    }
    ok(['affected' => $n], "{$n} user(s) deactivated.");
}

function apiBatchReset(): void {
    $d = getInput();
    if (empty($d['ids']) || !is_array($d['ids'])) fail('No IDs provided.', 422);
    foreach ($d['ids'] as $id) apiResetPassword(clean($id));
    ok(['count' => count($d['ids'])], 'Password reset sent to all selected users.');
}

function apiExportCSV(): void {
    $rows = sbRest('users_with_roles', 'GET', ['select' => 'user_id,full_name,email,roles,zone,status,emp_id,phone,created_at,last_login', 'order' => 'created_at.desc']);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="user_directory.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['User ID','Full Name','Email','Roles','Zone / Dept.','Status','Employee ID','Phone','Date Created','Last Login']);
    foreach ($rows as $r) {
        $roles = parsePgArr($r['roles'] ?? null);
        fputcsv($o, [
            $r['user_id']   ?? '',
            $r['full_name'] ?? '',
            $r['email']     ?? '',
            implode(', ', $roles),
            $r['zone']      ?? '',
            $r['status']    ?? '',
            $r['emp_id']    ?? '',
            $r['phone']     ?? '',
            isset($r['created_at']) ? date('Y-m-d', strtotime($r['created_at'])) : '',
            isset($r['last_login']) ? date('Y-m-d', strtotime($r['last_login'])) : '',
        ]);
    }
    fclose($o);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// API ROUTER
// ─────────────────────────────────────────────────────────────────
$isApi = isset($_GET['api'])
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'));

if ($isApi) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type,Authorization,X-Requested-With');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

    $method = $_SERVER["REQUEST_METHOD"];
    $route  = trim($_GET['route'] ?? '', '/');
    $parts  = explode('/', $route);
    $seg1   = !empty($parts[1]) ? $parts[1] : null;
    $seg2   = !empty($parts[2]) ? $parts[2] : null;

    try {
        if ($seg1 === 'stats'  && $method === 'GET')                   apiStats();
        elseif ($seg1 === 'next-emp-id' && $method === 'GET')          apiNextEmpId();
        elseif ($seg1 === 'export' && $method === 'GET')               apiExportCSV();
        elseif ($seg1 === 'batch' && $seg2 === 'deactivate')           apiBatchDeactivate();
        elseif ($seg1 === 'batch' && $seg2 === 'reset-password')       apiBatchReset();
        elseif (!$seg1 && $method === 'GET')                           apiListUsers();
        elseif (!$seg1 && $method === 'POST')                          apiCreateUser();
        elseif ($seg1 && !$seg2 && $method === 'GET')                  apiGetUser($seg1);
        elseif ($seg1 && !$seg2 && $method === 'PUT')                  apiUpdateUser($seg1);
        elseif ($seg1 && !$seg2 && $method === 'DELETE')               apiDeleteUser($seg1);
        elseif ($seg1 && $seg2 === 'reset-password' && $method === 'POST') apiResetPassword($seg1);
        elseif ($seg1 && in_array($seg2, ['deactivate','reactivate','suspend','unlock','lock']) && $method === 'POST') apiChangeStatus($seg1, $seg2);
        else fail('Route not found.', 404);
    } catch (Exception $e) { fail('Server error: ' . $e->getMessage(), 500); }
    exit;
}

$root = $_SERVER['DOCUMENT_ROOT'];
include $root . '/includes/superadmin_sidebar.php';
include $root . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management — System Administration</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/base.css">
<link rel="stylesheet" href="/css/sidebar.css">
<link rel="stylesheet" href="/css/header.css">
<style>
#mainContent,#userSlider,#slOverlay,#actionModal,#viewModal,.um-toasts {
  --s:#fff; --bd:rgba(46,125,50,.13); --bdm:rgba(46,125,50,.26);
  --t1:var(--text-primary); --t2:var(--text-secondary); --t3:#9EB0A2;
  --hbg:var(--hover-bg-light); --bg:var(--bg-color);
  --grn:var(--primary-color); --gdk:var(--primary-dark);
  --red:#DC2626; --amb:#D97706; --blu:#2563EB; --tel:#0D9488; --pur:#7C3AED;
  --shmd:0 4px 20px rgba(46,125,50,.12); --shlg:0 24px 60px rgba(0,0,0,.22);
  --rad:12px; --tr:var(--transition);
}
#mainContent *,#userSlider *,#slOverlay *,#actionModal *,#viewModal *,.um-toasts * { box-sizing:border-box; }
.um-wrap { max-width:1440px; margin:0 auto; padding:0 0 4rem; }
.um-ph   { display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:26px; animation:umUP .4s both; }
.um-ph .ey { font-size:11px; font-weight:600; letter-spacing:.14em; text-transform:uppercase; color:var(--grn); margin-bottom:4px; }
.um-ph h1  { font-size:26px; font-weight:800; color:var(--t1); line-height:1.15; }
.um-ph-r   { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.ubtn { display:inline-flex; align-items:center; gap:7px; font-family:'Inter',sans-serif; font-size:13px; font-weight:600; padding:9px 18px; border-radius:10px; border:none; cursor:pointer; transition:var(--tr); white-space:nowrap; }
.ubtn-primary  { background:var(--grn); color:#fff; box-shadow:0 2px 8px rgba(46,125,50,.32); }
.ubtn-primary:hover { background:var(--gdk); transform:translateY(-1px); }
.ubtn-ghost    { background:var(--s); color:var(--t2); border:1px solid var(--bdm); }
.ubtn-ghost:hover { background:var(--hbg); color:var(--t1); }
.ubtn-approve  { background:#DCFCE7; color:#166534; border:1px solid #BBF7D0; }
.ubtn-approve:hover { background:#BBF7D0; }
.ubtn-reject   { background:#FEE2E2; color:var(--red); border:1px solid #FECACA; }
.ubtn-reject:hover { background:#FCA5A5; }
.ubtn-warn     { background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; }
.ubtn-warn:hover { background:#FDE68A; }
.ubtn-purple   { background:#F5F3FF; color:var(--pur); border:1px solid #DDD6FE; }
.ubtn-purple:hover { background:#EDE9FE; }
.ubtn-teal     { background:#CCFBF1; color:#0F766E; border:1px solid #99F6E4; }
.ubtn-teal:hover { background:#99F6E4; }
.ubtn-blue     { background:#EFF6FF; color:var(--blu); border:1px solid #BFDBFE; }
.ubtn-blue:hover { background:#DBEAFE; }
.ubtn-sm  { font-size:12px; padding:6px 13px; }
.ubtn-xs  { font-size:11px; padding:4px 9px; border-radius:7px; }
.ubtn.ionly { width:28px; height:28px; padding:0; justify-content:center; font-size:14px; flex-shrink:0; border-radius:6px; }
.um-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:22px; animation:umUP .4s .05s both; }
.um-sc    { background:var(--s); border:1px solid var(--bd); border-radius:var(--rad); padding:14px 16px; box-shadow:0 1px 4px rgba(46,125,50,.07); display:flex; align-items:center; gap:12px; }
.um-sc-ic { width:38px; height:38px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:18px; }
.ic-b{background:#EFF6FF;color:var(--blu)} .ic-g{background:#DCFCE7;color:#166534}
.ic-r{background:#FEE2E2;color:var(--red)} .ic-a{background:#FEF3C7;color:var(--amb)}
.ic-p{background:#F5F3FF;color:var(--pur)} .ic-t{background:#CCFBF1;color:var(--tel)}
.um-sc-v  { font-size:22px; font-weight:800; color:var(--t1); line-height:1; }
.um-sc-l  { font-size:11px; color:var(--t2); margin-top:2px; }
.um-tb  { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:18px; animation:umUP .4s .1s both; }
.um-sw  { position:relative; flex:1; min-width:220px; }
.um-sw i { position:absolute; left:11px; top:50%; transform:translateY(-50%); font-size:17px; color:var(--t3); pointer-events:none; }
.um-si  { width:100%; padding:9px 11px 9px 36px; font-family:'Inter',sans-serif; font-size:13px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; transition:var(--tr); }
.um-si:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.10); }
.um-si::placeholder { color:var(--t3); }
.um-sel { font-family:'Inter',sans-serif; font-size:13px; padding:9px 28px 9px 11px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); cursor:pointer; outline:none; appearance:none; transition:var(--tr); background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; }
.um-sel:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.10); }
.um-drw { display:flex; align-items:center; gap:6px; }
.um-drw span { font-size:12px; color:var(--t3); font-weight:500; }
.um-date { font-family:'Inter',sans-serif; font-size:13px; padding:9px 11px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; transition:var(--tr); }
.um-date:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.10); }
.um-bulk { display:none; align-items:center; gap:10px; padding:10px 16px; background:linear-gradient(135deg,#F0FDF4,#DCFCE7); border:1px solid rgba(46,125,50,.22); border-radius:12px; margin-bottom:14px; flex-wrap:wrap; animation:umUP .25s both; }
.um-bulk.on { display:flex; }
.um-bc   { font-size:13px; font-weight:700; color:#166534; }
.um-bsep { width:1px; height:22px; background:rgba(46,125,50,.25); }
.um-sa-excl { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; background:linear-gradient(135deg,#FEF3C7,#FDE68A); color:#92400E; border:1px solid #FCD34D; border-radius:6px; padding:2px 7px; }
.um-sa-excl i { font-size:11px; }
.um-card { background:var(--s); border:1px solid var(--bd); border-radius:16px; overflow:hidden; box-shadow:var(--shmd); animation:umUP .4s .13s both; }
.um-tbl  { width:100%; border-collapse:collapse; font-size:12.5px; table-layout:fixed; }
.um-tbl thead th { font-size:10.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--t2); padding:10px 10px; text-align:left; background:var(--bg); border-bottom:1px solid var(--bd); white-space:nowrap; cursor:pointer; user-select:none; overflow:hidden; }
.um-tbl thead th.ns { cursor:default; }
.um-tbl thead th:hover:not(.ns) { color:var(--grn); }
.um-tbl thead th.srt { color:var(--grn); }
.um-tbl thead th .sic { margin-left:3px; opacity:.4; font-size:12px; vertical-align:middle; }
.um-tbl thead th.srt .sic { opacity:1; }
.um-tbl col.c-cb{width:38px} .um-tbl col.c-id{width:110px} .um-tbl col.c-nm{width:170px}
.um-tbl col.c-em{width:200px} .um-tbl col.c-rl{width:110px} .um-tbl col.c-zn{width:130px}
.um-tbl col.c-cr{width:105px} .um-tbl col.c-ll{width:105px} .um-tbl col.c-st{width:115px} .um-tbl col.c-ac{width:60px}
.um-tbl thead th:first-child,.um-tbl tbody td:first-child { padding-left:12px; padding-right:4px; }
.um-tbl tbody tr { border-bottom:1px solid var(--bd); transition:background .13s; }
.um-tbl tbody tr:last-child { border-bottom:none; }
.um-tbl tbody tr:hover { background:var(--hbg); }
.um-tbl tbody tr.row-sel { background:#F0FDF4; }
.um-tbl tbody td { padding:12px 10px; vertical-align:middle; cursor:pointer; max-width:0; overflow:hidden; text-overflow:ellipsis; }
.um-tbl tbody td:first-child { cursor:default; }
.um-tbl tbody td:last-child  { cursor:default; overflow:visible; padding:10px 8px; max-width:none; white-space:nowrap; }
.cb-wrap { display:flex; align-items:center; justify-content:center; }
input[type=checkbox].cb { width:15px; height:15px; accent-color:var(--grn); cursor:pointer; }
.uid-cell { font-family:'DM Mono',monospace; font-size:11px; font-weight:600; color:var(--t2); white-space:nowrap; }
.nm-cell  { display:flex; align-items:center; gap:8px; min-width:0; }
.nm-av    { width:30px; height:30px; border-radius:50%; font-size:10px; font-weight:700; color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.nm-txt   { font-weight:600; color:var(--t1); font-size:12.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.em-txt   { font-size:12px; color:var(--t2); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dt-txt   { font-size:11.5px; color:var(--t2); white-space:nowrap; }
.zn-txt   { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; }
.zn-dot   { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.act-cell { display:flex; gap:4px; align-items:center; }
.badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; white-space:nowrap; }
.badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }
.b-active{background:#DCFCE7;color:#166534} .b-inactive{background:#F3F4F6;color:#6B7280}
.b-suspended{background:#FEF3C7;color:#92400E} .b-locked{background:#FEE2E2;color:#991B1B}
.role-pill { display:inline-flex; align-items:center; font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap; }
.r-sa{background:#FEF3C7;color:#92400E;border:1px solid #FCD34D}
.r-admin{background:#FEE2E2;color:#991B1B;border:1px solid #FECACA}
.r-mgr{background:#FEF3C7;color:#B45309;border:1px solid #FDE68A}
.r-staff{background:#F0FDF4;color:#166534;border:1px solid #BBF7D0}
.um-pg   { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:14px 20px; border-top:1px solid var(--bd); background:var(--bg); font-size:13px; color:var(--t2); }
.pg-btns { display:flex; gap:5px; }
.pgb { width:32px; height:32px; border-radius:8px; border:1px solid var(--bdm); background:var(--s); font-family:'Inter',sans-serif; font-size:13px; cursor:pointer; display:grid; place-content:center; transition:var(--tr); color:var(--t1); }
.pgb:hover   { background:var(--hbg); border-color:var(--grn); color:var(--grn); }
.pgb.active  { background:var(--grn); border-color:var(--grn); color:#fff; }
.pgb:disabled { opacity:.4; pointer-events:none; }
.um-empty { padding:72px 20px; text-align:center; color:var(--t3); }
.um-empty i { font-size:54px; display:block; margin-bottom:14px; color:#C8E6C9; }
#viewModal { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9050; display:flex; align-items:center; justify-content:center; padding:20px; opacity:0; pointer-events:none; transition:opacity .25s; }
#viewModal.on { opacity:1; pointer-events:all; }
.vm-box { background:#fff; border-radius:20px; width:720px; max-width:100%; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.22); overflow:hidden; }
.vm-hd  { padding:24px 28px 0; border-bottom:1px solid rgba(46,125,50,.14); background:var(--bg); flex-shrink:0; }
.vm-top { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:16px; }
.vm-si  { display:flex; align-items:center; gap:16px; }
.vm-av  { width:56px; height:56px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:19px; color:#fff; flex-shrink:0; }
.vm-nm  { font-size:20px; font-weight:800; color:var(--text-primary); display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.vm-id  { font-family:'DM Mono',monospace; font-size:12px; color:var(--text-secondary); margin-top:3px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.vm-cl  { width:36px; height:36px; border-radius:8px; border:1px solid rgba(46,125,50,.22); background:#fff; cursor:pointer; display:grid; place-content:center; font-size:20px; color:var(--text-secondary); transition:var(--tr); flex-shrink:0; }
.vm-cl:hover { background:#FEE2E2; color:#DC2626; border-color:#FECACA; }
.vm-chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
.vm-chip  { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--text-secondary); background:#fff; border:1px solid rgba(46,125,50,.14); border-radius:8px; padding:5px 10px; line-height:1; }
.vm-chip i { font-size:14px; color:var(--primary-color); }
.vm-tabs  { display:flex; gap:4px; }
.vm-tab   { font-family:'Inter',sans-serif; font-size:13px; font-weight:600; padding:8px 16px; border-radius:8px 8px 0 0; cursor:pointer; transition:var(--tr); color:var(--text-secondary); border:none; background:transparent; display:flex; align-items:center; gap:6px; }
.vm-tab:hover { background:var(--hover-bg-light); color:var(--text-primary); }
.vm-tab.active { background:var(--primary-color); color:#fff; }
.vm-tab i { font-size:14px; }
.vm-bd  { flex:1; overflow-y:auto; padding:24px 28px; background:#fff; }
.vm-bd::-webkit-scrollbar { width:4px; }
.vm-bd::-webkit-scrollbar-thumb { background:rgba(46,125,50,.22); border-radius:4px; }
.vm-tp  { display:none; flex-direction:column; gap:18px; }
.vm-tp.active { display:flex; }
.vm-sbs { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.vm-sb  { background:var(--bg-color); border:1px solid rgba(46,125,50,.14); border-radius:10px; padding:14px 16px; }
.vm-sb .sbv { font-size:18px; font-weight:800; color:var(--text-primary); line-height:1; }
.vm-sb .sbl { font-size:11px; color:var(--text-secondary); margin-top:3px; }
.vm-ig  { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.vm-ii label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9EB0A2; display:block; margin-bottom:4px; }
.vm-ii .v { font-size:13px; font-weight:500; color:var(--text-primary); line-height:1.5; }
.vm-ii .v.muted { font-weight:400; color:#4B5563; }
.vm-full { grid-column:1/-1; }
.vm-sa-note { display:flex; align-items:flex-start; gap:8px; background:#FFFBEB; border:1px solid #FCD34D; border-radius:10px; padding:10px 14px; font-size:12px; color:#92400E; }
.vm-sa-note i { font-size:15px; flex-shrink:0; margin-top:1px; }
.vm-ft  { padding:16px 28px; border-top:1px solid rgba(46,125,50,.14); background:var(--bg-color); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; flex-wrap:wrap; }
.audit-item { display:flex; gap:12px; padding:12px 0; border-bottom:1px solid rgba(46,125,50,.14); }
.audit-item:last-child { border-bottom:none; padding-bottom:0; }
.audit-dot { width:28px; height:28px; border-radius:7px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:13px; }
.ad-c{background:#DCFCE7;color:#166534} .ad-s{background:#EFF6FF;color:#2563EB}
.ad-e{background:#F3F4F6;color:#6B7280} .ad-r{background:#FEE2E2;color:#DC2626}
.ad-o{background:#FEF3C7;color:#D97706} .ad-p{background:#F5F3FF;color:#7C3AED}
.ad-i{background:#CCFBF1;color:#0D9488}
.audit-bd { flex:1; min-width:0; }
.audit-bd .au { font-size:13px; font-weight:500; color:var(--text-primary); }
.audit-bd .at { font-size:11px; color:#9EB0A2; margin-top:3px; font-family:'DM Mono',monospace; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.audit-note { font-size:11.5px; color:#6B7280; margin-top:3px; font-style:italic; }
.audit-ip   { font-family:'DM Mono',monospace; font-size:10px; color:#9CA3AF; background:#F3F4F6; border-radius:4px; padding:1px 6px; }
.audit-ts   { font-family:'DM Mono',monospace; font-size:10px; color:#9EB0A2; flex-shrink:0; margin-left:auto; padding-left:8px; white-space:nowrap; }
.sa-tag { font-size:10px; font-weight:700; background:#FEF3C7; color:#92400E; border-radius:4px; padding:1px 5px; border:1px solid #FCD34D; }
#slOverlay  { position:fixed; inset:0; background:rgba(0,0,0,.52); z-index:9000; opacity:0; pointer-events:none; transition:opacity .25s; }
#slOverlay.on { opacity:1; pointer-events:all; }
#userSlider { position:fixed; top:0; right:-560px; bottom:0; width:520px; max-width:100vw; background:var(--s); z-index:9001; transition:right .3s cubic-bezier(.4,0,.2,1); display:flex; flex-direction:column; overflow:hidden; box-shadow:-4px 0 40px rgba(0,0,0,.18); }
#userSlider.on { right:0; }
.sl-hdr   { display:flex; align-items:flex-start; justify-content:space-between; padding:20px 24px 18px; border-bottom:1px solid var(--bd); background:var(--bg); flex-shrink:0; }
.sl-title { font-size:17px; font-weight:700; color:var(--t1); }
.sl-sub   { font-size:12px; color:var(--t2); margin-top:2px; }
.sl-close { width:36px; height:36px; border-radius:8px; border:1px solid var(--bdm); background:var(--s); cursor:pointer; display:grid; place-content:center; font-size:20px; color:var(--t2); transition:var(--tr); flex-shrink:0; }
.sl-close:hover { background:#FEE2E2; color:var(--red); border-color:#FECACA; }
.sl-body { flex:1; overflow-y:auto; padding:24px; display:flex; flex-direction:column; gap:18px; }
.sl-body::-webkit-scrollbar { width:4px; }
.sl-body::-webkit-scrollbar-thumb { background:var(--bdm); border-radius:4px; }
.sl-foot { padding:16px 24px; border-top:1px solid var(--bd); background:var(--bg); display:flex; gap:10px; justify-content:flex-end; flex-shrink:0; }
.fg  { display:flex; flex-direction:column; gap:6px; }
.fr  { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.fl  { font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--t2); }
.fl span { color:var(--red); margin-left:2px; }
.fi,.fs,.fta { font-family:'Inter',sans-serif; font-size:13px; padding:10px 12px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; transition:var(--tr); width:100%; }
.fi:focus,.fs:focus,.fta:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.11); }
.fs  { appearance:none; cursor:pointer; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; padding-right:30px; }
.fta { resize:vertical; min-height:70px; }
.sfd { font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--t3); display:flex; align-items:center; gap:10px; }
.sfd::after { content:''; flex:1; height:1px; background:var(--bd); }
.pm-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
.pm-item { display:flex; align-items:center; gap:7px; font-size:12px; color:var(--t2); cursor:pointer; padding:6px 8px; border-radius:8px; border:1px solid var(--bd); background:var(--s); transition:var(--tr); }
.pm-item:hover { background:var(--hbg); border-color:var(--bdm); color:var(--t1); }
.pm-item input { accent-color:var(--grn); cursor:pointer; width:13px; height:13px; }
.pm-item.chk { background:#E8F5E9; border-color:rgba(46,125,50,.3); color:var(--grn); }
#actionModal { position:fixed; inset:0; background:rgba(0,0,0,.52); z-index:9100; display:grid; place-content:center; opacity:0; pointer-events:none; transition:opacity .2s; }
#actionModal.on { opacity:1; pointer-events:all; }
.am-box   { background:var(--s); border-radius:16px; padding:28px 28px 24px; width:420px; max-width:92vw; box-shadow:var(--shlg); }
.am-icon  { font-size:46px; margin-bottom:10px; line-height:1; }
.am-title { font-size:18px; font-weight:700; color:var(--t1); margin-bottom:6px; }
.am-body  { font-size:13px; color:var(--t2); line-height:1.6; margin-bottom:16px; }
.am-sa    { display:flex; align-items:flex-start; gap:8px; background:#FFFBEB; border:1px solid #FCD34D; border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:12px; color:#92400E; }
.am-sa i  { font-size:15px; flex-shrink:0; margin-top:1px; }
.am-fg    { display:flex; flex-direction:column; gap:5px; margin-bottom:18px; }
.am-fg label { font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--t2); }
.am-fg textarea { font-family:'Inter',sans-serif; font-size:13px; padding:10px 12px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; resize:vertical; min-height:72px; width:100%; transition:var(--tr); }
.am-fg textarea:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.11); }
.am-acts  { display:flex; gap:10px; justify-content:flex-end; }
.um-toasts { position:fixed; bottom:28px; right:28px; z-index:9999; display:flex; flex-direction:column; gap:10px; pointer-events:none; }
.um-toast  { background:#0A1F0D; color:#fff; padding:12px 18px; border-radius:10px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:10px; box-shadow:var(--shlg); pointer-events:all; min-width:220px; animation:tIN .3s ease; }
.um-toast.ts{background:var(--grn)} .um-toast.tw{background:var(--amb)} .um-toast.td{background:var(--red)}
.um-toast.out { animation:tOUT .3s ease forwards; }
.um-loading { opacity:.5; pointer-events:none; }
@keyframes umUP { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
@keyframes tIN  { from{opacity:0;transform:translateY(8px)}  to{opacity:1;transform:translateY(0)} }
@keyframes tOUT { from{opacity:1;transform:translateY(0)}    to{opacity:0;transform:translateY(8px)} }
@keyframes umSHK{ 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-5px)} 40%,80%{transform:translateX(5px)} }
@media(max-width:768px){
  #userSlider{width:100vw} .fr{grid-template-columns:1fr} .um-stats{grid-template-columns:repeat(2,1fr)}
  .vm-sbs{grid-template-columns:repeat(2,1fr)} .vm-ig{grid-template-columns:1fr} .pm-grid{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="um-wrap">

  <div class="um-ph">
    <div>
      <p class="ey">System Administration</p>
      <h1>User Management</h1>
    </div>
    <div class="um-ph-r">
      <button class="ubtn ubtn-ghost" id="exportBtn"><i class="bx bx-export"></i> Export CSV</button>
      <button class="ubtn ubtn-primary" id="addUserBtn"><i class="bx bx-plus"></i> Add User</button>
    </div>
  </div>

  <div class="um-stats" id="statsBar"></div>

  <div class="um-tb">
    <div class="um-sw">
      <i class="bx bx-search"></i>
      <input type="text" class="um-si" id="srch" placeholder="Search by name, email, User ID, or zone…">
    </div>
    <select class="um-sel" id="fRole">
      <option value="">All Roles</option>
      <option>Super Admin</option><option>Admin</option><option>Manager</option><option>Staff</option>
    </select>
    <select class="um-sel" id="fZone"><option value="">All Zones / Dept.</option></select>
    <select class="um-sel" id="fStatus">
      <option value="">All Statuses</option>
      <option>Active</option><option>Inactive</option><option>Suspended</option><option>Locked</option>
    </select>
    <div class="um-drw">
      <input type="date" class="um-date" id="fDateFrom" title="Last Login From">
      <span>–</span>
      <input type="date" class="um-date" id="fDateTo" title="Last Login To">
    </div>
  </div>

  <div class="um-bulk" id="bulkBar">
    <span class="um-bc" id="bulkCount">0 selected</span>
    <div class="um-bsep"></div>
    <button class="ubtn ubtn-warn ubtn-sm" id="batchDeactBtn"><i class="bx bx-lock"></i> Batch Deactivate</button>
    <button class="ubtn ubtn-purple ubtn-sm" id="batchResetBtn"><i class="bx bx-key"></i> Batch Reset PW</button>
    <button class="ubtn ubtn-ghost ubtn-sm" id="clearSelBtn"><i class="bx bx-x-circle"></i> Clear</button>
    <span class="um-sa-excl" style="margin-left:auto"><i class="bx bx-shield-quarter"></i> Super Admin Exclusive</span>
  </div>

  <div class="um-card">
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
      <table class="um-tbl" id="tbl">
        <colgroup>
          <col class="c-cb"><col class="c-id"><col class="c-nm"><col class="c-em">
          <col class="c-rl"><col class="c-zn"><col class="c-cr"><col class="c-ll">
          <col class="c-st"><col class="c-ac">
        </colgroup>
        <thead>
          <tr>
            <th class="ns"><div class="cb-wrap"><input type="checkbox" class="cb" id="checkAll"></div></th>
            <th data-col="user_id">User ID <i class="bx bx-sort sic"></i></th>
            <th data-col="first_name">Full Name <i class="bx bx-sort sic"></i></th>
            <th data-col="email">Email <i class="bx bx-sort sic"></i></th>
            <th data-col="role">Role <i class="bx bx-sort sic"></i></th>
            <th data-col="zone">Zone / Dept. <i class="bx bx-sort sic"></i></th>
            <th data-col="created_at">Date Created <i class="bx bx-sort sic"></i></th>
            <th data-col="last_login">Last Login <i class="bx bx-sort sic"></i></th>
            <th data-col="status">Status <i class="bx bx-sort sic"></i></th>
            <th class="ns">Actions</th>
          </tr>
        </thead>
        <tbody id="tbody"><tr><td colspan="10"><div class="um-empty"><i class="bx bx-loader-alt bx-spin"></i><p>Loading users…</p></div></td></tr></tbody>
      </table>
    </div>
    <div class="um-pg" id="pager"></div>
  </div>

</div>
</main>

<div class="um-toasts" id="toastWrap"></div>

<!-- SLIDE-OVER -->
<div id="slOverlay">
<div id="userSlider">
  <div class="sl-hdr">
    <div><div class="sl-title" id="slTitle">Add New User</div><div class="sl-sub" id="slSub">Fill in all required fields below</div></div>
    <button class="sl-close" id="slClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="sl-body">
    <div class="fr">
      <div class="fg"><label class="fl">First Name <span>*</span></label><input type="text" class="fi" id="fFirst" placeholder="e.g. Juan"></div>
      <div class="fg"><label class="fl">Last Name <span>*</span></label><input type="text" class="fi" id="fLast" placeholder="e.g. Dela Cruz"></div>
    </div>
    <div class="fg"><label class="fl">Email Address <span>*</span></label><input type="email" class="fi" id="fEmail" placeholder="juan.delacruz@company.com"></div>
    <div class="fr">
      <div class="fg"><label class="fl">Role <span>*</span></label>
        <select class="fs" id="fRoleSl"><option value="">Select role…</option><option>Super Admin</option><option>Admin</option><option>Manager</option><option>Staff</option></select>
      </div>
      <div class="fg"><label class="fl">Status</label>
        <select class="fs" id="fStatusSl"><option value="Active">Active</option><option value="Inactive">Inactive</option><option value="Suspended">Suspended</option></select>
      </div>
    </div>
    <div class="fr" style="align-items:start">
      <div class="fg"><label class="fl">Zone / Department <span>*</span></label>
        <select class="fs" id="fZoneSl"><option value="">Select zone…</option>
          <option>Head Office</option><option>North Zone</option><option>South Zone</option>
          <option>East Zone</option><option>West Zone</option><option>Logistics</option>
          <option>Finance</option><option>HR</option><option>IT</option>
          <option>Operations</option><option>Engineering</option><option>Admin</option>
        </select>
      </div>
      <div class="fg">
        <label class="fl">Employee ID</label>
        <input type="text" class="fi" id="fEmpId" placeholder="Generating…" readonly style="background:var(--bg);color:var(--t2);cursor:default;font-family:'DM Mono',monospace;font-size:13px">
      </div>
    </div>
    <div class="fg"><label class="fl">Contact Number</label><input type="tel" class="fi" id="fPhone" placeholder="+63 9XX XXX XXXX"></div>
    <div id="fPasswordWrap">
      <div class="fg" style="margin-bottom:8px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
          <label class="fl" style="margin-bottom:0">Password <span style="color:var(--red)">*</span></label>
          <button type="button" id="genPwBtn" style="display:inline-flex;align-items:center;gap:5px;font-family:'Inter',sans-serif;font-size:11px;font-weight:700;color:var(--pur);background:#F5F3FF;border:1px solid #DDD6FE;border-radius:7px;padding:4px 10px;cursor:pointer;transition:var(--tr)" onmouseover="this.style.background='#EDE9FE'" onmouseout="this.style.background='#F5F3FF'">
            <i class="bx bx-refresh" style="font-size:13px"></i> Generate
          </button>
        </div>
        <div style="position:relative">
          <input type="text" class="fi" id="fPassword" placeholder="Min. 8 characters" style="padding-right:76px;font-family:'DM Mono',monospace;font-size:13px;letter-spacing:.04em">
          <div style="position:absolute;right:8px;top:50%;transform:translateY(-50%);display:flex;gap:4px;align-items:center">
            <button type="button" onclick="togglePw('fPassword','eyeIcon1')" style="background:none;border:none;cursor:pointer;color:var(--t3);font-size:16px;padding:2px;display:flex;align-items:center" title="Show/hide">
              <i class="bx bx-hide" id="eyeIcon1"></i>
            </button>
            <button type="button" id="copyPwBtn" onclick="copyPw()" style="background:none;border:none;cursor:pointer;color:var(--t3);font-size:16px;padding:2px;display:flex;align-items:center" title="Copy password">
              <i class="bx bx-copy" id="copyPwIcon"></i>
            </button>
          </div>
        </div>
        <!-- Password strength bar -->
        <div id="pwStrengthWrap" style="margin-top:6px;display:none">
          <div style="height:4px;background:var(--bd);border-radius:4px;overflow:hidden">
            <div id="pwStrengthBar" style="height:100%;width:0%;border-radius:4px;transition:width .3s,background .3s"></div>
          </div>
          <div id="pwStrengthLabel" style="font-size:10px;font-weight:700;margin-top:3px;letter-spacing:.06em;text-transform:uppercase"></div>
        </div>
      </div>
      <div class="fg">
        <label class="fl">Confirm Password <span style="color:var(--red)">*</span></label>
        <div style="position:relative">
          <input type="password" class="fi" id="fPasswordConfirm" placeholder="Re-enter password" style="padding-right:38px">
          <button type="button" onclick="togglePw('fPasswordConfirm','eyeIcon2')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--t3);font-size:17px;padding:0;display:flex;align-items:center">
            <i class="bx bx-hide" id="eyeIcon2"></i>
          </button>
        </div>
      </div>
    </div>
    <div class="sfd">Notes</div>
    <div class="fg"><label class="fl">Admin Remarks</label><textarea class="fta" id="fRemarks" placeholder="Optional notes about this user account…"></textarea></div>
  </div>
  <div class="sl-foot">
    <button class="ubtn ubtn-ghost ubtn-sm" id="slCancel">Cancel</button>
    <button class="ubtn ubtn-primary ubtn-sm" id="slSubmit"><i class="bx bx-save"></i> Save User</button>
  </div>
</div>
</div>

<!-- ACTION MODAL -->
<div id="actionModal">
  <div class="am-box">
    <div class="am-icon"  id="amIcon">⚙️</div>
    <div class="am-title" id="amTitle">Confirm Action</div>
    <div class="am-body"  id="amBody"></div>
    <div class="am-sa" id="amSaNote" style="display:none"><i class="bx bx-shield-quarter"></i><span id="amSaText"></span></div>
    <div class="am-fg"><label>Remarks / Notes (optional)</label><textarea id="amRemarks" placeholder="Add remarks for this action…"></textarea></div>
    <div class="am-acts">
      <button class="ubtn ubtn-ghost ubtn-sm" id="amCancel">Cancel</button>
      <button class="ubtn ubtn-sm" id="amConfirm">Confirm</button>
    </div>
  </div>
</div>

<!-- VIEW MODAL -->
<div id="viewModal">
  <div class="vm-box">
    <div class="vm-hd">
      <div class="vm-top">
        <div class="vm-si"><div class="vm-av" id="vmAv"></div><div><div class="vm-nm" id="vmNm"></div><div class="vm-id" id="vmId"></div></div></div>
        <button class="vm-cl" id="vmClose"><i class="bx bx-x"></i></button>
      </div>
      <div class="vm-chips" id="vmChips"></div>
      <div class="vm-tabs">
        <button class="vm-tab active" data-t="ov"><i class="bx bx-grid-alt"></i> Overview</button>
        <button class="vm-tab" data-t="au"><i class="bx bx-shield-quarter"></i> Login Audit</button>
      </div>
    </div>
    <div class="vm-bd"><div class="vm-tp active" id="vt-ov"></div><div class="vm-tp" id="vt-au"></div></div>
    <div class="vm-ft" id="vmFt"></div>
  </div>
</div>

<script>
/* ── CONFIG ── */
const API  = '<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES) ?>';
const ZC   = {'Head Office':'#2E7D32','North Zone':'#2563EB','South Zone':'#D97706','East Zone':'#0D9488','West Zone':'#7C3AED','Logistics':'#DC2626','Finance':'#0891B2','HR':'#059669','IT':'#D97706','Operations':'#2563EB','Engineering':'#7C3AED','Admin':'#6B7280'};

/* ── STATE ── */
let sortCol='created_at', sortDir='desc', page=1, pageSize=10;
let selectedIds=new Set(), actionTarget=null, actionKey=null, editId=null;
let allUsers=[], totalUsers=0, lastPage=1;

/* ── API FETCH ── */
async function api(path, method='GET', body=null) {
  const opts = { method, headers: {'X-Requested-With':'XMLHttpRequest'} };
  if (body) { opts.headers['Content-Type']='application/json'; opts.body=JSON.stringify(body); }
  const r = await fetch(API + path, opts);
  return r.json();
}

/* ── HELPERS ── */
const esc  = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const ini  = n => String(n||'').split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
const fD   = d => (!d||d==='—') ? '—' : new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
const zc   = z => ZC[z]||'#6B7280';

function badge(s){const m={Active:'b-active',Inactive:'b-inactive',Suspended:'b-suspended',Locked:'b-locked'};return`<span class="badge ${m[s]||''}">${s}</span>`;}
function rolePill(r){const m={'Super Admin':'r-sa',Admin:'r-admin',Manager:'r-mgr',Staff:'r-staff'};return`<span class="role-pill ${m[r]||''}">${r}</span>`;}
function toast(msg,type='s'){
  const ic={s:'bx-check-circle',w:'bx-error',d:'bx-error-circle'};
  const el=document.createElement('div');
  el.className=`um-toast t${type}`;
  el.innerHTML=`<i class="bx ${ic[type]}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(()=>{el.classList.add('out');setTimeout(()=>el.remove(),320);},3500);
}
function shk(id){const el=document.getElementById(id);el.style.borderColor='#DC2626';el.style.animation='none';el.offsetHeight;el.style.animation='umSHK .3s ease';setTimeout(()=>{el.style.borderColor='';el.style.animation='';},600);}

/* ── STATS ── */
async function loadStats(){
  const r=await api('?api=1&route=users/stats');
  if(!r.success)return;
  const d=r.data;
  document.getElementById('statsBar').innerHTML=`
    <div class="um-sc"><div class="um-sc-ic ic-b"><i class="bx bx-group"></i></div><div><div class="um-sc-v">${d.total}</div><div class="um-sc-l">Total Users</div></div></div>
    <div class="um-sc"><div class="um-sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="um-sc-v">${d.active}</div><div class="um-sc-l">Active</div></div></div>
    <div class="um-sc"><div class="um-sc-ic ic-t"><i class="bx bx-minus-circle"></i></div><div><div class="um-sc-v">${d.inactive}</div><div class="um-sc-l">Inactive</div></div></div>
    <div class="um-sc"><div class="um-sc-ic ic-a"><i class="bx bx-block"></i></div><div><div class="um-sc-v">${d.suspended}</div><div class="um-sc-l">Suspended</div></div></div>
    <div class="um-sc"><div class="um-sc-ic ic-r"><i class="bx bx-lock"></i></div><div><div class="um-sc-v">${d.locked}</div><div class="um-sc-l">Locked</div></div></div>
    <div class="um-sc"><div class="um-sc-ic ic-p"><i class="bx bx-shield-quarter"></i></div><div><div class="um-sc-v">${d.super_admins}</div><div class="um-sc-l">Super Admins</div></div></div>`;
}

/* ── LOAD USERS ── */
async function loadUsers(){
  const params = new URLSearchParams({
    'api':1, 'route':'users',
    search:  document.getElementById('srch').value,
    role:    document.getElementById('fRole').value,
    zone:    document.getElementById('fZone').value,
    status:  document.getElementById('fStatus').value,
    date_from: document.getElementById('fDateFrom').value,
    date_to:   document.getElementById('fDateTo').value,
    sort: sortCol, dir: sortDir, page, per_page: pageSize
  });
  const r = await api('?'+params.toString());
  if(!r.success){toast(r.message,'d');return;}
  allUsers   = r.data.users;
  totalUsers = r.data.total;
  lastPage   = r.data.last_page;
  renderTable();
  renderPager();
  buildZoneDD();
}

/* ── RENDER TABLE ── */
function renderTable(){
  const tb=document.getElementById('tbody');
  if(!allUsers.length){
    tb.innerHTML=`<tr><td colspan="10"><div class="um-empty"><i class="bx bx-user-x"></i><p>No users found.</p></div></td></tr>`;
    return;
  }
  tb.innerHTML=allUsers.map(u=>{
    const clr=zc(u.zone), chk=selectedIds.has(u.user_id);
    return`<tr class="${chk?'row-sel':''}">
      <td onclick="event.stopPropagation()"><div class="cb-wrap"><input type="checkbox" class="cb row-cb" data-id="${u.user_id}" ${chk?'checked':''}></div></td>
      <td onclick="openView('${u.user_id}')"><span class="uid-cell">${esc(u.user_id)}</span></td>
      <td onclick="openView('${u.user_id}')"><div class="nm-cell"><div class="nm-av" style="background:${clr}">${ini(u.full_name)}</div><span class="nm-txt">${esc(u.full_name)}</span></div></td>
      <td onclick="openView('${u.user_id}')"><span class="em-txt">${esc(u.email)}</span></td>
      <td onclick="openView('${u.user_id}')">${rolePill(u.role)}</td>
      <td onclick="openView('${u.user_id}')"><span class="zn-txt"><span class="zn-dot" style="background:${clr}"></span>${esc(u.zone)}</span></td>
      <td onclick="openView('${u.user_id}')"><span class="dt-txt">${fD(u.created)}</span></td>
      <td onclick="openView('${u.user_id}')"><span class="dt-txt">${fD(u.last_login)}</span></td>
      <td onclick="openView('${u.user_id}')">${badge(u.status)}</td>
      <td onclick="event.stopPropagation()">
        <div class="act-cell">
          <button class="ubtn ubtn-ghost ubtn-xs ionly" onclick="openView('${u.user_id}')" title="View"><i class="bx bx-show"></i></button>
        </div>
      </td>
    </tr>`;
  }).join('');

  document.querySelectorAll('.row-cb').forEach(cb=>{
    cb.addEventListener('change',function(){
      const id=this.dataset.id;
      if(this.checked)selectedIds.add(id);else selectedIds.delete(id);
      this.closest('tr').classList.toggle('row-sel',this.checked);
      updateBulkBar(); syncCA();
    });
  });
  syncCA();
  document.querySelectorAll('#tbl thead th[data-col]').forEach(th=>{
    const c=th.dataset.col; th.classList.toggle('srt',c===sortCol);
    const ic=th.querySelector('.sic');
    if(ic) ic.className=`bx ${c===sortCol?(sortDir==='asc'?'bx-sort-up':'bx-sort-down'):'bx-sort'} sic`;
  });
}

function renderPager(){
  const s=(page-1)*pageSize+1, e=Math.min(page*pageSize,totalUsers);
  let btns='';
  for(let i=1;i<=lastPage;i++){
    if(i===1||i===lastPage||(i>=page-2&&i<=page+2))btns+=`<button class="pgb ${i===page?'active':''}" onclick="goPage(${i})">${i}</button>`;
    else if(i===page-3||i===page+3)btns+=`<button class="pgb" disabled>…</button>`;
  }
  document.getElementById('pager').innerHTML=`
    <span>${totalUsers===0?'No results':`Showing ${s}–${e} of ${totalUsers} users`}</span>
    <div class="pg-btns">
      <button class="pgb" onclick="goPage(${page-1})" ${page<=1?'disabled':''}><i class="bx bx-chevron-left"></i></button>
      ${btns}
      <button class="pgb" onclick="goPage(${page+1})" ${page>=lastPage?'disabled':''}><i class="bx bx-chevron-right"></i></button>
    </div>`;
}

function goPage(p){page=p;loadUsers();}

function buildZoneDD(){
  const zones=[...new Set(allUsers.map(u=>u.zone))].sort();
  const el=document.getElementById('fZone'),dv=el.value;
  el.innerHTML='<option value="">All Zones / Dept.</option>'+zones.map(z=>`<option ${z===dv?'selected':''}>${esc(z)}</option>`).join('');
}

function syncCA(){
  const ca=document.getElementById('checkAll');
  const ids=allUsers.map(u=>u.user_id);
  const all=ids.length>0&&ids.every(id=>selectedIds.has(id));
  ca.checked=all; ca.indeterminate=!all&&ids.some(id=>selectedIds.has(id));
}

function updateBulkBar(){
  const n=selectedIds.size;
  document.getElementById('bulkBar').classList.toggle('on',n>0);
  document.getElementById('bulkCount').textContent=n===1?'1 selected':`${n} selected`;
}

/* ── SORT & FILTER ── */
document.querySelectorAll('#tbl thead th[data-col]').forEach(th=>{
  th.addEventListener('click',()=>{
    const c=th.dataset.col;
    sortDir=sortCol===c?(sortDir==='asc'?'desc':'asc'):'asc';
    sortCol=c; page=1; loadUsers();
  });
});
let searchTimer;
document.getElementById('srch').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{page=1;loadUsers();},350);});
['fRole','fZone','fStatus','fDateFrom','fDateTo'].forEach(id=>
  document.getElementById(id).addEventListener('change',()=>{page=1;loadUsers();}));
document.getElementById('checkAll').addEventListener('change',function(){
  allUsers.forEach(u=>{if(this.checked)selectedIds.add(u.user_id);else selectedIds.delete(u.user_id);});
  renderTable(); updateBulkBar();
});
document.getElementById('clearSelBtn').addEventListener('click',()=>{selectedIds.clear();renderTable();updateBulkBar();});

/* ── EXPORT ── */
document.getElementById('exportBtn').addEventListener('click',()=>{
  window.location.href=API+'?api=1&route=users/export';
});

/* ── BATCH ── */
document.getElementById('batchDeactBtn').addEventListener('click',()=>{
  const active=[...selectedIds].filter(id=>{const u=allUsers.find(x=>x.user_id===id);return u&&u.status==='Active';});
  if(!active.length)return toast('No Active users in selection.','w');
  actionKey='batch-deactivate'; window._batchIds=active;
  showActionModal('🔒',`Batch Deactivate ${active.length} User(s)`,`Deactivate <strong>${active.length}</strong> Active user(s). They will lose access immediately.`,true,'Super Admin authority required for bulk deactivation.','ubtn-warn','<i class="bx bx-lock"></i> Batch Deactivate');
});
document.getElementById('batchResetBtn').addEventListener('click',()=>{
  if(!selectedIds.size)return toast('No users selected.','w');
  actionKey='batch-reset'; window._batchIds=[...selectedIds];
  showActionModal('🔑','Batch Reset Password',`Send password reset to <strong>${selectedIds.size}</strong> user(s).`,true,'Super Admin authority required for bulk password reset.','ubtn-purple','<i class="bx bx-key"></i> Batch Reset');
});

/* ── ACTION MODAL ── */
function showActionModal(icon,title,body,sa,saText,btnClass,btnLabel){
  document.getElementById('amIcon').textContent=icon;
  document.getElementById('amTitle').textContent=title;
  document.getElementById('amBody').innerHTML=body;
  const saEl=document.getElementById('amSaNote');
  if(sa){saEl.style.display='flex';document.getElementById('amSaText').textContent=saText;}else saEl.style.display='none';
  document.getElementById('amRemarks').value='';
  const cb=document.getElementById('amConfirm');
  cb.className=`ubtn ubtn-sm ${btnClass}`;cb.innerHTML=btnLabel;
  document.getElementById('actionModal').classList.add('on');
}

function promptAct(id,type){
  const u=allUsers.find(x=>x.user_id===id)||{user_id:id,full_name:id,role:'',zone:''};
  actionTarget=id; actionKey=type;
  const cfg={
    deactivate:{icon:'🔒',title:'Deactivate Account', sa:true,saText:'Super Admin authority required.',       btn:'ubtn-warn',   label:'<i class="bx bx-user-x"></i> Deactivate'},
    reactivate:{icon:'✅',title:'Reactivate Account',  sa:true,saText:'Will restore full system access.',     btn:'ubtn-approve',label:'<i class="bx bx-user-check"></i> Reactivate'},
    reset:     {icon:'🔑',title:'Reset Password',      sa:true,saText:'Reset link sent to registered email.',btn:'ubtn-purple',label:'<i class="bx bx-key"></i> Send Reset Link'},
    unlock:    {icon:'🔓',title:'Unlock Account',      sa:true,saText:'Clears login lockout and restores access.',btn:'ubtn-teal',label:'<i class="bx bx-lock-open"></i> Unlock Account'},
    suspend:   {icon:'⛔',title:'Suspend Account',     sa:true,saText:'Locks access pending investigation.',  btn:'ubtn-reject',label:'<i class="bx bx-block"></i> Suspend'},
  };
  const c=cfg[type];
  showActionModal(c.icon,c.title,`<strong>${esc(u.full_name)}</strong> · ${rolePill(u.role)} · ${esc(u.zone)}`,c.sa,c.saText,c.btn,c.label);
}

document.getElementById('amConfirm').addEventListener('click', async ()=>{
  const rmk=document.getElementById('amRemarks').value.trim();
  document.getElementById('actionModal').classList.remove('on');

  if(actionKey==='batch-deactivate'){
    const r=await api('?api=1&route=users/batch/deactivate','POST',{ids:window._batchIds,remarks:rmk});
    toast(r.message, r.success?'s':'d');
    selectedIds.clear(); await refresh(); return;
  }
  if(actionKey==='batch-reset'){
    const r=await api('?api=1&route=users/batch/reset-password','POST',{ids:window._batchIds,remarks:rmk});
    toast(r.message, r.success?'s':'d');
    selectedIds.clear(); return;
  }

  const routeMap={deactivate:'deactivate',reactivate:'reactivate',suspend:'suspend',unlock:'unlock',reset:'reset-password'};
  const route=routeMap[actionKey];
  const r=await api(`?api=1&route=users/${actionTarget}/${route}`,'POST',{remarks:rmk});
  toast(r.message||'Done.', r.success?'s':'d');
  if(r.success){ await refresh(); if(document.getElementById('viewModal').classList.contains('on')) openView(actionTarget); }
});
document.getElementById('amCancel').addEventListener('click',()=>document.getElementById('actionModal').classList.remove('on'));
document.getElementById('actionModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('on');});

/* ── VIEW MODAL ── */
async function openView(id){
  document.getElementById('viewModal').classList.add('on');
  document.getElementById('vt-ov').innerHTML='<div style="text-align:center;padding:40px;color:#9EB0A2"><i class="bx bx-loader-alt bx-spin" style="font-size:32px"></i></div>';
  const r=await api(`?api=1&route=users/${id}`);
  if(!r.success){toast(r.message,'d');closeView();return;}
  renderDetail(r.data); setVmTab('ov');
}
function closeView(){document.getElementById('viewModal').classList.remove('on');}
document.getElementById('vmClose').addEventListener('click',closeView);
document.getElementById('viewModal').addEventListener('click',function(e){if(e.target===this)closeView();});
document.querySelectorAll('.vm-tab').forEach(t=>t.addEventListener('click',()=>setVmTab(t.dataset.t)));
function setVmTab(name){
  document.querySelectorAll('.vm-tab').forEach(t=>t.classList.toggle('active',t.dataset.t===name));
  document.querySelectorAll('.vm-tp').forEach(p=>p.classList.toggle('active',p.id==='vt-'+name));
}

function renderDetail(u){
  const clr=zc(u.zone);
  document.getElementById('vmAv').textContent=ini(u.full_name);
  document.getElementById('vmAv').style.background=clr;
  document.getElementById('vmNm').innerHTML=esc(u.full_name)+' '+rolePill(u.role);
  document.getElementById('vmId').innerHTML=`<span style="font-family:'DM Mono',monospace">${esc(u.user_id)}</span> &nbsp;·&nbsp; ${esc(u.email)} &nbsp;${badge(u.status)}`;
  document.getElementById('vmChips').innerHTML=`
    <div class="vm-chip"><i class="bx bx-building"></i>${esc(u.zone)}</div>
    <div class="vm-chip"><i class="bx bx-calendar"></i>Created ${fD(u.created)}</div>
    <div class="vm-chip"><i class="bx bx-time-five"></i>Last login ${fD(u.last_login)}</div>
    <div class="vm-chip"><i class="bx bx-id-card"></i>${esc(u.emp_id||'—')}</div>`;
  const isActive=u.status==='Active', isLocked=u.status==='Locked';
  document.getElementById('vmFt').innerHTML=`
    <button class="ubtn ubtn-blue ubtn-sm" onclick="closeView();openEdit('${u.user_id}')"><i class="bx bx-edit"></i> Edit</button>
    <button class="ubtn ubtn-purple ubtn-sm" onclick="closeView();promptAct('${u.user_id}','reset')"><i class="bx bx-key"></i> Reset Password</button>
    ${isLocked?`<button class="ubtn ubtn-teal ubtn-sm" onclick="closeView();promptAct('${u.user_id}','unlock')"><i class="bx bx-lock-open"></i> Unlock</button>`:''}
    ${isActive?`<button class="ubtn ubtn-warn ubtn-sm" onclick="closeView();promptAct('${u.user_id}','deactivate')"><i class="bx bx-user-x"></i> Deactivate</button>`:''}
    ${!isActive&&!isLocked?`<button class="ubtn ubtn-approve ubtn-sm" onclick="closeView();promptAct('${u.user_id}','reactivate')"><i class="bx bx-user-check"></i> Reactivate</button>`:''}
    ${u.status==='Active'?`<button class="ubtn ubtn-reject ubtn-sm" onclick="closeView();promptAct('${u.user_id}','suspend')"><i class="bx bx-block"></i> Suspend</button>`:''}
    <button class="ubtn ubtn-ghost ubtn-sm" onclick="closeView()">Close</button>`;

  const perms=Array.isArray(u.permissions)?u.permissions:[];
  document.getElementById('vt-ov').innerHTML=`
    <div class="vm-sbs">
      <div class="vm-sb"><div class="sbv">${esc(u.role)}</div><div class="sbl">System Role</div></div>
      <div class="vm-sb"><div class="sbv">${esc(u.zone)}</div><div class="sbl">Zone / Dept.</div></div>
      <div class="vm-sb"><div class="sbv">${perms.length}</div><div class="sbl">Modules Assigned</div></div>
    </div>
    <div class="vm-ig">
      <div class="vm-ii"><label>Full Name</label><div class="v">${esc(u.full_name)}</div></div>
      <div class="vm-ii"><label>Email</label><div class="v muted">${esc(u.email)}</div></div>
      <div class="vm-ii"><label>Employee ID</label><div class="v muted">${esc(u.emp_id||'—')}</div></div>
      <div class="vm-ii"><label>Phone</label><div class="v muted">${esc(u.phone||'—')}</div></div>
      <div class="vm-ii"><label>Date Created</label><div class="v muted">${fD(u.created)}</div></div>
      <div class="vm-ii"><label>Last Login</label><div class="v muted">${fD(u.last_login)}</div></div>
      <div class="vm-ii"><label>Account Status</label><div class="v">${badge(u.status)}</div></div>
      <div class="vm-ii"><label>Role</label><div class="v">${rolePill(u.role)}</div></div>
      ${perms.length?`<div class="vm-ii vm-full"><label>Module Permissions</label><div class="v" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px">${perms.map(p=>`<span style="background:#E8F5E9;color:#2E7D32;border:1px solid rgba(46,125,50,.3);font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px">${esc(p)}</span>`).join('')}</div></div>`:''}
      ${u.remarks?`<div class="vm-ii vm-full"><label>Admin Remarks</label><div class="v muted">${esc(u.remarks)}</div></div>`:''}
    </div>
    <div class="vm-sa-note"><i class="bx bx-shield-quarter"></i><span>Cross-zone visibility — Super Admin exclusive. Full user profile, role, and permission data visible across all zones.</span></div>`;

  /* ── AUDIT LOG — fixed icon mapping ── */
  const auditLog = u.audit_log || [];

  function auditIcon(action) {
    const a = action.toLowerCase();
    // Most-specific checks first to prevent substring collisions
    if (a.includes('batch'))                           return { icon: 'bx-lock',        cls: 'ad-r' };
    if (a.includes('imperson'))                        return { icon: 'bx-user-voice',  cls: 'ad-p' };
    if (a.includes('export'))                          return { icon: 'bx-export',      cls: 'ad-e' };
    if (a.includes('creat'))                           return { icon: 'bx-user-plus',   cls: 'ad-c' };
    // 'reactivat' must come before 'deactivat' and 'activ' — all share substrings
    if (a.includes('reactivat'))                       return { icon: 'bx-user-check',  cls: 'ad-c' };
    if (a.includes('deactivat'))                       return { icon: 'bx-user-x',      cls: 'ad-r' };
    if (a.includes('suspend'))                         return { icon: 'bx-block',       cls: 'ad-o' };
    // 'unlock' must come before 'lock'
    if (a.includes('unlock'))                          return { icon: 'bx-lock-open',   cls: 'ad-i' };
    if (a.includes('lock'))                            return { icon: 'bx-lock',        cls: 'ad-r' };
    if (a.includes('reset') || a.includes('password')) return { icon: 'bx-key',         cls: 'ad-e' };
    if (a.includes('login') || a.includes('session'))  return { icon: 'bx-log-in',      cls: 'ad-s' };
    if (a.includes('logout') || a.includes('sign out'))return { icon: 'bx-log-out',     cls: 'ad-e' };
    if (a.includes('updat') || a.includes('edit'))     return { icon: 'bx-edit',        cls: 'ad-s' };
    if (a.includes('delet') || a.includes('remov'))    return { icon: 'bx-trash',       cls: 'ad-r' };
    return { icon: 'bx-info-circle', cls: 'ad-e' };
  }

  document.getElementById('vt-au').innerHTML=`
    <div class="vm-sa-note"><i class="bx bx-shield-quarter"></i><span>Login audit trail — Super Admin only. All session events, IP addresses, and account actions are read-only and immutable.</span></div>
    ${auditLog.length ? auditLog.map(a => {
      const { icon, cls } = auditIcon(a.action);
      return `
        <div class="audit-item">
          <div class="audit-dot ${cls}"><i class="bx ${icon}"></i></div>
          <div class="audit-bd">
            <div class="au">${esc(a.action)} ${a.is_sa ? '<span class="sa-tag">Super Admin</span>' : ''}</div>
            <div class="at">
              <i class="bx bx-user" style="font-size:11px"></i>${esc(a.performed_by)}
              ${a.ip_address ? `<span class="audit-ip"><i class="bx bx-desktop" style="font-size:10px;margin-right:2px"></i>${esc(a.ip_address)}</span>` : ''}
            </div>
            ${a.remarks ? `<div class="audit-note">"${esc(a.remarks)}"</div>` : ''}
          </div>
          <div class="audit-ts">${esc(a.ts)}</div>
        </div>`;
    }).join('') : '<p style="color:#9EB0A2;font-size:13px;text-align:center;padding:20px">No audit logs yet.</p>'}`;
}

/* ── TOGGLE PASSWORD VISIBILITY ── */
function togglePw(inputId, iconId){
  const inp=document.getElementById(inputId);
  const ico=document.getElementById(iconId);
  if(inp.type==='password'||inp.type==='text'){
    const isHidden=inp.type==='password';
    inp.type=isHidden?'text':'password';
    ico.className=isHidden?'bx bx-show':'bx bx-hide';
  }
}

/* ── GENERATE PASSWORD ── */
function generatePassword(len=14){
  const upper='ABCDEFGHJKLMNPQRSTUVWXYZ';
  const lower='abcdefghjkmnpqrstuvwxyz';
  const nums  ='23456789';
  const syms  ='@#$%&!?';
  const all   = upper+lower+nums+syms;
  // Guarantee at least one of each type
  let pw = [
    upper[Math.floor(Math.random()*upper.length)],
    lower[Math.floor(Math.random()*lower.length)],
    nums [Math.floor(Math.random()*nums.length)],
    syms [Math.floor(Math.random()*syms.length)],
  ];
  for(let i=4;i<len;i++) pw.push(all[Math.floor(Math.random()*all.length)]);
  // Shuffle
  for(let i=pw.length-1;i>0;i--){
    const j=Math.floor(Math.random()*(i+1));
    [pw[i],pw[j]]=[pw[j],pw[i]];
  }
  return pw.join('');
}

function pwStrength(pw){
  let score=0;
  if(pw.length>=8)  score++;
  if(pw.length>=12) score++;
  if(/[A-Z]/.test(pw)) score++;
  if(/[a-z]/.test(pw)) score++;
  if(/[0-9]/.test(pw)) score++;
  if(/[^A-Za-z0-9]/.test(pw)) score++;
  if(score<=2) return {w:'33%', bg:'#DC2626', label:'Weak',   lc:'#DC2626'};
  if(score<=4) return {w:'66%', bg:'#D97706', label:'Medium', lc:'#D97706'};
  return              {w:'100%',bg:'#16A34A', label:'Strong', lc:'#16A34A'};
}

function updateStrength(){
  const pw=document.getElementById('fPassword').value;
  const wrap=document.getElementById('pwStrengthWrap');
  const bar =document.getElementById('pwStrengthBar');
  const lbl =document.getElementById('pwStrengthLabel');
  if(!pw){wrap.style.display='none';return;}
  wrap.style.display='block';
  const s=pwStrength(pw);
  bar.style.width=s.w; bar.style.background=s.bg;
  lbl.textContent=s.label; lbl.style.color=s.lc;
}

function copyPw(){
  const pw=document.getElementById('fPassword').value;
  if(!pw) return;
  navigator.clipboard.writeText(pw).then(()=>{
    const ico=document.getElementById('copyPwIcon');
    ico.className='bx bx-check';
    ico.style.color='#16A34A';
    setTimeout(()=>{ ico.className='bx bx-copy'; ico.style.color=''; },1800);
  });
}

document.getElementById('genPwBtn').addEventListener('click',()=>{
  const pw=generatePassword(14);
  const inp=document.getElementById('fPassword');
  inp.type='text';
  document.getElementById('eyeIcon1').className='bx bx-show';
  inp.value=pw;
  document.getElementById('fPasswordConfirm').value=pw;
  updateStrength();
  // Flash the field
  inp.style.borderColor='var(--pur)';
  inp.style.boxShadow='0 0 0 3px rgba(124,58,237,.13)';
  setTimeout(()=>{ inp.style.borderColor=''; inp.style.boxShadow=''; },800);
  toast('Password generated — copy it before saving!','w');
});

document.getElementById('fPassword').addEventListener('input', updateStrength);

/* ── SLIDE-OVER ── */
function openSlider(mode='add', u=null){
  editId=mode==='edit'?u.user_id:null;
  document.getElementById('slTitle').textContent=mode==='edit'?`Edit User — ${u.user_id}`:'Add New User';
  document.getElementById('slSub').textContent=mode==='edit'?'Update fields below':'Fill in all required fields below';
  if(mode==='edit'&&u){
    document.getElementById('fPasswordWrap').style.display='none';
    document.getElementById('fFirst').value=u.first_name||'';
    document.getElementById('fLast').value=u.last_name||'';
    document.getElementById('fEmail').value=u.email||'';
    document.getElementById('fRoleSl').value=u.role||'';
    document.getElementById('fStatusSl').value=u.status==='Locked'?'Suspended':(u.status||'Active');
    document.getElementById('fZoneSl').value=u.zone||'';
    document.getElementById('fEmpId').value=u.emp_id||'';
    document.getElementById('fPhone').value=u.phone||'';
    document.getElementById('fRemarks').value=u.remarks||'';
  } else {
    document.getElementById('fPasswordWrap').style.display='';
    ['fFirst','fLast','fEmail','fPhone','fRemarks','fPassword','fPasswordConfirm'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('fEmpId').value='Generating…';
    document.getElementById('pwStrengthWrap').style.display='none';
    document.getElementById('fPassword').type='text';
    ['fRoleSl','fZoneSl'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('fStatusSl').value='Active';
    // Fetch next emp_id from server
    api('?api=1&route=users/next-emp-id').then(r=>{
      if(r.success) document.getElementById('fEmpId').value=r.data.emp_id;
    });
  }
  document.getElementById('userSlider').classList.add('on');
  document.getElementById('slOverlay').classList.add('on');
  setTimeout(()=>document.getElementById('fFirst').focus(),350);
}
async function openEdit(id){
  const r=await api(`?api=1&route=users/${id}`);
  if(r.success) openSlider('edit',r.data); else toast(r.message,'d');
}
function closeSlider(){
  document.getElementById('userSlider').classList.remove('on');
  document.getElementById('slOverlay').classList.remove('on');
  editId=null;
}
document.getElementById('slOverlay').addEventListener('click',function(e){if(e.target===this)closeSlider();});
document.getElementById('slClose').addEventListener('click',closeSlider);
document.getElementById('slCancel').addEventListener('click',closeSlider);
document.getElementById('addUserBtn').addEventListener('click',()=>openSlider('add'));

document.getElementById('slSubmit').addEventListener('click', async ()=>{
  const fn=document.getElementById('fFirst').value.trim();
  const ln=document.getElementById('fLast').value.trim();
  const email=document.getElementById('fEmail').value.trim();
  const role=document.getElementById('fRoleSl').value;
  const zone=document.getElementById('fZoneSl').value;
  const status=document.getElementById('fStatusSl').value;
  const empId=document.getElementById('fEmpId').value.trim();
  const phone=document.getElementById('fPhone').value.trim();
  const remarks=document.getElementById('fRemarks').value.trim();
  const password=document.getElementById('fPassword').value;
  const passwordConfirm=document.getElementById('fPasswordConfirm').value;
  const permissions=[];
  if(!fn){shk('fFirst');return toast('First name is required','w');}
  if(!ln){shk('fLast');return toast('Last name is required','w');}
  if(!email){shk('fEmail');return toast('Email is required','w');}
  if(!role){shk('fRoleSl');return toast('Please select a role','w');}
  if(!zone){shk('fZoneSl');return toast('Please select a zone','w');}
  if(!editId){
    if(!password){shk('fPassword');return toast('Password is required','w');}
    if(password.length<8){shk('fPassword');return toast('Password must be at least 8 characters','w');}
    if(password!==passwordConfirm){shk('fPasswordConfirm');return toast('Passwords do not match','w');}
  }
  const payload={first_name:fn,last_name:ln,email,role,zone,status,emp_id:empId,phone,remarks,permissions};
  if(!editId) payload.password=password;
  const btn=document.getElementById('slSubmit');
  btn.disabled=true; btn.innerHTML='<i class="bx bx-loader-alt bx-spin"></i> Saving…';
  let r;
  if(editId){
    r=await api(`?api=1&route=users/${editId}`,'PUT',payload);
  } else {
    r=await api('?api=1&route=users','POST',payload);
  }
  btn.disabled=false; btn.innerHTML='<i class="bx bx-save"></i> Save User';
  if(r.success){ toast(editId?`${editId} updated.`:`User created.`,'s'); closeSlider(); await refresh(); }
  else toast(r.message,'d');
});

/* ── REFRESH ALL ── */
async function refresh(){
  await Promise.all([loadStats(), loadUsers()]);
}

/* ── INIT ── */
refresh();
</script>
</body>
</html>