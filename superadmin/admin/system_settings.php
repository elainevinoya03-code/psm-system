<?php
$root = $_SERVER['DOCUMENT_ROOT'] . '/Log1';
include $root . '/includes/superadmin_sidebar.php';
include $root . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Settings — Admin</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/Log1/css/base.css">
<link rel="stylesheet" href="/Log1/css/sidebar.css">
<link rel="stylesheet" href="/Log1/css/header.css">
<style>
/* ── RESET & TOKENS ─────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --primary-color:#2E7D32;
  --primary-dark:#1B5E20;
  --primary-light:#E8F5E9;
  --text-primary:#1A2E1C;
  --text-secondary:#5D6F62;
  --bg-color:#F4F7F4;
  --hover-bg-light:#F0FAF0;
  --transition:all .18s cubic-bezier(.4,0,.2,1);
  --s:#fff;
  --bd:rgba(46,125,50,.13);
  --bdm:rgba(46,125,50,.26);
  --t1:var(--text-primary);
  --t2:var(--text-secondary);
  --t3:#9EB0A2;
  --hbg:var(--hover-bg-light);
  --bg:var(--bg-color);
  --grn:var(--primary-color);
  --gdk:var(--primary-dark);
  --red:#DC2626;
  --amb:#D97706;
  --blu:#2563EB;
  --tel:#0D9488;
  --shmd:0 4px 20px rgba(46,125,50,.12);
  --shlg:0 24px 60px rgba(0,0,0,.22);
  --rad:12px;
  --tr:var(--transition);
}
body{font-family:'Inter',sans-serif;background:var(--bg-color);color:var(--text-primary);min-height:100vh;display:flex;}

/* ── MAIN ─────────────────────────────────────────────────── */

/* ── HEADER ─────────────────────────────────────────────── */
.top-hdr{display:flex;align-items:center;justify-content:space-between;background:#fff;border:1px solid var(--bd);border-radius:var(--rad);padding:12px 20px;margin-bottom:26px;box-shadow:0 1px 4px rgba(46,125,50,.06);}
.thdr-l{display:flex;align-items:center;gap:12px;}
.thdr-bc{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--t2);}
.thdr-bc a{color:var(--t2);text-decoration:none;transition:var(--tr);}
.thdr-bc a:hover{color:var(--grn);}
.thdr-bc i{font-size:14px;color:var(--t3);}
.thdr-r{display:flex;align-items:center;gap:10px;}
.thdr-ic{width:34px;height:34px;border-radius:9px;border:1px solid var(--bdm);background:#fff;display:grid;place-content:center;font-size:17px;color:var(--t2);cursor:pointer;transition:var(--tr);position:relative;}
.thdr-ic:hover{background:var(--hbg);color:var(--grn);}
.notif-dot{position:absolute;top:6px;right:6px;width:7px;height:7px;border-radius:50%;background:var(--red);border:2px solid #fff;}

/* ── PAGE HEADER ─────────────────────────────────────────── */
.pg-ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:26px;animation:UP .4s both;}
.pg-ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--grn);margin-bottom:4px;}
.pg-ph h1{font-size:26px;font-weight:800;color:var(--t1);line-height:1.15;}
.pg-ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}

