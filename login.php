<?php
// ============================================================
// MicroFinancial Management System — Login Page
// index.php
// ============================================================

session_start();

// ── Config ──────────────────────────────────────────────────
define('SUPABASE_URL', 'https://fnpxtquhvlflyjibuwlx.supabase.co');
define('SUPABASE_ANON_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZucHh0cXVodmxmbHlqaWJ1d2x4Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM0NTE1OTMsImV4cCI6MjA4OTAyNzU5M30.KZaOgxA4hPEYpfunOg1HGyjKSb5lXNlUHWNXdYJqHdE');
define('SUPABASE_SERVICE_ROLE_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZucHh0cXVodmxmbHlqaWJ1d2x4Iiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc3MzQ1MTU5MywiZXhwIjoyMDg5MDI3NTkzfQ.oEVViZBSr-WFCLmBwazQLGvPNg8M0IByN4Iz5vCIym0');
define('PG_DSN', 'pgsql:host=aws-1-ap-northeast-1.pooler.supabase.com;port=5432;dbname=postgres;sslmode=require');
define('PG_DB_USER', 'postgres.fnpxtquhvlflyjibuwlx');
define('PG_DB_PASSWORD', '0ltvCJjD0CkZoBpX');
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'noreply.microfinancial@gmail.com');
define('SMTP_PASS', 'dpjdwwlopkzdyfnk');
define('SMTP_PORT', 587);
define('MAIL_FROM_NAME', 'MicroFinancial');

function load_phpmailer() {
    $paths = [__DIR__ . '/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'];
    foreach ($paths as $p) { if (file_exists($p)) { require_once $p; return true; } }
    return false;
}

function send_email(string $to, string $subject, string $html_body): bool {
    if (!load_phpmailer()) return false;
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP(); $mail->Host = SMTP_HOST; $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER; $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT; $mail->setFrom(SMTP_USER, MAIL_FROM_NAME);
        $mail->addAddress($to); $mail->isHTML(true);
        $mail->Subject = $subject; $mail->Body = $html_body;
        $mail->send(); return true;
    } catch (Exception $e) { error_log('PHPMailer error: ' . $e->getMessage()); return false; }
}

function otp_email_html(string $otp, string $name): string {
    return <<<HTML
<!DOCTYPE html><html><body style="font-family:'DM Sans',Arial,sans-serif;background:#f0f7f2;padding:40px 0;">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(30,58,42,.1)">
  <div style="background:#1e3a2a;padding:28px 36px;">
    <div style="color:#7ab894;font-size:11px;letter-spacing:3px;text-transform:uppercase;margin-bottom:6px">MicroFinancial</div>
    <div style="color:#fff;font-size:20px;font-weight:600">Verification Code</div>
  </div>
  <div style="padding:32px 36px;">
    <p style="color:#374151;font-size:14px;margin:0 0 20px">Hello, <strong>{$name}</strong>. Use the code below to sign in:</p>
    <div style="background:#f0f7f2;border:1.5px solid #b8ddc8;border-radius:8px;padding:20px;text-align:center;margin-bottom:20px;">
      <div style="font-size:36px;font-weight:700;letter-spacing:10px;color:#1e3a2a;font-family:monospace">{$otp}</div>
      <div style="color:#6b7280;font-size:12px;margin-top:6px">Valid for <strong>5 minutes</strong></div>
    </div>
    <p style="color:#9ca3af;font-size:12px;margin:0">If you didn't request this, please ignore this email.</p>
  </div>
  <div style="background:#f8faf9;padding:14px 36px;border-top:1px solid #e8f0eb;text-align:center;color:#9ca3af;font-size:11px;">&copy; 2025 MicroFinancial Management System</div>
</div></body></html>
HTML;
}

function temp_pass_email_html(string $tmp_pass, string $name): string {
    return <<<HTML
<!DOCTYPE html><html><body style="font-family:'DM Sans',Arial,sans-serif;background:#f0f7f2;padding:40px 0;">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(30,58,42,.1)">
  <div style="background:#1e3a2a;padding:28px 36px;">
    <div style="color:#7ab894;font-size:11px;letter-spacing:3px;text-transform:uppercase;margin-bottom:6px">MicroFinancial</div>
    <div style="color:#fff;font-size:20px;font-weight:600">Password Reset</div>
  </div>
  <div style="padding:32px 36px;">
    <p style="color:#374151;font-size:14px;margin:0 0 20px">Hello, <strong>{$name}</strong>. Your temporary password is:</p>
    <div style="background:#f0f7f2;border:1.5px solid #b8ddc8;border-radius:8px;padding:18px;text-align:center;margin-bottom:20px;">
      <div style="font-size:22px;font-weight:700;letter-spacing:3px;color:#1e3a2a;font-family:monospace">{$tmp_pass}</div>
      <div style="color:#6b7280;font-size:12px;margin-top:6px">Please change this after logging in</div>
    </div>
    <p style="color:#9ca3af;font-size:12px;margin:0">If you didn't request this, contact your administrator immediately.</p>
  </div>
  <div style="background:#f8faf9;padding:14px 36px;border-top:1px solid #e8f0eb;text-align:center;color:#9ca3af;font-size:11px;">&copy; 2025 MicroFinancial Management System</div>
</div></body></html>
HTML;
}

