<?php
// ── BOOTSTRAP ────────────────────────────────────────────────────────────────
$root = $_SERVER['DOCUMENT_ROOT'];
require_once $root . '/config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ROLE & SCOPE (mirrors includes/superadmin_sidebar.php) ─────────────────────
function ov_resolve_role(): string {
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

$ovRoleName = ov_resolve_role();
$ovRoleRank = match($ovRoleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,
};
$ovUserZone = $_SESSION['zone'] ?? '';

// ── HELPERS ──────────────────────────────────────────────────────────────────
function ov_ok($payload): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}
function ov_err(string $msg, int $code = 400): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function ov_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    if ($d === null && json_last_error() !== JSON_ERROR_NONE) ov_err('Invalid JSON', 400);
    return is_array($d) ? $d : [];
}

// Supabase REST helper — identical pattern to bin_mapping.php
function ov_sb(string $table, string $method = 'GET', array $query = [], $body = null): array {
    $url = SUPABASE_URL . '/rest/v1/' . $table;
    if ($query) {
        $parts = [];
        foreach ($query as $k => $v) {
            if (preg_match('/^([a-z]+\.)(.+)$/', (string)$v, $m)) {
                $parts[] = urlencode($k) . '=' . $m[1] . rawurlencode($m[2]);
            } else {
                $parts[] = urlencode($k) . '=' . rawurlencode((string)$v);
            }
        }
        $url .= '?' . implode('&', $parts);
    }
    $prefer  = ($method === 'DELETE') ? 'return=minimal' : 'return=representation';
    $headers = [
        'Content-Type: application/json',
        'apikey: '               . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Prefer: '               . $prefer,
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
    if ($method === 'DELETE') {
        if ($code >= 400) {
            $data = json_decode($res, true);
            $msg  = is_array($data) ? ($data['message'] ?? $data['hint'] ?? $res) : $res;
            ov_err('Supabase DELETE: ' . $msg, 400);
        }
        return [];
    }
    if ($res === false || $res === '') {
        if ($code >= 400) ov_err('Supabase request failed', 500);
        return [];
    }
    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = is_array($data) ? ($data['message'] ?? $data['hint'] ?? $res) : $res;
        ov_err('Supabase: ' . $msg, 400);
    }
    return is_array($data) ? $data : [];
}

