<?php
// ============================================================
// LOGISTICS 1 — Sidebar
// - Role read from $_SESSION['role'] (set by login.php)
// - All module pages live under /superadmin/
// - Dashboard pages live at root  e.g. /superadmin_dashboard.php
// ============================================================

if (session_status() === PHP_SESSION_NONE) session_start();

// ── 1. ROLE ───────────────────────────────────────────────
function _sb_resolve_role(): string {
    // Primary: clean string stored by login
    if (!empty($_SESSION['role'])) {
        $r = $_SESSION['role'];
        if (str_contains($r, 'Super Admin')) return 'Super Admin';
        if (str_contains($r, 'Admin'))       return 'Admin';
        if (str_contains($r, 'Manager'))     return 'Manager';
        return 'Staff';
    }
    // Fallback: raw PG roles string e.g. {"Super Admin"}
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

$roleName = _sb_resolve_role();
$roleRank = match($roleName) {
    'Super Admin' => 4,
    'Admin'       => 3,
    'Manager'     => 2,
    default       => 1,
};

// ── 2. USER INFO ──────────────────────────────────────────
$userZone = $_SESSION['zone'] ?? '';

// ── 3. PATHS ──────────────────────────────────────────────
// Dashboards at root, modules under /superadmin/
$dashboardUrl = match($roleName) {
    'Super Admin' => '/superadmin_dashboard.php',
    'Admin'       => '/admin_dashboard.php',
    'Manager'     => '/manager_dashboard.php',
    default       => '/user_dashboard.php',
};

define('SB_BASE', '/superadmin');

// ── 4. HELPERS ────────────────────────────────────────────
function sb_can(int $min, bool $staffView = false): bool {
    global $roleRank;
    return $roleRank >= $min || ($staffView && $roleRank >= 1);
}

function sb_item(string $href, string $icon, string $label,
                 int $min = 1, bool $staffView = false): string {
    global $roleRank;
    if (!sb_can($min, $staffView)) return '';
    $badge = ($staffView && $roleRank === 1 && $min > 1)
        ? '<span class="nav-badge ro">View</span>'
        : '';
    return "
                    <li>
                        <a href=\"{$href}\">
                            <div class=\"icon-wrapper\"><i class='bx {$icon} icon'></i></div>
                            <span class=\"nav-label\">
                                <span class=\"main-text\">{$label}</span>{$badge}
                            </span>
                        </a>
                    </li>";
}

function sb_open(string $icon, string $main, string $sub): string {
    return "
            <li>
                <a onclick=\"toggleSubmenu(this)\">
                    <span class=\"left\">
                        <div class=\"icon-wrapper\"><i class='bx {$icon} icon'></i></div>
                        <span class=\"nav-label\">
                            <span class=\"main-text\">{$main}</span>
                            <span class=\"sub-text\">{$sub}</span>
                        </span>
                    </span>
                    <i class='bx bx-chevron-down'></i>
                </a>
                <ul class=\"sub-menu\">";
}

function sb_close(): string { return "\n                </ul>\n            </li>"; }
?>

<div class="overlay" id="overlay"></div>

<aside class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <div class="brand">
            <div class="logo"><i class='bx bx-package'></i></div>
            <div class="brand-info">
                <h1>LOGISTICS 1</h1>
                <p>Smart Supply Chain &amp; Procurement</p>
            </div>
        </div>
        <div class="system-status">
            <div class="status-content">
                <div class="status-left">
                    <span class="status-indicator"></span>
                    <span class="status-text">Online Services</span>
                </div>
            </div>
        </div>
    </div>

    <nav class="nav">

        <!-- DASHBOARD -->
        <span class="section-title">Dashboard</span>
        <ul>
            <li>
                <a href="<?= $dashboardUrl ?>">
                    <span class="left">
                        <div class="icon-wrapper"><i class='bx bx-tachometer icon'></i></div>
                        <span class="nav-label">
                            <span class="main-text">Dashboard</span>
                            <span class="sub-text">Overview &amp; Stats</span>
                        </span>
                    </span>
                </a>
            </li>
        </ul>

        <!-- SUPPLY CHAIN MODULES -->
        <span class="section-title">Supply Chain Modules</span>
        <ul>

            <?php
            // ── SWS ──────────────────────────────────────
            $b = SB_BASE . '/sws';
            $items  = sb_item("$b/overview.php",   'bx-home-alt',        'Warehouse Overview',         1, true);
            $items .= sb_item("$b/inventory.php",  'bx-bar-chart-square','Inventory Management',        1, true);
            $items .= sb_item("$b/stock.php",      'bx-transfer',        'Stock In / Stock Out',        1, true);
            $items .= sb_item("$b/location.php",   'bx-map',             'Bin &amp; Location Mapping',  1, true);
            if ($items): ?>
            <?= sb_open('bx-buildings', 'Smart Warehousing', 'SWS — Inventory &amp; Stock') ?>
            <?= $items ?>
            <?= sb_close() ?>
            <?php endif; ?>

            <?php
            // ── PSM ──────────────────────────────────────
            $b = SB_BASE . '/psm';
            $items  = sb_item("$b/requests.php",             'bx-list-ul',      'Purchase Requests',           1);
            $items .= sb_item("$b/rfq.php",                  'bx-send',         'Request for Quotation (RFQ)', 2);
            $items .= sb_item("$b/quotation_evaluation.php", 'bx-analyse',      'Quotation Evaluation',        2);
            $items .= sb_item("$b/orders.php",               'bx-purchase-tag', 'Purchase Orders',             2);
            $items .= sb_item("$b/contracts.php",            'bx-notepad',      'Contract Management',         2);
            $items .= sb_item("$b/receiving.php",            'bx-check-shield', 'Receiving &amp; Inspection',  2);
            $items .= sb_item("$b/suppliers.php",            'bx-user-check',   'Suppliers',                   1, true);
            if ($items): ?>
            <?= sb_open('bx-cart', 'Procurement &amp; Sourcing', 'PSM — Orders &amp; Suppliers') ?>
            <?= $items ?>
            <?= sb_close() ?>
            <?php endif; ?>

            <?php
            // ── PLT ──────────────────────────────────────
            $b = SB_BASE . '/plt';
            $items  = sb_item("$b/active.php",               'bx-task',     'Active Projects',        1, true);
            $items .= sb_item("$b/delivery_schedule.php",    'bx-calendar', 'Delivery Schedule',      1, true);
            $items .= sb_item("$b/logistics_assignments.php",'bx-user-pin', 'Logistics Assignments',  1, true);
            $items .= sb_item("$b/milestone_tracking.php",   'bx-flag',     'Milestone Tracking',     1, true);
            if ($items): ?>
            <?= sb_open('bx-clipboard', 'Project Logistics', 'PLT — Tracking &amp; Schedules') ?>
            <?= $items ?>
            <?= sb_close() ?>
            <?php endif; ?>

            <?php
            // ── ALMS ─────────────────────────────────────
            $b = SB_BASE . '/alms';
            $items  = sb_item("$b/asset_registry.php",        'bx-server', 'Asset Registry',           1, true);
            $items .= sb_item("$b/preventive_maintenance.php",'bx-time',   'Preventive Maintenance',   1, true);
            $items .= sb_item("$b/repair_service_logs.php",   'bx-note',   'Repair &amp; Service Logs',1, true);
            $items .= sb_item("$b/asset_disposal.php",        'bx-trash',  'Asset Disposal',           2);
            if ($items): ?>
            <?= sb_open('bx-wrench', 'Asset Lifecycle', 'ALMS — Maintenance &amp; Assets') ?>
            <?= $items ?>
            <?= sb_close() ?>
            <?php endif; ?>

            <?php
            // ── DTRS ─────────────────────────────────────
            $b = SB_BASE . '/dtrs';
            $items  = sb_item("$b/registry.php",          'bx-spreadsheet', 'Document Registry',            1, true);
            $items .= sb_item("$b/capture.php",           'bx-scan',        'Document Capture',             1, true);
            $items .= sb_item("$b/routing.php",           'bx-git-branch',  'Document Routing',             1, true);
            $items .= sb_item("$b/records_lifecycle.php", 'bx-refresh',     'Records Lifecycle Management', 1, true);
            if ($items): ?>
            <?= sb_open('bx-folder-open', 'Document Tracking', 'DTRS — Records &amp; Compliance') ?>
            <?= $items ?>
            <?= sb_close() ?>
            <?php endif; ?>

        </ul>

        <?php if (sb_can(3)): ?>
        <!-- SYSTEM ADMINISTRATION -->
        <span class="section-title">System Administration</span>
        <ul>
            <?php
            $b = SB_BASE . '/admin';
            $items  = sb_item("$b/user_management.php",           'bx-group',      'User Management',            3);
            $items .= sb_item("$b/role_permission_management.php",'bx-key',        'Role &amp; Permission Mgmt', 4);
            $items .= sb_item("$b/audit_logs.php",                'bx-list-check', 'Audit Logs',                 3);
            $items .= sb_item("$b/system_settings.php",           'bx-shield',     'System Settings',            3);
            $items .= sb_item("$b/notifications_alerts.php",      'bx-bell',       'Notifications &amp; Alerts', 3);
            ?>
            <?= sb_open('bx-cog', 'System Administration', 'Settings &amp; Access Control') ?>
            <?= $items ?>
            <?= sb_close() ?>
        </ul>
        <?php endif; ?>

        <!-- FOOTER -->
        <div class="sidebar-footer">
            <div class="footer-header">
                <div class="footer-icon"><i class='bx bx-shield-alt-2'></i></div>
                <div class="footer-content">
                    <div class="footer-title">Secure Platform</div>
                    <div class="footer-subtitle">All systems operational</div>
                </div>
            </div>
            <div class="footer-divider"></div>
            <div class="footer-bottom">
                <span class="version"><?= htmlspecialchars($userZone) ?: 'System' ?></span>
                <div class="status-online">Online</div>
            </div>
        </div>

    </nav>
</aside>

<style>
.nav-badge {
    display: inline-block;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: .3px;
    padding: 1px 5px;
    border-radius: 10px;
    margin-left: 6px;
    text-transform: uppercase;
    background: rgba(255,255,255,.1);
    color: rgba(255,255,255,.5);
    vertical-align: middle;
}
.nav-badge.ro {
    background: #0ea5e922;
    color: #38bdf8;
    border: 1px solid #0ea5e933;
}
</style>

<script>
(function () {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');

    function toggleSubmenu(el) {
        const parent = el.parentElement;
        document.querySelectorAll('.nav ul li.open').forEach(function(item) {
            if (item !== parent) item.classList.remove('open');
        });
        parent.classList.toggle('open');
    }
    window.toggleSubmenu = toggleSubmenu;

    document.querySelectorAll('.nav a').forEach(function(link) {
        link.addEventListener('click', function() {
            if (!this.hasAttribute('onclick')) {
                document.querySelectorAll('.nav a').forEach(function(a) {
                    a.classList.remove('active');
                });
                this.classList.add('active');
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                }
            }
        });
    });

    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }

    // Auto-highlight active page
    const current = window.location.pathname;
    document.querySelectorAll('.nav a[href]').forEach(function(a) {
        if (a.getAttribute('href') === current) {
            a.classList.add('active');
            const subMenu = a.closest('ul.sub-menu');
            if (subMenu) subMenu.closest('li').classList.add('open');
        }
    });
})();
</script>