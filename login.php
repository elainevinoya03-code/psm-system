<?php
// ============================================================
// MicroFinancial Management System — Login Page
// index.php
// ============================================================
session_start();

// ── Config (inlined — no require dependency risk) ────────────
if (!defined('SUPABASE_URL'))              define('SUPABASE_URL',              'https://fnpxtquhvlflyjibuwlx.supabase.co');
if (!defined('SUPABASE_ANON_KEY'))         define('SUPABASE_ANON_KEY',         'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZucHh0cXVodmxmbHlqaWJ1d2x4Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM0NTE1OTMsImV4cCI6MjA4OTAyNzU5M30.KZaOgxA4hPEYpfunOg1HGyjKSb5lXNlUHWNXdYJqHdE');
if (!defined('SUPABASE_SERVICE_ROLE_KEY')) define('SUPABASE_SERVICE_ROLE_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZucHh0cXVodmxmbHlqaWJ1d2x4Iiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc3MzQ1MTU5MywiZXhwIjoyMDg5MDI3NTkzfQ.oEVViZBSr-WFCLmBwazQLGvPNg8M0IByN4Iz5vCIym0');
if (!defined('SMTP_HOST'))      define('SMTP_HOST',      'smtp.gmail.com');
if (!defined('SMTP_USER'))      define('SMTP_USER',      'noreply.microfinancial@gmail.com');
if (!defined('SMTP_PASS'))      define('SMTP_PASS',      'dpjdwwlopkzdyfnk');
if (!defined('SMTP_PORT'))      define('SMTP_PORT',      587);
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'MicroFinancial');

// ── PHPMailer loader ─────────────────────────────────────────
function mf_load_phpmailer() {
    static $loaded = null;
    if ($loaded !== null) return $loaded;
    foreach ([
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
    ] as $p) {
        if (file_exists($p)) { require_once $p; $loaded = true; return true; }
    }
    $loaded = false; return false;
}

// ── Supabase cURL helper ─────────────────────────────────────
function sb_req($path, $method = 'GET', $body = null, $service = false, &$http_code = null) {
    $key = $service ? SUPABASE_SERVICE_ROLE_KEY : SUPABASE_ANON_KEY;
    $ch  = curl_init(SUPABASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: '               . $key,
            'Authorization: Bearer ' . $key,
        ],
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res       = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr      = curl_error($ch);
    curl_close($ch);
    if ($cerr) return ['_curl_error' => $cerr];
    $decoded = json_decode($res ?: '{}', true);
    return $decoded !== null ? $decoded : ['_parse_error' => $res];
}

// ── Role helpers ─────────────────────────────────────────────
function mf_resolve_role($roles) {
    // Handles both PG array string  {"Super Admin","Staff"}  and PHP arrays
    if (is_string($roles)) {
        if (strpos($roles, 'Super Admin') !== false) return 'Super Admin';
        if (strpos($roles, 'Admin')       !== false) return 'Admin';
        if (strpos($roles, 'Manager')     !== false) return 'Manager';
        return 'Staff';
    }
    if (is_array($roles)) {
        if (in_array('Super Admin', $roles, true)) return 'Super Admin';
        if (in_array('Admin',       $roles, true)) return 'Admin';
        if (in_array('Manager',     $roles, true)) return 'Manager';
    }
    return 'Staff';
}

function mf_dashboard($role) {
    switch ($role) {
        case 'Super Admin': return '/superadmin_dashboard.php';
        case 'Admin':       return '/admin_dashboard.php';
        case 'Manager':     return '/manager_dashboard.php';
        default:            return '/user_dashboard.php';
    }
}

// ── Already logged in ────────────────────────────────────────
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . mf_dashboard($_SESSION['role'] ?? 'Staff'));
    exit;
}