// ── API ROUTER ────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    $api    = trim($_GET['api']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor  = $_SESSION['full_name'] ?? ($_SESSION['user_id'] ?? 'Super Admin');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    try {

        // ── GET overview ──────────────────────────────────────────────────────
        // Returns: zones[] with derived stats + aggregates
        // Role-based scope:
        //   - Super Admin/Admin → full site (all zones)
        //   - Manager/Staff     → assigned zone only (via $_SESSION['zone'])
        // Each zone carries:
        //   capacity  → sum of sws_bins.capacity for that zone
        //   occupancy → sum of sws_bins.used for that zone
        //   activeSKUs     → count of active sws_inventory items in zone
        //   lowStockAlerts → count where stock <= min_level AND stock > 0
        //   overstocked    → count where stock > max_level
        //   health         → derived from bin occupancy %
        //   skus[]         → top 20 inventory items with status
        // ─────────────────────────────────────────────────────────────────────
        if ($api === 'overview' && $method === 'GET') {

            // Determine scope zone for Manager/Staff
            $scopeZone = null;
            if ($ovRoleName === 'Manager' || $ovRoleName === 'Staff') {
                $z = trim((string)$ovUserZone);
                if ($z !== '') $scopeZone = $z;
            }

            // 1. Zones (scoped)
            $zoneQuery = [
                'select' => 'id,name,color',
                'order'  => 'id.asc',
            ];
            if ($scopeZone !== null) {
                $zoneQuery['id'] = 'eq.' . $scopeZone;
            }
            $zoneRows = ov_sb('sws_zones', 'GET', $zoneQuery);

            // 2. Bin capacity + used aggregated per zone (scoped)
            $binQuery = [
                'select' => 'zone,capacity,used,active',
            ];
            if ($scopeZone !== null) {
                $binQuery['zone'] = 'eq.' . $scopeZone;
            }
            $binRows = ov_sb('sws_bins', 'GET', $binQuery);
            // Build zone → { cap, used } map
            $binCap = [];   // zone_id => total capacity
            $binUsed = [];  // zone_id => total used
            foreach ($binRows as $b) {
                $zid = $b['zone'];
                $binCap[$zid]  = ($binCap[$zid]  ?? 0) + (int)$b['capacity'];
                $binUsed[$zid] = ($binUsed[$zid] ?? 0) + (int)$b['used'];
            }

            // 3. Inventory items per zone — active only, scoped
            $invQuery = [
                'select' => 'id,code,name,category,uom,zone,stock,min_level,max_level,active',
                'active' => 'eq.true',
                'order'  => 'name.asc',
            ];
            if ($scopeZone !== null) {
                $invQuery['zone'] = 'eq.' . $scopeZone;
            }
            $invRows = ov_sb('sws_inventory', 'GET', $invQuery);
            // Build zone → items map
            $zoneItems = [];  // zone_id => [ item, ... ]
            foreach ($invRows as $item) {
                $zid = $item['zone'] ?? '';
                if (!$zid) continue;
                $zoneItems[$zid][] = $item;
            }

            // 4. Build each zone object
            $zones = [];
            foreach ($zoneRows as $z) {
                $zid      = $z['id'];
                $items    = $zoneItems[$zid] ?? [];
                $cap      = $binCap[$zid]  ?? 0;
                $used     = $binUsed[$zid] ?? 0;
                $occPct   = $cap > 0 ? round(($used / $cap) * 100) : 0;

                // Derive health from bin occupancy percentage
                $health = $occPct > 90 ? 'critical' : ($occPct > 75 ? 'alert' : 'healthy');

                // SKU-level alerts
                $lowCount  = 0;
                $overCount = 0;
                $skus      = [];
                foreach ($items as $it) {
                    $stk = (int)$it['stock'];
                    $min = (int)$it['min_level'];
                    $max = (int)$it['max_level'];

                    if ($stk === 0)        $status = 'crit';
                    elseif ($stk <= $min)  $status = 'low';
                    elseif ($stk > $max)   $status = 'over';
                    else                   $status = 'ok';

                    if ($status === 'low' || $status === 'crit') $lowCount++;
                    if ($status === 'over') $overCount++;

                    $skus[] = [
                        'id'     => $it['code'],
                        'name'   => $it['name'],
                        'qty'    => $stk,
                        'min'    => $min,
                        'max'    => $max,
                        'status' => $status,
                    ];
                }

                // If no bins exist yet, fall back health to healthy
                if ($cap === 0) $health = 'healthy';

                $zones[] = [
                    'id'             => $zid,
                    'name'           => $z['name'],
                    'color'          => $z['color'],
                    'capacity'       => $cap,
                    'occupancy'      => $used,
                    'activeSKUs'     => count($items),
                    'lowStockAlerts' => $lowCount,
                    'overstocked'    => $overCount,
                    'health'         => $health,
                    'skus'           => array_slice($skus, 0, 20), // cap at 20 for modal
                ];
            }

            // 5. Site-level totals
            $totalCap  = array_sum($binCap);
            $totalUsed = array_sum($binUsed);
            $totalSKUs = count($invRows);
            $totalLow  = array_sum(array_column($zones, 'lowStockAlerts'));
            $totalOver = array_sum(array_column($zones, 'overstocked'));

            ov_ok([
                'zones'       => $zones,
                'totalCap'    => $totalCap,
                'totalUsed'   => $totalUsed,
                'totalSKUs'   => $totalSKUs,
                'totalLow'    => $totalLow,
                'totalOver'   => $totalOver,
                'zoneCount'   => count($zones),
            ]);
        }

        // ── POST save_zone (add zone) ──────────────────────────────────────────
        if ($api === 'save_zone' && $method === 'POST') {
            // Only Admin/Super Admin can create zones
            if ($ovRoleRank < 3) {
                ov_err('Not authorized to create zones', 403);
            }
            $b     = ov_body();
            $id    = strtoupper(trim($b['id']    ?? ''));
            $name  = trim($b['name']  ?? '');
            $color = trim($b['color'] ?? '#2E7D32');

            if (!$id)   ov_err('Zone ID is required', 400);
            if (!$name) ov_err('Zone name is required', 400);
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#2E7D32';

            // Duplicate check
            $existing = ov_sb('sws_zones', 'GET', [
                'select' => 'id',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            if (!empty($existing)) ov_err("Zone ID '{$id}' already exists", 409);

            ov_sb('sws_zones', 'POST', [], [[
                'id'    => $id,
                'name'  => $name,
                'color' => $color,
            ]]);

            $rows = ov_sb('sws_zones', 'GET', [
                'select' => 'id,name,color',
                'id'     => 'eq.' . $id,
                'limit'  => '1',
            ]);
            ov_ok($rows[0] ?? ['id' => $id, 'name' => $name, 'color' => $color]);
        }

        ov_err('Unsupported API route', 404);

    } catch (Throwable $e) {
        ov_err('Server error: ' . $e->getMessage(), 500);
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
<title>Warehouse Overview — SWS</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/base.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/sidebar.css">
<link rel="stylesheet" href="<?= LOG1_WEB_BASE ?>/css/header.css">
<style>
:root {
  --bg:#F3F6F2; --s:#FFFFFF; --t1:#1A2B1C; --t2:#5D7263; --t3:#9EB5A4;
  --bd:rgba(46,125,50,.12); --bdm:rgba(46,125,50,.22);
  --grn:#2E7D32; --gdk:#1B5E20; --glt:#4CAF50; --gxl:#E8F5E9;
  --amb:#F59E0B; --amblt:#FFFBEB; --red:#DC2626; --redlt:#FEF2F2;
  --blu:#2563EB; --tel:#0D9488; --pur:#7C3AED;
  --shsm:0 1px 4px rgba(46,125,50,.08);
  --shmd:0 4px 20px rgba(46,125,50,.11);
  --shlg:0 12px 40px rgba(0,0,0,.14);
  --rad:14px; --tr:all .18s ease;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--t1);min-height:100vh;font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;}
::-webkit-scrollbar{width:5px;height:5px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.wrap{max-width:1440px;margin:0 auto;padding:0 0 4rem}
.ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:16px;animation:UP .45s both}
.ph-l .ey{font-size:11px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--grn);margin-bottom:5px;display:flex;align-items:center;gap:7px}
.ph-l .ey::before{display:none}
.ph-l h1{font-size:28px;font-weight:800;color:var(--t1);line-height:1.15;letter-spacing:-.3px}
.ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 10px rgba(46,125,50,.28)}.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);box-shadow:0 4px 16px rgba(46,125,50,.35)}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm)}.btn-ghost:hover{background:var(--gxl);color:var(--grn);border-color:var(--grn)}
.btn i{font-size:17px}.btn-sm{font-size:12px;padding:6px 14px}
.btn:disabled{opacity:.45;pointer-events:none}
.sum-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px;animation:UP .45s .07s both}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:18px 20px;box-shadow:var(--shsm);display:flex;align-items:center;gap:14px;transition:var(--tr);position:relative;overflow:hidden}
.sc:hover{box-shadow:var(--shmd);transform:translateY(-2px)}
.sc-ic{width:44px;height:44px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:20px}
.ic-g{background:#E8F5E9;color:#2E7D32}.ic-a{background:#FEF3C7;color:#D97706}.ic-r{background:#FEF2F2;color:#DC2626}.ic-b{background:#EFF6FF;color:#2563EB}.ic-t{background:#CCFBF1;color:#0D9488}
.sc-body{min-width:0}
.sc-v{font-size:26px;font-weight:800;color:var(--t1);line-height:1;font-variant-numeric:tabular-nums}
.sc-v.mono{font-family:'DM Mono',monospace;font-size:19px}
.sc-l{font-size:11.5px;color:var(--t2);margin-top:3px;font-weight:500}
.sc-sub{font-size:10.5px;color:var(--t3);margin-top:2px;font-family:'DM Mono',monospace}
.cap-bar-wrap{margin-top:8px}
.cap-bar-track{height:5px;background:#E5E7EB;border-radius:3px;overflow:hidden}
.cap-bar-fill{height:100%;border-radius:3px;transition:width .8s cubic-bezier(.4,0,.2,1)}
.sec-hd{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px}
.sec-hd h2{font-size:16px;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.sec-hd h2 i{font-size:18px;color:var(--grn)}
.sec-badge{font-size:11px;font-weight:700;background:var(--gxl);color:var(--grn);border-radius:20px;padding:3px 10px}
.wh-section{animation:UP .45s .13s both;margin-bottom:28px}
.wh-card{background:var(--s);border:1px solid var(--bd);border-radius:18px;box-shadow:var(--shmd);overflow:hidden}
.wh-card-top{display:grid;grid-template-columns:1fr auto;gap:24px;padding:24px 28px 20px;border-bottom:1px solid var(--bd)}
.wh-info{display:flex;align-items:flex-start;gap:16px}
.wh-ic{width:54px;height:54px;background:#2E7D32;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;flex-shrink:0;box-shadow:0 4px 14px rgba(46,125,50,.3)}
.wh-nm{font-size:20px;font-weight:800;color:var(--t1);line-height:1.2}
.wh-loc{display:flex;align-items:center;gap:5px;font-size:12.5px;color:var(--t2);margin-top:4px}
.wh-loc i{font-size:14px;color:var(--grn)}
.wh-status-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;border-radius:6px;padding:3px 10px;margin-top:7px}
.wst-active{background:#DCFCE7;color:#166534}
.wh-kpis{display:flex;align-items:center;gap:28px;flex-wrap:wrap}
.wh-kpi{text-align:right}
.wh-kpi-v{font-size:22px;font-weight:800;color:var(--t1);font-family:'DM Mono',monospace;line-height:1}
.wh-kpi-l{font-size:11px;color:var(--t2);margin-top:3px;font-weight:500}
.wh-kpi-sep{width:1px;height:40px;background:var(--bd)}
.wh-occ{padding:20px 28px 24px;border-bottom:1px solid var(--bd)}
.occ-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px}
.occ-label{font-size:13px;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.occ-pct{font-family:'DM Mono',monospace;font-size:22px;font-weight:800}
.occ-detail{font-size:12px;color:var(--t2)}
.occ-track{height:12px;background:#E5E7EB;border-radius:8px;overflow:hidden;position:relative}
.occ-fill{height:100%;border-radius:8px;transition:width 1s cubic-bezier(.4,0,.2,1);position:relative}
.occ-fill::after{content:'';position:absolute;top:2px;left:8px;right:8px;height:4px;background:rgba(255,255,255,.35);border-radius:4px}
.occ-ticks{display:flex;justify-content:space-between;margin-top:5px}
.occ-tick{font-size:10px;color:var(--t3);font-family:'DM Mono',monospace}
.wh-metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:0}
.wh-metric{padding:18px 22px;display:flex;align-items:center;gap:12px;border-right:1px solid var(--bd)}
.wh-metric:last-child{border-right:none}
.wm-ic{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.wm-v{font-size:20px;font-weight:800;color:var(--t1);line-height:1;font-variant-numeric:tabular-nums}
.wm-l{font-size:11px;color:var(--t2);margin-top:2px;font-weight:500}
.zones-section{animation:UP .45s .18s both}
.zones-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:14px}
.zone-card{background:var(--s);border-radius:14px;border:1px solid var(--bd);overflow:hidden;transition:var(--tr);box-shadow:var(--shsm);cursor:pointer}
.zone-card:hover{box-shadow:var(--shmd);transform:translateY(-2px)}
.zone-top{padding:14px 16px 12px;display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.zone-id{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);font-family:'DM Mono',monospace}
.zone-nm{font-size:15px;font-weight:700;color:var(--t1);margin-top:2px}
.zone-dot-strip{width:36px;height:5px;border-radius:3px;margin-top:6px}
.zone-health{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap}
.zh-healthy{background:#DCFCE7;color:#166534}.zh-alert{background:#FEF3C7;color:#92400E}.zh-critical{background:#FEE2E2;color:#991B1B}
.zone-health::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor}
.zone-bar-wrap{padding:0 16px 14px}
.zone-bar-track{height:8px;background:#F3F4F6;border-radius:5px;overflow:hidden;margin-bottom:5px}
.zone-bar-fill{height:100%;border-radius:5px;transition:width 1s cubic-bezier(.4,0,.2,1)}
.zone-bar-row{display:flex;justify-content:space-between;align-items:center}
.zone-bar-pct{font-family:'DM Mono',monospace;font-size:11.5px;font-weight:700}
.zone-bar-cap{font-size:11px;color:var(--t3)}
.zone-stats{display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid var(--bd)}
.zone-stat{padding:10px 14px;text-align:center;border-right:1px solid var(--bd)}
.zone-stat:last-child{border-right:none}
.zsv{font-size:16px;font-weight:800;color:var(--t1);line-height:1;font-variant-numeric:tabular-nums}
.zsl{font-size:10px;color:var(--t2);margin-top:3px;font-weight:500;text-transform:uppercase;letter-spacing:.05em}
.zone-alerts{display:flex;align-items:center;gap:8px;padding:9px 16px;background:#FFFBEB;border-top:1px solid #FDE68A;font-size:12px;color:#92400E}
.zone-alerts i{font-size:15px;flex-shrink:0}
.zone-alerts.critical{background:#FEF2F2;border-color:#FECACA;color:#991B1B}
.zone-alerts.none{background:#F0FDF4;border-color:#BBF7D0;color:#166534}
.legend{display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.leg-item{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--t2);font-weight:500}
.leg-dot{width:8px;height:8px;border-radius:50%}
.ld-g{background:#22C55E}.ld-a{background:#F59E0B}.ld-r{background:#EF4444}
.fill-healthy{background:#22C55E}.fill-alert{background:#F59E0B}.fill-critical{background:#EF4444}
.occ-healthy{background:#2E7D32}.occ-alert{background:#D97706}.occ-critical{background:#B91C1C}
.pct-healthy{color:#166534}.pct-alert{color:#92400E}.pct-critical{color:#991B1B}
.cap-healthy{background:#2E7D32}.cap-alert{background:#D97706}.cap-critical{background:#B91C1C}
.spin{display:inline-block;animation:SPIN .8s linear infinite}
@keyframes SPIN{to{transform:rotate(360deg)}}
.filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.fil-pill{font-family:'Inter',sans-serif;font-size:12.5px;font-weight:600;padding:6px 14px;border-radius:20px;border:1px solid var(--bdm);background:var(--s);color:var(--t2);cursor:pointer;transition:var(--tr)}
.fil-pill:hover,.fil-pill.active{background:var(--grn);color:#fff;border-color:var(--grn)}
/* Zone Modal */
#zoneModal{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9050;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .22s}
#zoneModal.on{opacity:1;pointer-events:all}
.zm-box{background:var(--s);border-radius:16px;width:760px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden}
.zm-hd{padding:22px 24px 0;border-bottom:1px solid var(--bd);background:var(--bg);flex-shrink:0}
.zm-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}
.zm-si{display:flex;align-items:center;gap:14px}
.zm-av{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:17px;color:#fff;flex-shrink:0}
.zm-nm{font-size:18px;font-weight:800;color:var(--t1);line-height:1.2}
.zm-meta{font-size:12px;color:var(--t2);margin-top:3px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-family:'DM Mono',monospace}
.zm-cl{width:34px;height:34px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:19px;color:var(--t2);transition:var(--tr);flex-shrink:0}
.zm-cl:hover{background:#FEE2E2;color:#DC2626;border-color:#FECACA}
.zm-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.zm-chip{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--t2);background:var(--s);border:1px solid var(--bd);border-radius:8px;padding:4px 10px}
.zm-chip i{font-size:13px;color:var(--grn)}
.zm-tabs{display:flex;gap:4px}
.zm-tab{font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:8px 16px;border-radius:8px 8px 0 0;cursor:pointer;transition:all .15s;color:var(--t2);border:none;background:transparent;display:flex;align-items:center;gap:6px}
.zm-tab:hover{background:rgba(46,125,50,.08);color:var(--t1)}
.zm-tab.active{background:var(--grn);color:#fff}
.zm-tab i{font-size:14px}
.zm-bd{flex:1;overflow-y:auto;padding:22px 24px;background:var(--s)}
.zm-bd::-webkit-scrollbar{width:4px}.zm-bd::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.zm-tp{display:none;flex-direction:column;gap:16px}
.zm-tp.active{display:flex}
.zm-sbs{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.zm-sb{background:var(--bg);border:1px solid var(--bd);border-radius:10px;padding:14px 16px}
.zm-sb .sbv{font-size:20px;font-weight:800;color:var(--t1);line-height:1}
.zm-sb .sbv.mono{font-family:'DM Mono',monospace;font-size:13px;color:var(--grn)}
.zm-sb .sbl{font-size:11px;color:var(--t2);margin-top:3px}
.zm-ig{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.zm-ii label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--t3);display:block;margin-bottom:4px}
.zm-ii .v{font-size:13px;font-weight:500;color:var(--t1)}
.zm-ii .vm{font-size:13px;color:var(--t2);font-weight:400}
.zm-ft{padding:14px 24px;border-top:1px solid var(--bd);background:var(--s);display:flex;gap:8px;justify-content:flex-end;flex-shrink:0;flex-wrap:wrap}
/* Add zone slider */
#azOverlay{position:fixed;inset:0;background:rgba(0,0,0,.52);z-index:9000;opacity:0;pointer-events:none;transition:opacity .25s}
#azOverlay.on{opacity:1;pointer-events:all}
#azSlider{position:fixed;top:0;right:-600px;bottom:0;width:560px;max-width:100vw;background:var(--s);z-index:9001;transition:right .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;box-shadow:-4px 0 40px rgba(0,0,0,.18)}
#azSlider.on{right:0}
.az-hd{display:flex;align-items:flex-start;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--bd);background:#F0FAF0;flex-shrink:0}
.az-title{font-size:17px;font-weight:700;color:var(--t1)}.az-sub{font-size:12px;color:var(--t2);margin-top:2px}
.az-cl{width:36px;height:36px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);cursor:pointer;display:grid;place-content:center;font-size:20px;color:var(--t2);transition:var(--tr);flex-shrink:0}
.az-cl:hover{background:#FEE2E2;color:#DC2626;border-color:#FECACA}
.az-bd{flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:16px}
.az-bd::-webkit-scrollbar{width:4px}.az-bd::-webkit-scrollbar-thumb{background:var(--bdm);border-radius:4px}
.az-ft{padding:16px 24px;border-top:1px solid var(--bd);background:#F0FAF0;display:flex;gap:10px;justify-content:flex-end;flex-shrink:0}
.az-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.az-fg{display:flex;flex-direction:column;gap:5px}.az-fg.full{grid-column:1/-1}
.az-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t2)}.az-lbl span{color:#DC2626;margin-left:2px}
.az-fi,.az-fs,.az-fta{font-family:'Inter',sans-serif;font-size:13px;padding:10px 12px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);width:100%}
.az-fi:focus,.az-fs:focus,.az-fta:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.11)}
.az-fi:disabled{background:var(--bg);color:var(--t3);cursor:not-allowed}
.az-fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D7263' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:30px}
.az-fta{resize:vertical;min-height:70px}
.az-divider{font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--t3);display:flex;align-items:center;gap:10px}.az-divider::after{content:'';flex:1;height:1px;background:var(--bd)}
.az-hint{font-size:11.5px;color:var(--t3);margin-top:3px}
.az-err{font-size:12px;color:#DC2626;margin-top:3px;display:none}.az-err.on{display:block}
.color-presets{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.cp{width:24px;height:24px;border-radius:6px;cursor:pointer;border:2px solid transparent;transition:var(--tr);flex-shrink:0}
.cp:hover,.cp.sel{border-color:#fff;box-shadow:0 0 0 2px var(--grn)}
/* SKU table */
.sku-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.sku-tbl thead th{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2);padding:8px 10px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd)}
.sku-tbl thead th:last-child{text-align:right}
.sku-tbl tbody tr{border-bottom:1px solid var(--bd)}
.sku-tbl tbody tr:last-child{border-bottom:none}
.sku-tbl tbody td{padding:10px 10px;vertical-align:middle}
.sku-tbl tbody td:last-child{text-align:right}
.sku-nm{font-weight:600;color:var(--t1)}.sku-id{font-family:'DM Mono',monospace;font-size:10.5px;color:var(--t3);margin-top:1px}
.stk-badge{font-size:10.5px;font-weight:700;padding:3px 8px;border-radius:20px}
.stk-ok{background:#DCFCE7;color:#166534}.stk-low{background:#FEF3C7;color:#92400E}.stk-crit{background:#FEE2E2;color:#991B1B}.stk-over{background:#EFF6FF;color:#2563EB}
/* Loading skeleton */
.skel{background:linear-gradient(90deg,#e8f0e8 25%,#d4e8d4 50%,#e8f0e8 75%);background-size:200% 100%;animation:SKEL 1.4s ease infinite;border-radius:8px}
@keyframes SKEL{0%{background-position:200% 0}100%{background-position:-200% 0}}
@keyframes UP{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:900px){
  .sum-grid{grid-template-columns:repeat(2,1fr)}
  .wh-card-top{grid-template-columns:1fr}
  .wh-metrics{grid-template-columns:repeat(2,1fr)}
  .wh-metric{border-right:none;border-bottom:1px solid var(--bd)}
  .wh-metrics .wh-metric:nth-last-child(-n+2){border-bottom:none}
}
@media(max-width:600px){
  .wrap{padding:0 0 2rem}
  .sum-grid{grid-template-columns:1fr 1fr;gap:10px}
  .zones-grid{grid-template-columns:1fr}
  .ph-l h1{font-size:22px}
  .wh-metrics{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="wrap">

  <div class="ph">
    <div class="ph-l">
      <p class="ey">SWS · Smart Warehousing System</p>
      <h1>Warehouse <span>Overview</span></h1>
    </div>
    <div class="ph-r">
      <button class="btn btn-ghost" id="refreshBtn"><i class="bx bx-refresh"></i> Refresh</button>
      <?php if ($ovRoleRank >= 3): ?>
      <button class="btn btn-primary" id="addZoneBtn"><i class="bx bx-plus"></i> Add Zone</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="sum-grid" id="sumGrid">
    <!-- skeleton placeholders -->
    <div class="sc"><div class="skel" style="width:44px;height:44px;border-radius:12px;flex-shrink:0"></div><div class="sc-body"><div class="skel" style="height:26px;width:60px;margin-bottom:6px"></div><div class="skel" style="height:11px;width:100px"></div></div></div>
    <div class="sc"><div class="skel" style="width:44px;height:44px;border-radius:12px;flex-shrink:0"></div><div class="sc-body"><div class="skel" style="height:26px;width:40px;margin-bottom:6px"></div><div class="skel" style="height:11px;width:110px"></div></div></div>
    <div class="sc"><div class="skel" style="width:44px;height:44px;border-radius:12px;flex-shrink:0"></div><div class="sc-body"><div class="skel" style="height:26px;width:40px;margin-bottom:6px"></div><div class="skel" style="height:11px;width:120px"></div></div></div>
    <div class="sc"><div class="skel" style="width:44px;height:44px;border-radius:12px;flex-shrink:0"></div><div class="sc-body"><div class="skel" style="height:26px;width:70px;margin-bottom:6px"></div><div class="skel" style="height:11px;width:90px"></div></div></div>
  </div>

  <div class="wh-section" id="whSection"></div>

  <div class="zones-section">
    <div class="sec-hd">
      <h2><i class="bx bx-grid-alt"></i> Zone Layout</h2>
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <div class="legend">
          <div class="leg-item"><div class="leg-dot ld-g"></div>Healthy (≤75%)</div>
          <div class="leg-item"><div class="leg-dot ld-a"></div>Alert (76–90%)</div>
          <div class="leg-item"><div class="leg-dot ld-r"></div>Critical (&gt;90%)</div>
        </div>
        <div class="filter-bar" id="zoneFilter">
          <button class="fil-pill active" data-f="all">All</button>
          <button class="fil-pill" data-f="healthy">Healthy</button>
          <button class="fil-pill" data-f="alert">Alert</button>
          <button class="fil-pill" data-f="critical">Critical</button>
        </div>
      </div>
    </div>
    <div class="zones-grid" id="zonesGrid">
      <div class="skel" style="height:220px;border-radius:14px"></div>
      <div class="skel" style="height:220px;border-radius:14px"></div>
      <div class="skel" style="height:220px;border-radius:14px"></div>
    </div>
  </div>

</div>

<div id="azOverlay"></div>
<div id="azSlider">
  <div class="az-hd">
    <div><div class="az-title">Add New Zone</div><div class="az-sub">Stored in Supabase · sws_zones</div></div>
    <button class="az-cl" id="azClose"><i class="bx bx-x"></i></button>
  </div>
  <div class="az-bd">
    <div class="az-row">
      <div class="az-fg">
        <label class="az-lbl">Zone ID <span>*</span></label>
        <div style="display:flex;gap:6px;align-items:center">
          <input type="text" class="az-fi" id="azId" placeholder="ZN-A01" maxlength="10"
            style="font-family:'DM Mono',monospace;text-transform:uppercase;flex:1"
            oninput="this.value=this.value.toUpperCase();azCheckId(this.value)">
          <button type="button" id="azIdRefresh" title="Suggest ID"
            style="flex-shrink:0;width:36px;height:38px;border-radius:9px;border:1px solid var(--bdm);background:var(--s);color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:16px;transition:var(--tr)"
            onmouseover="this.style.background='var(--gxl)';this.style.color='var(--grn)'"
            onmouseout="this.style.background='';this.style.color=''">
            <i class="bx bx-refresh"></i>
          </button>
        </div>
        <div class="az-err" id="azIdErr">Zone ID is required.</div>
        <div class="az-hint" id="azIdHint">Format: ZN-A01, ZN-B02…</div>
      </div>
      <div class="az-fg">
        <label class="az-lbl">Zone Name <span>*</span></label>
        <input type="text" class="az-fi" id="azName" placeholder="e.g. Zone H — Spare Parts">
        <div class="az-err" id="azNameErr">Zone name is required.</div>
      </div>
    </div>

    <div class="az-divider">Appearance</div>
    <div class="az-fg">
      <label class="az-lbl">Zone Color</label>
      <div style="display:flex;align-items:center;gap:8px">
        <input type="color" id="azColorPicker" value="#2E7D32"
          style="width:38px;height:38px;border-radius:8px;border:1px solid var(--bdm);padding:2px;cursor:pointer;background:transparent"
          oninput="azSyncColor(this.value)">
        <input type="text" class="az-fi" id="azColor" value="#2E7D32"
          style="font-family:'DM Mono',monospace;text-transform:uppercase;flex:1"
          oninput="azSyncColorText(this.value)">
      </div>
      <div class="color-presets" id="azColorPresets"></div>
      <div class="az-hint">This color is used on zone cards and bin legend pills.</div>
    </div>

    <!-- Zone preview -->
    <div style="background:var(--bg);border:1px solid var(--bd);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px">
      <div id="azPreviewSwatch" style="width:36px;height:36px;border-radius:9px;background:#2E7D32;display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;flex-shrink:0"><i class="bx bx-layer"></i></div>
      <div>
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--t3)">Preview</div>
        <div id="azPreviewName" style="font-size:13px;font-weight:700;color:var(--t1)">Zone Name</div>
        <div id="azPreviewId" style="font-family:'DM Mono',monospace;font-size:11px;color:var(--t3)">ZN-—</div>
      </div>
    </div>
  </div>
  <div class="az-ft">
    <button class="btn btn-ghost btn-sm" id="azCancel">Cancel</button>
    <button class="btn btn-primary btn-sm" id="azSubmit"><i class="bx bx-save"></i> Create Zone</button>
  </div>
</div>

<div id="zoneModal">
  <div class="zm-box">
    <div class="zm-hd">
      <div class="zm-top">
        <div class="zm-si">
          <div class="zm-av" id="zmAvatar"></div>
          <div>
            <div class="zm-nm" id="zmName">—</div>
            <div class="zm-meta" id="zmMeta">—</div>
          </div>
        </div>
        <button class="zm-cl" id="zmClose"><i class="bx bx-x"></i></button>
      </div>
      <div class="zm-chips" id="zmChips"></div>
      <div class="zm-tabs">
        <button class="zm-tab active" data-zt="ov"><i class="bx bx-grid-alt"></i> Overview</button>
        <button class="zm-tab" data-zt="sk"><i class="bx bx-list-ul"></i> Inventory</button>
      </div>
    </div>
    <div class="zm-bd">
      <div class="zm-tp active" id="zt-ov"></div>
      <div class="zm-tp"        id="zt-sk"></div>
    </div>
    <div class="zm-ft" id="zmFoot"></div>
  </div>
</div>

<script>
// ── ROLE CONTEXT FROM PHP ─────────────────────────────────────────────────────
const ROLE      = '<?= addslashes($ovRoleName) ?>';
const USER_ZONE = '<?= addslashes((string)$ovUserZone) ?>';

// ── API ───────────────────────────────────────────────────────────────────────
const API = '<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES) ?>';

async function apiFetch(path, opts = {}) {
    const r = await fetch(path, { headers: { 'Content-Type': 'application/json' }, ...opts });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Request failed');
    return j.data;
}
const apiGet  = p    => apiFetch(p);
const apiPost = (p,b)=> apiFetch(p, { method:'POST', body:JSON.stringify(b) });

// ── STATE ─────────────────────────────────────────────────────────────────────
let ZONES        = [];
let SITE         = {};
let activeFilter = 'all';

// ── LOAD ──────────────────────────────────────────────────────────────────────
async function loadAll() {
    try {
        const d = await apiGet(API + '?api=overview');
        ZONES = d.zones  || [];
        SITE  = {
            totalCap  : d.totalCap  || 0,
            totalUsed : d.totalUsed || 0,
            totalSKUs : d.totalSKUs || 0,
            totalLow  : d.totalLow  || 0,
            totalOver : d.totalOver || 0,
            zoneCount : d.zoneCount || 0,
        };
        renderStats();
        renderWarehouse();
        renderZones();
    } catch(e) {
        showToast('Failed to load data: ' + e.message, 'd');
        document.getElementById('sumGrid').innerHTML  = `<div style="grid-column:1/-1;color:var(--red);font-size:13px;padding:16px">⚠ ${e.message}</div>`;
        document.getElementById('zonesGrid').innerHTML = '';
    }
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
function pct(u,c){ return c > 0 ? Math.round((u/c)*100) : 0; }
function healthOf(p){ return p>90?'critical':p>75?'alert':'healthy'; }
function occFillCls(p){ return p>90?'occ-critical':p>75?'occ-alert':'occ-healthy'; }
function pctColorCls(p){ return p>90?'pct-critical':p>75?'pct-alert':'pct-healthy'; }
function capFillCls(p){ return p>90?'cap-critical':p>75?'cap-alert':'cap-healthy'; }
function zoneFillCls(p){ return p>90?'fill-critical':p>75?'fill-alert':'fill-healthy'; }
function healthLabel(h){ return h==='healthy'?'Healthy':h==='alert'?'Alert':'Critical'; }
function healthCls(h){ return 'zh-'+h; }
function nowTS(){ return new Date().toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'}); }
function stkBadge(s){
    const m = { ok:['stk-ok','OK'], low:['stk-low','Low Stock'], crit:['stk-crit','Critical'], over:['stk-over','Overstocked'] };
    const [cls,lbl] = m[s] || m.ok;
    return `<span class="stk-badge ${cls}">${lbl}</span>`;
}

// ── RENDER SUMMARY STATS ──────────────────────────────────────────────────────
function renderStats() {
    const capPct  = pct(SITE.totalUsed, SITE.totalCap);
    const capFill = capFillCls(capPct);
    const scopeLabel = (ROLE === 'Manager' || ROLE === 'Staff')
        ? 'Zone Capacity Used'
        : 'Site Capacity Used';
    document.getElementById('sumGrid').innerHTML = `
        <div class="sc">
          <div class="sc-ic ic-b"><i class="bx bx-package"></i></div>
          <div class="sc-body">
            <div class="sc-v">${SITE.totalSKUs.toLocaleString()}</div>
            <div class="sc-l">Total Active SKUs</div>
            <div class="sc-sub">${SITE.zoneCount} active zone${SITE.zoneCount!==1?'s':''}</div>
          </div>
        </div>
        <div class="sc">
          <div class="sc-ic ic-a"><i class="bx bx-error"></i></div>
          <div class="sc-body">
            <div class="sc-v">${SITE.totalLow}</div>
            <div class="sc-l">Low Stock Alerts</div>
            <div class="sc-sub">${ZONES.filter(z=>z.lowStockAlerts>0).length} zones affected</div>
          </div>
        </div>
        <div class="sc">
          <div class="sc-ic ic-t"><i class="bx bx-trending-up"></i></div>
          <div class="sc-body">
            <div class="sc-v">${SITE.totalOver}</div>
            <div class="sc-l">Overstocked Items</div>
            <div class="sc-sub">${ZONES.filter(z=>z.overstocked>0).length} zones with surplus</div>
          </div>
        </div>
        <div class="sc">
          <div class="sc-ic ${capPct>90?'ic-r':capPct>75?'ic-a':'ic-g'}"><i class="bx bx-building-house"></i></div>
          <div class="sc-body">
            <div class="sc-v mono ${pctColorCls(capPct)}">${capPct}%</div>
            <div class="sc-l">${scopeLabel}</div>
            <div class="cap-bar-wrap">
              <div class="cap-bar-track"><div class="cap-bar-fill ${capFill}" style="width:0%" data-to="${capPct}"></div></div>
            </div>
          </div>
        </div>`;
    requestAnimationFrame(()=>requestAnimationFrame(()=>{
        document.querySelectorAll('.cap-bar-fill[data-to]').forEach(el=>{ el.style.width=el.dataset.to+'%'; });
    }));
}

// ── RENDER WAREHOUSE CARD ─────────────────────────────────────────────────────
function renderWarehouse() {
    const p       = pct(SITE.totalUsed, SITE.totalCap);
    const fillCls = occFillCls(p);
    const pctCls  = pctColorCls(p);
    const healthy = ZONES.filter(z=>z.health==='healthy').length;
    const alert   = ZONES.filter(z=>z.health==='alert').length;
    const crit    = ZONES.filter(z=>z.health==='critical').length;

    document.getElementById('whSection').innerHTML = `
        <div class="sec-hd">
          <h2><i class="bx bx-buildings"></i> Warehouse</h2>
          <span class="sec-badge">1 Active Facility</span>
        </div>
        <div class="wh-card">
          <div class="wh-card-top">
            <div class="wh-info">
              <div class="wh-ic"><i class="bx bx-building-house"></i></div>
              <div>
                <div class="wh-nm">Central Distribution Warehouse</div>
                <div class="wh-loc"><i class="bx bx-map-pin"></i>Tracked across ${SITE.zoneCount} active zone${SITE.zoneCount!==1?'s':''}</div>
                <span class="wh-status-tag wst-active"><i class="bx bx-check-circle" style="font-size:12px"></i>&nbsp;Operational</span>
              </div>
            </div>
            <div class="wh-kpis">
              <div class="wh-kpi">
                <div class="wh-kpi-v">${SITE.totalSKUs.toLocaleString()}</div>
                <div class="wh-kpi-l">Active SKUs</div>
              </div>
              <div class="wh-kpi-sep"></div>
              <div class="wh-kpi">
                <div class="wh-kpi-v" style="color:${SITE.totalLow>0?'#D97706':'#166534'}">${SITE.totalLow}</div>
                <div class="wh-kpi-l">Low Stock Alerts</div>
              </div>
              <div class="wh-kpi-sep"></div>
              <div class="wh-kpi">
                <div class="wh-kpi-v">${SITE.zoneCount}</div>
                <div class="wh-kpi-l">Total Zones</div>
              </div>
            </div>
          </div>
          <div class="wh-occ">
            <div class="occ-row">
              <span class="occ-label"><i class="bx bx-bar-chart-alt-2" style="font-size:18px;color:var(--grn)"></i> Bin Capacity &amp; Occupancy</span>
              <div style="text-align:right">
                <div class="occ-pct ${pctCls}">${p}%</div>
                <div class="occ-detail">${SITE.totalUsed.toLocaleString()} / ${SITE.totalCap.toLocaleString()} units used across all bins</div>
              </div>
            </div>
            <div class="occ-track">
              <div class="occ-fill ${fillCls}" id="whOccBar" style="width:0%"></div>
            </div>
            <div class="occ-ticks">
              <span class="occ-tick">0%</span>
              <span class="occ-tick">25%</span>
              <span class="occ-tick">50%</span>
              <span class="occ-tick">75%</span>
              <span class="occ-tick">100%</span>
            </div>
          </div>
          <div class="wh-metrics">
            <div class="wh-metric">
              <div class="wm-ic ic-b"><i class="bx bx-grid-alt"></i></div>
              <div><div class="wm-v">${healthy}</div><div class="wm-l">Healthy Zones</div></div>
            </div>
            <div class="wh-metric">
              <div class="wm-ic ic-a"><i class="bx bx-error-alt"></i></div>
              <div><div class="wm-v">${alert}</div><div class="wm-l">Alert Zones</div></div>
            </div>
            <div class="wh-metric">
              <div class="wm-ic ic-r"><i class="bx bx-x-circle"></i></div>
              <div><div class="wm-v">${crit}</div><div class="wm-l">Critical Zones</div></div>
            </div>
            <div class="wh-metric">
              <div class="wm-ic ic-g"><i class="bx bx-time"></i></div>
              <div>
                <div class="wm-v" style="font-size:12px;font-family:'DM Mono',monospace;">${nowTS()}</div>
                <div class="wm-l">Last Updated</div>
              </div>
            </div>
          </div>
        </div>`;

    requestAnimationFrame(()=>requestAnimationFrame(()=>{
        const bar = document.getElementById('whOccBar');
        if (bar) bar.style.width = p + '%';
    }));
}

// ── RENDER ZONES GRID ─────────────────────────────────────────────────────────
function renderZones() {
    const list = activeFilter === 'all' ? ZONES : ZONES.filter(z => z.health === activeFilter);
    const grid = document.getElementById('zonesGrid');
    if (!list.length) {
        grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:48px;color:var(--t3);font-size:14px;font-weight:600;">No zones match this filter.</div>`;
        return;
    }
    grid.innerHTML = list.map(z => {
        const p         = pct(z.occupancy, z.capacity);
        const fillCls   = zoneFillCls(p);
        const pctCls    = pctColorCls(p);
        const hLabel    = healthLabel(z.health);
        const hCls      = healthCls(z.health);
        const capLabel  = z.capacity > 0
            ? `${z.occupancy.toLocaleString()} / ${z.capacity.toLocaleString()} units`
            : 'No bins configured';

        let alertsHtml = '';
        if (z.health === 'critical')
            alertsHtml = `<div class="zone-alerts critical"><i class="bx bx-error"></i>${z.lowStockAlerts>0 ? z.lowStockAlerts+' low-stock item(s) — immediate restock needed' : 'Near full bin capacity — redistribution required'}</div>`;
        else if (z.health === 'alert')
            alertsHtml = `<div class="zone-alerts"><i class="bx bx-error-alt"></i>${z.lowStockAlerts>0 ? z.lowStockAlerts+' low-stock item(s) — review needed' : 'Approaching bin capacity threshold'}</div>`;
        else
            alertsHtml = `<div class="zone-alerts none"><i class="bx bx-check-circle"></i>All stock levels within healthy range</div>`;

        return `<div class="zone-card" onclick="openZoneModal('${esc(z.id)}')">
          <div class="zone-top">
            <div>
              <div class="zone-id">${esc(z.id)}</div>
              <div class="zone-nm">${esc(z.name)}</div>
              <div class="zone-dot-strip" style="background:${z.color}"></div>
            </div>
            <span class="zone-health ${hCls}">${hLabel}</span>
          </div>
          <div class="zone-bar-wrap">
            <div class="zone-bar-track">
              <div class="zone-bar-fill ${fillCls}" data-to="${p}" style="width:0%"></div>
            </div>
            <div class="zone-bar-row">
              <span class="zone-bar-pct ${pctCls}">${p}% bin capacity used</span>
              <span class="zone-bar-cap">${capLabel}</span>
            </div>
          </div>
          <div class="zone-stats">
            <div class="zone-stat">
              <div class="zsv">${z.activeSKUs}</div>
              <div class="zsl">SKUs</div>
            </div>
            <div class="zone-stat">
              <div class="zsv" style="${z.lowStockAlerts>0?'color:#D97706':''}">${z.lowStockAlerts}</div>
              <div class="zsl">Low Stock</div>
            </div>
            <div class="zone-stat">
              <div class="zsv" style="${z.overstocked>0?'color:#2563EB':''}">${z.overstocked}</div>
              <div class="zsl">Overstocked</div>
            </div>
          </div>
          ${alertsHtml}
        </div>`;
    }).join('');

    requestAnimationFrame(()=>requestAnimationFrame(()=>{
        document.querySelectorAll('.zone-bar-fill[data-to]').forEach(el=>{ el.style.width=el.dataset.to+'%'; });
    }));
}

// ── ZONE MODAL ────────────────────────────────────────────────────────────────
function setZmTab(name) {
    document.querySelectorAll('.zm-tab').forEach(t => t.classList.toggle('active', t.dataset.zt === name));
    document.querySelectorAll('.zm-tp').forEach(p => p.classList.toggle('active', p.id === 'zt-' + name));
}
document.querySelectorAll('.zm-tab').forEach(t => t.addEventListener('click', () => setZmTab(t.dataset.zt)));

function openZoneModal(id) {
    const z = ZONES.find(x => x.id === id); if (!z) return;
    const p = pct(z.occupancy, z.capacity);

    const av = document.getElementById('zmAvatar');
    av.textContent  = z.id.replace('ZN-', '');
    av.style.background = z.color || '#2E7D32';

    document.getElementById('zmName').textContent = z.name;
    document.getElementById('zmMeta').innerHTML   =
        `<span>${esc(z.id)}</span>&nbsp;·&nbsp;
         <span class="zone-health ${healthCls(z.health)}">${healthLabel(z.health)}</span>`;

    document.getElementById('zmChips').innerHTML = `
        <div class="zm-chip"><i class="bx bx-bar-chart-alt-2"></i>${p}% bin capacity</div>
        <div class="zm-chip"><i class="bx bx-package"></i>${z.activeSKUs} SKUs</div>
        <div class="zm-chip"><i class="bx bx-error" style="color:${z.lowStockAlerts>0?'#D97706':'#9EB5A4'}"></i>${z.lowStockAlerts} low stock</div>
        <div class="zm-chip"><i class="bx bx-trending-up" style="color:${z.overstocked>0?'#2563EB':'#9EB5A4'}"></i>${z.overstocked} overstocked</div>`;

    // Overview tab
    document.getElementById('zt-ov').innerHTML = `
        <div class="zm-sbs">
          <div class="zm-sb"><div class="sbv">${z.activeSKUs}</div><div class="sbl">Active SKUs</div></div>
          <div class="zm-sb"><div class="sbv mono">${p}%</div><div class="sbl">Bin Occupancy</div></div>
          <div class="zm-sb"><div class="sbv" style="${z.lowStockAlerts>0?'color:#D97706':''}">${z.lowStockAlerts}</div><div class="sbl">Low Stock</div></div>
          <div class="zm-sb"><div class="sbv" style="${z.overstocked>0?'color:#2563EB':''}">${z.overstocked}</div><div class="sbl">Overstocked</div></div>
        </div>
        <div class="zm-ig">
          <div class="zm-ii"><label>Zone ID</label><div class="v" style="font-family:'DM Mono',monospace">${esc(z.id)}</div></div>
          <div class="zm-ii"><label>Zone Name</label><div class="vm">${esc(z.name)}</div></div>
          <div class="zm-ii"><label>Total Bin Capacity</label><div class="v">${z.capacity.toLocaleString()} units</div></div>
          <div class="zm-ii"><label>Current Bin Usage</label><div class="v">${z.occupancy.toLocaleString()} units</div></div>
          <div class="zm-ii"><label>Health Status</label><div class="v"><span class="zone-health ${healthCls(z.health)}">${healthLabel(z.health)}</span></div></div>
          <div class="zm-ii"><label>Available Capacity</label><div class="v">${(z.capacity - z.occupancy).toLocaleString()} units</div></div>
        </div>`;

    // Inventory tab
    if (z.skus && z.skus.length) {
        const rows = z.skus.map((s, i) => `
            <tr>
              <td style="color:#9CA3AF;font-size:11px;font-weight:600">${i+1}</td>
              <td><div class="sku-nm">${esc(s.name)}</div><div class="sku-id">${esc(s.id)}</div></td>
              <td style="font-weight:700;font-variant-numeric:tabular-nums;text-align:right">${s.qty.toLocaleString()}</td>
              <td style="font-size:11px;color:var(--t3)">${s.min}–${s.max}</td>
              <td style="text-align:right">${stkBadge(s.status)}</td>
            </tr>`).join('');
        document.getElementById('zt-sk').innerHTML = `
            <table class="sku-tbl">
              <thead><tr>
                <th style="width:24px">#</th><th>Item</th>
                <th style="text-align:right">Stock</th><th>Min–Max</th>
                <th style="text-align:right">Status</th>
              </tr></thead>
              <tbody>${rows}</tbody>
            </table>`;
    } else {
        document.getElementById('zt-sk').innerHTML = `<p style="color:var(--t3);font-size:13px;padding:8px 0">No inventory items assigned to this zone yet.</p>`;
    }

    document.getElementById('zmFoot').innerHTML = `
        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('zoneModal').classList.remove('on')"><i class="bx bx-x"></i> Close</button>`;

    setZmTab('ov');
    document.getElementById('zoneModal').classList.add('on');
}
document.getElementById('zmClose').addEventListener('click', () => document.getElementById('zoneModal').classList.remove('on'));
document.getElementById('zoneModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('on'); });

// ── ADD ZONE SLIDER ───────────────────────────────────────────────────────────
const COLOR_PRESETS = ['#2E7D32','#0D9488','#DC2626','#2563EB','#7C3AED','#D97706','#059669','#0EA5E9','#EC4899','#6B7280'];

// Build color preset swatches
(function buildPresets(){
    const wrap = document.getElementById('azColorPresets');
    wrap.innerHTML = COLOR_PRESETS.map(c =>
        `<div class="cp" style="background:${c}" data-color="${c}" title="${c}"
             onclick="azPickPreset(this)"></div>`
    ).join('');
    // Mark first as active
    wrap.firstElementChild?.classList.add('sel');
})();

function azPickPreset(el) {
    document.querySelectorAll('#azColorPresets .cp').forEach(c => c.classList.remove('sel'));
    el.classList.add('sel');
    azSyncColor(el.dataset.color);
}
function azSyncColor(val) {
    document.getElementById('azColor').value       = val.toUpperCase();
    document.getElementById('azColorPicker').value = val;
    document.getElementById('azPreviewSwatch').style.background = val;
}
function azSyncColorText(val) {
    if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
        document.getElementById('azColorPicker').value = val;
        document.getElementById('azPreviewSwatch').style.background = val;
        document.querySelectorAll('#azColorPresets .cp').forEach(c =>
            c.classList.toggle('sel', c.dataset.color.toLowerCase() === val.toLowerCase())
        );
    }
}

// Live preview wiring
document.getElementById('azName').addEventListener('input', e => {
    document.getElementById('azPreviewName').textContent = e.target.value || 'Zone Name';
});
document.getElementById('azId').addEventListener('input', e => {
    document.getElementById('azPreviewId').textContent = e.target.value || 'ZN-—';
});

// Auto-suggest next zone ID
function azNextId() {
    const taken   = new Set(ZONES.map(z => z.id));
    const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    for (let i = 0; i < letters.length; i++) {
        const candidate = `ZN-${letters[i]}${String(i+1).padStart(2,'0')}`;
        if (!taken.has(candidate)) return candidate;
    }
    for (const letter of letters)
        for (let n = 1; n <= 99; n++) {
            const c = `ZN-${letter}${String(n).padStart(2,'0')}`;
            if (!taken.has(c)) return c;
        }
    return 'ZN-A01';
}

function azCheckId(val) {
    const hint = document.getElementById('azIdHint');
    const err  = document.getElementById('azIdErr');
    if (!val) { hint.textContent = 'Format: ZN-A01, ZN-B02…'; hint.style.color=''; err.classList.remove('on'); return; }
    if (ZONES.find(z => z.id === val)) {
        hint.textContent = ''; err.textContent = `"${val}" already exists — choose a different ID.`; err.classList.add('on');
    } else {
        err.classList.remove('on');
        hint.innerHTML = `<span style="color:var(--grn)">✓ "${val}" is available</span>`;
    }
}

document.getElementById('azIdRefresh').addEventListener('click', () => {
    const next = azNextId();
    document.getElementById('azId').value = next;
    document.getElementById('azPreviewId').textContent = next;
    azCheckId(next);
});

function openAddZone() {
    const next = azNextId();
    document.getElementById('azId').value    = next;
    document.getElementById('azName').value  = '';
    document.getElementById('azColor').value = '#2E7D32';
    document.getElementById('azColorPicker').value = '#2E7D32';
    document.getElementById('azPreviewSwatch').style.background = '#2E7D32';
    document.getElementById('azPreviewName').textContent = 'Zone Name';
    document.getElementById('azPreviewId').textContent  = next;
    document.querySelectorAll('#azColorPresets .cp').forEach((c,i) => c.classList.toggle('sel', i===0));
    document.querySelectorAll('.az-err').forEach(e => e.classList.remove('on'));
    document.getElementById('azIdHint').innerHTML = `<span style="color:var(--grn)">✓ "${next}" is available</span>`;
    document.getElementById('azSlider').classList.add('on');
    document.getElementById('azOverlay').classList.add('on');
    setTimeout(() => document.getElementById('azName').focus(), 350);
}
function closeAddZone() {
    document.getElementById('azSlider').classList.remove('on');
    document.getElementById('azOverlay').classList.remove('on');
}

const addZoneBtn = document.getElementById('addZoneBtn');
if (addZoneBtn) {
    addZoneBtn.addEventListener('click', openAddZone);
}
document.getElementById('azClose').addEventListener('click', closeAddZone);
document.getElementById('azCancel').addEventListener('click', closeAddZone);
document.getElementById('azOverlay').addEventListener('click', closeAddZone);

document.getElementById('azSubmit').addEventListener('click', async () => {
    let valid = true;
    const show = (id, msg) => { const el=document.getElementById(id); el.textContent=msg; el.classList.add('on'); valid=false; };
    const hide = id => document.getElementById(id).classList.remove('on');

    const zId    = document.getElementById('azId').value.trim().toUpperCase();
    const zName  = document.getElementById('azName').value.trim();
    const zColor = document.getElementById('azColor').value.trim() || '#2E7D32';

    if (!zId)                          { show('azIdErr','Zone ID is required.'); }  else hide('azIdErr');
    if (ZONES.find(z=>z.id===zId))     { show('azIdErr','This Zone ID already exists.'); valid=false; }
    if (!zName)                        { show('azNameErr','Zone name is required.'); } else hide('azNameErr');
    if (!/^#[0-9A-Fa-f]{6}$/.test(zColor)) { showToast('Enter a valid hex color.','w'); valid=false; }

    if (!valid) return;

    const btn = document.getElementById('azSubmit');
    btn.disabled = true;
    btn.innerHTML = '<i class="bx bx-loader-alt spin"></i> Saving…';

    try {
        const saved = await apiPost(API + '?api=save_zone', { id: zId, name: zName, color: zColor });
        // Optimistically add to local state — overview will fill derived stats on next refresh
        ZONES.push({
            id: saved.id, name: saved.name, color: saved.color,
            capacity: 0, occupancy: 0, activeSKUs: 0,
            lowStockAlerts: 0, overstocked: 0, health: 'healthy', skus: [],
        });
        SITE.zoneCount = ZONES.length;
        closeAddZone();
        renderStats();
        renderWarehouse();
        renderZones();
        showToast(`Zone "${saved.name}" created successfully.`, 's');
    } catch(e) {
        showToast(e.message, 'd');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bx bx-save"></i> Create Zone';
    }
});

// ── FILTER ────────────────────────────────────────────────────────────────────
document.querySelectorAll('#zoneFilter .fil-pill').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('#zoneFilter .fil-pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeFilter = btn.dataset.f;
        renderZones();
    });
});

// ── REFRESH ───────────────────────────────────────────────────────────────────
document.getElementById('refreshBtn').addEventListener('click', () => {
    const btn = document.getElementById('refreshBtn');
    btn.innerHTML = '<i class="bx bx-refresh spin"></i> Refreshing…';
    btn.disabled  = true;
    loadAll().finally(() => {
        btn.innerHTML = '<i class="bx bx-refresh"></i> Refresh';
        btn.disabled  = false;
    });
});

// ── TOAST ─────────────────────────────────────────────────────────────────────
function showToast(msg, type = 's') {
    const colors = { s:'#2E7D32', w:'#D97706', d:'#DC2626' };
    const icons  = { s:'bx-check-circle', w:'bx-error', d:'bx-error-circle' };
    const t = document.createElement('div');
    t.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;align-items:center;gap:10px;background:${colors[type]};color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;box-shadow:0 8px 28px rgba(0,0,0,.2);animation:UP .3s ease;min-width:200px;font-family:'Inter',sans-serif;`;
    t.innerHTML = `<i class="bx ${icons[type]}" style="font-size:18px;flex-shrink:0"></i>${msg}`;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; t.style.transform='translateY(6px)'; t.style.transition='all .3s ease'; setTimeout(()=>t.remove(),310); }, 3200);
}

// ── INIT ──────────────────────────────────────────────────────────────────────
loadAll();
</script>
</main>
</body>
</html>