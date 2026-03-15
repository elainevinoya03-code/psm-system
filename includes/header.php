<?php
/**
 * header.php
 * Reusable top navigation bar component for the Microfinancial Management System.
 * Include this file wherever the top navbar is needed.
 *
 * Usage:
 *   <?php include 'header.php'; ?>
 *
 * Dependencies (add to parent page <head>):
 *   <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
 *   <link rel="stylesheet" href="path/to/base.css">
 *   <link rel="stylesheet" href="path/to/header.css">
 *
 * Note:
 *   This component controls the sidebar toggle. It expects sidebar.php to also
 *   be included on the same page (which provides #sidebar and #overlay elements).
 */
?>

<style>
    /* ---- Profile Dropdown ---- */
    .profile-wrapper {
        position: relative;
    }

    .profile-icon {
        cursor: pointer;
    }

    .profile-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 10px);
        right: 0;
        min-width: 180px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        overflow: hidden;
        z-index: 9999;
        animation: dropdownFadeIn 0.18s ease;
    }

    .profile-dropdown.open {
        display: block;
    }

    @keyframes dropdownFadeIn {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .profile-dropdown-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 14px 16px;
        border-bottom: 1px solid #f0f0f0;
        background: #f9fafb;
    }

    .profile-dropdown-header .avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #4f46e5;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .profile-dropdown-header .avatar i {
        font-size: 18px;
        color: #fff;
    }

    .profile-dropdown-header .user-info {
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .profile-dropdown-header .user-name {
        font-size: 13px;
        font-weight: 600;
        color: #111827;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .profile-dropdown-header .user-role {
        font-size: 11px;
        color: #6b7280;
        margin-top: 1px;
    }

    .profile-dropdown-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 11px 16px;
        font-size: 13px;
        font-weight: 500;
        color: #374151;
        cursor: pointer;
        text-decoration: none;
        transition: background 0.15s ease, color 0.15s ease;
        border: none;
        background: none;
        width: 100%;
        text-align: left;
    }

    .profile-dropdown-item:hover {
        background: #f3f4f6;
        color: #111827;
    }

    .profile-dropdown-item i {
        font-size: 17px;
        color: #6b7280;
        flex-shrink: 0;
        transition: color 0.15s ease;
    }

    .profile-dropdown-item:hover i {
        color: #4f46e5;
    }

    .profile-dropdown-item.logout:hover {
        background: #fff1f2;
        color: #dc2626;
    }

    .profile-dropdown-item.logout:hover i {
        color: #dc2626;
    }

    .profile-dropdown-divider {
        height: 1px;
        background: #f0f0f0;
        margin: 0;
    }

    /* ---- Command-Palette Search ---- */
    .search-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }

    /* The visible search bar (hidden by default) */
    .search-bar {
        display: flex;
        align-items: center;
        gap: 6px;
        position: absolute;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
        background: #fff;
        border: 1.5px solid #e5e7eb;
        border-radius: 10px;
        padding: 0 10px;
        height: 40px;
        width: 0;
        overflow: hidden;
        opacity: 0;
        pointer-events: none;
        transition: width 0.28s cubic-bezier(.4,0,.2,1),
                    opacity 0.22s ease,
                    border-color 0.2s ease,
                    box-shadow 0.2s ease;
        box-shadow: none;
        z-index: 1001;
        white-space: nowrap;
    }

    .search-bar.open {
        width: 280px;
        opacity: 1;
        pointer-events: all;
        border-color: var(--accent-color, #4f46e5);
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        border-bottom-left-radius: 0;
        border-bottom-right-radius: 0;
        border-bottom-color: transparent;
    }

    .search-bar-icon {
        font-size: 17px;
        color: #9ca3af;
        flex-shrink: 0;
    }

    .search-input {
        border: none;
        outline: none;
        background: transparent;
        font-size: 13.5px;
        color: #111827;
        width: 100%;
        min-width: 0;
        font-family: inherit;
    }

    .search-input::placeholder {
        color: #9ca3af;
    }

    .search-clear-btn {
        background: none;
        border: none;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #9ca3af;
        font-size: 18px;
        flex-shrink: 0;
        transition: color 0.15s ease;
        line-height: 1;
    }

    .search-clear-btn:hover {
        color: #374151;
    }

    /* The toggle icon button sits on top when bar is closed */
    .search-toggle-btn {
        position: relative;
        z-index: 1002;
    }

    /* On mobile: full-width search bar */
    @media (max-width: 768px) {
        .search-bar.open {
            width: 220px;
        }
    }

    /* Results dropdown */
    .search-results {
        display: none;
        position: absolute;
        top: calc(50% + 20px);
        right: 0;
        width: 280px;
        background: #fff;
        border: 1.5px solid var(--accent-color, #4f46e5);
        border-top: none;
        border-bottom-left-radius: 10px;
        border-bottom-right-radius: 10px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        z-index: 1000;
        max-height: 340px;
        overflow-y: auto;
        scrollbar-width: thin;
    }

    .search-results.visible { display: block; }

    .search-results::-webkit-scrollbar { width: 4px; }
    .search-results::-webkit-scrollbar-track { background: transparent; }
    .search-results::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }

    .search-result-group { padding: 6px 0 2px; }

    .search-result-group-label {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .6px;
        text-transform: uppercase;
        color: #9ca3af;
        padding: 4px 14px 2px;
    }

    .search-result-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 14px;
        cursor: pointer;
        transition: background 0.13s ease;
        text-decoration: none;
        color: #374151;
    }

    .search-result-item:hover,
    .search-result-item.focused {
        background: #f3f4f6;
        color: #111827;
    }

    .search-result-item .sri-icon {
        width: 28px;
        height: 28px;
        border-radius: 7px;
        background: #ede9fe;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .search-result-item .sri-icon i {
        font-size: 15px;
        color: #6d28d9;
    }

    .search-result-item .sri-text {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .search-result-item .sri-label {
        font-size: 13px;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .search-result-item .sri-label mark {
        background: #ede9fe;
        color: #4f46e5;
        border-radius: 2px;
        padding: 0 1px;
        font-weight: 700;
    }

    .search-result-item .sri-sub {
        font-size: 11px;
        color: #9ca3af;
        margin-top: 1px;
    }

    .search-no-results {
        padding: 20px 14px;
        text-align: center;
        font-size: 13px;
        color: #9ca3af;
    }

    .search-no-results i {
        display: block;
        font-size: 24px;
        margin-bottom: 6px;
        color: #d1d5db;
    }

    @media (max-width: 768px) {
        .search-results { width: 220px; }
    }
</style>

<nav class="top-navbar" id="topNavbar">
    <div class="navbar-container">
        <div class="navbar-content">

            <!-- Left: Toggle Button -->
            <div class="navbar-left">
                <button class="toggle-btn" id="toggleBtn" title="Toggle Sidebar">
                    <i class='bx bx-menu'></i>
                </button>
            </div>

            <!-- Right: Time, Search, Notifications, Profile -->
            <div class="navbar-right">
                <div class="time-display" id="timeDisplay">
                    <span id="currentTime"></span>
                    <span class="date-separator">•</span>
                    <span id="currentDate"></span>
                </div>

                <!-- Command-Palette Search -->
                <div class="search-wrapper" id="searchWrapper">
                    <div class="search-bar" id="searchBar">
                        <i class='bx bx-search search-bar-icon'></i>
                        <input type="text" id="searchInput" class="search-input" placeholder="Search pages…" autocomplete="off" />
                        <button class="search-clear-btn" id="searchClearBtn" title="Clear">
                            <i class='bx bx-x'></i>
                        </button>
                    </div>
                    <div class="search-results" id="searchResults"></div>
                    <button class="icon-btn search-toggle-btn" id="searchBtn" title="Search">
                        <i class='bx bx-search'></i>
                    </button>
                </div>

                <button class="icon-btn" id="notificationBtn" title="Notifications">
                    <i class='bx bxs-bell'></i>
                    <span class="badge-dot"></span>
                </button>

                <!-- Profile with dropdown -->
                <div class="profile-wrapper">
                    <div class="profile-icon" id="profileBtn" title="Profile">
                        <i class='bx bx-user'></i>
                    </div>

                    <div class="profile-dropdown" id="profileDropdown">

                        <div class="profile-dropdown-header">
                            <div class="avatar">
                                <i class='bx bx-user'></i>
                            </div>
                            <div class="user-info">
                                <span class="user-name">
                                    <?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'Admin User'; ?>
                                </span>
                                <span class="user-role">
                                    <?php echo isset($_SESSION['user_role']) ? htmlspecialchars($_SESSION['user_role']) : 'Administrator'; ?>
                                </span>
                            </div>
                        </div>

                        <a class="profile-dropdown-item" href="profile.php">
                            <i class='bx bx-user-circle'></i>
                            My Profile
                        </a>

                        <div class="profile-dropdown-divider"></div>

                        <a class="profile-dropdown-item logout" href="/logout.php">
                            <i class='bx bx-log-out'></i>
                            Logout
                        </a>

                    </div>
                </div>

            </div>

        </div>
    </div>
</nav>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const sidebar     = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const toggleBtn   = document.getElementById('toggleBtn');
        const overlay     = document.getElementById('overlay');
        const topbar      = document.getElementById('topNavbar');

        /* ---- Live Clock (Philippine Time) ---- */
        function updateTime() {
            const now    = new Date();
            const phTime = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));

            let hours   = phTime.getHours();
            const mins  = String(phTime.getMinutes()).padStart(2, '0');
            const ampm  = hours >= 12 ? 'PM' : 'AM';
            hours       = hours % 12 || 12;

            const days   = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                            'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

            const timeEl = document.getElementById('currentTime');
            const dateEl = document.getElementById('currentDate');

            if (timeEl) timeEl.textContent = `${hours}:${mins} ${ampm}`;
            if (dateEl) dateEl.textContent = `${days[phTime.getDay()]}, ${months[phTime.getMonth()]} ${phTime.getDate()}`;
        }

        updateTime();
        setInterval(updateTime, 1000);

        /* ---- Sidebar Toggle ---- */
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function (e) {
                e.stopPropagation();

                if (window.innerWidth <= 768) {
                    sidebar  && sidebar.classList.toggle('show');
                    overlay  && overlay.classList.toggle('show');
                } else {
                    sidebar     && sidebar.classList.toggle('collapsed');
                    mainContent && mainContent.classList.toggle('expanded');
                    topbar      && topbar.classList.toggle('expanded');
                }
            });
        }

        /* ---- Resize: reset state on breakpoint change ---- */
        window.addEventListener('resize', function () {
            if (window.innerWidth > 768) {
                sidebar     && sidebar.classList.remove('show');
                overlay     && overlay.classList.remove('show');
                sidebar     && sidebar.classList.remove('collapsed');
                mainContent && mainContent.classList.remove('expanded');
                topbar      && topbar.classList.remove('expanded');
            }
        });

        /* ---- Command-Palette Search ---- */
        const searchBtn    = document.getElementById('searchBtn');
        const searchBar    = document.getElementById('searchBar');
        const searchInput  = document.getElementById('searchInput');
        const searchClear  = document.getElementById('searchClearBtn');
        const searchResults = document.getElementById('searchResults');

        // Full page index from sidebar.php
        const PAGES = [
            // Dashboard
            { label: 'Dashboard',                   sub: 'Overview & Stats',              icon: 'bx-tachometer',       href: '/superadmin_dashboard.php',                              group: 'Dashboard' },
            // SWS
            { label: 'Warehouse Overview',           sub: 'Smart Warehousing · SWS',       icon: 'bx-home-alt',         href: '/superadmin/sws/overview.php',                           group: 'Smart Warehousing' },
            { label: 'Inventory Management',         sub: 'Smart Warehousing · SWS',       icon: 'bx-bar-chart-square', href: '/superadmin/sws/inventory.php',                          group: 'Smart Warehousing' },
            { label: 'Stock In / Stock Out',         sub: 'Smart Warehousing · SWS',       icon: 'bx-transfer',         href: '/superadmin/sws/stock.php',                              group: 'Smart Warehousing' },
            { label: 'Bin & Location Mapping',       sub: 'Smart Warehousing · SWS',       icon: 'bx-map',              href: '/superadmin/sws/location.php',                           group: 'Smart Warehousing' },
            // PSM
            { label: 'Purchase Requests',            sub: 'Procurement & Sourcing · PSM',  icon: 'bx-list-ul',          href: '/superadmin/psm/requests.php',                           group: 'Procurement & Sourcing' },
            { label: 'Request for Quotation (RFQ)',  sub: 'Procurement & Sourcing · PSM',  icon: 'bx-send',             href: '/superadmin/psm/rfq.php',                                group: 'Procurement & Sourcing' },
            { label: 'Quotation Evaluation',         sub: 'Procurement & Sourcing · PSM',  icon: 'bx-analyse',          href: '/superadmin/psm/quotation_evaluation.php',               group: 'Procurement & Sourcing' },
            { label: 'Purchase Orders',              sub: 'Procurement & Sourcing · PSM',  icon: 'bx-purchase-tag',     href: '/superadmin/psm/orders.php',                             group: 'Procurement & Sourcing' },
            { label: 'Contract Management',          sub: 'Procurement & Sourcing · PSM',  icon: 'bx-notepad',          href: '/superadmin/psm/contracts.php',                          group: 'Procurement & Sourcing' },
            { label: 'Receiving & Inspection',       sub: 'Procurement & Sourcing · PSM',  icon: 'bx-check-shield',     href: '/superadmin/psm/receiving.php',                          group: 'Procurement & Sourcing' },
            { label: 'Suppliers',                    sub: 'Procurement & Sourcing · PSM',  icon: 'bx-user-check',       href: '/superadmin/psm/suppliers.php',                          group: 'Procurement & Sourcing' },
            // PLT
            { label: 'Active Projects',              sub: 'Project Logistics · PLT',       icon: 'bx-task',             href: '/superadmin/plt/active.php',                             group: 'Project Logistics' },
            { label: 'Delivery Schedule',            sub: 'Project Logistics · PLT',       icon: 'bx-calendar',         href: '/superadmin/plt/delivery_schedule.php',                  group: 'Project Logistics' },
            { label: 'Logistics Assignments',        sub: 'Project Logistics · PLT',       icon: 'bx-user-pin',         href: '/superadmin/plt/logistics_assignments.php',              group: 'Project Logistics' },
            { label: 'Milestone Tracking',           sub: 'Project Logistics · PLT',       icon: 'bx-flag',             href: '/superadmin/plt/milestone_tracking.php',                 group: 'Project Logistics' },
            // ALMS
            { label: 'Asset Registry',               sub: 'Asset Lifecycle · ALMS',        icon: 'bx-server',           href: '/superadmin/alms/asset_registry.php',                    group: 'Asset Lifecycle' },
            { label: 'Preventive Maintenance',       sub: 'Asset Lifecycle · ALMS',        icon: 'bx-time',             href: '/superadmin/alms/preventive_maintenance.php',            group: 'Asset Lifecycle' },
            { label: 'Repair & Service Logs',        sub: 'Asset Lifecycle · ALMS',        icon: 'bx-note',             href: '/superadmin/alms/repair_service_logs.php',               group: 'Asset Lifecycle' },
            { label: 'Asset Disposal',               sub: 'Asset Lifecycle · ALMS',        icon: 'bx-trash',            href: '/superadmin/alms/asset_disposal.php',                    group: 'Asset Lifecycle' },
            // DTRS
            { label: 'Document Registry',            sub: 'Document Tracking · DTRS',      icon: 'bx-spreadsheet',      href: '/superadmin/dtrs/registry.php',                          group: 'Document Tracking' },
            { label: 'Document Capture',             sub: 'Document Tracking · DTRS',      icon: 'bx-scan',             href: '/superadmin/dtrs/capture.php',                           group: 'Document Tracking' },
            { label: 'Document Routing',             sub: 'Document Tracking · DTRS',      icon: 'bx-git-branch',       href: '/superadmin/dtrs/routing.php',                           group: 'Document Tracking' },
            { label: 'Records Lifecycle Management', sub: 'Document Tracking · DTRS',      icon: 'bx-refresh',          href: '/superadmin/dtrs/records_lifecycle.php',                 group: 'Document Tracking' },
            // Admin
            { label: 'User Management',              sub: 'System Administration',         icon: 'bx-group',            href: '/superadmin/admin/user_management.php',                  group: 'System Administration' },
            { label: 'Role & Permission Management', sub: 'System Administration',         icon: 'bx-key',              href: '/superadmin/admin/role_permission_management.php',       group: 'System Administration' },
            { label: 'Audit Logs',                   sub: 'System Administration',         icon: 'bx-list-check',       href: '/superadmin/admin/audit_logs.php',                       group: 'System Administration' },
            { label: 'System Settings',              sub: 'System Administration',         icon: 'bx-shield',           href: '/superadmin/admin/system_settings.php',                  group: 'System Administration' },
            { label: 'Notifications & Alerts',       sub: 'System Administration',         icon: 'bx-bell',             href: '/superadmin/admin/notifications_alerts.php',             group: 'System Administration' },
        ];

        let focusedIndex = -1;

        function highlight(text, query) {
            if (!query) return text;
            const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            return text.replace(new RegExp(`(${escaped})`, 'gi'), '<mark>$1</mark>');
        }

        function renderResults(query) {
            const q = query.trim().toLowerCase();
            if (!q) {
                searchResults.classList.remove('visible');
                searchResults.innerHTML = '';
                focusedIndex = -1;
                return;
            }

            const matches = PAGES.filter(p =>
                p.label.toLowerCase().includes(q) ||
                p.sub.toLowerCase().includes(q) ||
                p.group.toLowerCase().includes(q)
            );

            if (!matches.length) {
                searchResults.innerHTML = `
                    <div class="search-no-results">
                        <i class='bx bx-search-alt'></i>
                        No pages found for "<strong>${query}</strong>"
                    </div>`;
                searchResults.classList.add('visible');
                focusedIndex = -1;
                return;
            }

            // Group results
            const grouped = {};
            matches.forEach(p => {
                if (!grouped[p.group]) grouped[p.group] = [];
                grouped[p.group].push(p);
            });

            let html = '';
            Object.entries(grouped).forEach(([group, items]) => {
                html += `<div class="search-result-group">
                    <div class="search-result-group-label">${group}</div>`;
                items.forEach(p => {
                    html += `
                    <a class="search-result-item" href="${p.href}" tabindex="-1">
                        <div class="sri-icon"><i class='bx ${p.icon}'></i></div>
                        <div class="sri-text">
                            <span class="sri-label">${highlight(p.label, query.trim())}</span>
                            <span class="sri-sub">${p.sub}</span>
                        </div>
                    </a>`;
                });
                html += `</div>`;
            });

            searchResults.innerHTML = html;
            searchResults.classList.add('visible');
            focusedIndex = -1;
        }

        function getFocusableItems() {
            return Array.from(searchResults.querySelectorAll('.search-result-item'));
        }

        function moveFocus(dir) {
            const items = getFocusableItems();
            if (!items.length) return;
            items.forEach(i => i.classList.remove('focused'));
            focusedIndex = (focusedIndex + dir + items.length) % items.length;
            items[focusedIndex].classList.add('focused');
            items[focusedIndex].scrollIntoView({ block: 'nearest' });
        }

        function openSearch() {
            searchBar.classList.add('open');
            searchBtn.style.opacity = '0';
            searchBtn.style.pointerEvents = 'none';
            setTimeout(() => searchInput && searchInput.focus(), 50);
        }

        function closeSearch() {
            searchBar.classList.remove('open');
            searchResults.classList.remove('visible');
            searchResults.innerHTML = '';
            searchBtn.style.opacity = '';
            searchBtn.style.pointerEvents = '';
            if (searchInput) searchInput.value = '';
            focusedIndex = -1;
        }

        if (searchBtn) {
            searchBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                openSearch();
            });
        }

        if (searchClear) {
            searchClear.addEventListener('click', function (e) {
                e.stopPropagation();
                closeSearch();
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                renderResults(this.value);
            });

            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') { e.preventDefault(); moveFocus(1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); moveFocus(-1); }
                else if (e.key === 'Enter') {
                    const items = getFocusableItems();
                    if (focusedIndex >= 0 && items[focusedIndex]) {
                        window.location.href = items[focusedIndex].getAttribute('href');
                    } else if (items.length === 1) {
                        window.location.href = items[0].getAttribute('href');
                    }
                }
                else if (e.key === 'Escape') { closeSearch(); }
            });
        }

        document.addEventListener('click', function (e) {
            const wrapper = document.getElementById('searchWrapper');
            if (searchBar && searchBar.classList.contains('open')) {
                if (wrapper && !wrapper.contains(e.target)) closeSearch();
            }
        });

        /* ---- Notification Button ---- */
        const notifBtn = document.getElementById('notificationBtn');
        if (notifBtn) {
            notifBtn.addEventListener('click', function () {
                window.location.href = '/superadmin/admin/notifications_alerts.php';
            });
        }

        /* ---- Profile Dropdown Toggle ---- */
        const profileBtn      = document.getElementById('profileBtn');
        const profileDropdown = document.getElementById('profileDropdown');

        if (profileBtn && profileDropdown) {
            profileBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                profileDropdown.classList.toggle('open');
            });

            document.addEventListener('click', function (e) {
                if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                    profileDropdown.classList.remove('open');
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    profileDropdown.classList.remove('open');
                }
            });
        }

        /* ---- Prevent Browser Back Button ---- */
        window.history.pushState(null, "", window.location.href);
        window.onpopstate = function () {
            window.history.pushState(null, "", window.location.href);
        };
    });
</script>