// ════════════════════════════════════════════════════════════
// POST handler — JSON body (matches reference exactly)
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) $input = [];

    $email    = trim($input['email']    ?? '');
    $password = trim($input['password'] ?? '');

    // ── FORGOT PASSWORD ──────────────────────────────────────
    if (!empty($input['forgot'])) {
        if (!$email) {
            echo json_encode(['success' => false, 'message' => 'Enter your email address first.']);
            exit;
        }

        $c = null;
        $users = sb_req('/rest/v1/users_with_roles?email=eq.' . urlencode($email) . '&select=*', 'GET', null, true, $c);

        if (empty($users) || !isset($users[0])) {
            echo json_encode(['success' => true, 'message' => 'If this email exists, a temporary password has been sent.']);
            exit;
        }

        $user    = $users[0];
        $auth_id = $user['auth_id'] ?? null;
        $tmp     = bin2hex(random_bytes(4));

        // Email first
        if (mf_load_phpmailer()) {
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = SMTP_PORT;
                $mail->setFrom(SMTP_USER, MAIL_FROM_NAME);
                $mail->addAddress($email);
                $mail->isHTML(true);
                $mail->Subject = 'Temporary Password — MicroFinancial';
                $mail->Body    = '
<div style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;border:1px solid #e2e8f0;border-radius:12px">
  <div style="margin-bottom:20px"><span style="display:inline-block;background:#1a4f28;color:#fff;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:5px 14px;border-radius:20px">MicroFinancial</span></div>
  <h2 style="margin:0 0 8px;color:#1a1a18;font-size:20px;font-weight:600">Password Reset</h2>
  <p style="margin:0 0 20px;color:#6b6b64;font-size:13px;line-height:1.6">A temporary password has been generated. Sign in then change it immediately.</p>
  <div style="background:#f0faf2;border:1px solid #aee6bc;border-radius:10px;padding:18px 24px;text-align:center;margin-bottom:20px">
    <p style="margin:0 0 6px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#2a7d3f">Temporary Password</p>
    <p style="margin:0;font-size:26px;font-weight:700;letter-spacing:.2em;color:#1a4f28;font-family:monospace">' . htmlspecialchars($tmp) . '</p>
  </div>
  <p style="margin:0;color:#c0c0bb;font-size:11px">&copy; ' . date('Y') . ' MicroFinancial &middot; Do not reply to this email.</p>
</div>';
                $mail->AltBody = 'Your temporary password: ' . $tmp;
                $mail->send();
            } catch (Exception $e) {
                error_log('PHPMailer forgot: ' . $e->getMessage());
            }
        }

        // Then update Supabase Auth
        if ($auth_id) {
            $uc = null;
            sb_req('/auth/v1/admin/users/' . $auth_id, 'PUT',
                ['password' => $tmp, 'email_confirm' => true], true, $uc);
            error_log('Supabase pw reset HTTP: ' . $uc);
        }

        echo json_encode(['success' => true, 'message' => 'If this email exists, a temporary password has been sent.']);
        exit;
    }

    // ── LOGIN ────────────────────────────────────────────────
    if (!$email || !$password) {
        echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
        exit;
    }

    $debug = [
        'email'          => $email,
        'curl_available' => function_exists('curl_init'),
        'step'           => 'start',
    ];

    // Step 1 — Supabase Auth
    $auth_code = null;
    $auth      = sb_req('/auth/v1/token?grant_type=password', 'POST',
                        ['email' => $email, 'password' => $password], false, $auth_code);

    $debug['auth_http_code']  = $auth_code;
    $debug['auth_has_token']  = !empty($auth['access_token']);
    $debug['auth_error']      = $auth['error_description'] ?? ($auth['msg'] ?? ($auth['_curl_error'] ?? null));

    if ($auth_code !== 200 || empty($auth['access_token'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password.',
            '_debug'  => $debug,
        ]);
        exit;
    }

    // Step 2 — Fetch user profile via REST view (same as reference)
    $debug['step'] = 'fetch_user';
    $user_code = null;
    $users = sb_req('/rest/v1/users_with_roles?email=eq.' . urlencode($email) . '&select=*',
                    'GET', null, true, $user_code);

    $debug['user_http_code']  = $user_code;
    $debug['user_rows_found'] = is_array($users) ? count($users) : 0;
    $debug['user_raw']        = (is_array($users) && !empty($users)) ? $users[0] : null;
    $debug['user_curl_error'] = $users['_curl_error'] ?? null;

    if (empty($users) || !is_array($users) || !isset($users[0])) {
        echo json_encode([
            'success' => false,
            'message' => 'Account not found. Contact your administrator.',
            '_debug'  => $debug,
        ]);
        exit;
    }

    $user = $users[0];

    // Step 3 — Status check
    $status = $user['status'] ?? '';
    if ($status !== 'Active') {
        echo json_encode([
            'success' => false,
            'message' => 'Your account is ' . strtolower($status ?: 'inactive') . '. Contact your administrator.',
        ]);
        exit;
    }

    // Step 4 — Resolve role & session
    $raw_roles = $user['roles'] ?? '';
    $top_role  = mf_resolve_role($raw_roles);
    $redirect  = mf_dashboard($top_role);

    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['name']    = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $_SESSION['role']    = $top_role;
    $_SESSION['roles']   = $raw_roles;
    $_SESSION['zone']    = $user['zone']   ?? '';
    $_SESSION['emp_id']  = $user['emp_id'] ?? '';

    // Step 5 — Update last_login
    $lc = null;
    sb_req('/rest/v1/users?user_id=eq.' . urlencode($user['user_id']),
           'PATCH', ['last_login' => date('c')], true, $lc);

    // Step 6 — Audit log
    sb_req('/rest/v1/audit_logs', 'POST', [
        'user_id'      => $user['user_id'],
        'action'       => 'Login',
        'performed_by' => $_SESSION['name'],
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '',
        'remarks'      => 'Successful login',
        'is_sa'        => ($top_role === 'Super Admin'),
    ], true);

    echo json_encode(['success' => true, 'redirect' => $redirect]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — MicroFinancial</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,600;1,9..144,300;1,9..144,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --g50:#f0faf2;--g100:#d6f3dc;--g200:#aee6bc;--g400:#4db86a;
  --g600:#2a7d3f;--g800:#1a4f28;--g900:#0f2e18;
  --cream:#faf8f3;--ink:#1a1a18;--ink2:#3d3d38;--ink3:#6b6b64;--ink4:#9e9e95;
  --bd:rgba(26,26,24,.1);--bd2:rgba(26,26,24,.18);
  --r:14px;
  --sh:0 2px 8px rgba(26,26,24,.06),0 8px 32px rgba(26,26,24,.08);
  --shl:0 4px 16px rgba(26,26,24,.08),0 24px 64px rgba(26,26,24,.14)
}
html,body{height:100%;font-family:'DM Sans',-apple-system,sans-serif;background:var(--cream);color:var(--ink);-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;background-image:radial-gradient(circle at 20% 20%,rgba(42,125,63,.07) 0%,transparent 50%),radial-gradient(circle at 80% 80%,rgba(42,125,63,.05) 0%,transparent 50%),radial-gradient(circle at 60% 10%,rgba(42,125,63,.04) 0%,transparent 40%);pointer-events:none;z-index:0}
body::after{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(26,26,24,.015) 1px,transparent 1px),linear-gradient(90deg,rgba(26,26,24,.015) 1px,transparent 1px);background-size:48px 48px;pointer-events:none;z-index:0}