function supabase_sign_in(string $email, string $password): array {
    $url = SUPABASE_URL . '/auth/v1/token?grant_type=password';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . SUPABASE_ANON_KEY],
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $password])]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'data' => json_decode($res, true) ?? []];
}

function fetch_user_record(string $auth_id): ?array {
    try {
        $pdo = new PDO(PG_DSN, PG_DB_USER, PG_DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->prepare("SELECT u.user_id, u.first_name, u.last_name, u.email, u.status,
                               ARRAY_AGG(r.name) FILTER (WHERE r.name IS NOT NULL) AS roles
                               FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.user_id
                               LEFT JOIN roles r ON r.id = ur.role_id WHERE u.auth_id = :aid
                               GROUP BY u.user_id, u.first_name, u.last_name, u.email, u.status");
        $stmt->execute([':aid' => $auth_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $roles_raw = trim($row['roles'] ?? '{}', '{}');
        $row['roles'] = $roles_raw ? array_map(fn($r) => trim($r, " \""), explode(',', $roles_raw)) : [];
        return $row;
    } catch (Exception $e) { error_log('DB error: ' . $e->getMessage()); return null; }
}

function role_to_dashboard(array $roles): string {
    if (in_array('Super Admin', $roles)) return '/superadmin_dashboard.php';
    if (in_array('Admin', $roles))       return '/admin_dashboard.php';
    if (in_array('Manager', $roles))     return '/manager_dashboard.php';
    return '/user_dashboard.php';
}

function generate_otp(): string { return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); }
function generate_temp_password(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$';
    $pass = '';
    for ($i = 0; $i < 10; $i++) $pass .= $chars[random_int(0, strlen($chars) - 1)];
    return $pass;
}

$action = $_POST['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');

    if ($action === 'login') {
        $email = trim($_POST['email'] ?? ''); $password = trim($_POST['password'] ?? '');
        if (!$email || !$password) { echo json_encode(['ok' => false, 'msg' => 'Email and password are required.']); exit; }
        try {
            $pdo = new PDO(PG_DSN, PG_DB_USER, PG_DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $stmt = $pdo->prepare("SELECT user_id, status, failed_attempts FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($dbUser && $dbUser['status'] !== 'Active') {
                echo json_encode(['ok' => false, 'msg' => 'Your account is ' . strtolower($dbUser['status']) . '. Contact your administrator.']);
                exit;
            }
        } catch (Exception $e) { $dbUser = null; }

        $result = supabase_sign_in($email, $password);
        if ($result['code'] !== 200 || empty($result['data']['access_token'])) {
            if (!empty($dbUser) && $dbUser['status'] === 'Active') {
                $attempts = ($dbUser['failed_attempts'] ?? 0) + 1;
                try {
                    if ($attempts >= 3) {
                        $upd = $pdo->prepare("UPDATE users SET failed_attempts = :attempts, status = 'Suspended' WHERE user_id = :uid");
                        $upd->execute([':attempts' => $attempts, ':uid' => $dbUser['user_id']]);
                        echo json_encode(['ok' => false, 'msg' => 'Your account has been suspended due to 3 failed login attempts. Contact your administrator.']);
                        exit;
                    } else {
                        $upd = $pdo->prepare("UPDATE users SET failed_attempts = :attempts WHERE user_id = :uid");
                        $upd->execute([':attempts' => $attempts, ':uid' => $dbUser['user_id']]);
                    }
                } catch (Exception $e) {}
            }
            echo json_encode(['ok' => false, 'msg' => 'Invalid email or password.']); exit;
        }

        if (!empty($dbUser) && ($dbUser['failed_attempts'] ?? 0) > 0) {
            try {
                $upd = $pdo->prepare("UPDATE users SET failed_attempts = 0 WHERE user_id = :uid");
                $upd->execute([':uid' => $dbUser['user_id']]);
            } catch (Exception $e) {}
        }

        $auth_id = $result['data']['user']['id'] ?? '';
        $user = fetch_user_record($auth_id);
        if (!$user) { echo json_encode(['ok' => false, 'msg' => 'Account not found. Contact your administrator.']); exit; }
        if ($user['status'] !== 'Active') { echo json_encode(['ok' => false, 'msg' => 'Your account is ' . strtolower($user['status']) . '. Contact your administrator.']); exit; }
        $otp = generate_otp();
        $_SESSION['otp_code'] = $otp; $_SESSION['otp_expires'] = time() + 300;
        $_SESSION['otp_user'] = $user; $_SESSION['otp_access_tok'] = $result['data']['access_token'];
        $_SESSION['otp_email'] = $email;
        $sent = send_email($email, 'Your MicroFinancial Verification Code', otp_email_html($otp, $user['first_name']));
        echo json_encode(['ok' => true, 'email_sent' => $sent, 'email_hint' => substr($email, 0, 3) . '***@' . explode('@', $email)[1]]);
        exit;
    }

    if ($action === 'verify_otp') {
        $entered = trim($_POST['otp'] ?? '');
        if (empty($_SESSION['otp_code'])) { echo json_encode(['ok' => false, 'msg' => 'Session expired. Please log in again.']); exit; }
        if (time() > ($_SESSION['otp_expires'] ?? 0)) { unset($_SESSION['otp_code'], $_SESSION['otp_expires']); echo json_encode(['ok' => false, 'msg' => 'OTP expired. Please request a new one.']); exit; }
        if ($entered !== $_SESSION['otp_code']) { echo json_encode(['ok' => false, 'msg' => 'Incorrect code. Please try again.']); exit; }
        $user = $_SESSION['otp_user'];
        $_SESSION['user_id'] = $user['user_id']; $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_email'] = $user['email']; $_SESSION['user_roles'] = $user['roles'];
        $_SESSION['roles'] = $user['roles']; $_SESSION['role'] = empty($user['roles']) ? 'Staff' : implode(',', $user['roles']);
        $_SESSION['logged_in'] = true;
        unset($_SESSION['otp_code'], $_SESSION['otp_expires'], $_SESSION['otp_user'], $_SESSION['otp_access_tok'], $_SESSION['otp_email']);
        echo json_encode(['ok' => true, 'redirect' => role_to_dashboard($user['roles'])]);
        exit;
    }

    if ($action === 'resend_otp') {
        if (empty($_SESSION['otp_user'])) { echo json_encode(['ok' => false, 'msg' => 'Session lost. Please log in again.']); exit; }
        $otp = generate_otp(); $_SESSION['otp_code'] = $otp; $_SESSION['otp_expires'] = time() + 300;
        $email = $_SESSION['otp_email']; $user = $_SESSION['otp_user'];
        $sent = send_email($email, 'Your MicroFinancial Verification Code', otp_email_html($otp, $user['first_name']));
        echo json_encode(['ok' => true, 'email_sent' => $sent]);
        exit;
    }

    if ($action === 'forgot_password') {
        $email = trim($_POST['email'] ?? '');
        if (!$email) { echo json_encode(['ok' => false, 'msg' => 'Please enter your email address.']); exit; }
        try {
            $pdo = new PDO(PG_DSN, PG_DB_USER, PG_DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $stmt = $pdo->prepare("SELECT user_id, first_name, status FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { echo json_encode(['ok' => false, 'msg' => 'Database error. Please try again.']); exit; }
        if (!$row || $row['status'] !== 'Active') { echo json_encode(['ok' => true, 'msg' => 'If that email is registered, a temporary password has been sent.']); exit; }
        $tmp = generate_temp_password();
        $stmt2 = $pdo->prepare("SELECT auth_id FROM users WHERE email = :email LIMIT 1");
        $stmt2->execute([':email' => $email]); $auth_row = $stmt2->fetch(PDO::FETCH_ASSOC);
        $auth_id = $auth_row['auth_id'] ?? null; $updated = false;
        if ($auth_id) {
            $ch = curl_init(SUPABASE_URL . '/auth/v1/admin/users/' . $auth_id);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . SUPABASE_SERVICE_ROLE_KEY, 'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY],
                CURLOPT_POSTFIELDS => json_encode(['password' => $tmp])]);
            $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            $updated = ($code === 200);
        }
        if ($updated) send_email($email, 'Your MicroFinancial Temporary Password', temp_pass_email_html($tmp, $row['first_name']));
        echo json_encode(['ok' => true, 'msg' => 'If that email is registered, a temporary password has been sent.']);
        exit;
    }
    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']); exit;
}

if (!empty($_SESSION['logged_in'])) { header('Location: ' . role_to_dashboard($_SESSION['user_roles'] ?? [])); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — MicroFinancial Management System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
<style>
/* ── Reset ─────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* ── Design Tokens — exact match to dashboard ──────────────── */
:root {
    /* Sidebar greens */
    --sidebar-bg:       #1a3328;
    --sidebar-bg2:      #1e3a2e;
    --sidebar-active:   #2a4a38;
    --sidebar-border:   rgba(255,255,255,.07);
    --sidebar-text:     #e8f3ec;
    --sidebar-muted:    #7aaa8a;
    --sidebar-label:    #4d7a5e;

    /* Content area */
    --page-bg:          #f2f7f4;
    --card-bg:          #ffffff;
    --card-border:      #ddeee5;
    --card-shadow:      0 1px 3px rgba(30,58,42,.06), 0 1px 2px rgba(30,58,42,.04);

    /* Green palette */
    --green-50:         #f0f7f2;
    --green-100:        #ddeee5;
    --green-200:        #b8ddc8;
    --green-300:        #7ab894;
    --green-400:        #4a9966;
    --green-500:        #2d7a47;   /* primary button */
    --green-600:        #1e5c34;
    --green-700:        #1a3328;

    /* Text */
    --text-primary:     #0f1f16;
    --text-secondary:   #4a6355;
    --text-muted:       #7a9485;
    --text-label:       #3d6b50;   /* section labels — uppercase */

    /* Inputs */
    --input-bg:         #f8fbf9;
    --input-border:     #c8ddd0;
    --input-focus:      #2d7a47;
    --input-radius:     8px;

    /* Status */
    --err:              #dc2626;
    --err-bg:           #fef2f2;
    --err-border:       #fca5a5;
    --warn-bg:          #fffbeb;
    --warn-border:      #fde68a;
    --warn-text:        #92400e;
    --info-bg:          #f0f7f2;

    --radius-sm:        6px;
    --radius-md:        8px;
    --radius-lg:        12px;
    --radius-xl:        16px;
}

html, body {
    height: 100%;
    font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--page-bg);
    color: var(--text-primary);
    -webkit-font-smoothing: antialiased;
    font-size: 14px;
}

/* ══════════════════════════════════════════════════════════
   LAYOUT — Centered single column
══════════════════════════════════════════════════════════ */
.page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* ── Sidebar hidden ─── */
.sidebar {
    display: none;
}
.sidebar-UNUSED {
    background: var(--sidebar-bg);
    display: flex;
    flex-direction: column;
    padding: 0;
    position: relative;
    overflow: hidden;
}

/* Subtle texture overlay */
.sidebar::before {
    content: '';
    position: absolute;
    inset: 0;
    background: repeating-linear-gradient(
        0deg,
        transparent,
        transparent 47px,
        rgba(255,255,255,.025) 47px,
        rgba(255,255,255,.025) 48px
    );
    pointer-events: none;
}

.sidebar-top {
    padding: 28px 24px 20px;
    border-bottom: 1px solid var(--sidebar-border);
    position: relative;
}

.brand {
    display: flex;
    align-items: center;
    gap: 11px;
    margin-bottom: 0;
}

.brand-icon {
    width: 34px; height: 34px;
    background: linear-gradient(145deg, var(--green-400), var(--green-500));
    border-radius: var(--radius-md);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(0,0,0,.25);
}

.brand-icon svg { width: 16px; height: 16px; fill: white; }

.brand-text { flex: 1; }
.brand-name {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: var(--sidebar-text);
    letter-spacing: -.2px;
    line-height: 1.2;
}
.brand-sub {
    display: block;
    font-size: 11px;
    color: var(--sidebar-muted);
    margin-top: 1px;
}

.sidebar-section-label {
    padding: 20px 24px 8px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--sidebar-label);
    display: flex;
    align-items: center;
    gap: 8px;
    position: relative;
}

.sidebar-section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--sidebar-border);
}

.sidebar-section-label .dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--green-400);
    flex-shrink: 0;
}