/* ── BUTTONS ─────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.32);}
.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}
.btn-ghost:hover{background:var(--hbg);color:var(--t1);}
.btn-sm{font-size:12px;padding:7px 14px;}
.btn-xs{font-size:11px;padding:4px 9px;border-radius:7px;}
.btn-warn{background:#FFFBEB;color:#92400E;border:1px solid #FCD34D;}
.btn-warn:hover{background:#FEF3C7;}
.btn-disabled{background:#F3F4F6;color:#9CA3AF;border:1px solid #E5E7EB;cursor:not-allowed;opacity:.7;}
.btn-danger{background:#FEE2E2;color:var(--red);border:1px solid #FECACA;}
.btn-danger:hover{background:#FCA5A5;}

/* ── NOTICE BANNER ─────────────────────────────────────────── */
.notice-banner{display:flex;align-items:flex-start;gap:12px;background:linear-gradient(135deg,#FFFBEB,#FEF3C7);border:1px solid #FCD34D;border-radius:12px;padding:14px 18px;margin-bottom:22px;animation:UP .4s .03s both;}
.notice-banner i{font-size:20px;color:#D97706;flex-shrink:0;margin-top:1px;}
.nb-body{}
.nb-t{font-size:13px;font-weight:700;color:#92400E;margin-bottom:2px;}
.nb-s{font-size:12px;color:#B45309;line-height:1.6;}
.nb-s strong{font-weight:700;}

/* ── TABS ─────────────────────────────────────────────────── */
.st-tabs{display:flex;gap:4px;background:#fff;border:1px solid var(--bd);border-radius:14px;padding:5px;margin-bottom:24px;animation:UP .4s .06s both;overflow-x:auto;}
.st-tab{display:flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 16px;border-radius:10px;cursor:pointer;border:none;background:transparent;color:var(--t2);white-space:nowrap;transition:var(--tr);}
.st-tab:hover{background:var(--hbg);color:var(--t1);}
.st-tab.active{background:var(--grn);color:#fff;}
.st-tab i{font-size:16px;}

/* ── SECTION GRID ─────────────────────────────────────────── */
.st-grid{display:grid;grid-template-columns:280px 1fr;gap:20px;animation:UP .4s .09s both;}
.st-sidebar{display:flex;flex-direction:column;gap:12px;}
.st-main{display:flex;flex-direction:column;gap:20px;}

/* ── INFO CARD (sidebar) ─────────────────────────────────── */
.info-card{background:#fff;border:1px solid var(--bd);border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(46,125,50,.07);}
.ic-hd{padding:14px 16px;border-bottom:1px solid var(--bd);background:var(--bg);}
.ic-hd-t{font-size:12px;font-weight:700;color:var(--t1);}
.ic-hd-s{font-size:11px;color:var(--t2);margin-top:2px;}
.ic-body{padding:14px 16px;display:flex;flex-direction:column;gap:10px;}
.ic-row{display:flex;flex-direction:column;gap:2px;}
.ic-row label{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--t3);}
.ic-row .v{font-size:12.5px;font-weight:600;color:var(--t1);}
.ic-row .v.mono{font-family:'DM Mono',monospace;font-size:11.5px;color:var(--grn);}
.ic-row .v.muted{font-weight:400;color:#4B5563;}

/* ── PERMISSION LEGEND ─────────────────────────────────────── */
.perm-card{background:#fff;border:1px solid var(--bd);border-radius:14px;overflow:hidden;}
.perm-hd{padding:14px 16px;border-bottom:1px solid var(--bd);background:var(--bg);display:flex;align-items:center;gap:8px;}
.perm-hd i{font-size:16px;color:var(--grn);}
.perm-hd-t{font-size:12px;font-weight:700;color:var(--t1);}
.perm-body{padding:14px 16px;display:flex;flex-direction:column;gap:6px;}
.perm-item{display:flex;align-items:flex-start;gap:8px;font-size:11.5px;color:var(--t2);line-height:1.45;}
.perm-item i{font-size:14px;flex-shrink:0;margin-top:1px;}
.perm-can i{color:#166534;}
.perm-cant i{color:var(--red);}
.perm-divider{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--t3);padding:4px 0 2px;border-top:1px solid var(--bd);margin-top:4px;}

/* ── CONTENT CARDS ─────────────────────────────────────────── */
.sec-card{background:#fff;border:1px solid var(--bd);border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(46,125,50,.07);}
.sec-hd{padding:16px 20px;border-bottom:1px solid var(--bd);background:var(--bg);display:flex;align-items:center;justify-content:space-between;gap:12px;}
.sec-hd-l{display:flex;align-items:center;gap:10px;}
.sec-hd-ic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.ic-b{background:#EFF6FF;color:var(--blu)} .ic-a{background:#FEF3C7;color:var(--amb)}
.ic-g{background:#DCFCE7;color:#166534}    .ic-r{background:#FEE2E2;color:var(--red)}
.ic-t{background:#CCFBF1;color:var(--tel)} .ic-p{background:#F5F3FF;color:#6D28D9}
.ic-d{background:#F3F4F6;color:#374151}
.sec-hd-t{font-size:14px;font-weight:700;color:var(--t1);}
.sec-hd-s{font-size:11.5px;color:var(--t2);margin-top:1px;}
.sec-body{padding:20px;}
.sec-body-rows{display:flex;flex-direction:column;gap:0;}

/* ── SETTING ROWS ─────────────────────────────────────────── */
.setting-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 0;border-bottom:1px solid var(--bd);}
.setting-row:last-child{border-bottom:none;padding-bottom:0;}
.setting-row:first-child{padding-top:0;}
.sr-l{flex:1;min-width:0;}
.sr-t{font-size:13px;font-weight:600;color:var(--t1);display:flex;align-items:center;gap:6px;}
.sr-s{font-size:11.5px;color:var(--t2);margin-top:2px;line-height:1.5;}
.sr-v{font-size:13px;font-weight:600;color:var(--t1);display:flex;align-items:center;gap:8px;flex-shrink:0;}
.sr-v.mono{font-family:'DM Mono',monospace;font-size:12px;color:var(--grn);}
.sr-v.muted{color:#6B7280;font-weight:400;}
.lock-ic{font-size:13px;color:var(--t3);}

/* ── TAGS / CHIPS ─────────────────────────────────────────── */
.chip{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;white-space:nowrap;}
.chip-grn{background:#DCFCE7;color:#166534;}
.chip-amb{background:#FEF3C7;color:#92400E;}
.chip-blu{background:#EFF6FF;color:#1D4ED8;}
.chip-red{background:#FEE2E2;color:#991B1B;}
.chip-gry{background:#F3F4F6;color:#374151;}
.chip-tel{background:#CCFBF1;color:#0F766E;}
.chip::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0;}

/* ── TOGGLE SWITCH (view only) ─────────────────────────────── */
.tgl-wrap{display:flex;align-items:center;gap:7px;}
.tgl{width:38px;height:21px;border-radius:11px;position:relative;flex-shrink:0;transition:var(--tr);}
.tgl.on{background:var(--grn);}
.tgl.off{background:#D1D5DB;}
.tgl.locked{opacity:.6;cursor:not-allowed;}
.tgl-knob{width:15px;height:15px;border-radius:50%;background:#fff;position:absolute;top:3px;transition:var(--tr);box-shadow:0 1px 3px rgba(0,0,0,.2);}
.tgl.on .tgl-knob{left:20px;}
.tgl.off .tgl-knob{left:3px;}
.tgl-lbl{font-size:12px;font-weight:600;color:var(--t2);}

/* ── REQUEST CHANGE BANNER ─────────────────────────────────── */
.rc-bar{display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,#F0FDF4,#DCFCE7);border:1px solid rgba(46,125,50,.2);border-radius:10px;padding:12px 16px;}
.rc-bar i{font-size:18px;color:var(--grn);flex-shrink:0;}
.rc-bar-t{font-size:13px;font-weight:600;color:#166534;flex:1;}
.rc-bar-s{font-size:11px;color:#4ADE80;margin-top:1px;}

/* ── MODULE TABLE ─────────────────────────────────────────── */
.mod-tbl{width:100%;border-collapse:collapse;font-size:12.5px;}
.mod-tbl thead th{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--t2);padding:9px 12px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);}
.mod-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .13s;}
.mod-tbl tbody tr:last-child{border-bottom:none;}
.mod-tbl tbody tr:hover{background:var(--hbg);}
.mod-tbl tbody td{padding:11px 12px;vertical-align:middle;}
.mod-nm{font-weight:600;color:var(--t1);}
.mod-desc{font-size:11px;color:var(--t2);margin-top:1px;}
.mod-ic{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.mod-cell{display:flex;align-items:center;gap:9px;}

/* ── NOTIFICATION MATRIX ─────────────────────────────────── */
.notif-grid{display:grid;gap:10px;}
.notif-item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;background:var(--bg);border:1px solid var(--bd);border-radius:10px;}
.ni-l{display:flex;align-items:center;gap:10px;}
.ni-ic{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.ni-t{font-size:13px;font-weight:600;color:var(--t1);}
.ni-s{font-size:11.5px;color:var(--t2);margin-top:1px;}
.ni-ch{display:flex;gap:6px;align-items:center;flex-shrink:0;}

/* ── INTEGRATIONS TABLE ─────────────────────────────────────── */
.int-item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 0;border-bottom:1px solid var(--bd);}
.int-item:last-child{border-bottom:none;padding-bottom:0;}
.int-item:first-child{padding-top:0;}
.int-l{display:flex;align-items:center;gap:12px;}
.int-logo{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:19px;border:1px solid var(--bd);background:#fff;flex-shrink:0;}
.int-nm{font-size:13px;font-weight:700;color:var(--t1);}
.int-url{font-family:'DM Mono',monospace;font-size:11px;color:var(--t2);margin-top:2px;}
.int-r{display:flex;align-items:center;gap:8px;flex-shrink:0;}

/* ── LOCKED OVERLAY for cards ─────────────────────────────── */
.locked-row{display:flex;align-items:center;gap:8px;padding:12px 16px;background:#FAFAFA;border:1px dashed #D1D5DB;border-radius:10px;font-size:12.5px;color:#9CA3AF;margin-top:8px;}
.locked-row i{font-size:16px;color:#D1D5DB;}

/* ── AUDIT ZONE ─────────────────────────────────────────────── */
.audit-item{display:flex;gap:12px;padding:11px 0;border-bottom:1px solid var(--bd);}
.audit-item:last-child{border-bottom:none;padding-bottom:0;}
.audit-item:first-child{padding-top:0;}
.audit-dot{width:27px;height:27px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;}
.ad-c{background:#DCFCE7;color:#166534}.ad-s{background:#EFF6FF;color:#2563EB}
.ad-a{background:#DCFCE7;color:#166534}.ad-r{background:#FEE2E2;color:#DC2626}
.ad-e{background:#F3F4F6;color:#6B7280}.ad-o{background:#FEF3C7;color:#D97706}
.ad-x{background:#F3F4F6;color:#374151}.ad-d{background:#F5F3FF;color:#6D28D9}
.audit-body{flex:1;min-width:0;}
.audit-body .au{font-size:12.5px;font-weight:500;color:var(--t1);}
.audit-body .at{font-size:11px;color:#9EB0A2;margin-top:2px;font-family:'DM Mono',monospace;}
.audit-ts{font-family:'DM Mono',monospace;font-size:10px;color:#9EB0A2;flex-shrink:0;margin-left:auto;padding-left:8px;white-space:nowrap;}

/* ── MODALS ─────────────────────────────────────────────────── */
#reqModal,#viewModal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9050;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;}
#reqModal.on,#viewModal.on{opacity:1;pointer-events:all;}
.rm-box,.vm-box{background:#fff;border-radius:16px;width:460px;max-width:100%;box-shadow:var(--shlg);overflow:hidden;}
.vm-box{width:540px;}
.rm-hd{padding:20px 22px 18px;border-bottom:1px solid var(--bd);background:var(--bg);display:flex;align-items:flex-start;justify-content:space-between;}
.rm-hd-t{font-size:16px;font-weight:700;color:var(--t1);}
.rm-hd-s{font-size:12px;color:var(--t2);margin-top:2px;}
.rm-cl{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:#fff;cursor:pointer;display:grid;place-content:center;font-size:18px;color:var(--t2);transition:var(--tr);}
.rm-cl:hover{background:#FEE2E2;color:var(--red);border-color:#FECACA;}
.rm-body{padding:20px 22px;display:flex;flex-direction:column;gap:14px;}
.rm-fg{display:flex;flex-direction:column;gap:5px;}
.rm-fg label{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--t2);}
.rm-fg input,.rm-fg select,.rm-fg textarea{font-family:'Inter',sans-serif;font-size:13px;padding:9px 12px;border:1px solid var(--bdm);border-radius:9px;background:#fff;color:var(--t1);outline:none;transition:var(--tr);width:100%;}
.rm-fg input:focus,.rm-fg select:focus,.rm-fg textarea:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.1);}
.rm-fg textarea{resize:vertical;min-height:75px;}
.rm-fg select{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;padding-right:28px;}
.rm-ft{padding:14px 22px;border-top:1px solid var(--bd);background:var(--bg);display:flex;gap:8px;justify-content:flex-end;}
.rm-note{display:flex;align-items:flex-start;gap:8px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:9px;padding:10px 12px;font-size:11.5px;color:#92400E;line-height:1.55;}
.rm-note i{font-size:15px;flex-shrink:0;margin-top:1px;}

/* ── TOAST ─────────────────────────────────────────────────── */
.ss-toasts{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;}
.toast{background:#0A1F0D;color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:var(--shlg);pointer-events:all;min-width:220px;animation:TIN .3s ease;}
.toast.ts{background:var(--grn);}.toast.tw{background:var(--amb);}.toast.td{background:var(--red);}
.toast.out{animation:TOUT .3s ease forwards;}

/* ── ANIMATIONS ─────────────────────────────────────────────── */
@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes TOUT{from{opacity:1;transform:translateY(0)}to{opacity:0;transform:translateY(8px)}}

/* ── RESPONSIVE ─────────────────────────────────────────────── */
@media(max-width:1100px){.st-grid{grid-template-columns:1fr;}.st-sidebar{display:grid;grid-template-columns:1fr 1fr;gap:12px;}}
@media(max-width:640px){.st-sidebar{grid-template-columns:1fr;}.st-tabs{padding:4px;}.st-tab{padding:7px 12px;font-size:12px;}}
@media(max-width:640px){.st-sidebar{grid-template-columns:1fr;}.st-tabs{padding:4px;}.st-tab{padding:7px 12px;font-size:12px;}}
</style>
</head>
<body>

<main class="main-content" id="mainContent">

  <!-- PAGE HEADER -->
  <div class="pg-ph">
    <div>
      <p class="ey">System Administration</p>
      <h1>System Settings</h1>
    </div>
    <div class="pg-ph-r">
      <button class="btn btn-ghost btn-sm" onclick="toast('Change log exported.','s')"><i class="bx bx-export"></i> Export Change Log</button>
      <button class="btn btn-primary btn-sm" onclick="openReqModal()"><i class="bx bx-send"></i> Request Change</button>
    </div>
  </div>

  <!-- NOTICE BANNER -->
  <div class="notice-banner">
    <i class="bx bx-lock-alt"></i>
    <div class="nb-body">
      <div class="nb-t">View-Only Access — Zone Manager Role</div>
      <div class="nb-s">You can <strong>view</strong> all zone-specific configurations. To modify master settings, enable/disable modules, or set RA 9184 compliance rules, please submit a <strong>Change Request</strong> to the System Administrator. Changes are logged and audited.</div>
    </div>
  </div>

  <!-- TABS -->
  <div class="st-tabs" id="mainTabs">
    <button class="st-tab active" data-tab="general" onclick="switchTab('general',this)"><i class="bx bx-building"></i> General</button>
    <button class="st-tab" data-tab="modules" onclick="switchTab('modules',this)"><i class="bx bx-grid-alt"></i> Modules</button>
    <button class="st-tab" data-tab="notifications" onclick="switchTab('notifications',this)"><i class="bx bx-bell"></i> Notifications</button>
    <button class="st-tab" data-tab="integrations" onclick="switchTab('integrations',this)"><i class="bx bx-link"></i> Integrations</button>
  </div>

  <!-- ════ TAB: GENERAL ════ -->
  <div class="tab-panel" id="tab-general">
    <div class="st-grid">
      <!-- Sidebar -->
      <div class="st-sidebar">
        <div class="info-card">
          <div class="ic-hd">
            <div class="ic-hd-t">Organization Info</div>
            <div class="ic-hd-s">Zone-level view only</div>
          </div>
          <div class="ic-body">
            <div class="ic-row"><label>Organization</label><div class="v">MicroFinance Corp.</div></div>
            <div class="ic-row"><label>Zone</label><div class="v">NCR — Luzon Zone 1</div></div>
            <div class="ic-row"><label>Zone Code</label><div class="v mono">ZN-NCR-001</div></div>
            <div class="ic-row"><label>Branch Count</label><div class="v">12 Active Branches</div></div>
            <div class="ic-row"><label>System Version</label><div class="v mono">v4.2.1-stable</div></div>
            <div class="ic-row"><label>Last Sync</label><div class="v muted">Mar 14, 2026 08:00 AM</div></div>
          </div>
        </div>
        <div class="perm-card">
          <div class="perm-hd">
            <i class="bx bx-shield-quarter"></i>
            <div class="perm-hd-t">Your Permissions</div>
          </div>
          <div class="perm-body">
            <div class="perm-divider" style="margin-top:0;border-top:none;padding-top:0;">Can Do</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>View organization settings</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>Zone timezone preferences</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>View zone module defaults</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>Request configuration changes</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>Configure team notifications</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>View zone module linkages</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>Request API access</div>
            <div class="perm-divider">Cannot Do</div>
            <div class="perm-item perm-cant"><i class="bx bx-x-circle"></i>Master configuration changes</div>
            <div class="perm-item perm-cant"><i class="bx bx-x-circle"></i>Enable / disable modules</div>
            <div class="perm-item perm-cant"><i class="bx bx-x-circle"></i>Set RA 9184 compliance rules</div>
          </div>
        </div>
      </div>

      <!-- Main -->
      <div class="st-main">
        <!-- Org Settings -->
        <div class="sec-card">
          <div class="sec-hd">
            <div class="sec-hd-l">
              <div class="sec-hd-ic ic-b"><i class="bx bx-buildings"></i></div>
              <div>
                <div class="sec-hd-t">Organization Settings</div>
                <div class="sec-hd-s">View organization-wide configuration (read-only for your role)</div>
              </div>
            </div>
            <button class="btn btn-ghost btn-sm" onclick="openReqModal('org')"><i class="bx bx-edit"></i> Request Change</button>
          </div>
          <div class="sec-body">
            <div class="sec-body-rows">
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">Organization Name <i class="bx bx-lock-alt lock-ic"></i></div><div class="sr-s">Official registered name of the microfinance institution</div></div>
                <div class="sr-v">MicroFinance Corp.</div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">Head Office Address <i class="bx bx-lock-alt lock-ic"></i></div><div class="sr-s">Primary business address for regulatory filings</div></div>
                <div class="sr-v muted" style="max-width:200px;text-align:right;font-size:12px;">123 Ayala Ave., Makati City, Metro Manila</div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">SEC Registration No. <i class="bx bx-lock-alt lock-ic"></i></div><div class="sr-s">Securities and Exchange Commission registration</div></div>
                <div class="sr-v mono">SEC-2019-00421-A</div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">BSP License No. <i class="bx bx-lock-alt lock-ic"></i></div><div class="sr-s">Bangko Sentral ng Pilipinas operating license</div></div>
                <div class="sr-v mono">BSP-MFI-2019-0091</div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">RA 9184 Compliance Mode <i class="bx bx-lock-alt lock-ic"></i></div><div class="sr-s">Government Procurement Reform Act compliance level — Super Admin only</div></div>
                <span class="chip chip-amb">Strict Mode · Locked</span>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">Fiscal Year</div><div class="sr-s">Current fiscal year period for budget and reporting</div></div>
                <div class="sr-v">January – December 2026</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Zone Settings -->
        <div class="sec-card">
          <div class="sec-hd">
            <div class="sec-hd-l">
              <div class="sec-hd-ic ic-g"><i class="bx bx-map-pin"></i></div>
              <div>
                <div class="sec-hd-t">Zone Configuration</div>
                <div class="sec-hd-s">Settings specific to NCR — Luzon Zone 1</div>
              </div>
            </div>
            <button class="btn btn-ghost btn-sm" onclick="openReqModal('zone')"><i class="bx bx-edit"></i> Request Change</button>
          </div>
          <div class="sec-body">
            <div class="sec-body-rows">
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">Zone Name</div><div class="sr-s">Display name for this zone across all modules</div></div>
                <div class="sr-v">NCR — Luzon Zone 1</div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">Timezone</div><div class="sr-s">All timestamps in this zone use this timezone</div></div>
                <div class="sr-v"><i class="bx bx-time" style="color:var(--grn);font-size:15px;"></i>Asia/Manila (UTC+8)</div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">Date Format</div><div class="sr-s">How dates are displayed across zone reports and UI</div></div>
                <div class="sr-v mono">MMM DD, YYYY</div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">Currency</div><div class="sr-s">Default currency for all financial transactions</div></div>
                <div class="sr-v"><span class="chip chip-grn">PHP — Philippine Peso ₱</span></div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">Language</div><div class="sr-s">Default UI language for zone users</div></div>
                <div class="sr-v">English (Philippines)</div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">Zone Manager</div><div class="sr-s">Assigned manager responsible for this zone</div></div>
                <div class="sr-v">Juan Dela Cruz</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Changes -->
        <div class="sec-card">
          <div class="sec-hd">
            <div class="sec-hd-l">
              <div class="sec-hd-ic ic-d"><i class="bx bx-history"></i></div>
              <div>
                <div class="sec-hd-t">Recent Configuration Changes</div>
                <div class="sec-hd-s">Last 5 approved change requests for this zone</div>
              </div>
            </div>
          </div>
          <div class="sec-body">
            <div id="auditGeneral"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ════ TAB: MODULES ════ -->
  <div class="tab-panel" id="tab-modules" style="display:none;">
    <div class="st-grid">
      <div class="st-sidebar">
        <div class="info-card">
          <div class="ic-hd"><div class="ic-hd-t">Module Summary</div><div class="ic-hd-s">Zone activation status</div></div>
          <div class="ic-body">
            <div class="ic-row"><label>Total Modules</label><div class="v">18 Modules</div></div>
            <div class="ic-row"><label>Active</label><div class="v" style="color:#166534;">14 Active</div></div>
            <div class="ic-row"><label>Inactive</label><div class="v" style="color:#6B7280;">3 Inactive</div></div>
            <div class="ic-row"><label>Pending Request</label><div class="v" style="color:var(--amb);">1 Pending</div></div>
          </div>
        </div>
        <div class="perm-card">
          <div class="perm-hd"><i class="bx bx-info-circle"></i><div class="perm-hd-t">Module Permissions</div></div>
          <div class="perm-body">
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>View zone module defaults</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>Request module access changes</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>View module version info</div>
            <div class="perm-divider">Cannot Do</div>
            <div class="perm-item perm-cant"><i class="bx bx-x-circle"></i>Enable or disable modules</div>
            <div class="perm-item perm-cant"><i class="bx bx-x-circle"></i>Change module configurations</div>
          </div>
        </div>
      </div>
      <div class="st-main">
        <div class="sec-card">
          <div class="sec-hd">
            <div class="sec-hd-l">
              <div class="sec-hd-ic ic-p"><i class="bx bx-grid-alt"></i></div>
              <div>
                <div class="sec-hd-t">Zone Module Defaults</div>
                <div class="sec-hd-s">View current activation status — enable/disable requires Super Admin</div>
              </div>
            </div>
            <button class="btn btn-warn btn-sm" onclick="openReqModal('module')"><i class="bx bx-send"></i> Request Access Change</button>
          </div>
          <div style="overflow-x:auto;">
            <table class="mod-tbl" id="modTable">
              <thead>
                <tr>
                  <th>Module</th>
                  <th>Category</th>
                  <th>Version</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="modTbody"></tbody>
            </table>
          </div>
        </div>
        <div class="sec-card">
          <div class="sec-hd">
            <div class="sec-hd-l">
              <div class="sec-hd-ic ic-r"><i class="bx bx-lock-alt"></i></div>
              <div>
                <div class="sec-hd-t">Restricted Configuration</div>
                <div class="sec-hd-s">These settings require Super Admin authority</div>
              </div>
            </div>
          </div>
          <div class="sec-body">
            <div class="locked-row"><i class="bx bx-lock-alt"></i>Enable / Disable Modules globally — Super Admin only</div>
            <div class="locked-row" style="margin-top:8px;"><i class="bx bx-lock-alt"></i>Set RA 9184 Procurement Compliance Rules — Super Admin only</div>
            <div class="locked-row" style="margin-top:8px;"><i class="bx bx-lock-alt"></i>Master Module Configuration — Super Admin only</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ════ TAB: NOTIFICATIONS ════ -->
  <div class="tab-panel" id="tab-notifications" style="display:none;">
    <div class="st-grid">
      <div class="st-sidebar">
        <div class="info-card">
          <div class="ic-hd"><div class="ic-hd-t">Alert Summary</div><div class="ic-hd-s">Zone notification status</div></div>
          <div class="ic-body">
            <div class="ic-row"><label>Active Alert Rules</label><div class="v" style="color:#166534;">9 Active</div></div>
            <div class="ic-row"><label>Channels Enabled</label><div class="v">Email, SMS, In-App</div></div>
            <div class="ic-row"><label>Team Recipients</label><div class="v">24 Users</div></div>
            <div class="ic-row"><label>Digest Frequency</label><div class="v">Daily @ 08:00 AM</div></div>
          </div>
        </div>
        <div class="perm-card">
          <div class="perm-hd"><i class="bx bx-bell"></i><div class="perm-hd-t">Notification Permissions</div></div>
          <div class="perm-body">
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>View zone alert rules</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>Configure team notification preferences</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>Add/remove team recipients</div>
            <div class="perm-divider">Cannot Do</div>
            <div class="perm-item perm-cant"><i class="bx bx-x-circle"></i>Create global alert rules</div>
            <div class="perm-item perm-cant"><i class="bx bx-x-circle"></i>Disable system-wide alerts</div>
          </div>
        </div>
      </div>
      <div class="st-main">
        <div class="sec-card">
          <div class="sec-hd">
            <div class="sec-hd-l">
              <div class="sec-hd-ic ic-a"><i class="bx bx-bell-ring"></i></div>
              <div>
                <div class="sec-hd-t">Zone Alert Rules</div>
                <div class="sec-hd-s">View active alert triggers for NCR — Luzon Zone 1</div>
              </div>
            </div>
          </div>
          <div class="sec-body">
            <div class="notif-grid" id="alertGrid"></div>
          </div>
        </div>
        <div class="sec-card">
          <div class="sec-hd">
            <div class="sec-hd-l">
              <div class="sec-hd-ic ic-t"><i class="bx bx-group"></i></div>
              <div>
                <div class="sec-hd-t">Team Notification Preferences</div>
                <div class="sec-hd-s">Configure how your team receives alerts and digests</div>
              </div>
            </div>
            <button class="btn btn-primary btn-sm" onclick="openTeamNotifModal()"><i class="bx bx-cog"></i> Configure</button>
          </div>
          <div class="sec-body">
            <div class="sec-body-rows">
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">Email Notifications</div><div class="sr-s">Send alert emails to team recipients list</div></div>
                <div class="tgl-wrap"><div class="tgl on"><div class="tgl-knob"></div></div><span class="tgl-lbl">Enabled</span></div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">SMS Notifications</div><div class="sr-s">SMS alerts for critical events (e.g. overdue loans)</div></div>
                <div class="tgl-wrap"><div class="tgl on"><div class="tgl-knob"></div></div><span class="tgl-lbl">Enabled</span></div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">In-App Notifications</div><div class="sr-s">Real-time alerts inside the MIS platform</div></div>
                <div class="tgl-wrap"><div class="tgl on"><div class="tgl-knob"></div></div><span class="tgl-lbl">Enabled</span></div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">Daily Digest Email</div><div class="sr-s">Summary email sent every morning at 08:00 AM</div></div>
                <div class="tgl-wrap"><div class="tgl on"><div class="tgl-knob"></div></div><span class="tgl-lbl">Enabled</span></div>
              </div>
              <div class="setting-row">
                <div class="sr-l"><div class="sr-t">Escalation Alerts <i class="bx bx-lock-alt lock-ic"></i></div><div class="sr-s">System-level escalation rules — Super Admin only</div></div>
                <span class="chip chip-amb">Master Lock</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ════ TAB: INTEGRATIONS ════ -->
  <div class="tab-panel" id="tab-integrations" style="display:none;">
    <div class="st-grid">
      <div class="st-sidebar">
        <div class="info-card">
          <div class="ic-hd"><div class="ic-hd-t">Integration Summary</div><div class="ic-hd-s">Zone linkage status</div></div>
          <div class="ic-body">
            <div class="ic-row"><label>Active Linkages</label><div class="v" style="color:#166534;">5 Connected</div></div>
            <div class="ic-row"><label>Pending Requests</label><div class="v" style="color:var(--amb);">1 Pending</div></div>
            <div class="ic-row"><label>API Keys Issued</label><div class="v">3 Keys</div></div>
            <div class="ic-row"><label>Last API Call</label><div class="v muted">Today, 07:48 AM</div></div>
          </div>
        </div>
        <div class="perm-card">
          <div class="perm-hd"><i class="bx bx-link"></i><div class="perm-hd-t">Integration Permissions</div></div>
          <div class="perm-body">
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>View zone module linkages</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>Request API access</div>
            <div class="perm-item perm-can"><i class="bx bx-check-circle"></i>View API usage logs</div>
            <div class="perm-divider">Cannot Do</div>
            <div class="perm-item perm-cant"><i class="bx bx-x-circle"></i>Generate or revoke API keys</div>
            <div class="perm-item perm-cant"><i class="bx bx-x-circle"></i>Add new integrations</div>
          </div>
        </div>
      </div>
      <div class="st-main">
        <div class="sec-card">
          <div class="sec-hd">
            <div class="sec-hd-l">
              <div class="sec-hd-ic ic-t"><i class="bx bx-link-external"></i></div>
              <div>
                <div class="sec-hd-t">Zone Module Linkages</div>
                <div class="sec-hd-s">Active integrations for NCR — Luzon Zone 1</div>
              </div>
            </div>
            <button class="btn btn-warn btn-sm" onclick="openReqModal('api')"><i class="bx bx-key"></i> Request API Access</button>
          </div>
          <div class="sec-body">
            <div id="intList"></div>
          </div>
        </div>
        <div class="sec-card">
          <div class="sec-hd">
            <div class="sec-hd-l">
              <div class="sec-hd-ic ic-b"><i class="bx bx-key"></i></div>
              <div>
                <div class="sec-hd-t">API Keys</div>
                <div class="sec-hd-s">Issued API keys for this zone — values masked for security</div>
              </div>
            </div>
          </div>
          <div class="sec-body">
            <div class="sec-body-rows" id="apiKeyList"></div>
          </div>
        </div>
        <div class="sec-card">
          <div class="sec-hd">
            <div class="sec-hd-l">
              <div class="sec-hd-ic ic-r"><i class="bx bx-lock-alt"></i></div>
              <div>
                <div class="sec-hd-t">Restricted Integration Actions</div>
                <div class="sec-hd-s">These require Super Admin authority</div>
              </div>
            </div>
          </div>
          <div class="sec-body">
            <div class="locked-row"><i class="bx bx-lock-alt"></i>Generate or revoke API keys — Super Admin only</div>
            <div class="locked-row" style="margin-top:8px;"><i class="bx bx-lock-alt"></i>Add or remove system integrations — Super Admin only</div>
            <div class="locked-row" style="margin-top:8px;"><i class="bx bx-lock-alt"></i>Configure webhook endpoints — Super Admin only</div>
          </div>
        </div>
      </div>
    </div>
  </div>

</main>

<!-- ════ TOAST ════ -->
<div class="ss-toasts" id="toastWrap"></div>

<!-- ════ REQUEST CHANGE MODAL ════ -->
<div id="reqModal">
  <div class="rm-box">
    <div class="rm-hd">
      <div>
        <div class="rm-hd-t">📤 Request Configuration Change</div>
        <div class="rm-hd-s">Your request will be reviewed by the System Administrator</div>
      </div>
      <button class="rm-cl" onclick="closeModal('reqModal')"><i class="bx bx-x"></i></button>
    </div>
    <div class="rm-body">
      <div class="rm-note">
        <i class="bx bx-info-circle"></i>
        <span>As Zone Manager, you cannot directly modify master settings. This form submits a <strong>change request ticket</strong> to the Super Admin. You'll be notified once it's actioned.</span>
      </div>
      <div class="rm-fg">
        <label>Change Category <span style="color:var(--red)">*</span></label>
        <select id="rcCategory">
          <option value="">Select category…</option>
          <option>Organization Settings</option>
          <option>Zone Configuration</option>
          <option>Module Access</option>
          <option>Integration / API Access</option>
          <option>Notification Rules</option>
          <option>Compliance Settings</option>
          <option>Other</option>
        </select>
      </div>
      <div class="rm-fg">
        <label>Subject / Title <span style="color:var(--red)">*</span></label>
        <input type="text" id="rcSubject" placeholder="Brief title of the change needed…">
      </div>
      <div class="rm-fg">
        <label>Justification / Description <span style="color:var(--red)">*</span></label>
        <textarea id="rcDesc" placeholder="Explain what change is needed, why, and expected impact…"></textarea>
      </div>
      <div class="rm-fg">
        <label>Priority</label>
        <select id="rcPriority">
          <option>Normal</option>
          <option>High</option>
          <option>Urgent</option>
        </select>
      </div>
    </div>
    <div class="rm-ft">
      <button class="btn btn-ghost btn-sm" onclick="closeModal('reqModal')">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="submitChangeReq()"><i class="bx bx-send"></i> Submit Request</button>
    </div>
  </div>
</div>

<!-- ════ TEAM NOTIF MODAL ════ -->
<div id="viewModal">
  <div class="vm-box">
    <div class="rm-hd">
      <div>
        <div class="rm-hd-t">🔔 Configure Team Notifications</div>
        <div class="rm-hd-s">NCR — Luzon Zone 1 · Team Alert Preferences</div>
      </div>
      <button class="rm-cl" onclick="closeModal('viewModal')"><i class="bx bx-x"></i></button>
    </div>
    <div class="rm-body">
      <div class="rm-fg">
        <label>Digest Time</label>
        <select id="vmDigest">
          <option>06:00 AM</option>
          <option>07:00 AM</option>
          <option selected>08:00 AM</option>
          <option>09:00 AM</option>
          <option>12:00 PM</option>
          <option>06:00 PM</option>
        </select>
      </div>
      <div class="rm-fg">
        <label>Alert Recipients (comma-separated emails)</label>
        <textarea id="vmRecipients" style="min-height:60px;">jdelacruz@microfinance.ph, msantos@microfinance.ph, preyes@microfinance.ph</textarea>
      </div>
      <div class="rm-fg">
        <label>SMS Alert Number (Zone Hotline)</label>
        <input type="text" id="vmSMS" value="+63 917 000 1234">
      </div>
      <div class="rm-fg">
        <label>Escalation Threshold (Days Overdue)</label>
        <input type="number" id="vmEscalate" value="3" min="1" max="30">
      </div>
    </div>
    <div class="rm-ft">
      <button class="btn btn-ghost btn-sm" onclick="closeModal('viewModal')">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveTeamNotif()"><i class="bx bx-save"></i> Save Preferences</button>
    </div>
  </div>
</div>

<script>
/* ── DATA ─────────────────────────────────────────────────── */
const MODULES = [
  {name:'Applicant Management',          cat:'HR 1',          ver:'v3.1',  status:'Active',  ic:'ic-g',  icon:'bx-user-plus'},
  {name:'Time & Attendance',             cat:'HR 3',          ver:'v2.8',  status:'Active',  ic:'ic-g',  icon:'bx-time'},
  {name:'Payroll Management',            cat:'HR 4',          ver:'v4.0',  status:'Active',  ic:'ic-g',  icon:'bx-money'},
  {name:'Client Registration & KYC',     cat:'Core TXN 1',    ver:'v3.5',  status:'Active',  ic:'ic-b',  icon:'bx-id-card'},
  {name:'Loan Application & Disbursement',cat:'Core TXN 1',   ver:'v3.5',  status:'Active',  ic:'ic-b',  icon:'bx-money-withdraw'},
  {name:'Savings Account Management',    cat:'Core TXN 1',    ver:'v2.9',  status:'Active',  ic:'ic-b',  icon:'bx-wallet'},
  {name:'Loan Portfolio & Risk Mgmt',    cat:'Core TXN 2',    ver:'v4.1',  status:'Active',  ic:'ic-a',  icon:'bx-bar-chart-alt-2'},
  {name:'Procurement & Sourcing (PSM)',   cat:'Logistics 1',   ver:'v4.2',  status:'Active',  ic:'ic-p',  icon:'bx-package'},
  {name:'Fleet & Vehicle Mgmt',          cat:'Logistics 2',   ver:'v2.3',  status:'Active',  ic:'ic-t',  icon:'bx-car'},
  {name:'General Ledger',                cat:'Financials',    ver:'v3.8',  status:'Active',  ic:'ic-d',  icon:'bx-book-content'},
  {name:'HR Analytics Dashboard',        cat:'HR 4',          ver:'v2.1',  status:'Inactive',ic:'ic-d',  icon:'bx-line-chart'},
  {name:'Succession Planning',           cat:'HR 2',          ver:'v1.9',  status:'Inactive',ic:'ic-d',  icon:'bx-network-chart'},
  {name:'Group Lending & Solidarity',    cat:'Core TXN 1',    ver:'v3.2',  status:'Active',  ic:'ic-b',  icon:'bx-group'},
  {name:'Visitor Management',            cat:'Admin',         ver:'v1.5',  status:'Inactive',ic:'ic-d',  icon:'bx-badge-check'},
  {name:'Legal Management',              cat:'Admin',         ver:'v2.0',  status:'Active',  ic:'ic-r',  icon:'bx-file'},
];

const ALERTS = [
  {title:'Loan Overdue Alert',  desc:'Triggers when loan is 3+ days past due',    ch:['Email','SMS'],    ic:'ic-r',  icon:'bx-time-five',   on:true},
  {title:'PR Pending Approval', desc:'Notifies approvers of pending purchase requests', ch:['Email','In-App'], ic:'ic-a',  icon:'bx-receipt',     on:true},
  {title:'KYC Expiry Reminder', desc:'7-day advance notice before KYC expiry',    ch:['Email'],          ic:'ic-b',  icon:'bx-id-card',     on:true},
  {title:'Daily Collection Report', desc:'End-of-day savings and collections summary', ch:['Email'],     ic:'ic-g',  icon:'bx-money-withdraw',on:true},
  {title:'New Client Registration', desc:'Alert when a new client completes KYC', ch:['In-App'],         ic:'ic-t',  icon:'bx-user-plus',   on:true},
  {title:'Budget Threshold Warning', desc:'Triggers at 80% of zone budget utilization', ch:['Email','SMS'], ic:'ic-amb', icon:'bx-error',      on:false},
  {title:'Fleet Maintenance Due', desc:'Vehicle service reminder 7 days in advance', ch:['Email'],       ic:'ic-d',  icon:'bx-car',         on:true},
  {title:'Compliance Deadline', desc:'RA 9184 filing deadline reminders',           ch:['Email','SMS'],   ic:'ic-r',  icon:'bx-shield-quarter',on:true},
  {title:'User Login Anomaly', desc:'Unusual login activity detection',             ch:['Email','SMS'],   ic:'ic-p',  icon:'bx-shield-x',    on:true},
];

const INTEGRATIONS = [
  {name:'BSP Reporting API',        url:'api.bsp.gov.ph/v2/reports',        status:'Connected', ic:'ic-g',  icon:'🏦',  updated:'Mar 10, 2026'},
  {name:'SEC E-Filing Gateway',     url:'eservice.sec.gov.ph/api/v1',       status:'Connected', ic:'ic-g',  icon:'🏛️',  updated:'Mar 01, 2026'},
  {name:'BIR Automated Tax Portal', url:'efps.bir.gov.ph/mfi/sync',         status:'Connected', ic:'ic-g',  icon:'📋',  updated:'Feb 28, 2026'},
  {name:'PhilSys Verification API', url:'philsys.gov.ph/api/verify',        status:'Connected', ic:'ic-g',  icon:'🪪',  updated:'Mar 05, 2026'},
  {name:'CIBI Credit Bureau API',   url:'api.cibi.ph/credit-check/v3',      status:'Connected', ic:'ic-g',  icon:'📊',  updated:'Mar 08, 2026'},
  {name:'GCASH Disbursement API',   url:'api.gcash.com/mfi/disburse',       status:'Pending',   ic:'ic-a',  icon:'💳',  updated:'Awaiting approval'},
];

const API_KEYS = [
  {label:'BSP Reporting Key',       key:'bsp_sk_•••••••••••••••XQ8F', issued:'Jan 15, 2026', exp:'Jan 15, 2027', status:'Active'},
  {label:'PhilSys Verify Key',      key:'psv_•••••••••••••••A2KL',    issued:'Feb 01, 2026', exp:'Feb 01, 2027', status:'Active'},
  {label:'CIBI Credit Bureau Key',  key:'cibi_sk_•••••••••••ZPR7',    issued:'Dec 01, 2025', exp:'Dec 01, 2026', status:'Active'},
];

const AUDIT_GEN = [
  {act:'Zone Timezone updated to Asia/Manila',    by:'Super Admin', ts:'Mar 10, 2026', cls:'ad-e', icon:'bx-map'},
  {act:'Fiscal Year set to Jan–Dec 2026',         by:'Super Admin', ts:'Jan 02, 2026', cls:'ad-s', icon:'bx-calendar'},
  {act:'Zone Manager changed to Juan Dela Cruz',  by:'Super Admin', ts:'Dec 15, 2025', cls:'ad-o', icon:'bx-user'},
  {act:'Currency confirmed as PHP ₱',             by:'Super Admin', ts:'Dec 01, 2025', cls:'ad-c', icon:'bx-money'},
  {act:'Organization registered SEC-2019-00421-A',by:'Super Admin', ts:'Nov 10, 2025', cls:'ad-d', icon:'bx-file-blank'},
];

/* ── INIT RENDER ──────────────────────────────────────────── */
function renderAll() {
  renderAuditGeneral();
  renderModules();
  renderAlerts();
  renderIntegrations();
  renderApiKeys();
}

function renderAuditGeneral() {
  document.getElementById('auditGeneral').innerHTML = AUDIT_GEN.map(a => `
    <div class="audit-item">
      <div class="audit-dot ${a.cls}"><i class="bx ${a.icon}"></i></div>
      <div class="audit-body">
        <div class="au">${a.act}</div>
        <div class="at"><i class="bx bx-user" style="font-size:11px"></i> ${a.by}</div>
      </div>
      <div class="audit-ts">${a.ts}</div>
    </div>`).join('');
}

function renderModules() {
  document.getElementById('modTbody').innerHTML = MODULES.map(m => {
    const sChip = m.status === 'Active'
      ? `<span class="chip chip-grn">${m.status}</span>`
      : `<span class="chip chip-gry">${m.status}</span>`;
    return `<tr>
      <td>
        <div class="mod-cell">
          <div class="mod-ic ${m.ic}"><i class="bx ${m.icon}"></i></div>
          <div>
            <div class="mod-nm">${m.name}</div>
          </div>
        </div>
      </td>
      <td><span class="chip chip-blu">${m.cat}</span></td>
      <td><span style="font-family:'DM Mono',monospace;font-size:11.5px;color:#6B7280;">${m.ver}</span></td>
      <td>${sChip}</td>
      <td>
        <div style="display:flex;gap:5px;">
          <button class="btn btn-ghost btn-xs" title="View details" onclick="toast('Viewing module details','s')"><i class="bx bx-show"></i></button>
          <button class="btn btn-warn btn-xs" title="Request change" onclick="openReqModal('module')"><i class="bx bx-send"></i></button>
          <button class="btn btn-disabled btn-xs" title="Enable/Disable requires Super Admin"><i class="bx bx-lock-alt"></i></button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

function renderAlerts() {
  document.getElementById('alertGrid').innerHTML = ALERTS.map(a => `
    <div class="notif-item">
      <div class="ni-l">
        <div class="ni-ic ${a.ic}"><i class="bx ${a.icon}"></i></div>
        <div>
          <div class="ni-t">${a.title}</div>
          <div class="ni-s">${a.desc}</div>
        </div>
      </div>
      <div class="ni-ch">
        ${a.ch.map(c=>`<span class="chip ${c==='Email'?'chip-blu':c==='SMS'?'chip-grn':'chip-tel'}">${c}</span>`).join('')}
        <div class="tgl ${a.on?'on':'off'} locked"><div class="tgl-knob"></div></div>
      </div>
    </div>`).join('');
}

function renderIntegrations() {
  document.getElementById('intList').innerHTML = INTEGRATIONS.map(i => `
    <div class="int-item">
      <div class="int-l">
        <div class="int-logo">${i.icon}</div>
        <div>
          <div class="int-nm">${i.name}</div>
          <div class="int-url">${i.url}</div>
        </div>
      </div>
      <div class="int-r">
        <span class="chip ${i.status==='Connected'?'chip-grn':'chip-amb'}">${i.status}</span>
        <span style="font-size:11px;color:var(--t3);">${i.updated}</span>
        <button class="btn btn-ghost btn-xs int-view-btn" data-name="${i.name}"><i class="bx bx-show"></i></button>
      </div>
    </div>`).join('');
}

function renderApiKeys() {
  document.getElementById('apiKeyList').innerHTML = API_KEYS.map(k => `
    <div class="setting-row">
      <div class="sr-l">
        <div class="sr-t">${k.label}</div>
        <div class="sr-s">Issued ${k.issued} · Expires ${k.exp}</div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
        <div class="sr-v mono" style="font-size:11px;">${k.key}</div>
        <span class="chip chip-grn">${k.status}</span>
        <button class="btn btn-disabled btn-xs" title="Revoke requires Super Admin"><i class="bx bx-lock-alt"></i></button>
      </div>
    </div>`).join('');
}

/* ── TAB SWITCHING ─────────────────────────────────────────── */
function switchTab(name, el) {
  document.querySelectorAll('.tab-panel').forEach(p => p.style.display='none');
  document.querySelectorAll('.st-tab').forEach(t => t.classList.remove('active'));
  document.getElementById('tab-'+name).style.display='';
  el.classList.add('active');
}

/* ── MODALS ─────────────────────────────────────────────────── */
function openReqModal(pre='') {
  if (pre) {
    const map={org:'Organization Settings',zone:'Zone Configuration',module:'Module Access',api:'Integration / API Access'};
    if (map[pre]) document.getElementById('rcCategory').value = map[pre];
  }
  document.getElementById('reqModal').classList.add('on');
}
function openTeamNotifModal() { document.getElementById('viewModal').classList.add('on'); }
function closeModal(id) { document.getElementById(id).classList.remove('on'); }

document.getElementById('reqModal').addEventListener('click', function(e){ if(e.target===this) closeModal('reqModal'); });
document.getElementById('viewModal').addEventListener('click', function(e){ if(e.target===this) closeModal('viewModal'); });

function submitChangeReq() {
  const cat  = document.getElementById('rcCategory').value;
  const subj = document.getElementById('rcSubject').value.trim();
  const desc = document.getElementById('rcDesc').value.trim();
  if (!cat)  return shakeEl('rcCategory');
  if (!subj) return shakeEl('rcSubject');
  if (!desc) return shakeEl('rcDesc');
  closeModal('reqModal');
  toast('Change request submitted. You will be notified once reviewed by Super Admin.','s');
  document.getElementById('rcSubject').value='';
  document.getElementById('rcDesc').value='';
  document.getElementById('rcCategory').value='';
}

function saveTeamNotif() {
  closeModal('viewModal');
  toast('Team notification preferences saved successfully.','s');
}

/* ── UTILS ─────────────────────────────────────────────────── */
function shakeEl(id) {
  const el = document.getElementById(id);
  el.style.borderColor='var(--red)';
  el.style.animation='none'; el.offsetHeight;
  el.style.animation='SHK .3s ease';
  setTimeout(()=>{el.style.borderColor='';el.style.animation='';},600);
}
function toast(msg,type='s'){
  const ic={s:'bx-check-circle',w:'bx-error',d:'bx-error-circle'};
  const el=document.createElement('div');
  el.className=`toast t${type}`;
  el.innerHTML=`<i class="bx ${ic[type]}" style="font-size:18px;flex-shrink:0"></i>${msg}`;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(()=>{el.classList.add('out');setTimeout(()=>el.remove(),320);},3500);
}

/* ── ADD SHAKE KEYFRAME ─────────────────────────────────────── */
const style = document.createElement('style');
style.textContent='@keyframes SHK{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-5px)}40%,80%{transform:translateX(5px)}}';
document.head.appendChild(style);

/* ── DELEGATED EVENTS ───────────────────────────────────────── */
document.addEventListener('click', function(e) {
  const btn = e.target.closest('.int-view-btn');
  if (btn) {
    const name = btn.dataset.name || 'Integration';
    toast('Viewing ' + name + ' details', 's');
  }
});

/* ── INIT ─────────────────────────────────────────────────── */
renderAll();
</script>
</body>
</html>