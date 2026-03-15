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
<title>Document Reports — DTRS</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/Log1/css/base.css">
<link rel="stylesheet" href="/Log1/css/sidebar.css">
<link rel="stylesheet" href="/Log1/css/header.css">
<style>
*,*::before,*::after{box-sizing:border-box}
/* Do NOT zero margin/padding globally - it breaks sidebar.css .main-content spacing */
/* Only zero specific elements that need it */
h1,h2,h3,h4,h5,h6,p,ul,ol,li,figure,blockquote{margin:0;padding:0}
:root{
  --primary:#2E7D32;--primary-dark:#1B5E20;
  --surface:#fff;--bg:#F5F7F5;
  --border:rgba(46,125,50,.14);--border-mid:rgba(46,125,50,.22);
  --text-1:#1A2B1C;--text-2:#5D6F62;--text-3:#9EB0A2;
  --hover-s:rgba(46,125,50,.05);
  --shadow-sm:0 1px 4px rgba(46,125,50,.08);
  --shadow-md:0 4px 16px rgba(46,125,50,.12);
  --shadow-xl:0 20px 60px rgba(0,0,0,.22);
  --danger:#DC2626;--warning:#D97706;--info:#2563EB;
  --gold:#B45309;--purple:#7C3AED;--teal:#0D9488;
  --tr:all .18s ease;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text-1);min-height:100vh}

/* ── PAGE ── */
.page{max-width:1500px;margin:0 auto;padding:0 0 3rem}

.ph{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:28px;animation:fadeUp .4s both;flex-wrap:wrap}
.ph .ey{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--primary);margin-bottom:4px}
.ph h1{font-size:26px;font-weight:800;color:var(--text-1);line-height:1.15}
.ph-acts{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

.btn{display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap}
.btn-p{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(46,125,50,.3)}
.btn-p:hover{background:var(--primary-dark);transform:translateY(-1px)}
.btn-g{background:var(--surface);color:var(--text-2);border:1px solid var(--border-mid)}
.btn-g:hover{background:var(--hover-s);color:var(--text-1)}
.btn-s{font-size:12px;padding:7px 14px}
.btn-info{background:var(--info);color:#fff}
.btn-info:hover{background:#1D4ED8;transform:translateY(-1px)}
.btn:disabled{opacity:.45;pointer-events:none}

/* ── STATS: 5 equal columns, always visible ── */
.stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:22px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:10px;box-shadow:var(--shadow-sm);animation:fadeUp .4s both}
.stat-card:nth-child(1){animation-delay:.04s}.stat-card:nth-child(2){animation-delay:.07s}
.stat-card:nth-child(3){animation-delay:.1s}.stat-card:nth-child(4){animation-delay:.13s}
.stat-card:nth-child(5){animation-delay:.16s}
.sic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.sic-g{background:#E8F5E9;color:var(--primary)}.sic-b{background:#EFF6FF;color:var(--info)}
.sic-o{background:#FEF3C7;color:var(--warning)}.sic-r{background:#FEE2E2;color:var(--danger)}
.sic-pu{background:#F3E8FF;color:var(--purple)}
.sv{font-size:19px;font-weight:800;line-height:1}.sl{font-size:10px;color:var(--text-2);margin-top:2px}

/* ── TWO-COLUMN LAYOUT: left panel + right content ── */
/* ── LAYOUT: full width, tabs on top ── */
.layout{display:flex;flex-direction:column;gap:16px}

/* ── REPORT TYPE TABS (horizontal strip) ── */
.rtype-list{display:flex;gap:8px;flex-wrap:nowrap;overflow-x:auto;animation:fadeUp .4s .08s both;padding-bottom:4px}
.rtype-list::-webkit-scrollbar{height:3px}
.rtype-list::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px}
.rtype-card{background:var(--surface);border:1.5px solid var(--border);border-radius:12px;padding:10px 14px;cursor:pointer;transition:var(--tr);display:flex;align-items:center;gap:9px;position:relative;flex:1;min-width:0}
.rtype-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;background:transparent;border-radius:0 0 12px 12px;transition:var(--tr)}
.rtype-card:hover{background:var(--hover-s);border-color:var(--border-mid)}
.rtype-card.active{border-color:var(--primary);background:rgba(46,125,50,.05)}
.rtype-card.active::after{background:var(--primary)}
.rtype-card.sa-locked{border-style:dashed}
.rc-ic{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.rc-name{font-size:12px;font-weight:700;color:var(--text-1);line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rc-sub{display:none}
.rtype-card.active .rc-name{color:var(--primary)}
.sa-badge{font-size:8px;font-weight:800;background:var(--gold);color:#fff;padding:2px 6px;border-radius:20px;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap}

.right-col{display:flex;flex-direction:column;gap:16px;animation:fadeUp .4s .12s both}

.report-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-md)}
.rc-hd{padding:16px 20px;border-bottom:1px solid var(--border);background:var(--bg);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.rc-hd-l{display:flex;align-items:center;gap:12px}
.rc-hd-ic{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.rc-hd-title{font-size:15px;font-weight:700;color:var(--text-1)}
.rc-hd-sub{font-size:11px;color:var(--text-2);margin-top:2px}
.rc-hd-acts{display:flex;gap:7px;flex-wrap:wrap}
.rc-body{padding:18px 20px}

.filter-row{display:flex;align-items:center;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.fl-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);white-space:nowrap}
.fsel,.fdate{font-family:'Inter',sans-serif;font-size:12px;height:32px;border:1px solid var(--border-mid);border-radius:8px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);cursor:pointer}
.fsel{padding:0 26px 0 9px;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 7px center}
.fdate{padding:0 9px}
.fsel:focus,.fdate:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.1)}

.metrics-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:11px;margin-bottom:18px}
.metric-box{background:var(--bg);border:1px solid var(--border);border-radius:11px;padding:14px 16px}
.m-val{font-size:22px;font-weight:800;line-height:1;font-family:'DM Mono',monospace}
.m-lbl{font-size:11px;color:var(--text-2);margin-top:4px}
.m-trend{font-size:10px;font-weight:700;margin-top:5px;display:flex;align-items:center;gap:3px}
.t-up{color:var(--primary)}.t-dn{color:var(--danger)}.t-neu{color:var(--text-3)}

.chart-wrap{background:var(--bg);border:1px solid var(--border);border-radius:11px;padding:14px 16px;margin-bottom:18px}
.chart-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.chart-ttl{font-size:12px;font-weight:700;color:var(--text-2)}
.chart-legend{display:flex;gap:12px}
.leg-item{display:flex;align-items:center;gap:5px;font-size:10px;font-weight:600;color:var(--text-3)}
.leg-dot{width:9px;height:9px;border-radius:3px;flex-shrink:0}
.bar-chart{display:flex;align-items:flex-end;gap:6px;height:128px}
.bar-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:5px;min-width:0}
.bar-grp{width:100%;flex:1;display:flex;align-items:flex-end;gap:2px}
.bar{border-radius:5px 5px 0 0;min-height:3px;position:relative;cursor:pointer;transition:filter .15s}
.bar:hover{filter:brightness(1.12)}
.bar[data-tip]:hover::after{content:attr(data-tip);position:absolute;top:-26px;left:50%;transform:translateX(-50%);background:var(--text-1);color:#fff;font-size:10px;font-weight:700;padding:3px 7px;border-radius:5px;white-space:nowrap;z-index:10;pointer-events:none}
.bar-lbl{font-size:9px;color:var(--text-3);font-weight:600;text-align:center;white-space:nowrap;overflow:hidden;max-width:100%}

.donut-area{display:flex;gap:20px;align-items:center}
.donut-legend{display:flex;flex-direction:column;gap:7px;flex:1}
.dl-row{display:flex;align-items:center;gap:8px}
.dl-sw{width:10px;height:10px;border-radius:3px;flex-shrink:0}
.dl-nm{font-size:11px;color:var(--text-2);flex:1}
.dl-n{font-size:11px;font-weight:700;color:var(--text-1);font-family:'DM Mono',monospace}
.dl-p{font-size:10px;color:var(--text-3);font-family:'DM Mono',monospace;min-width:30px;text-align:right}

.tbl-wrap{overflow-x:auto;border-radius:10px;border:1px solid var(--border);overflow:hidden}
.rp-tbl{width:100%;border-collapse:collapse;font-size:12px}
.rp-tbl thead th{font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-2);padding:9px 11px;text-align:left;background:var(--bg);border-bottom:1px solid var(--border);white-space:nowrap}
.rp-tbl thead th:first-child{padding-left:14px}
.rp-tbl tbody tr{border-bottom:1px solid var(--border)}
.rp-tbl tbody tr:last-child{border-bottom:none}
.rp-tbl tbody tr:hover{background:var(--hover-s)}
.rp-tbl tbody td{padding:9px 11px;vertical-align:middle}
.rp-tbl tbody td:first-child{padding-left:14px}
.rp-tbl tfoot td{padding:9px 11px;font-size:12px;font-weight:700;background:var(--bg);border-top:1px solid var(--border)}
.rp-tbl tfoot td:first-child{padding-left:14px}
.mono{font-family:'DM Mono',monospace}