.sidebar-nav { padding: 8px 12px; flex: 1; }

.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: var(--radius-md);
    cursor: default;
    margin-bottom: 2px;
    transition: background .15s;
    position: relative;
}

.nav-item:hover { background: rgba(255,255,255,.05); }

.nav-item.active {
    background: var(--sidebar-active);
}

.nav-item.active::before {
    content: '';
    position: absolute;
    left: 0; top: 8px; bottom: 8px;
    width: 3px;
    background: var(--green-400);
    border-radius: 0 2px 2px 0;
}

.nav-icon {
    width: 30px; height: 30px;
    border-radius: var(--radius-sm);
    background: rgba(255,255,255,.06);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.nav-item.active .nav-icon {
    background: rgba(74,153,102,.25);
}

.nav-icon svg {
    width: 14px; height: 14px;
    stroke: var(--sidebar-muted);
    fill: none;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.nav-item.active .nav-icon svg { stroke: var(--green-300); }

.nav-text { flex: 1; }
.nav-label { display: block; font-size: 13px; font-weight: 500; color: var(--sidebar-text); opacity: .85; line-height: 1.2; }
.nav-sub   { display: block; font-size: 11px; color: var(--sidebar-muted); margin-top: 1px; }
.nav-item.active .nav-label { opacity: 1; }

.sidebar-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--sidebar-border);
    position: relative;
}