.page{position:relative;z-index:1;min-height:100vh;display:grid;grid-template-columns:1fr 480px 1fr;align-items:center}

/* ── LEFT ── */
.deco{grid-column:1;padding:60px 40px;display:flex;flex-direction:column;justify-content:center;gap:40px;align-self:stretch}
.badge{display:inline-flex;align-items:center;gap:8px;background:var(--g100);color:var(--g600);font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;padding:6px 14px;border-radius:100px;width:fit-content}
.headline{font-family:'Fraunces',serif;font-size:clamp(30px,3.2vw,48px);font-weight:300;line-height:1.14;color:var(--ink);letter-spacing:-.02em}
.headline em{font-style:italic;color:var(--g600)}
.plabel{font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink4);margin-bottom:12px}
.rl{display:flex;flex-direction:column;gap:10px}
.ri{display:flex;align-items:center;gap:12px;padding:11px 14px;background:#fff;border:1px solid var(--bd);border-radius:10px;box-shadow:var(--sh);animation:slideL .6s ease both}
.ri:nth-child(1){animation-delay:.10s}.ri:nth-child(2){animation-delay:.18s}.ri:nth-child(3){animation-delay:.26s}.ri:nth-child(4){animation-delay:.34s}
.dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.dsa{background:#f0b429}.dad{background:#58a6ff}.dmg{background:#bc8cff}.dst{background:#3fb950}
.ri-info strong{display:block;font-size:12px;font-weight:600;color:var(--ink)}
.ri-info span{font-size:11px;color:var(--ink3)}
.ri-arr{margin-left:auto;font-size:11px;color:var(--ink4);font-weight:500;white-space:nowrap}

/* ── CARD ── */
.cw{grid-column:2;padding:24px 0}
.card{background:#fff;border:1px solid var(--bd);border-radius:24px;padding:48px 44px;box-shadow:var(--shl);animation:riseUp .5s cubic-bezier(.16,1,.3,1) both}
.logo{display:flex;align-items:center;gap:10px;margin-bottom:36px}
.lmark{width:40px;height:40px;background:var(--g800);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.lmark svg{width:20px;height:20px;fill:none;stroke:#fff;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.lname{font-family:'Fraunces',serif;font-size:15px;font-weight:400;color:var(--ink);letter-spacing:-.01em;line-height:1.1;display:block}
.lsub{font-size:10px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;color:var(--ink4);display:block}
.ct{font-family:'Fraunces',serif;font-size:28px;font-weight:300;color:var(--ink);letter-spacing:-.02em;margin-bottom:6px}
.cs{font-size:13px;color:var(--ink3);margin-bottom:32px}

.field{display:flex;flex-direction:column;gap:6px;margin-bottom:18px}
.field label{font-size:11px;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--ink2)}
.iw{position:relative}
.iw>.ic{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:17px;color:var(--ink4);pointer-events:none;transition:color .2s}
.iw input{width:100%;padding:13px 44px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--ink);background:var(--cream);border:1.5px solid var(--bd2);border-radius:var(--r);outline:none;transition:border-color .2s,box-shadow .2s,background .2s}
.iw input::placeholder{color:var(--ink4)}
.iw input:focus{background:#fff;border-color:var(--g400);box-shadow:0 0 0 3px rgba(77,184,106,.14)}
.iw:focus-within>.ic{color:var(--g600)}
.iw.ferr input{border-color:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.1)}
.tpw{position:absolute;right:0;top:0;bottom:0;width:44px;background:none;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:17px;color:var(--ink4);transition:color .2s;z-index:2}
.tpw:hover{color:var(--ink2)}

.forgot{display:flex;justify-content:flex-end;margin-top:-8px;margin-bottom:18px}
.forgot a{font-size:12px;color:var(--g600);text-decoration:none;font-weight:500;transition:color .2s}
.forgot a:hover{color:var(--g800);text-decoration:underline}

.alert{display:none;align-items:flex-start;gap:10px;padding:12px 14px;border:1px solid;border-radius:10px;margin-bottom:18px;font-size:13px;line-height:1.5;animation:shake .3s ease}
.alert.on{display:flex}
.alert.err{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.alert.info{background:var(--g50);border-color:var(--g200);color:var(--g800)}
.alert i{font-size:16px;flex-shrink:0;margin-top:1px}

.btn{width:100%;padding:14px;background:var(--g800);color:#fff;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;border:none;border-radius:var(--r);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:background .2s,transform .15s,box-shadow .2s;box-shadow:0 2px 8px rgba(15,46,24,.25);letter-spacing:.01em}
.btn:hover:not(:disabled){background:var(--g900);transform:translateY(-1px);box-shadow:0 4px 16px rgba(15,46,24,.3)}
.btn:active:not(:disabled){transform:translateY(0)}
.btn:disabled{opacity:.65;cursor:not-allowed}
.btn .sp{width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;display:none}
.btn.loading .sp{display:block}.btn.loading .bt{display:none}
.secure{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:18px;font-size:11px;color:var(--ink4)}
.secure i{font-size:13px;color:var(--g400)}

/* ── RIGHT ── */
.rp{grid-column:3;padding:60px 40px;display:flex;flex-direction:column;justify-content:center;gap:32px;align-self:stretch}
.sl{display:flex;flex-direction:column;gap:18px}
.si{display:flex;gap:14px;align-items:flex-start;animation:slideR .6s ease both}
.si:nth-child(1){animation-delay:.15s}.si:nth-child(2){animation-delay:.25s}.si:nth-child(3){animation-delay:.35s}
.sn{width:28px;height:28px;border-radius:8px;background:var(--g800);color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px}
.sb2 strong{display:block;font-size:13px;font-weight:600;color:var(--ink);margin-bottom:2px}
.sb2 p{font-size:12px;color:var(--ink3);line-height:1.5}
.vtag{display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--ink4);background:#fff;border:1px solid var(--bd);border-radius:8px;padding:6px 12px;width:fit-content}

/* ── DEBUG OVERLAY ── */
#dbgOv{display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:99999;align-items:flex-start;justify-content:center;padding:24px;overflow:auto;font-family:monospace}
#dbgOv.show{display:flex}
.dbx{background:#0f1117;border:1px solid #475569;border-radius:12px;width:100%;max-width:720px;overflow:hidden;margin:auto}
.dbhd{background:#1e293b;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #334155}
.dbhd span{color:#f87171;font-weight:700;font-size:13px}
.dbhd button{background:#334155;border:none;color:#94a3b8;padding:5px 12px;border-radius:5px;cursor:pointer;font-size:12px;font-family:monospace}
.dbgd{background:#1a1a2e;padding:10px 16px;font-size:11.5px;color:#94a3b8;border-bottom:1px solid #334155;line-height:1.9}
.dbb{padding:16px;font-size:12.5px;line-height:1.9;white-space:pre-wrap;word-break:break-all}
.dbr{display:flex;gap:12px;padding:4px 0;border-bottom:1px solid #1e293b}
.dbk{color:#64748b;min-width:200px;flex-shrink:0}
.dbv{color:#e2e8f0;flex:1}
.dbv.ok{color:#4ade80}.dbv.er{color:#f87171}.dbv.wn{color:#fbbf24}
.dbft{background:#1e293b;padding:10px 16px;border-top:1px solid #334155}
.dbft button{background:#334155;border:none;color:#e2e8f0;padding:7px 14px;border-radius:5px;cursor:pointer;font-size:12px;width:100%;font-family:monospace}

@keyframes riseUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
@keyframes slideL{from{opacity:0;transform:translateX(-14px)}to{opacity:1;transform:none}}
@keyframes slideR{from{opacity:0;transform:translateX(14px)}to{opacity:1;transform:none}}
@keyframes shake{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-5px)}40%,80%{transform:translateX(5px)}}
@keyframes spin{to{transform:rotate(360deg)}}

@media(max-width:1100px){.page{grid-template-columns:1fr;justify-items:center;padding:40px 20px}.deco,.rp{display:none}.cw{grid-column:1;width:100%;max-width:460px}}
@media(max-width:500px){.card{padding:32px 20px}}
</style>
</head>
<body>

<!-- ── DEBUG OVERLAY ─────────────────────────────────────────── -->
<div id="dbgOv">
  <div class="dbx">
    <div class="dbhd">
      <span>🔍 LOGIN DEBUG</span>
      <button onclick="document.getElementById('dbgOv').classList.remove('show')">✕ Close</button>
    </div>
    <div class="dbgd">
      <span style="color:#fbbf24">📋 HOW TO READ:</span><br>
      • <b style="color:#f87171">auth_http_code ≠ 200</b> — wrong password or account not in Supabase Auth<br>
      • <b style="color:#f87171">user_rows_found: 0</b> — auth passed but email not in <code>users_with_roles</code> view<br>
      • <b style="color:#fbbf24">curl_available: false</b> — cURL not installed on this server<br>
      • <b style="color:#4ade80">user_raw has your data</b> — check that status = "Active"
    </div>
    <div class="dbb" id="dbgBody"></div>
    <div class="dbft"><button id="dbgCopy">📋 Copy debug data</button></div>
  </div>
</div>

<div class="page">

  <!-- LEFT -->
  <div class="deco">
    <div>
      <div class="badge"><i class="bx bx-shield-quarter"></i> Secure Access Portal</div>
    </div>
    <div>
      <h1 class="headline">Managing finance,<br><em>one record</em><br>at a time.</h1>
    </div>
    <div>
      <p class="plabel">Role-Based Access</p>
      <div class="rl">
        <div class="ri"><div class="dot dsa"></div><div class="ri-info"><strong>Super Admin</strong><span>Full system access · All zones</span></div><span class="ri-arr">→ SA Dashboard</span></div>
        <div class="ri"><div class="dot dad"></div><div class="ri-info"><strong>Admin</strong><span>Branch-level administrative access</span></div><span class="ri-arr">→ Admin Dashboard</span></div>
        <div class="ri"><div class="dot dmg"></div><div class="ri-info"><strong>Manager</strong><span>Department &amp; project oversight</span></div><span class="ri-arr">→ Manager Dashboard</span></div>
        <div class="ri"><div class="dot dst"></div><div class="ri-info"><strong>Staff</strong><span>Assigned tasks &amp; transactions</span></div><span class="ri-arr">→ User Dashboard</span></div>
      </div>
    </div>
  </div>

  <!-- CENTER CARD -->
  <div class="cw">
    <div class="card">
      <div class="logo">
        <div class="lmark">
          <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        </div>
        <div><span class="lname">MicroFinancial</span><span class="lsub">Management System</span></div>
      </div>

      <h2 class="ct">Welcome back</h2>
      <p class="cs">Sign in — you'll be directed to your dashboard automatically.</p>

      <div class="alert err" id="alertEl">
        <i class="bx bx-error-circle"></i>
        <span id="alertMsg"></span>
      </div>

      <div class="field">
        <label for="emailEl">Email Address</label>
        <div class="iw" id="emailWrap">
          <i class="bx bx-envelope ic"></i>
          <input type="email" id="emailEl" placeholder="you@microfinancial.com" autocomplete="email">
        </div>
      </div>

      <div class="field">
        <label for="pwEl">Password</label>
        <div class="iw" id="pwWrap">
          <i class="bx bx-lock ic"></i>
          <input type="password" id="pwEl" placeholder="Enter your password" autocomplete="current-password">
          <button class="tpw" type="button" id="pwTgl" aria-label="Toggle password">
            <i class="bx bx-hide" id="pwIc"></i>
          </button>
        </div>
      </div>

      <div class="forgot"><a href="#" id="forgotLink">Forgot password?</a></div>

      <button class="btn" id="loginBtn" type="button">
        <div class="sp"></div>
        <span class="bt"><i class="bx bx-log-in" style="font-size:16px"></i> Sign In</span>
      </button>

      <div class="secure"><i class="bx bx-shield-quarter"></i> Protected by Supabase Auth &middot; 256-bit SSL</div>
    </div>
  </div>

  <!-- RIGHT -->
  <div class="rp">
    <div>
      <p class="plabel">How it works</p>
      <div class="sl">
        <div class="si"><div class="sn">1</div><div class="sb2"><strong>Authenticate</strong><p>Credentials are verified via Supabase Auth with secure JWT tokens.</p></div></div>
        <div class="si"><div class="sn">2</div><div class="sb2"><strong>Role Resolved</strong><p>Your highest role is read from the database — Super Admin, Admin, Manager, or Staff.</p></div></div>
        <div class="si"><div class="sn">3</div><div class="sb2"><strong>Auto-Redirected</strong><p>You land directly on the dashboard built for your access level.</p></div></div>
      </div>
    </div>
    <div class="vtag"><i class="bx bx-code-alt"></i> MicroFinancial v1.0 · PHP + Supabase</div>
  </div>

</div>

<script>
var emailEl  = document.getElementById('emailEl');
var pwEl     = document.getElementById('pwEl');
var loginBtn = document.getElementById('loginBtn');
var alertEl  = document.getElementById('alertEl');
var alertMsg = document.getElementById('alertMsg');
var _dbgData = null;

function showAlert(msg, type) {
  type = type || 'err';
  alertEl.className = 'alert on ' + type;
  alertEl.querySelector('i').className = type === 'info' ? 'bx bx-check-circle' : 'bx bx-error-circle';
  alertMsg.textContent = msg;
}
function hideAlert() { alertEl.className = 'alert err'; }

// Password toggle
document.getElementById('pwTgl').addEventListener('click', function() {
  var show = pwEl.type === 'password';
  pwEl.type = show ? 'text' : 'password';
  document.getElementById('pwIc').className = show ? 'bx bx-show' : 'bx bx-hide';
});

// Clear on type
[emailEl, pwEl].forEach(function(el) {
  el.addEventListener('input', function() {
    hideAlert();
    document.getElementById('emailWrap').classList.remove('ferr');
    document.getElementById('pwWrap').classList.remove('ferr');
  });
  el.addEventListener('keydown', function(e) { if (e.key === 'Enter') loginBtn.click(); });
});

// Debug overlay
function showDebug(data) {
  if (!data) return;
  _dbgData = data;
  var html = '';
  Object.keys(data).forEach(function(k) {
    var v   = data[k];
    var str = JSON.stringify(v, null, 2);
    var cls = 'dbv';
    if (k === 'auth_http_code') cls += v === 200 ? ' ok' : ' er';
    else if (k === 'user_rows_found') cls += v > 0 ? ' ok' : ' er';
    else if (k === 'auth_has_token') cls += v ? ' ok' : ' er';
    else if (k === 'curl_available') cls += v ? ' ok' : ' er';
    else if (v === null || v === false) cls += ' er';
    else if (v === true) cls += ' ok';
    html += '<div class="dbr"><span class="dbk">' + k + '</span><span class="' + cls + '">' + str + '</span></div>';
  });
  document.getElementById('dbgBody').innerHTML = html;
  document.getElementById('dbgOv').classList.add('show');
}

document.getElementById('dbgCopy').addEventListener('click', function() {
  if (!_dbgData) return;
  var btn = this;
  navigator.clipboard.writeText(JSON.stringify(_dbgData, null, 2))
    .then(function() { btn.textContent = '✅ Copied!'; setTimeout(function(){ btn.textContent = '📋 Copy debug data'; }, 2000); })
    .catch(function() { btn.textContent = 'Failed — see console'; });
});

// Forgot password
document.getElementById('forgotLink').addEventListener('click', async function(e) {
  e.preventDefault();
  var email = emailEl.value.trim();
  if (!email) {
    showAlert('Enter your email first, then click Forgot Password.');
    document.getElementById('emailWrap').classList.add('ferr');
    emailEl.focus(); return;
  }
  try {
    var r = await fetch('', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({forgot:true, email:email}) });
    var d = await r.json();
    showAlert(d.message || 'If this email exists, a temporary password has been sent.', 'info');
  } catch(e) {
    showAlert('Network error. Please try again.');
  }
});

// Login
loginBtn.addEventListener('click', async function() {
  var email    = emailEl.value.trim();
  var password = pwEl.value;
  hideAlert();
  document.getElementById('emailWrap').classList.remove('ferr');
  document.getElementById('pwWrap').classList.remove('ferr');
  document.getElementById('dbgOv').classList.remove('show');

  if (!email)    { showAlert('Email address is required.'); document.getElementById('emailWrap').classList.add('ferr'); emailEl.focus(); return; }
  if (!password) { showAlert('Password is required.'); document.getElementById('pwWrap').classList.add('ferr'); pwEl.focus(); return; }

  loginBtn.classList.add('loading'); loginBtn.disabled = true;

  try {
    var r = await fetch('', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email, password: password }),
    });
    var d = await r.json();

    if (d.success) {
      loginBtn.innerHTML = '<i class="bx bx-check" style="font-size:18px"></i><span style="font-size:14px">Redirecting\u2026</span>';
      loginBtn.style.background = 'var(--g400)';
      setTimeout(function() { window.location.href = d.redirect; }, 500);
    } else {
      showAlert(d.message || 'Login failed. Please try again.');
      document.getElementById('pwWrap').classList.add('ferr');
      pwEl.value = ''; pwEl.focus();
      if (d._debug) showDebug(d._debug);
      loginBtn.classList.remove('loading'); loginBtn.disabled = false;
    }
  } catch(err) {
    showAlert('Network error. Please check your connection.');
    loginBtn.classList.remove('loading'); loginBtn.disabled = false;
  }
});
</script>
</body>
</html>