.chip{display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;padding:3px 8px;border-radius:20px;white-space:nowrap}
.chip::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0}
.chip-g{background:#E8F5E9;color:var(--primary)}.chip-b{background:#EFF6FF;color:var(--info)}
.chip-o{background:#FEF3C7;color:var(--warning)}.chip-r{background:#FEE2E2;color:var(--danger)}

.prog-row{display:flex;align-items:center;gap:7px}
.prog-bar{flex:1;height:5px;background:var(--border);border-radius:4px;overflow:hidden}
.prog-fill{height:100%;border-radius:4px}
.prog-pct{font-size:10px;font-weight:700;color:var(--text-2);min-width:28px;text-align:right}

.sa-banner{background:linear-gradient(135deg,rgba(27,94,32,.04),rgba(46,125,50,.07));border:1px solid rgba(46,125,50,.2);border-radius:11px;padding:11px 14px;display:flex;align-items:center;gap:9px;margin-bottom:16px}
.sa-banner i{color:var(--primary);font-size:17px;flex-shrink:0}
.sa-banner span{font-size:12px;font-weight:600;color:var(--primary)}

.audit-feed{display:flex;flex-direction:column}
.audit-row{display:flex;gap:11px;padding:10px 0;border-bottom:1px solid var(--border)}
.audit-row:last-child{border-bottom:none}
.a-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px}
.d-g{background:var(--primary)}.d-b{background:var(--info)}.d-o{background:var(--warning)}
.d-r{background:var(--danger)}.d-gy{background:#9CA3AF}
.a-act{font-size:12px;font-weight:600;color:var(--text-1)}
.a-by{font-size:11px;color:var(--text-2);margin-top:1px}
.a-ts{font-size:10px;color:var(--text-3);font-family:'DM Mono',monospace;margin-top:1px}

.sched-section{background:var(--surface);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-md)}
.sched-hd{padding:14px 20px;border-bottom:1px solid var(--border);background:var(--bg);display:flex;align-items:center;justify-content:space-between}
.sched-hd-title{font-size:13px;font-weight:700;color:var(--text-1);display:flex;align-items:center;gap:7px}
.sched-hd-title i{color:var(--primary);font-size:16px}
.sched-body{padding:16px 20px}
.sched-list{display:flex;flex-direction:column;gap:9px}
.sched-item{background:var(--bg);border:1px solid var(--border);border-radius:11px;padding:12px 14px;display:flex;align-items:center;gap:12px}
.sched-ic{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.sched-name{font-size:12px;font-weight:700;color:var(--text-1)}
.sched-meta{font-size:10px;color:var(--text-3);margin-top:2px}
.sched-acts{display:flex;gap:5px;flex-shrink:0;align-items:center}
.tog{position:relative;width:38px;height:20px;flex-shrink:0}
.tog input{opacity:0;width:0;height:0}
.tog-sl{position:absolute;inset:0;background:#D1D5DB;border-radius:20px;cursor:pointer;transition:.22s}
.tog-sl::before{content:'';position:absolute;height:14px;width:14px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.22s}
.tog input:checked+.tog-sl{background:var(--primary)}
.tog input:checked+.tog-sl::before{transform:translateX(18px)}
.ib{width:24px;height:24px;border-radius:6px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:12px;color:var(--text-2);transition:var(--tr)}
.ib:hover{background:var(--hover-s);border-color:var(--primary);color:var(--primary)}
.ib.del:hover{background:#FEE2E2;border-color:#FECACA;color:var(--danger)}

.ov{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1100;opacity:0;pointer-events:none;transition:opacity .25s}
.ov.show{opacity:1;pointer-events:all}
.modal{position:fixed;inset:0;z-index:1200;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s}
.modal.show{opacity:1;pointer-events:all}
.mbox{background:var(--surface);border-radius:18px;width:520px;max-width:100%;box-shadow:var(--shadow-xl);transform:scale(.96);transition:transform .25s;overflow:hidden}
.mbox-lg{background:var(--surface);border-radius:18px;width:820px;max-width:100%;max-height:92vh;display:flex;flex-direction:column;box-shadow:var(--shadow-xl);transform:scale(.96);transition:transform .25s;overflow:hidden}
.modal.show .mbox,.modal.show .mbox-lg{transform:scale(1)}
.mhd{padding:18px 22px 14px;border-bottom:1px solid var(--border);background:var(--bg);display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-shrink:0}
.mhd-ic{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.mhd-title{font-size:15px;font-weight:700;color:var(--text-1)}
.mhd-sub{font-size:11px;color:var(--text-2);margin-top:2px}
.mcl{width:30px;height:30px;border-radius:7px;border:1px solid var(--border-mid);background:var(--surface);cursor:pointer;display:grid;place-content:center;font-size:17px;color:var(--text-2);transition:var(--tr);flex-shrink:0}
.mcl:hover{background:#FEE2E2;color:var(--danger);border-color:#FECACA}
.mbody{flex:1;overflow-y:auto;padding:20px 22px}
.mbody-p{padding:18px 22px}
.mbody::-webkit-scrollbar{width:4px}
.mbody::-webkit-scrollbar-thumb{background:var(--border-mid);border-radius:4px}
.mft{padding:12px 22px;border-top:1px solid var(--border);background:var(--bg);display:flex;gap:8px;justify-content:flex-end;flex-shrink:0}

.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-grid .full{grid-column:1/-1}
.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}
.fg:last-child{margin-bottom:0}
.flbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-2)}
.req{color:var(--danger)}
.fi,.fs{font-family:'Inter',sans-serif;font-size:13px;padding:9px 11px;border:1px solid var(--border-mid);border-radius:9px;background:var(--surface);color:var(--text-1);outline:none;transition:var(--tr);width:100%}
.fi:focus,.fs:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,50,.1)}
.fi::placeholder{color:var(--text-3)}
.fs{appearance:none;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}

.empty{padding:52px 20px;text-align:center;color:var(--text-3)}
.empty i{font-size:44px;display:block;margin-bottom:9px;color:#C8E6C9}
.empty p{font-size:13px}

#tw{position:fixed;bottom:26px;right:26px;display:flex;flex-direction:column;gap:9px;z-index:9999;pointer-events:none}
.toast{background:#0A1F0D;color:#fff;padding:10px 14px;border-radius:9px;font-size:12px;font-weight:500;display:flex;align-items:center;gap:9px;box-shadow:var(--shadow-xl);min-width:210px;animation:toastIn .28s ease;pointer-events:all}
.toast.success{background:var(--primary)}.toast.warning{background:var(--warning)}
.toast.danger{background:var(--danger)}.toast.info{background:var(--info)}
.toast.out{animation:toastOut .28s ease forwards}

@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@keyframes toastOut{to{opacity:0;transform:translateY(10px)}}
@keyframes shake{0%,100%{transform:translateX(0)}30%,70%{transform:translateX(-5px)}50%{transform:translateX(5px)}}

@media(max-width:1100px){
  .stats-row{grid-template-columns:repeat(3,1fr)}
}
@media(max-width:720px){
  .stats-row{grid-template-columns:repeat(2,1fr)}
  .metrics-grid{grid-template-columns:1fr 1fr}
  .rtype-list{gap:6px}
  .rc-name{font-size:11px}
}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="page">

<div class="ph">
  <div>
    <p class="ey">DTRS · Document Tracking &amp; Logistics Records</p>
    <h1>Document Reports</h1>
  </div>
  <div class="ph-acts">
    <button class="btn btn-g" id="btnSchedule"><i class='bx bx-calendar'></i> Scheduled Reports</button>
    <button class="btn btn-g" id="btnExport"><i class='bx bx-export'></i> Export CSV</button>
    <button class="btn btn-p" id="btnGenerate"><i class='bx bx-file-find'></i> Generate Report</button>
  </div>
</div>

<div class="stats-row" id="statsRow"></div>

<div class="layout">
  <div class="rtype-list" id="rtypeList"></div>

  <div class="right-col">
    <div class="report-card" id="reportCard">
      <div class="rc-hd">
        <div class="rc-hd-l">
          <div class="rc-hd-ic" id="hdIc"></div>
          <div>
            <div class="rc-hd-title" id="hdTitle">Select a report</div>
            <div class="rc-hd-sub" id="hdSub">Choose a report type from the tabs above</div>
          </div>
        </div>
        <div class="rc-hd-acts" id="hdActs"></div>
      </div>
      <div class="rc-body" id="rcBody">
        <div class="empty"><i class='bx bx-file-find'></i><p>Select a report type to view data and charts</p></div>
      </div>
    </div>

    <div class="sched-section" id="schedSection">
      <div class="sched-hd">
        <div class="sched-hd-title"><i class='bx bx-calendar-check'></i> Scheduled Reports</div>
        <button class="btn btn-p btn-s" id="btnAddSched"><i class='bx bx-plus'></i> Add Schedule</button>
      </div>
      <div class="sched-body">
        <div class="sched-list" id="schedList"></div>
      </div>
    </div>
  </div>
</div>
</div><!-- /.page -->

<div id="tw"></div>
</main><!-- /.main-content -->

<div class="ov" id="ov"></div>

<!-- GENERATE MODAL -->
<div class="modal" id="mGenerate">
  <div class="mbox">
    <div class="mhd">
      <div style="display:flex;align-items:center;gap:11px">
        <div class="mhd-ic" style="background:#E8F5E9;color:var(--primary)"><i class='bx bx-file-find'></i></div>
        <div><div class="mhd-title">Generate Report</div><div class="mhd-sub">Configure and generate a new report</div></div>
      </div>
      <button class="mcl" onclick="closeModal('mGenerate')"><i class='bx bx-x'></i></button>
    </div>
    <div class="mbody-p">
      <div class="form-grid">
        <div class="fg full">
          <label class="flbl">Report Type <span class="req">*</span></label>
          <select class="fs" id="gType">
            <option value="">— Select Report Type —</option>
            <option value="volume">Document Volume Report</option>
            <option value="routing">Routing &amp; Transit Report</option>
            <option value="inout">Incoming / Outgoing Summary</option>
            <option value="archive">Archiving Status Report</option>
            <option value="retention">Retention &amp; Compliance Report</option>
            <option value="audit">Audit Trail Report (SA Only)</option>
          </select>
        </div>
        <div class="fg">
          <label class="flbl">Date From</label>
          <input type="date" class="fi" id="gDateFrom">
        </div>
        <div class="fg">
          <label class="flbl">Date To</label>
          <input type="date" class="fi" id="gDateTo">
        </div>
        <div class="fg">
          <label class="flbl">Department</label>
          <select class="fs" id="gDept">
            <option value="">All Departments</option>
            <option>HR</option><option>Finance</option><option>Admin</option><option>Legal</option><option>PSM</option>
          </select>
        </div>
        <div class="fg">
          <label class="flbl">Export Format</label>
          <select class="fs" id="gFormat">
            <option>PDF</option><option>CSV / Excel</option><option>Both</option>
          </select>
        </div>
      </div>
    </div>
    <div class="mft">
      <button class="btn btn-g btn-s" onclick="closeModal('mGenerate')">Cancel</button>
      <button class="btn btn-p btn-s" id="gConfirm"><i class='bx bx-file-find'></i> Generate</button>
    </div>
  </div>
</div>

<!-- SCHEDULE MODAL -->
<div class="modal" id="mSched">
  <div class="mbox">
    <div class="mhd">
      <div style="display:flex;align-items:center;gap:11px">
        <div class="mhd-ic" style="background:#EFF6FF;color:var(--info)"><i class='bx bx-calendar-plus'></i></div>
        <div><div class="mhd-title" id="sModalTitle">Add Scheduled Report</div><div class="mhd-sub">Auto-generate and email on a recurring schedule</div></div>
      </div>
      <button class="mcl" onclick="closeModal('mSched')"><i class='bx bx-x'></i></button>
    </div>
    <div class="mbody-p">
      <div class="fg">
        <label class="flbl">Report Type <span class="req">*</span></label>
        <select class="fs" id="sType">
          <option value="">— Select —</option>
          <option value="volume">Document Volume Report</option>
          <option value="routing">Routing &amp; Transit Report</option>
          <option value="inout">Incoming / Outgoing Summary</option>
          <option value="archive">Archiving Status Report</option>
          <option value="retention">Retention &amp; Compliance Report</option>
          <option value="audit">Audit Trail Report (SA Only)</option>
        </select>
      </div>
      <div class="form-grid">
        <div class="fg">
          <label class="flbl">Frequency <span class="req">*</span></label>
          <select class="fs" id="sFreq">
            <option value="">— Select —</option>
            <option value="Weekly">Weekly</option>
            <option value="Monthly">Monthly</option>
          </select>
        </div>
        <div class="fg">
          <label class="flbl">When</label>
          <select class="fs" id="sDay">
            <option value="Every Monday">Every Monday</option>
            <option value="Every Friday">Every Friday</option>
            <option value="1st of Month">1st of Month</option>
            <option value="Last Day of Month">Last Day of Month</option>
          </select>
        </div>
        <div class="fg full">
          <label class="flbl">Send To Email <span class="req">*</span></label>
          <input type="email" class="fi" id="sEmail" placeholder="reports@company.com">
        </div>
        <div class="fg">
          <label class="flbl">Format</label>
          <select class="fs" id="sFormat">
            <option value="PDF">PDF</option><option value="CSV">CSV / Excel</option><option value="Both">Both</option>
          </select>
        </div>
        <div class="fg">
          <label class="flbl">Department</label>
          <select class="fs" id="sDept">
            <option value="All">All Departments</option>
            <option>HR</option><option>Finance</option><option>Admin</option><option>Legal</option><option>PSM</option>
          </select>
        </div>
      </div>
    </div>
    <div class="mft">
      <button class="btn btn-g btn-s" onclick="closeModal('mSched')">Cancel</button>
      <button class="btn btn-info btn-s" id="sConfirm"><i class='bx bx-calendar-check'></i> Save Schedule</button>
    </div>
  </div>
</div>

<!-- PREVIEW MODAL -->
<div class="modal" id="mPreview">
  <div class="mbox-lg">
    <div class="mhd">
      <div style="display:flex;align-items:center;gap:11px">
        <div class="mhd-ic" style="background:#E8F5E9;color:var(--primary)"><i class='bx bx-printer'></i></div>
        <div><div class="mhd-title" id="pvTitle">Report Preview</div><div class="mhd-sub" id="pvSub"></div></div>
      </div>
      <button class="mcl" onclick="closeModal('mPreview')"><i class='bx bx-x'></i></button>
    </div>
    <div class="mbody" id="pvBody"></div>
    <div class="mft">
      <button class="btn btn-g btn-s" onclick="closeModal('mPreview')">Close</button>
      <button class="btn btn-g btn-s" onclick="window.print()"><i class='bx bx-printer'></i> Print</button>
      <button class="btn btn-p btn-s" onclick="toast('PDF download started','success')"><i class='bx bx-download'></i> Download PDF</button>
    </div>
  </div>
</div>

<div id="tw"></div>
</main>
<script>
const esc=s=>String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fmtDate=d=>d?new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'}):'—';
const todayStr=()=>new Date().toISOString().split('T')[0];

const TYPES=[
  {id:'volume',   name:'Document Volume Report',       sub:'Docs by type, dept & period',      ic:'bx-bar-chart-alt-2',bg:'#E8F5E9',col:'#2E7D32',sa:false},
  {id:'routing',  name:'Routing & Transit Report',     sub:'Routes, statuses & transit times',  ic:'bx-git-branch',      bg:'#EFF6FF',col:'#2563EB',sa:false},
  {id:'inout',    name:'Incoming / Outgoing Summary',  sub:'Document flow per department',      ic:'bx-transfer',         bg:'#F3E8FF',col:'#7C3AED',sa:false},
  {id:'archive',  name:'Archiving Status Report',      sub:'Active, archived & disposal queue', ic:'bx-archive',          bg:'#FEF3C7',col:'#B45309',sa:false},
  {id:'retention',name:'Retention & Compliance Report',sub:'Retention stages & compliance',     ic:'bx-shield-check',     bg:'#D1FAE5',col:'#065F46',sa:false},
  {id:'audit',    name:'Audit Trail Report',           sub:'Full system-wide activity (SA only)',ic:'bx-history',          bg:'#FEE2E2',col:'#DC2626',sa:true},
];
const TM=Object.fromEntries(TYPES.map(t=>[t.id,t]));
const MON=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

let scheds=[
  {id:1,type:'volume',   freq:'Monthly', day:'1st of Month',      email:'admin@dtrs.ph',      format:'PDF', dept:'All',  active:true},
  {id:2,type:'routing',  freq:'Weekly',  day:'Every Monday',      email:'routing@dtrs.ph',    format:'Both',dept:'All',  active:true},
  {id:3,type:'retention',freq:'Monthly', day:'Last Day of Month', email:'compliance@dtrs.ph', format:'PDF', dept:'Legal',active:false},
];
let editId=null, curReport=null;

/* ── STATS ── */
function renderStats(){
  const active=scheds.filter(s=>s.active).length;
  document.getElementById('statsRow').innerHTML=`
    <div class="stat-card"><div class="sic sic-g"><i class='bx bx-file'></i></div><div><div class="sv">6</div><div class="sl">Report Types</div></div></div>
    <div class="stat-card"><div class="sic sic-b"><i class='bx bx-calendar-check'></i></div><div><div class="sv">${scheds.length}</div><div class="sl">Scheduled</div></div></div>
    <div class="stat-card"><div class="sic sic-g"><i class='bx bx-check-circle'></i></div><div><div class="sv">${active}</div><div class="sl">Active Schedules</div></div></div>
    <div class="stat-card"><div class="sic sic-o"><i class='bx bx-trending-up'></i></div><div><div class="sv">72</div><div class="sl">Docs This Month</div></div></div>
    <div class="stat-card"><div class="sic sic-pu"><i class='bx bx-history'></i></div><div><div class="sv">1,248</div><div class="sl">Audit Entries</div></div></div>`;
}

/* ── TYPE LIST ── */
function renderTypes(){
  document.getElementById('rtypeList').innerHTML=TYPES.map(t=>`
    <div class="rtype-card${t.sa?' sa-locked':''}" id="rc-${t.id}" onclick="selectReport('${t.id}')">
      <div class="rc-ic" style="background:${t.bg};color:${t.col}"><i class='bx ${t.ic}'></i></div>
      <div style="flex:1;min-width:0"><div class="rc-name">${esc(t.name)}</div><div class="rc-sub">${esc(t.sub)}</div></div>
      ${t.sa?`<div class="sa-badge">SA Only</div>`:''}
    </div>`).join('');
}

/* ── SELECT REPORT ── */
function selectReport(id){
  curReport=id;
  const t=TM[id];
  document.querySelectorAll('.rtype-card').forEach(c=>c.classList.toggle('active',c.id===`rc-${id}`));
  const hi=document.getElementById('hdIc');
  hi.innerHTML=`<i class='bx ${t.ic}'></i>`;
  hi.style.cssText=`background:${t.bg};color:${t.col};width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0`;
  document.getElementById('hdTitle').textContent=t.name;
  document.getElementById('hdSub').textContent=t.sub;
  document.getElementById('hdActs').innerHTML=`
    <button class="btn btn-g btn-s" onclick="openPreview('${id}')"><i class='bx bx-show'></i> Preview</button>
    <button class="btn btn-g btn-s" onclick="doExport('${id}')"><i class='bx bx-export'></i> CSV</button>
    <button class="btn btn-p btn-s" onclick="toast('Preparing PDF…','info');setTimeout(()=>toast('PDF ready','success'),700)"><i class='bx bx-download'></i> PDF</button>`;
  buildBody(id);
}

/* ── REPORT BODIES ── */
function buildBody(id){
  const fns={volume:volBody,routing:routeBody,inout:inoutBody,archive:archBody,retention:retBody,audit:auditBody};
  document.getElementById('rcBody').innerHTML=fns[id]?fns[id]():'';
  requestAnimationFrame(()=>initCharts(id));
}

function volBody(){
  return`<div class="filter-row">
    <span class="fl-label">Period</span>
    <select class="fsel"><option>2025</option><option>2024</option><option>2023</option></select>
    <select class="fsel"><option>All Departments</option><option>HR</option><option>Finance</option><option>Admin</option><option>Legal</option><option>PSM</option></select>
    <select class="fsel"><option>All Doc Types</option><option>Contracts</option><option>Financial</option><option>HR Records</option><option>Legal</option></select>
  </div>
  <div class="metrics-grid">
    <div class="metric-box"><div class="m-val" style="color:var(--primary)">623</div><div class="m-lbl">Total Documents YTD</div><div class="m-trend t-up"><i class='bx bx-trending-up'></i>+12% vs last year</div></div>
    <div class="metric-box"><div class="m-val" style="color:var(--info)">72</div><div class="m-lbl">This Month</div><div class="m-trend t-up"><i class='bx bx-trending-up'></i>+8% vs last month</div></div>
    <div class="metric-box"><div class="m-val" style="color:var(--warning)">24</div><div class="m-lbl">Avg Per Week</div><div class="m-trend t-dn"><i class='bx bx-trending-down'></i>-3% vs last quarter</div></div>
  </div>
  <div class="chart-wrap">
    <div class="chart-title-row"><span class="chart-ttl">Monthly Document Volume — 2025</span><span style="font-size:10px;color:var(--text-3)">All Departments</span></div>
    <div class="bar-chart" id="cVol"></div>
  </div>
  <div class="tbl-wrap"><table class="rp-tbl">
    <thead><tr><th>Department</th><th>Contracts</th><th>Financial</th><th>HR Records</th><th>Legal</th><th>Compliance</th><th>Total</th><th>% Share</th></tr></thead>
    <tbody>
      ${[['HR','12','8','48','6','10','84','14','var(--primary)'],
         ['Finance','18','62','4','10','18','112','18','var(--info)'],
         ['Legal','44','10','2','58','22','136','22','var(--purple)'],
         ['Admin','8','14','16','12','30','80','13','var(--warning)'],
         ['PSM','22','20','10','8','24','84','14','var(--teal)']
        ].map(r=>`<tr><td><b>${r[0]}</b></td><td>${r[1]}</td><td>${r[2]}</td><td>${r[3]}</td><td>${r[4]}</td><td>${r[5]}</td>
        <td class="mono" style="font-weight:700;color:${r[8]}">${r[6]}</td>
        <td><div class="prog-row"><div class="prog-bar"><div class="prog-fill" style="width:${r[7]}%;background:${r[8]}"></div></div><span class="prog-pct">${r[7]}%</span></div></td></tr>`).join('')}
    </tbody>
    <tfoot><tr><td>Total</td><td>104</td><td>114</td><td>80</td><td>94</td><td>104</td><td class="mono" style="color:var(--primary);font-weight:800">623</td><td style="font-weight:700;color:var(--primary)">100%</td></tr></tfoot>
  </table></div>`;
}

function routeBody(){
  const rows=[
    ['DOC-2025-0011','Non-Disclosure Agreement','For Signature','var(--purple)','#F3E8FF','Legal → Executive','Ana Lim','Mar 12','chip-b','In Transit','1d 3h'],
    ['DOC-2025-0003','Supplier Accreditation','For Review','var(--info)','#EFF6FF','PSM → HR','Maria Santos','Mar 10','chip-g','Received','1d 4h'],
    ['DOC-2025-0019','Health & Safety Policy','For Review','var(--info)','#EFF6FF','Admin → Legal','Jose Bautista','Mar 08','chip-g','Completed','3d 1h'],
    ['DOC-2025-0022','Q1 Budget Variance','For Action','var(--danger)','#FEE2E2','Finance → Admin','Ryan Cruz','Mar 11','chip-b','In Transit','2d 0h'],
    ['DOC-2025-0007','Employee Handbook Amend.','For Review','var(--info)','#EFF6FF','HR → Legal','Rosa Dela Cruz','Mar 05','chip-o','Returned','3d 6h'],
  ];
  return`<div class="filter-row">
    <span class="fl-label">Period</span>
    <select class="fsel"><option>Last 30 Days</option><option>Last 90 Days</option><option>YTD</option></select>
    <select class="fsel"><option>All Route Types</option><option>For Action</option><option>For Review</option><option>For Signature</option><option>For Filing</option></select>
    <select class="fsel"><option>All Statuses</option><option>In Transit</option><option>Received</option><option>Returned</option><option>Completed</option></select>
  </div>
  <div class="metrics-grid">
    <div class="metric-box"><div class="m-val" style="color:var(--info)">48</div><div class="m-lbl">Total Routes (30d)</div><div class="m-trend t-up"><i class='bx bx-trending-up'></i>+6 vs prev period</div></div>
    <div class="metric-box"><div class="m-val" style="color:var(--warning)">3</div><div class="m-lbl">In Transit Now</div><div class="m-trend t-neu">System-wide</div></div>
    <div class="metric-box"><div class="m-val" style="color:var(--primary)">1.8d</div><div class="m-lbl">Avg Transit Time</div><div class="m-trend t-up"><i class='bx bx-trending-up'></i>Improved 0.3d</div></div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px">
    <div class="chart-wrap" style="margin-bottom:0"><div class="chart-title-row"><span class="chart-ttl">By Route Type</span></div><div class="donut-area" id="cRtype"></div></div>
    <div class="chart-wrap" style="margin-bottom:0"><div class="chart-title-row"><span class="chart-ttl">By Status</span></div><div class="donut-area" id="cRstatus"></div></div>
  </div>
  <div class="tbl-wrap"><table class="rp-tbl">
    <thead><tr><th>Doc ID</th><th>Name</th><th>Route Type</th><th>From → To</th><th>Assignee</th><th>Date</th><th>Status</th><th>Transit</th></tr></thead>
    <tbody>${rows.map(r=>`<tr>
      <td class="mono" style="color:var(--primary);font-size:11px">${r[0]}</td>
      <td style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${r[1]}</td>
      <td><span style="font-size:10px;font-weight:700;background:${r[4]};color:${r[3]};padding:2px 7px;border-radius:5px">${r[2]}</span></td>
      <td style="font-size:11px;color:var(--text-2)">${r[5]}</td>
      <td style="font-size:11px">${r[6]}</td>
      <td style="font-size:11px;color:var(--text-2)">${r[7]}</td>
      <td><span class="chip ${r[8]}">${r[9]}</span></td>
      <td class="mono" style="font-size:11px">${r[10]}</td></tr>`).join('')}</tbody>
  </table></div>`;
}

function inoutBody(){
  const rows=[['HR','84','62','+22','March'],['Finance','72','80','-8','April'],
    ['Legal','58','48','+10','June'],['Admin','60','58','+2','July'],['PSM','54','47','+7','December']];
  return`<div class="filter-row">
    <span class="fl-label">Period</span>
    <select class="fsel"><option>2025</option><option>2024</option><option>2023</option></select>
    <select class="fsel"><option>All Departments</option><option>HR</option><option>Finance</option><option>Admin</option><option>Legal</option><option>PSM</option></select>
  </div>
  <div class="metrics-grid">
    <div class="metric-box"><div class="m-val" style="color:var(--info)">328</div><div class="m-lbl">Total Incoming YTD</div><div class="m-trend t-up"><i class='bx bx-trending-up'></i>+14% vs last year</div></div>
    <div class="metric-box"><div class="m-val" style="color:var(--purple)">295</div><div class="m-lbl">Total Outgoing YTD</div><div class="m-trend t-up"><i class='bx bx-trending-up'></i>+9% vs last year</div></div>
    <div class="metric-box"><div class="m-val" style="color:var(--primary)">+33</div><div class="m-lbl">Net Document Flow</div><div class="m-trend t-up"><i class='bx bx-trending-up'></i>Net positive</div></div>
  </div>
  <div class="chart-wrap">
    <div class="chart-title-row"><span class="chart-ttl">Incoming vs Outgoing — 2025</span>
    <div class="chart-legend">
      <div class="leg-item"><div class="leg-dot" style="background:var(--info)"></div>Incoming</div>
      <div class="leg-item"><div class="leg-dot" style="background:var(--purple)"></div>Outgoing</div>
    </div></div>
    <div class="bar-chart" id="cInOut"></div>
  </div>
  <div class="tbl-wrap"><table class="rp-tbl">
    <thead><tr><th>Department</th><th>Incoming</th><th>Outgoing</th><th>Net</th><th>Peak Month</th></tr></thead>
    <tbody>${rows.map(r=>`<tr><td><b>${r[0]}</b></td><td class="mono">${r[1]}</td><td class="mono">${r[2]}</td>
      <td class="mono" style="font-weight:700;color:${r[3].startsWith('+')?'var(--primary)':'var(--danger)'}">${r[3]}</td>
      <td style="font-size:11px;color:var(--text-2)">${r[4]}</td></tr>`).join('')}</tbody>
    <tfoot><tr><td>Total</td><td class="mono">328</td><td class="mono">295</td><td class="mono" style="color:var(--primary);font-weight:800">+33</td><td>December</td></tr></tfoot>
  </table></div>`;
}

function archBody(){
  const rows=[
    ['DOC-2018-0001','Master Service Agreement','Contracts','Mar 15, 2018','7.1','chip-r','Compliance Review','Dispose / Extend','var(--danger)'],
    ['DOC-2019-0014','Annual Financial Report FY2018','Financial','Jan 20, 2019','6.2','chip-r','Compliance Review','Dispose / Extend','var(--danger)'],
    ['DOC-2020-0032','Employee Handbook v3.0','HR Records','Jun 10, 2020','4.8','chip-b','Archive','Monitor','var(--text-3)'],
    ['DOC-2022-0045','Procurement Policy v2','Procurement','Aug 01, 2022','2.7','chip-g','Active','3-yr check 2025','var(--text-3)'],
    ['DOC-2024-0019','Health & Safety Policy 2024','Compliance','May 22, 2024','0.8','chip-g','Active','3-yr check 2027','var(--text-3)'],
  ];
  return`<div class="filter-row">
    <span class="fl-label">Category</span>
    <select class="fsel"><option>All Categories</option><option>Contracts</option><option>Financial</option><option>HR Records</option><option>Legal</option></select>
    <select class="fsel"><option>All Stages</option><option>Active</option><option>Archive</option><option>Compliance Review</option><option>Disposed</option></select>
  </div>
  <div class="metrics-grid">
    <div class="metric-box"><div class="m-val" style="color:var(--primary)">7</div><div class="m-lbl">Active Storage</div><div class="m-trend t-neu">2 approaching archive</div></div>
    <div class="metric-box"><div class="m-val" style="color:var(--info)">4</div><div class="m-lbl">Archived</div><div class="m-trend t-neu">Avg 4.2 yrs in archive</div></div>
    <div class="metric-box"><div class="m-val" style="color:var(--danger)">3</div><div class="m-lbl">Compliance Review</div><div class="m-trend t-dn"><i class='bx bx-error'></i>Awaiting SA sign-off</div></div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px">
    <div class="chart-wrap" style="margin-bottom:0"><div class="chart-title-row"><span class="chart-ttl">By Archiving Stage</span></div><div class="donut-area" id="cArchStage"></div></div>
    <div class="chart-wrap" style="margin-bottom:0"><div class="chart-title-row"><span class="chart-ttl">By Document Category</span></div><div class="donut-area" id="cArchCat"></div></div>
  </div>
  <div class="tbl-wrap"><table class="rp-tbl">
    <thead><tr><th>Document ID</th><th>Document Name</th><th>Category</th><th>Date Created</th><th>Years Active</th><th>Stage</th><th>Next Action</th></tr></thead>
    <tbody>${rows.map(r=>`<tr>
      <td class="mono" style="color:var(--primary);font-size:11px">${r[0]}</td>
      <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${r[1]}</td>
      <td style="font-size:11px">${r[2]}</td><td style="font-size:11px">${r[3]}</td>
      <td class="mono" style="font-size:11px">${r[4]}</td>
      <td><span class="chip ${r[5]}">${r[6]}</span></td>
      <td style="font-size:11px;color:${r[8]};font-weight:600">${r[7]}</td></tr>`).join('')}</tbody>
  </table></div>`;
}

function retBody(){
  const rows=[['Contracts','12','8','2','1','3 yrs','7 yrs'],['Financial','18','12','1','2','3 yrs','7 yrs'],
    ['HR Records','22','10','0','0','3 yrs','7 yrs'],['Legal','8','6','2','0','5 yrs','10 yrs'],['Compliance','14','4','0','0','3 yrs','7 yrs']];
  return`<div class="filter-row">
    <span class="fl-label">Category</span>
    <select class="fsel"><option>All Categories</option><option>Contracts</option><option>Financial</option><option>HR Records</option><option>Legal</option></select>
    <select class="fsel"><option>All Stages</option><option>Active (0–3yr)</option><option>Archive (3–7yr)</option><option>Review (7+yr)</option><option>Disposed</option></select>
  </div>
  <div class="metrics-grid">
    <div class="metric-box"><div class="m-val" style="color:var(--danger)">5</div><div class="m-lbl">Pending Compliance Action</div><div class="m-trend t-dn"><i class='bx bx-error'></i>Requires SA review</div></div>
    <div class="metric-box"><div class="m-val" style="color:var(--warning)">2</div><div class="m-lbl">Approaching 3-Year Mark</div><div class="m-trend t-neu">Archive soon</div></div>
    <div class="metric-box"><div class="m-val" style="color:var(--text-3)">3</div><div class="m-lbl">Total Disposed (All Time)</div><div class="m-trend t-neu">SA-approved</div></div>
  </div>
  <div class="chart-wrap">
    <div class="chart-title-row"><span class="chart-ttl">Retention Stage Distribution</span></div>
    <div class="donut-area" id="cRet"></div>
  </div>
  <div class="tbl-wrap"><table class="rp-tbl">
    <thead><tr><th>Category</th><th>Active (0–3yr)</th><th>Archive (3–7yr)</th><th>Review (7+yr)</th><th>Disposed</th><th>Active Rule</th><th>Review Rule</th></tr></thead>
    <tbody>${rows.map(r=>`<tr><td><b>${r[0]}</b></td><td class="mono">${r[1]}</td><td class="mono">${r[2]}</td>
      <td class="mono" style="font-weight:700;color:${r[4]!='0'?'var(--danger)':'inherit'}">${r[4]}</td>
      <td class="mono" style="color:var(--text-3)">${r[5]}</td>
      <td style="font-size:11px;color:var(--text-2)">${r[6]}</td>
      <td style="font-size:11px;color:var(--text-2)">${r[7]}</td></tr>`).join('')}</tbody>
    <tfoot><tr><td>Total</td><td class="mono">74</td><td class="mono">40</td><td class="mono" style="color:var(--danger)">5</td><td class="mono" style="color:var(--text-3)">3</td><td colspan="2"></td></tr></tfoot>
  </table></div>`;
}

function auditBody(){
  const entries=[
    {act:'Document Disposed — DOC-2015-0003 (Master Contract)',by:'superadmin@dtrs.ph',ts:'Feb 20, 2025 · 02:00 PM',ref:'DOC-2015-0003',dot:'d-r'},
    {act:'SA Override: Rerouted DOC-2025-0011 Legal → Executive Office',by:'superadmin@dtrs.ph',ts:'Mar 12, 2025 · 09:10 AM',ref:'DOC-2025-0011',dot:'d-o'},
    {act:'Retention policy updated — Legal: 5 yrs → 7 yrs',by:'superadmin@dtrs.ph',ts:'Mar 01, 2025 · 10:00 AM',ref:'SYSTEM',dot:'d-b'},
    {act:'Document registered — DOC-2025-0028 (Procurement Manual v2)',by:'Carlos Reyes',ts:'Mar 13, 2025 · 08:00 AM',ref:'DOC-2025-0028',dot:'d-g'},
    {act:'Route completed — DOC-2025-0019 Legal review cleared',by:'Jose Bautista',ts:'Mar 11, 2025 · 04:00 PM',ref:'DOC-2025-0019',dot:'d-g'},
    {act:'Document returned — DOC-2025-0007 revisions required',by:'Rosa Dela Cruz',ts:'Mar 08, 2025 · 03:00 PM',ref:'DOC-2025-0007',dot:'d-o'},
    {act:'New route created — DOC-2025-0022 Finance → Admin',by:'Ana Lim',ts:'Mar 11, 2025 · 11:00 AM',ref:'DOC-2025-0022',dot:'d-b'},
    {act:'Document received — DOC-2025-0003 HR Department',by:'Maria Santos',ts:'Mar 11, 2025 · 02:00 PM',ref:'DOC-2025-0003',dot:'d-g'},
    {act:'Access level changed — DOC-2024-0019 → Confidential',by:'superadmin@dtrs.ph',ts:'Mar 09, 2025 · 11:30 AM',ref:'DOC-2024-0019',dot:'d-o'},
    {act:'User login — admin@dtrs.ph from 192.168.1.42',by:'System',ts:'Mar 13, 2025 · 07:55 AM',ref:'SYSTEM',dot:'d-gy'},
  ];
  return`<div class="sa-banner"><i class='bx bx-shield-quarter'></i><span>Super Admin Exclusive — Full system-wide audit trail. All entries are permanent and read-only.</span></div>
  <div class="filter-row">
    <span class="fl-label">Filter</span>
    <select class="fsel" onchange="filterAudit(this.value,'act')"><option value="">All Actions</option><option value="disposed">Disposals</option><option value="override">Overrides</option><option value="route">Routes</option><option value="policy">Policy Changes</option></select>
    <select class="fsel" onchange="filterAudit(this.value,'by')"><option value="">All Users</option><option>superadmin@dtrs.ph</option><option>Maria Santos</option><option>Carlos Reyes</option><option>Jose Bautista</option></select>
    <input type="date" class="fdate" title="Date from">
    <input type="date" class="fdate" title="Date to">
  </div>
  <div class="metrics-grid">
    <div class="metric-box"><div class="m-val" style="color:var(--primary)">1,248</div><div class="m-lbl">Total Audit Entries</div></div>
    <div class="metric-box"><div class="m-val" style="color:var(--danger)">12</div><div class="m-lbl">SA Actions (30d)</div></div>
    <div class="metric-box"><div class="m-val" style="color:var(--warning)">3</div><div class="m-lbl">Force Overrides (30d)</div></div>
  </div>
  <div class="audit-feed" id="auditFeed">
  ${entries.map(e=>`<div class="audit-row" data-act="${esc(e.act.toLowerCase())}" data-by="${esc(e.by.toLowerCase())}">
    <div class="a-dot ${e.dot}"></div>
    <div style="flex:1"><div class="a-act">${esc(e.act)}</div><div class="a-by">By ${esc(e.by)}</div><div class="a-ts">${esc(e.ref)} · ${esc(e.ts)}</div></div>
  </div>`).join('')}
  </div>`;
}

window.filterAudit=(v,field)=>{
  const q=v.toLowerCase();
  document.querySelectorAll('#auditFeed .audit-row').forEach(r=>{
    r.style.display=(!q||r.dataset[field].includes(q))?'flex':'none';
  });
};

/* ── CHARTS ── */
const VOL=[32,28,45,41,38,52,60,48,55,62,58,72];
const IND=[18,14,24,20,19,28,32,25,28,34,30,40];
const OUTD=[14,14,21,21,19,24,28,23,27,28,28,32];

function initCharts(id){
  if(id==='volume'){
    const el=document.getElementById('cVol');if(!el)return;
    const mx=Math.max(...VOL);
    el.innerHTML=VOL.map((v,i)=>`<div class="bar-col">
      <div class="bar-grp"><div class="bar" data-tip="${v} docs" style="flex:1;height:${Math.round(v/mx*110)}px;background:var(--primary);opacity:${.4+i/VOL.length*.6}"></div></div>
      <div class="bar-lbl">${MON[i]}</div></div>`).join('');
  }
  if(id==='routing'){
    mkDonut('cRtype',[{l:'For Action',v:14,c:'#DC2626'},{l:'For Review',v:22,c:'#2563EB'},{l:'For Signature',v:8,c:'#7C3AED'},{l:'For Filing',v:4,c:'#9CA3AF'}]);
    mkDonut('cRstatus',[{l:'Completed',v:28,c:'#2E7D32'},{l:'In Transit',v:8,c:'#2563EB'},{l:'Received',v:6,c:'#065F46'},{l:'Returned',v:6,c:'#B45309'}]);
  }
  if(id==='inout'){
    const el=document.getElementById('cInOut');if(!el)return;
    const mx=Math.max(...IND,...OUTD);
    el.innerHTML=IND.map((v,i)=>`<div class="bar-col">
      <div class="bar-grp">
        <div class="bar" data-tip="In: ${v}" style="flex:1;height:${Math.round(v/mx*110)}px;background:var(--info)"></div>
        <div class="bar" data-tip="Out: ${OUTD[i]}" style="flex:1;height:${Math.round(OUTD[i]/mx*110)}px;background:var(--purple)"></div>
      </div>
      <div class="bar-lbl">${MON[i]}</div></div>`).join('');
  }
  if(id==='archive'){
    mkDonut('cArchStage',[{l:'Active',v:7,c:'#2E7D32'},{l:'Archived',v:4,c:'#2563EB'},{l:'Review',v:3,c:'#DC2626'},{l:'Disposed',v:3,c:'#9CA3AF'}]);
    mkDonut('cArchCat',[{l:'Contracts',v:23,c:'#2E7D32'},{l:'Financial',v:33,c:'#2563EB'},{l:'HR Records',v:32,c:'#7C3AED'},{l:'Legal',v:16,c:'#B45309'},{l:'Compliance',v:18,c:'#0D9488'}]);
  }
  if(id==='retention'){
    mkDonut('cRet',[{l:'Active (0–3yr)',v:74,c:'#2E7D32'},{l:'Archive (3–7yr)',v:40,c:'#2563EB'},{l:'Review (7+yr)',v:5,c:'#DC2626'},{l:'Disposed',v:3,c:'#9CA3AF'}]);
  }
}

function mkDonut(elId,data){
  const el=document.getElementById(elId);if(!el)return;
  const total=data.reduce((s,d)=>s+d.v,0);
  const R=42,cx=55,cy=55,C=2*Math.PI*R;
  let off=0;
  const segs=data.map(d=>{
    const dash=d.v/total*C;
    const s=`<circle cx="${cx}" cy="${cy}" r="${R}" fill="none" stroke="${d.c}" stroke-width="13" stroke-dasharray="${dash.toFixed(2)} ${(C-dash).toFixed(2)}" stroke-dashoffset="${(-off).toFixed(2)}" transform="rotate(-90 ${cx} ${cy})"><title>${d.l}: ${d.v}</title></circle>`;
    off+=dash;return s;
  }).join('');
  el.innerHTML=`<svg width="110" height="110" viewBox="0 0 110 110" style="flex-shrink:0">${segs}
    <text x="${cx}" y="${cy+5}" text-anchor="middle" font-size="14" font-weight="800" fill="var(--text-1)" font-family="'DM Mono',monospace">${total}</text></svg>
    <div class="donut-legend">${data.map(d=>`<div class="dl-row"><div class="dl-sw" style="background:${d.c}"></div><div class="dl-nm">${esc(d.l)}</div><div class="dl-n">${d.v}</div><div class="dl-p">${Math.round(d.v/total*100)}%</div></div>`).join('')}</div>`;
}

/* ── SCHEDULES ── */
const TN={volume:'Volume Report',routing:'Routing Report',inout:'In/Out Summary',archive:'Archive Status',retention:'Retention Report',audit:'Audit Trail (SA)'};
const TI={volume:'bx-bar-chart-alt-2',routing:'bx-git-branch',inout:'bx-transfer',archive:'bx-archive',retention:'bx-shield-check',audit:'bx-history'};
const TC={volume:'#2E7D32',routing:'#2563EB',inout:'#7C3AED',archive:'#B45309',retention:'#065F46',audit:'#DC2626'};

function renderScheds(){
  const el=document.getElementById('schedList');
  if(!scheds.length){el.innerHTML=`<div style="padding:22px;text-align:center;color:var(--text-3);font-size:12px">No scheduled reports. Add one with the button above.</div>`;renderStats();return;}
  el.innerHTML=scheds.map(s=>`<div class="sched-item">
    <div class="sched-ic" style="background:${TC[s.type]}22;color:${TC[s.type]}"><i class='bx ${TI[s.type]}'></i></div>
    <div style="flex:1;min-width:0">
      <div class="sched-name">${TN[s.type]||s.type}</div>
      <div class="sched-meta">${esc(s.freq)} · ${esc(s.day)} · ${esc(s.email)} · ${esc(s.format)} · ${esc(s.dept)}</div>
    </div>
    <div class="sched-acts">
      <label class="tog" title="${s.active?'Active':'Paused'}">
        <input type="checkbox" ${s.active?'checked':''} onchange="toggleS(${s.id},this.checked)">
        <span class="tog-sl"></span>
      </label>
      <button class="ib" onclick="editS(${s.id})" title="Edit"><i class='bx bx-edit'></i></button>
      <button class="ib del" onclick="deleteS(${s.id})" title="Delete"><i class='bx bx-trash'></i></button>
    </div>
  </div>`).join('');
  renderStats();
}

window.toggleS=(id,v)=>{const s=scheds.find(x=>x.id===id);if(s){s.active=v;toast(v?'Schedule activated':'Schedule paused',v?'success':'warning');renderScheds();}};
window.deleteS=(id)=>{if(!confirm('Remove this scheduled report?'))return;scheds=scheds.filter(x=>x.id!==id);toast('Schedule removed','warning');renderScheds();};
window.editS=(id)=>{
  const s=scheds.find(x=>x.id===id);if(!s)return;
  editId=id;document.getElementById('sModalTitle').textContent='Edit Scheduled Report';
  document.getElementById('sType').value=s.type;document.getElementById('sFreq').value=s.freq;
  document.getElementById('sDay').value=s.day;document.getElementById('sEmail').value=s.email;
  document.getElementById('sFormat').value=s.format;document.getElementById('sDept').value=s.dept;
  showM('mSched');
};

document.getElementById('btnAddSched').onclick=()=>{
  editId=null;document.getElementById('sModalTitle').textContent='Add Scheduled Report';
  ['sType','sFreq','sEmail'].forEach(i=>document.getElementById(i).value='');
  document.getElementById('sDay').value='Every Monday';document.getElementById('sFormat').value='PDF';document.getElementById('sDept').value='All';
  showM('mSched');
};

document.getElementById('sConfirm').onclick=()=>{
  const type=document.getElementById('sType').value,freq=document.getElementById('sFreq').value,email=document.getElementById('sEmail').value.trim();
  if(!type){shk('sType');return toast('Select a report type','danger');}
  if(!freq){shk('sFreq');return toast('Select a frequency','danger');}
  if(!email){shk('sEmail');return toast('Email address is required','danger');}
  const d={type,freq,day:document.getElementById('sDay').value,email,format:document.getElementById('sFormat').value,dept:document.getElementById('sDept').value,active:true};
  if(editId){Object.assign(scheds.find(x=>x.id===editId),d);toast('Schedule updated','success');}
  else{d.id=Date.now();scheds.push(d);toast('Schedule saved — auto-reports enabled','success');}
  closeM('mSched');editId=null;renderScheds();
};

/* ── GENERATE ── */
document.getElementById('btnGenerate').onclick=()=>{
  document.getElementById('gType').value=curReport||'';
  document.getElementById('gDateFrom').value='';document.getElementById('gDateTo').value=todayStr();
  showM('mGenerate');
};
document.getElementById('gConfirm').onclick=()=>{
  const type=document.getElementById('gType').value;
  if(!type){shk('gType');return toast('Select a report type','danger');}
  toast(`Generating ${TN[type]}…`,'info');
  setTimeout(()=>{closeM('mGenerate');selectReport(type);toast(`${TN[type]} ready`,'success');},800);
};

/* ── EXPORT / SCHEDULE SCROLL ── */
document.getElementById('btnSchedule').onclick=()=>document.getElementById('schedSection').scrollIntoView({behavior:'smooth',block:'start'});
document.getElementById('btnExport').onclick=()=>{
  if(!curReport)return toast('Select a report type first','warning');
  toast(`Exporting ${TN[curReport]} as CSV…`,'info');setTimeout(()=>toast('Export complete','success'),700);
};
window.doExport=id=>{toast(`Exporting ${TN[id]} as CSV…`,'info');setTimeout(()=>toast('Export complete','success'),700);};

/* ── PREVIEW ── */
window.openPreview=id=>{
  const t=TM[id];
  document.getElementById('pvTitle').textContent=t.name;
  document.getElementById('pvSub').textContent=`Generated ${fmtDate(todayStr())} · All Departments`;
  document.getElementById('pvBody').innerHTML=`
    <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between">
      <div><div style="font-size:14px;font-weight:800">${esc(t.name)}</div><div style="font-size:11px;color:var(--text-3)">DTRS · Document Tracking & Logistics Records</div></div>
      <div style="text-align:right"><div style="font-size:10px;color:var(--text-3)">Generated</div><div style="font-size:12px;font-weight:700;font-family:'DM Mono',monospace">${fmtDate(todayStr())}</div></div>
    </div>
    ${document.getElementById('rcBody').innerHTML}`;
  showM('mPreview');
};

/* ── MODAL HELPERS ── */
function showM(id){document.getElementById(id).classList.add('show');document.getElementById('ov').classList.add('show');}
function closeM(id){
  document.getElementById(id).classList.remove('show');
  const open=['mGenerate','mSched','mPreview'].some(m=>m!==id&&document.getElementById(m).classList.contains('show'));
  if(!open)document.getElementById('ov').classList.remove('show');
}
window.closeModal=closeM;
document.getElementById('ov').onclick=()=>{['mGenerate','mSched','mPreview'].forEach(closeM);document.getElementById('ov').classList.remove('show');};

/* ── TOAST ── */
window.toast=(msg,type='success')=>{
  const ic={success:'bx-check-circle',danger:'bx-error-circle',warning:'bx-error',info:'bx-info-circle'};
  const el=document.createElement('div');el.className=`toast ${type}`;
  el.innerHTML=`<i class='bx ${ic[type]}' style="font-size:16px;flex-shrink:0"></i>${esc(msg)}`;
  document.getElementById('tw').appendChild(el);
  setTimeout(()=>{el.classList.add('out');setTimeout(()=>el.remove(),300);},3200);
};
function shk(id){const el=document.getElementById(id);if(!el)return;el.style.borderColor='#DC2626';el.offsetHeight;el.style.animation='shake .3s ease';setTimeout(()=>{el.style.borderColor='';el.style.animation='';},600);}

/* ── INIT ── */
renderStats();renderTypes();renderScheds();selectReport('volume');
</script>
</body>
</html>