.footer-status {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    color: var(--sidebar-muted);
}

.status-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--green-400);
    box-shadow: 0 0 0 2px rgba(74,153,102,.25);
}

/* ── Main Content ───────────────────────────────────────── */
.main {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 24px;
    min-height: 100vh;
    width: 100%;
    background: var(--page-bg);
    position: relative;
}

/* Subtle dot grid */
.main::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: radial-gradient(circle, rgba(45,122,71,.08) 1px, transparent 1px);
    background-size: 28px 28px;
    pointer-events: none;
}

.login-wrap {
    width: 100%;
    max-width: 420px;
    position: relative;
    z-index: 1;
}

/* ── Top breadcrumb ─── */
.login-breadcrumb {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--text-label);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.login-breadcrumb span { color: var(--text-muted); }

/* ── Login Card ─── */
.card {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--radius-xl);
    box-shadow: 0 4px 24px rgba(30,58,42,.08), 0 1px 4px rgba(30,58,42,.04);
    overflow: hidden;
    animation: cardIn .4s cubic-bezier(.22,1,.36,1) both;
}

/* Card header strip — matches dashboard page header style */
.card-header {
    padding: 24px 28px 20px;
    border-bottom: 1px solid var(--green-100);
    background: linear-gradient(180deg, #f8fbf9 0%, #ffffff 100%);
}

.card-header-top {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 6px;
}

.card-logo {
    width: 36px; height: 36px;
    background: var(--green-700);
    border-radius: var(--radius-md);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.card-logo svg { width: 16px; height: 16px; stroke: var(--green-300); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.card-header h1 {
    font-size: 20px;
    font-weight: 600;
    color: var(--text-primary);
    letter-spacing: -.3px;
    line-height: 1.2;
}

.card-header p {
    font-size: 12.5px;
    color: var(--text-muted);
    margin-left: 48px;
}

/* Card body */
.card-body { padding: 24px 28px 28px; }

/* ── Messages ─── */
.msg {
    display: none;
    padding: 9px 12px;
    border-radius: var(--radius-md);
    font-size: 12.5px;
    line-height: 1.45;
    margin-bottom: 16px;
    border: 1px solid;
}
.msg.err  { background: var(--err-bg);  border-color: var(--err-border); color: #b91c1c; }
.msg.info { background: var(--info-bg); border-color: var(--green-200);  color: var(--green-600); }
.msg.warn { background: var(--warn-bg); border-color: var(--warn-border); color: var(--warn-text); }

/* ── Form Groups ─── */
.form-group { margin-bottom: 16px; }

.form-group label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--text-label);
    margin-bottom: 6px;
}

.input-wrap {
    position: relative;
    display: flex;
    align-items: center;
}

.input-wrap .field-icon {
    position: absolute;
    left: 11px;
    width: 14px; height: 14px;
    stroke: var(--green-300);
    fill: none;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
    pointer-events: none;
    z-index: 1;
}

.form-control {
    width: 100%;
    height: 40px;
    padding: 0 12px 0 36px;
    border: 1.5px solid var(--input-border);
    border-radius: var(--input-radius);
    font-family: 'DM Sans', sans-serif;
    font-size: 13.5px;
    color: var(--text-primary);
    background: var(--input-bg);
    transition: border-color .15s, box-shadow .15s, background .15s;
    outline: none;
}

.form-control:focus {
    border-color: var(--input-focus);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(45,122,71,.1);
}

.form-control::placeholder { color: #aabfb2; }

.pw-toggle {
    position: absolute;
    right: 0; top: 0; bottom: 0;
    width: 38px;
    background: none;
    border: none;
    border-left: 1px solid var(--input-border);
    border-radius: 0 var(--input-radius) var(--input-radius) 0;
    cursor: pointer;
    color: var(--text-muted);
    display: flex; align-items: center; justify-content: center;
    transition: color .15s, background .15s;
}
.pw-toggle:hover { color: var(--green-500); background: var(--green-50); }
.pw-toggle svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
#loginPassword { padding-right: 42px; }

/* ── Form row (remember + forgot) ─── */
.form-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    margin-top: -4px;
}

.remember-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12.5px;
    color: var(--text-secondary);
    cursor: pointer;
    user-select: none;
}

.remember-label input[type="checkbox"] {
    width: 14px; height: 14px;
    accent-color: var(--green-500);
    cursor: pointer;
}

.btn-link {
    background: none;
    border: none;
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    font-size: 12.5px;
    color: var(--green-500);
    font-weight: 500;
    padding: 0;
    transition: color .15s;
    text-decoration: none;
}
.btn-link:hover { color: var(--green-600); text-decoration: underline; text-underline-offset: 2px; }
.btn-link:disabled { opacity: .45; cursor: not-allowed; text-decoration: none; }

/* ── Primary Button — matches "Generate Report" / "Apply" ─── */
.btn-primary {
    width: 100%;
    height: 40px;
    background: var(--green-500);
    border: none;
    border-radius: var(--input-radius);
    font-family: 'DM Sans', sans-serif;
    font-size: 13.5px;
    font-weight: 600;
    color: #fff;
    cursor: pointer;
    transition: background .15s, box-shadow .15s, transform .1s;
    position: relative;
    overflow: hidden;
    letter-spacing: .15px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
}

.btn-primary:hover:not(:disabled) {
    background: var(--green-600);
    box-shadow: 0 3px 10px rgba(30,92,52,.25);
}

.btn-primary:active:not(:disabled) { transform: scale(.99); }
.btn-primary:disabled { opacity: .55; cursor: not-allowed; transform: none; }

.btn-primary svg { width: 14px; height: 14px; stroke: white; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.btn-primary .spinner {
    display: none;
    width: 15px; height: 15px;
    border: 2px solid rgba(255,255,255,.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin .65s linear infinite;
}

.btn-primary.loading .btn-text { display: none; }
.btn-primary.loading svg { display: none; }
.btn-primary.loading .spinner  { display: block; }

/* Divider */
.divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 18px 0;
    font-size: 11.5px;
    color: var(--text-muted);
}
.divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--card-border); }

/* ── Panels ─── */
.panel { display: none; }
.panel.active { display: block; animation: fadeIn .25s ease; }

/* ── OTP screen ─── */
.otp-hint {
    text-align: center;
    font-size: 13px;
    color: var(--text-secondary);
    margin-bottom: 20px;
    line-height: 1.55;
}
.otp-hint strong { color: var(--text-primary); }

.otp-boxes {
    display: flex;
    gap: 7px;
    justify-content: center;
    margin-bottom: 16px;
}

.otp-box {
    width: 50px; height: 56px;
    border: 1.5px solid var(--input-border);
    border-radius: var(--radius-md);
    font-size: 22px;
    font-weight: 700;
    text-align: center;
    color: var(--green-600);
    background: var(--input-bg);
    outline: none;
    transition: border-color .15s, box-shadow .15s, background .15s;
    font-family: 'DM Sans', monospace;
    caret-color: var(--green-500);
}

.otp-box:focus {
    border-color: var(--input-focus);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(45,122,71,.1);
}

.otp-box.filled {
    background: var(--green-50);
    border-color: var(--green-300);
}

.timer-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-bottom: 16px;
    font-size: 12.5px;
    color: var(--text-muted);
}

.timer-badge {
    font-weight: 700;
    font-size: 12.5px;
    color: var(--green-600);
    background: var(--green-50);
    border: 1px solid var(--green-200);
    border-radius: var(--radius-sm);
    padding: 2px 8px;
    font-family: monospace;
    min-width: 46px;
    text-align: center;
}

.timer-badge.urgent { color: var(--err); background: var(--err-bg); border-color: var(--err-border); }

.timer-icon { width: 13px; height: 13px; stroke: var(--green-400); fill: none; stroke-width: 1.8; }

/* ── Info chips below card (role pills) ─── */
.role-strip {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 16px;
    justify-content: center;
}

.role-chip {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: 20px;
    font-size: 11px;
    font-weight: 500;
    color: var(--text-secondary);
    box-shadow: var(--card-shadow);
}

.role-chip-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
}

/* ── Animations ─── */
@keyframes fadeIn  { from { opacity: 0; }                       to { opacity: 1; } }
@keyframes cardIn  { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
@keyframes spin    { to { transform: rotate(360deg); } }
@keyframes shake   {
    0%,100% { transform: translateX(0); }
    20%,60% { transform: translateX(-5px); }
    40%,80% { transform: translateX(5px); }
}
.shake { animation: shake .35s ease; }

/* ── Responsive ─── */
@media (max-width: 900px) {
    .page { grid-template-columns: 1fr; }
    .sidebar { display: none; }
    .main { padding: 24px 16px; }
}

@media (max-width: 480px) {
    .card-body { padding: 20px; }
    .card-header { padding: 18px 20px 16px; }
    .otp-box { width: 42px; height: 50px; font-size: 20px; }
}
</style>
</head>
<body>
<div class="page">


  <!-- ══ MAIN CONTENT ═════════════════════════════════════════ -->
  <main class="main">
    <div class="login-wrap">

      <div class="login-breadcrumb">
        System &nbsp;<span>›</span>&nbsp; Authentication
      </div>

      <div class="card">
        <!-- Card Header -->
        <div class="card-header">
          <div class="card-header-top">
            <div class="card-logo">
              <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <h1 id="cardTitle">Sign In</h1>
          </div>
          <p id="cardSubtitle">MicroFinancial Management System — enter your credentials to continue</p>
        </div>

        <!-- Card Body -->
        <div class="card-body">

          <!-- Panel: Login -->
          <div class="panel active" id="panelLogin">
            <div class="msg" id="loginMsg"></div>

            <div class="form-group">
              <label>Email Address</label>
              <div class="input-wrap">
                <svg class="field-icon" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <input type="email" class="form-control" id="loginEmail" placeholder="you@company.com" autocomplete="email">
              </div>
            </div>

            <div class="form-group">
              <label>Password</label>
              <div class="input-wrap">
                <svg class="field-icon" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <input type="password" class="form-control" id="loginPassword" placeholder="••••••••" autocomplete="current-password">
                <button type="button" class="pw-toggle" id="pwToggle" title="Toggle visibility">
                  <svg id="eyeIcon" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>

            <div class="form-footer">
              <label class="remember-label">
                <input type="checkbox" id="rememberMe"> Remember me
              </label>
              <button type="button" class="btn-link" id="btnForgot">Forgot password?</button>
            </div>

            <button class="btn-primary" id="btnLogin">
              <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
              <span class="btn-text">Sign In</span>
              <div class="spinner"></div>
            </button>
          </div>

          <!-- Panel: OTP -->
          <div class="panel" id="panelOtp">
            <div class="msg" id="otpMsg"></div>

            <div class="otp-hint">
              A 6-digit verification code was sent to<br><strong id="otpEmailHint"></strong>
            </div>

            <div class="otp-boxes">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1" id="otp0">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1" id="otp1">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1" id="otp2">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1" id="otp3">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1" id="otp4">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1" id="otp5">
            </div>

            <div class="timer-row">
              <svg class="timer-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              Code expires in <span class="timer-badge" id="timerBadge">5:00</span>
            </div>

            <button class="btn-primary" id="btnVerify">
              <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
              <span class="btn-text">Verify &amp; Continue</span>
              <div class="spinner"></div>
            </button>

            <div class="divider">or</div>

            <div style="display:flex;align-items:center;justify-content:space-between;font-size:12.5px;color:var(--text-muted)">
              <span>Didn't receive the code?</span>
              <button type="button" class="btn-link" id="btnResend" disabled>Resend code</button>
            </div>

            <div style="text-align:center;margin-top:14px">
              <button type="button" class="btn-link" id="btnBackLogin">← Back to sign in</button>
            </div>
          </div>

          <!-- Panel: Forgot Password -->
          <div class="panel" id="panelForgot">
            <div class="msg" id="forgotMsg"></div>

            <p style="font-size:12.5px;color:var(--text-secondary);margin-bottom:16px;line-height:1.6">
              Enter your registered email address and we'll send a temporary password.
            </p>

            <div class="form-group">
              <label>Email Address</label>
              <div class="input-wrap">
                <svg class="field-icon" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <input type="email" class="form-control" id="forgotEmail" placeholder="you@company.com">
              </div>
            </div>

            <button class="btn-primary" id="btnSendReset" style="margin-bottom:14px">
              <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              <span class="btn-text">Send Temporary Password</span>
              <div class="spinner"></div>
            </button>

            <div style="text-align:center">
              <button type="button" class="btn-link" id="btnBackLogin2">← Back to sign in</button>
            </div>
          </div>

        </div><!-- /card-body -->
      </div><!-- /card -->

      <!-- Role chips below card -->
      <div class="role-strip">
        <div class="role-chip"><div class="role-chip-dot" style="background:#1e3a2a"></div>Super Admin</div>
        <div class="role-chip"><div class="role-chip-dot" style="background:#2d7a47"></div>Admin</div>
        <div class="role-chip"><div class="role-chip-dot" style="background:#4a9966"></div>Manager</div>
        <div class="role-chip"><div class="role-chip-dot" style="background:#7ab894"></div>Staff</div>
      </div>

    </div><!-- /login-wrap -->
  </main>

</div><!-- /page -->

<script>
function showPanel(id) {
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.getElementById(id).classList.add('active');
}
function showMsg(elId, text, type = 'err') {
    const el = document.getElementById(elId);
    el.className = 'msg ' + type;
    el.textContent = text;
    el.style.display = 'block';
}
function hideMsg(elId) { document.getElementById(elId).style.display = 'none'; }
function setLoading(btn, on) { btn.disabled = on; btn.classList.toggle('loading', on); }

async function post(data) {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    const res = await fetch(window.location.href, { method: 'POST', body: fd });
    return res.json();
}

// ── Login ────────────────────────────────────────────────────
const btnLogin    = document.getElementById('btnLogin');
const loginEmail  = document.getElementById('loginEmail');
const loginPw     = document.getElementById('loginPassword');

document.getElementById('pwToggle').addEventListener('click', () => {
    const isText = loginPw.type === 'text';
    loginPw.type = isText ? 'password' : 'text';
    const icon = document.getElementById('eyeIcon');
    icon.innerHTML = isText
        ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
        : '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
});

[loginEmail, loginPw].forEach(el => el.addEventListener('keydown', e => { if (e.key === 'Enter') btnLogin.click(); }));

btnLogin.addEventListener('click', async () => {
    hideMsg('loginMsg');
    const email = loginEmail.value.trim(), pass = loginPw.value;
    if (!email || !pass) { showMsg('loginMsg', 'Please enter your email and password.'); return; }
    setLoading(btnLogin, true);
    try {
        const res = await post({ action: 'login', email, password: pass });
        if (!res.ok) {
            showMsg('loginMsg', res.msg);
            document.querySelector('.card').classList.add('shake');
            setTimeout(() => document.querySelector('.card').classList.remove('shake'), 400);
        } else {
            document.getElementById('otpEmailHint').textContent = res.email_hint;
            if (!res.email_sent) showMsg('otpMsg', 'Email delivery failed. Code generated but not sent — contact your admin.', 'warn');
            document.getElementById('cardTitle').textContent = 'Verify Identity';
            document.getElementById('cardSubtitle').textContent = 'Enter the 6-digit code sent to your email';
            showPanel('panelOtp');
            startTimer();
            setTimeout(() => document.getElementById('otp0').focus(), 80);
        }
    } catch(e) { showMsg('loginMsg', 'Network error. Please try again.'); }
    setLoading(btnLogin, false);
});

// ── OTP ──────────────────────────────────────────────────────
let timerInterval = null, timerSeconds = 300, resendCooldown = null;

function startTimer() {
    timerSeconds = 300;
    clearInterval(timerInterval);
    updateTimer();
    timerInterval = setInterval(() => {
        timerSeconds--;
        updateTimer();
        if (timerSeconds <= 0) {
            clearInterval(timerInterval);
            showMsg('otpMsg', 'Your OTP has expired. Please request a new one.', 'warn');
        }
    }, 1000);
    const resendBtn = document.getElementById('btnResend');
    resendBtn.disabled = true;
    let cd = 30;
    resendBtn.textContent = `Resend (${cd}s)`;
    clearInterval(resendCooldown);
    resendCooldown = setInterval(() => {
        cd--;
        if (cd <= 0) { clearInterval(resendCooldown); resendBtn.disabled = false; resendBtn.textContent = 'Resend code'; }
        else resendBtn.textContent = `Resend (${cd}s)`;
    }, 1000);
}

function updateTimer() {
    const m = Math.floor(timerSeconds / 60), s = timerSeconds % 60;
    const badge = document.getElementById('timerBadge');
    badge.textContent = m + ':' + String(s).padStart(2, '0');
    badge.classList.toggle('urgent', timerSeconds <= 60);
}

const otpBoxes = Array.from({length: 6}, (_, i) => document.getElementById('otp' + i));

otpBoxes.forEach((box, i) => {
    box.addEventListener('input', e => {
        const val = e.target.value.replace(/\D/g, '');
        e.target.value = val;
        box.classList.toggle('filled', !!val);
        if (val && i < 5) otpBoxes[i + 1].focus();
        if (otpBoxes.every(b => b.value)) setTimeout(() => document.getElementById('btnVerify').click(), 80);
    });
    box.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !box.value && i > 0) { otpBoxes[i-1].focus(); otpBoxes[i-1].value = ''; otpBoxes[i-1].classList.remove('filled'); }
        if (e.key === 'ArrowLeft'  && i > 0) otpBoxes[i-1].focus();
        if (e.key === 'ArrowRight' && i < 5) otpBoxes[i+1].focus();
    });
    box.addEventListener('paste', e => {
        e.preventDefault();
        const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
        paste.split('').forEach((ch, j) => { if (otpBoxes[i+j]) { otpBoxes[i+j].value = ch; otpBoxes[i+j].classList.add('filled'); } });
        otpBoxes[Math.min(i + paste.length, 5)].focus();
        if (paste.length === 6) setTimeout(() => document.getElementById('btnVerify').click(), 80);
    });
});

const btnVerify = document.getElementById('btnVerify');
btnVerify.addEventListener('click', async () => {
    hideMsg('otpMsg');
    const otp = otpBoxes.map(b => b.value).join('');
    if (otp.length < 6) { showMsg('otpMsg', 'Please enter all 6 digits.'); return; }
    setLoading(btnVerify, true);
    try {
        const res = await post({ action: 'verify_otp', otp });
        if (!res.ok) {
            showMsg('otpMsg', res.msg);
            otpBoxes.forEach(b => { b.value = ''; b.classList.remove('filled'); });
            otpBoxes[0].focus();
            document.querySelector('.otp-boxes').classList.add('shake');
            setTimeout(() => document.querySelector('.otp-boxes').classList.remove('shake'), 400);
        } else {
            document.getElementById('cardTitle').textContent = 'Authenticated';
            document.getElementById('cardSubtitle').textContent = 'Redirecting to your dashboard…';
            clearInterval(timerInterval);
            setTimeout(() => { window.location.href = res.redirect; }, 1000);
        }
    } catch(e) { showMsg('otpMsg', 'Network error. Please try again.'); }
    setLoading(btnVerify, false);
});

document.getElementById('btnResend').addEventListener('click', async () => {
    hideMsg('otpMsg');
    const btn = document.getElementById('btnResend');
    btn.disabled = true;
    otpBoxes.forEach(b => { b.value = ''; b.classList.remove('filled'); });
    try {
        const res = await post({ action: 'resend_otp' });
        if (res.ok) { showMsg('otpMsg', 'A new code has been sent to your email.', 'info'); startTimer(); }
        else { showMsg('otpMsg', res.msg); btn.disabled = false; }
    } catch(e) { showMsg('otpMsg', 'Network error.'); btn.disabled = false; }
    setTimeout(() => otpBoxes[0].focus(), 80);
});

document.getElementById('btnBackLogin').addEventListener('click', () => {
    clearInterval(timerInterval); clearInterval(resendCooldown);
    otpBoxes.forEach(b => { b.value = ''; b.classList.remove('filled'); });
    hideMsg('otpMsg');
    document.getElementById('cardTitle').textContent = 'Sign In';
    document.getElementById('cardSubtitle').textContent = 'MicroFinancial Management System — enter your credentials to continue';
    showPanel('panelLogin');
});

// ── Forgot ───────────────────────────────────────────────────
document.getElementById('btnForgot').addEventListener('click', () => {
    document.getElementById('forgotEmail').value = loginEmail.value;
    hideMsg('forgotMsg');
    document.getElementById('cardTitle').textContent = 'Reset Password';
    document.getElementById('cardSubtitle').textContent = 'Receive a temporary password via email';
    showPanel('panelForgot');
});

document.getElementById('btnBackLogin2').addEventListener('click', () => {
    hideMsg('forgotMsg');
    document.getElementById('cardTitle').textContent = 'Sign In';
    document.getElementById('cardSubtitle').textContent = 'MicroFinancial Management System — enter your credentials to continue';
    showPanel('panelLogin');
});

const btnSendReset = document.getElementById('btnSendReset');
btnSendReset.addEventListener('click', async () => {
    hideMsg('forgotMsg');
    const email = document.getElementById('forgotEmail').value.trim();
    if (!email) { showMsg('forgotMsg', 'Please enter your email address.'); return; }
    setLoading(btnSendReset, true);
    try {
        const res = await post({ action: 'forgot_password', email });
        if (res.ok) {
            showMsg('forgotMsg', res.msg, 'info');
            btnSendReset.disabled = true;
            setTimeout(() => {
                document.getElementById('cardTitle').textContent = 'Sign In';
                document.getElementById('cardSubtitle').textContent = 'MicroFinancial Management System — enter your credentials to continue';
                showPanel('panelLogin');
                btnSendReset.disabled = false;
            }, 3000);
        } else { showMsg('forgotMsg', res.msg); }
    } catch(e) { showMsg('forgotMsg', 'Network error.'); }
    setLoading(btnSendReset, false);
});

window.history.pushState(null, '', window.location.href);
window.onpopstate = () => window.history.pushState(null, '', window.location.href);
</script>
</body>
</html>