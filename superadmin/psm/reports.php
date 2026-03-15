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
  <title>Procurement Reports — PSM</title>
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/Log1/css/base.css">
  <link rel="stylesheet" href="/Log1/css/sidebar.css">
  <link rel="stylesheet" href="/Log1/css/header.css">

  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --primary:     #2E7D32;
  --primary-dark:#1B5E20;
  --primary-lt:  #81C784;
  --bg:          #F4F6F5;
  --surface:     #FFFFFF;
  --border:      rgba(46,125,50,.13);
  --border-md:   rgba(46,125,50,.24);
  --text-1:      #1A2E1C;
  --text-2:      #4A6350;
  --text-3:      #9EB0A2;
  --hover:       rgba(46,125,50,.05);
  --shadow-sm:   0 1px 4px rgba(46,125,50,.08);
  --shadow-md:   0 4px 16px rgba(46,125,50,.11);
  --shadow-lg:   0 20px 60px rgba(0,0,0,.15);
  --red:         #DC2626;
  --amber:       #D97706;
  --blue:        #2563EB;
  --teal:        #0D9488;
  --purple:      #7C3AED;
  --rad:         12px;
  --tr:          all .18s ease;
}

body { font-family: 'Inter', sans-serif; color: var(--text-1); background: var(--bg); min-height: 100vh; }

.rpt-page { max-width: 1400px; margin: 0 auto; }

/* PAGE HEADER */
.rpt-hdr {
  display: flex; align-items: flex-end;
  justify-content: space-between; flex-wrap: wrap;
  gap: 12px; margin-bottom: 26px;
  animation: fadeUp .4s both;
}
.rpt-hdr .eyebrow {
  font-size: 11px; font-weight: 600;
  letter-spacing: .14em; text-transform: uppercase;
  color: var(--primary); margin-bottom: 4px;
}
.rpt-hdr h1 { font-size: 26px; font-weight: 800; color: var(--text-1); line-height: 1.15; }
.hdr-actions { display: flex; gap: 10px; align-items: center; }

/* BUTTONS */
.btn {
  display: inline-flex; align-items: center; gap: 7px;
  font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600;
  padding: 9px 18px; border-radius: 10px;
  border: none; cursor: pointer; transition: var(--tr); white-space: nowrap;
}
.btn-primary { background: var(--primary); color: #fff; box-shadow: 0 2px 8px rgba(46,125,50,.3); }
.btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
.btn-primary:disabled { opacity: .45; pointer-events: none; }
.btn-ghost { background: var(--surface); color: var(--text-2); border: 1px solid var(--border-md); }
.btn-ghost:hover { background: var(--hover); color: var(--text-1); }
.btn-excel { background: #16A34A; color: #fff; }
.btn-excel:hover { background: #15803D; transform: translateY(-1px); }
.btn-excel:disabled { opacity: .45; pointer-events: none; }
.btn-pdf { background: var(--red); color: #fff; }
.btn-pdf:hover { background: #B91C1C; transform: translateY(-1px); }
.btn-pdf:disabled { opacity: .45; pointer-events: none; }
.btn-csv { background: var(--teal); color: #fff; }
.btn-csv:hover { background: #0F766E; transform: translateY(-1px); }
.btn-csv:disabled { opacity: .45; pointer-events: none; }
.btn-sm { font-size: 12px; padding: 7px 14px; }

/* REPORT TYPE TABS */
.rpt-type-wrap {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 6px;
  margin-bottom: 16px;
  box-shadow: var(--shadow-sm);
  display: flex; flex-wrap: wrap; gap: 4px;
  animation: fadeUp .4s .03s both;
}
.rpt-tab {
  display: inline-flex; align-items: center; gap: 6px;
  font-family: 'Inter', sans-serif; font-size: 12px; font-weight: 600;
  padding: 8px 14px; border-radius: 10px;
  border: none; cursor: pointer; transition: var(--tr);
  color: var(--text-2); background: transparent;
  white-space: nowrap;
}
.rpt-tab i { font-size: 14px; }
.rpt-tab:hover { background: var(--hover); color: var(--text-1); }
.rpt-tab.active { background: var(--primary); color: #fff; box-shadow: 0 2px 8px rgba(46,125,50,.3); }

/* CONTROL CARD */
.ctrl-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 20px 24px;
  box-shadow: var(--shadow-md);
  margin-bottom: 16px;
  animation: fadeUp .4s .06s both;
}
.ctrl-row {
  display: flex; flex-wrap: wrap;
  align-items: flex-end; gap: 12px;
}
.ctrl-group { display: flex; flex-direction: column; gap: 7px; }
.ctrl-group.grow { flex: 1; min-width: 130px; }
.ctrl-group.fixed-sm { flex: 0 0 130px; }
.ctrl-group.fixed-md { flex: 0 0 160px; }
.ctrl-label {
  font-size: 11px; font-weight: 700;
  letter-spacing: .07em; text-transform: uppercase; color: var(--text-2);
}
.ctrl-select, .ctrl-date {
  font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 500;
  padding: 10px 34px 10px 12px;
  border: 1.5px solid var(--border-md);
  border-radius: 10px;
  background: var(--bg); color: var(--text-1);
  outline: none; transition: var(--tr); cursor: pointer;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 10px center; width: 100%;
}
.ctrl-date { padding: 10px 12px; appearance: auto; background-image: none; }
.ctrl-select:focus, .ctrl-date:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(46,125,50,.12);
  background-color: var(--surface);
}
.ctrl-sep {
  font-size: 12px; color: var(--text-3); padding-bottom: 13px;
  font-weight: 600; display: flex; align-items: flex-end; justify-content: center;
}
.ctrl-actions { display: flex; align-items: flex-end; gap: 8px; }

/* STATS */
.rpt-stats {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(155px,1fr));
  gap: 12px; margin-bottom: 16px; animation: fadeUp .4s .08s both;
}
.rpt-stat {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--rad); padding: 14px 16px; box-shadow: var(--shadow-sm);
  display: flex; align-items: center; gap: 12px;
}
.rpt-stat-ic {
  width: 38px; height: 38px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}
.ic-g { background:#E8F5E9; color:var(--primary); }
.ic-a { background:#FEF3C7; color:var(--amber); }
.ic-r { background:#FEE2E2; color:var(--red); }
.ic-b { background:#EFF6FF; color:var(--blue); }
.ic-t { background:#CCFBF1; color:var(--teal); }
.ic-p { background:#EDE9FE; color:var(--purple); }
.rpt-stat-v { font-size: 20px; font-weight: 800; color: var(--text-1); line-height: 1.1; }
.rpt-stat-l { font-size: 11px; color: var(--text-2); margin-top: 2px; }

/* RESULT CARD */
.rpt-result {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 16px; overflow: hidden; box-shadow: var(--shadow-md);
  animation: fadeUp .4s .1s both;
}
.rpt-result-hdr {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 12px; padding: 18px 22px 16px;
  border-bottom: 1px solid var(--border); background: var(--bg);
}
.rpt-result-title { font-size: 15px; font-weight: 700; color: var(--text-1); display: flex; align-items: center; gap: 9px; }
.rpt-result-meta { font-size: 12px; color: var(--text-2); margin-top: 3px; }
.rpt-export-btns { display: flex; gap: 8px; flex-wrap: wrap; }

/* TABLE */
.rpt-table-wrap { overflow-x: auto; }
.rpt-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rpt-table thead th {
  font-size: 11px; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; color: var(--text-2);
  padding: 11px 16px; text-align: left;
  background: var(--bg); border-bottom: 1px solid var(--border);
  white-space: nowrap; cursor: pointer; user-select: none; transition: color .15s;
}
.rpt-table thead th:hover { color: var(--primary); }
.rpt-table thead th.sorted { color: var(--primary); }
.rpt-table thead th .si { margin-left: 4px; opacity:.4; font-size:13px; vertical-align:middle; }
.rpt-table thead th.sorted .si { opacity:1; }
.rpt-table thead th:first-child { padding-left: 22px; }
.rpt-table thead th:last-child  { padding-right: 22px; }
.rpt-table tbody tr { border-bottom: 1px solid var(--border); transition: background .12s; }
.rpt-table tbody tr:last-child { border-bottom: none; }
.rpt-table tbody tr:hover { background: var(--hover); }
.rpt-table tbody td { padding: 12px 16px; vertical-align: middle; }
.rpt-table tbody td:first-child { padding-left: 22px; }
.rpt-table tbody td:last-child  { padding-right: 22px; }
.rpt-table tfoot td {
  padding: 12px 16px; font-weight: 700; font-size: 12px;
  background: var(--bg); border-top: 2px solid var(--border); color: var(--text-1);
}
.rpt-table tfoot td:first-child { padding-left: 22px; }

/* CELL STYLES */
.mono { font-family: 'DM Mono', monospace; font-size: 12px; color: var(--text-1); }
.item-name { font-weight: 600; color: var(--text-1); }
.item-sub  { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--text-2); margin-top: 2px; }
.cat-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 7px;
  background: var(--bg); color: var(--text-2); border: 1px solid var(--border);
}
.status-chip {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 8px;
}
.status-chip::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; flex-shrink:0; }
.s-approved  { background:#E8F5E9; color:#1B5E20; }
.s-pending   { background:#FEF3C7; color:#92400E; }
.s-rejected  { background:#FEE2E2; color:#991B1B; }
.s-partial   { background:#EFF6FF; color:#1E40AF; }
.s-complete  { background:#CCFBF1; color:#065F46; }
.s-overdue   { background:#FEE2E2; color:#991B1B; }
.s-ontime    { background:#E8F5E9; color:#1B5E20; }
.s-draft     { background:#F3F4F6; color:#6B7280; }
.s-active    { background:#E8F5E9; color:#1B5E20; }
.s-expiring  { background:#FEF3C7; color:#92400E; }
.s-expired   { background:#FEE2E2; color:#991B1B; }
.s-disputed  { background:#FFF5F5; color:#9B1C1C; }

.micro-bar { width: 70px; height: 5px; background: #E5E7EB; border-radius: 99px; overflow: hidden; }
.micro-fill { height: 100%; border-radius: 99px; }

/* EMPTY / LOADING */
.rpt-empty { padding: 70px 20px; text-align: center; color: var(--text-3); }
.rpt-empty i { font-size: 52px; display: block; margin-bottom: 14px; color: #C8E6C9; }
.rpt-empty p { font-size: 14px; }
.rpt-empty .sub { font-size: 12px; margin-top: 6px; color: var(--text-3); }
.rpt-loading { padding: 70px 20px; text-align: center; display: none; }
.spinner {
  width: 38px; height: 38px; border: 3px solid #C8E6C9;
  border-top-color: var(--primary); border-radius: 50%;
  animation: spin .7s linear infinite;
  display: inline-block; margin-bottom: 14px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* SCHEDULE MODAL */
.modal-ov {
  position: fixed; inset: 0; background: rgba(0,0,0,.45);
  z-index: 1200; display: flex; align-items: center; justify-content: center;
  padding: 20px; opacity: 0; pointer-events: none; transition: opacity .25s;
}
.modal-ov.show { opacity: 1; pointer-events: all; }
.modal-box {
  background: var(--surface); border-radius: 20px;
  width: 520px; max-width: 100%; max-height: 90vh; overflow-y: auto;
  box-shadow: var(--shadow-lg); transform: scale(.96); transition: transform .25s;
}
.modal-ov.show .modal-box { transform: scale(1); }
.modal-hd {
  padding: 22px 26px 18px; border-bottom: 1px solid var(--border);
  display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
  background: var(--bg);
}
.modal-title { font-size: 16px; font-weight: 700; color: var(--text-1); }
.modal-sub   { font-size: 12px; color: var(--text-2); margin-top: 3px; }
.modal-cl {
  width: 34px; height: 34px; border-radius: 8px; border: 1px solid var(--border-md);
  background: var(--surface); cursor: pointer; display: grid; place-content: center;
  font-size: 18px; color: var(--text-2); transition: var(--tr); flex-shrink: 0;
}
.modal-cl:hover { background: #FEE2E2; color: var(--red); border-color: #FECACA; }
.modal-bd { padding: 22px 26px; display: flex; flex-direction: column; gap: 16px; }
.modal-ft {
  padding: 16px 26px; border-top: 1px solid var(--border);
  display: flex; gap: 10px; justify-content: flex-end; background: var(--bg);
}
.form-group { display: flex; flex-direction: column; gap: 7px; }
.form-row   { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-label {
  font-size: 11px; font-weight: 700; letter-spacing: .07em;
  text-transform: uppercase; color: var(--text-2);
}
.form-label span { color: var(--red); margin-left: 2px; }
.form-input, .form-select {
  font-family: 'Inter', sans-serif; font-size: 13px;
  padding: 10px 12px; border: 1.5px solid var(--border-md);
  border-radius: 10px; background: var(--bg); color: var(--text-1);
  outline: none; transition: var(--tr); width: 100%;
}
.form-select {
  appearance: none; cursor: pointer; padding-right: 32px;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: right 10px center;
}
.form-input:focus, .form-select:focus {
  border-color: var(--primary); box-shadow: 0 0 0 3px rgba(46,125,50,.12);
  background-color: var(--surface);
}
.sched-info {
  background: linear-gradient(135deg,rgba(46,125,50,.04),rgba(13,148,136,.04));
  border: 1px solid rgba(46,125,50,.15); border-radius: 10px;
  padding: 12px 14px; font-size: 12px; color: var(--text-2);
  display: flex; gap: 10px; align-items: flex-start;
}
.sched-info i { color: var(--primary); font-size: 16px; flex-shrink: 0; margin-top: 1px; }

/* TOAST */
.toast-wrap {
  position: fixed; bottom: 28px; right: 28px;
  display: flex; flex-direction: column; gap: 10px;
  z-index: 9999; pointer-events: none;
}
.toast {
  background: #0A1F0D; color: #fff; padding: 12px 18px; border-radius: 10px;
  font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 10px;
  box-shadow: var(--shadow-lg); pointer-events: all; min-width: 220px;
  animation: toastIn .3s ease;
}
.toast.t-success { background: var(--primary); }
.toast.t-warning { background: var(--amber); }
.toast.t-danger  { background: var(--red); }
.toast.t-teal    { background: var(--teal); }
.toast.t-out     { animation: toastOut .3s ease forwards; }

@keyframes fadeUp  { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
@keyframes toastIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
@keyframes toastOut{ from{opacity:1} to{opacity:0;transform:translateY(8px)} }
@keyframes rowIn   { from{opacity:0;transform:translateX(-6px)} to{opacity:1;transform:translateX(0)} }

@media (max-width: 900px) {
  .ctrl-row { flex-wrap: wrap; }
  .ctrl-group.fixed-sm, .ctrl-group.fixed-md { flex: 1 1 140px; }
  .rpt-stats { grid-template-columns: repeat(2,1fr); }
  .rpt-export-btns { flex-wrap: wrap; }
  .rpt-type-wrap { overflow-x: auto; flex-wrap: nowrap; }
  .form-row { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<main class="main-content" id="mainContent">
<div class="rpt-page">

  <!-- PAGE HEADER -->
  <div class="rpt-hdr">
    <div>
      <p class="eyebrow">PSM · Procurement &amp; Sourcing Management</p>
      <h1>Procurement Reports</h1>
    </div>
    <div class="hdr-actions">
      <button class="btn btn-ghost" onclick="openScheduleModal()">
        <i class='bx bx-calendar-event'></i> Schedule Report
      </button>
    </div>
  </div>

  <!-- REPORT TYPE TABS -->
  <div class="rpt-type-wrap" id="rptTabs">
    <button class="rpt-tab active" data-type="pr_summary"       onclick="setTab(this,'pr_summary')">      <i class='bx bx-list-ul'></i>        PR Summary</button>
    <button class="rpt-tab"        data-type="po_summary"       onclick="setTab(this,'po_summary')">      <i class='bx bx-purchase-tag-alt'></i>PO Summary</button>
    <button class="rpt-tab"        data-type="supplier_spend"   onclick="setTab(this,'supplier_spend')">  <i class='bx bx-money'></i>           Supplier Spend</button>
    <button class="rpt-tab"        data-type="rfq_cycle"        onclick="setTab(this,'rfq_cycle')">       <i class='bx bx-time-five'></i>       RFQ Cycle Time</button>
    <button class="rpt-tab"        data-type="contract_expiry"  onclick="setTab(this,'contract_expiry')"> <i class='bx bx-file-blank'></i>      Contract Expiry</button>
    <button class="rpt-tab"        data-type="receiving"        onclick="setTab(this,'receiving')">       <i class='bx bx-package'></i>         Receiving &amp; Inspection</button>
    <button class="rpt-tab"        data-type="supplier_perf"    onclick="setTab(this,'supplier_perf')">   <i class='bx bx-bar-chart-alt-2'></i> Supplier Performance</button>
    <button class="rpt-tab"        data-type="audit_trail"      onclick="setTab(this,'audit_trail')">     <i class='bx bx-shield-quarter'></i>  Audit Trail</button>
  </div>

  <!-- FILTERS -->
  <div class="ctrl-card">
    <div class="ctrl-row">
      <div class="ctrl-group fixed-sm">
        <label class="ctrl-label">Date From</label>
        <input type="date" class="ctrl-date" id="dateFrom">
      </div>
      <span class="ctrl-sep">—</span>
      <div class="ctrl-group fixed-sm">
        <label class="ctrl-label">Date To</label>
        <input type="date" class="ctrl-date" id="dateTo">
      </div>
      <div class="ctrl-group grow">
        <label class="ctrl-label">Branch</label>
        <select class="ctrl-select" id="fBranch">
          <option value="">All Branches</option>
          <option>Main Office</option>
          <option>Branch — Cebu</option>
          <option>Branch — Davao</option>
          <option>Branch — Pampanga</option>
        </select>
      </div>
      <div class="ctrl-group grow">
        <label class="ctrl-label">Department</label>
        <select class="ctrl-select" id="fDept">
          <option value="">All Departments</option>
          <option>Operations</option>
          <option>Maintenance</option>
          <option>Finance</option>
          <option>Logistics</option>
          <option>IT</option>
          <option>Admin</option>
        </select>
      </div>
      <div class="ctrl-group grow">
        <label class="ctrl-label">Supplier</label>
        <select class="ctrl-select" id="fSupplier">
          <option value="">All Suppliers</option>
          <option>SafePro Industries Inc.</option>
          <option>ToolMaster Corporation</option>
          <option>ElecSupply Philippines</option>
          <option>BuildRight Materials Ltd.</option>
          <option>ChemDist Philippines</option>
          <option>PackPro Supply PH</option>
          <option>OfficeWorld Philippines</option>
          <option>HeavyLoad Equipment Corp.</option>
        </select>
      </div>
      <div class="ctrl-group fixed-md">
        <label class="ctrl-label">Status</label>
        <select class="ctrl-select" id="fStatus">
          <option value="">All Statuses</option>
          <option>Draft</option>
          <option>Pending</option>
          <option>Approved</option>
          <option>Rejected</option>
          <option>Completed</option>
          <option>Cancelled</option>
        </select>
      </div>
      <div class="ctrl-actions">
        <button class="btn btn-primary" onclick="generateReport()">
          <i class='bx bx-line-chart'></i> Generate
        </button>
      </div>
    </div>
  </div>

  <!-- STATS -->
  <div class="rpt-stats" id="rptStats" style="display:none"></div>

  <!-- RESULT CARD -->
  <div class="rpt-result" id="rptResult">
    <div class="rpt-result-hdr" id="rptResultHdr" style="display:none">
      <div>
        <div class="rpt-result-title" id="rptResultTitle"></div>
        <div class="rpt-result-meta"  id="rptResultMeta"></div>
      </div>
      <div class="rpt-export-btns">
        <button class="btn btn-pdf   btn-sm" id="exportPdfBtn"  onclick="exportPDF()"   disabled><i class='bx bxs-file-pdf'></i> PDF</button>
        <button class="btn btn-excel btn-sm" id="exportXlsxBtn" onclick="exportExcel()" disabled><i class='bx bxs-file'></i> Excel</button>
        <button class="btn btn-csv   btn-sm" id="exportCsvBtn"  onclick="exportCSV()"   disabled><i class='bx bx-spreadsheet'></i> CSV</button>
      </div>
    </div>
    <div class="rpt-loading" id="rptLoading">
      <div class="spinner"></div>
      <p style="font-size:14px;color:var(--text-2)">Generating report…</p>
    </div>
    <div id="rptTableWrap">
      <div class="rpt-empty">
        <i class='bx bx-bar-chart-alt-2'></i>
        <p>Select a report type and apply filters</p>
        <p class="sub">then click <strong>Generate</strong> to view results</p>
      </div>
    </div>
  </div>

</div>

<!-- SCHEDULE MODAL -->
<div class="modal-ov" id="schedModal">
  <div class="modal-box">
    <div class="modal-hd">
      <div>
        <div class="modal-title"><i class='bx bx-calendar-event' style="color:var(--primary);margin-right:6px;vertical-align:-2px"></i>Schedule Report Delivery</div>
        <div class="modal-sub">Auto-generate and deliver report to email on a recurring schedule</div>
      </div>
      <button class="modal-cl" onclick="closeScheduleModal()"><i class='bx bx-x'></i></button>
    </div>
    <div class="modal-bd">
      <div class="sched-info">
        <i class='bx bx-info-circle'></i>
        <span>Scheduled reports are automatically generated and sent to the specified email address. The report will use the current filter settings at the time of each run.</span>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Report Type <span>*</span></label>
          <select class="form-select" id="schedType">
            <option value="pr_summary">PR Summary</option>
            <option value="po_summary">PO Summary</option>
            <option value="supplier_spend">Supplier Spend</option>
            <option value="rfq_cycle">RFQ Cycle Time</option>
            <option value="contract_expiry">Contract Expiry</option>
            <option value="receiving">Receiving &amp; Inspection</option>
            <option value="supplier_perf">Supplier Performance</option>
            <option value="audit_trail">Audit Trail</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Frequency <span>*</span></label>
          <select class="form-select" id="schedFreq">
            <option value="weekly">Weekly</option>
            <option value="monthly" selected>Monthly</option>
          </select>
        </div>
      </div>
      <div class="form-row" id="schedDayRow">
        <div class="form-group">
          <label class="form-label">Day of Week / Month <span>*</span></label>
          <select class="form-select" id="schedDay">
            <option value="1">1st of month</option>
            <option value="7">7th of month</option>
            <option value="15">15th of month</option>
            <option value="last">Last day of month</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Export Format <span>*</span></label>
          <select class="form-select" id="schedFormat">
            <option value="pdf">PDF</option>
            <option value="excel">Excel (.xlsx)</option>
            <option value="csv">CSV</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Recipient Email(s) <span>*</span></label>
        <input type="text" class="form-input" id="schedEmail" placeholder="e.g. manager@company.com, admin@company.com">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Branch Filter</label>
          <select class="form-select" id="schedBranch">
            <option value="">All Branches</option>
            <option>Main Office</option>
            <option>Branch — Cebu</option>
            <option>Branch — Davao</option>
            <option>Branch — Pampanga</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Department Filter</label>
          <select class="form-select" id="schedDept">
            <option value="">All Departments</option>
            <option>Operations</option>
            <option>Maintenance</option>
            <option>Finance</option>
            <option>Logistics</option>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-ft">
      <button class="btn btn-ghost btn-sm" onclick="closeScheduleModal()">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="saveSchedule()"><i class='bx bx-check'></i> Save Schedule</button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>
/* ═══════════════ MOCK DATA ═══════════════ */
const BRANCHES    = ['Main Office','Branch — Cebu','Branch — Davao','Branch — Pampanga'];
const DEPARTMENTS = ['Operations','Maintenance','Finance','Logistics','IT','Admin'];
const SUPPLIERS   = [
  { id:'S001', name:'SafePro Industries Inc.',   cat:'PPE',       rating:4.7, leadDays:5  },
  { id:'S002', name:'ToolMaster Corporation',    cat:'Tools',     rating:4.2, leadDays:7  },
  { id:'S003', name:'ElecSupply Philippines',    cat:'Electrical',rating:4.5, leadDays:4  },
  { id:'S004', name:'BuildRight Materials Ltd.', cat:'Materials', rating:3.9, leadDays:10 },
  { id:'S005', name:'ChemDist Philippines',      cat:'Chemicals', rating:4.1, leadDays:8  },
  { id:'S006', name:'PackPro Supply PH',         cat:'Packaging', rating:4.8, leadDays:3  },
  { id:'S007', name:'OfficeWorld Philippines',   cat:'Office',    rating:4.3, leadDays:5  },
  { id:'S008', name:'HeavyLoad Equipment Corp.', cat:'Equipment', rating:4.0, leadDays:12 },
];
const PR_STATUSES = ['Draft','Pending Approval','Approved','Rejected','Cancelled'];
const PO_STATUSES = ['Draft','Sent','Confirmed','Partially Fulfilled','Fulfilled','Cancelled','Voided'];
const RFQ_STATUSES= ['Open','Closed','Cancelled'];
const CONTRACT_STATUSES = ['Active','Expiring Soon','Expired','Terminated','Under Review'];
const RECV_STATUSES=['Pending','Received','Partially Received','Rejected','Disputed','Completed'];
const ITEMS = ['Safety Gloves (L)','Drill Bits Set','PVC Pipe 2"','Cable Ties 300mm','Hard Hat Yellow','Wrench 14mm','Lubricant WD-40','Safety Vest XL','Wire 2.5mm²','Respirator Mask N95','Extension Cord 10m','Hammer 16oz','Duct Tape 2"','Allen Key Set','Sandpaper 80-grit'];

function rand(a,b){ return a+Math.floor(Math.random()*(b-a+1)); }
function randF(a,b){ return +(a+Math.random()*(b-a)).toFixed(2); }
function pick(a){ return a[Math.floor(Math.random()*a.length)]; }
function randDate(from,to){ return new Date(new Date(from).getTime()+Math.random()*(new Date(to).getTime()-new Date(from).getTime())); }
function supFilter(name){ return name ? SUPPLIERS.filter(s=>s.name===name) : SUPPLIERS; }

/* PR Summary */
function genPR(df,dt,branch,dept,sup,status){
  const rows=[];
  (sup?SUPPLIERS.filter(s=>s.name===sup):SUPPLIERS).forEach(s=>{
    for(let i=0;i<rand(3,6);i++){
      const st = status || pick(PR_STATUSES);
      rows.push({
        prNum:'PR-'+rand(2024001,2024999), date:randDate(df,dt),
        requestor:'User '+rand(1,20), dept: dept||pick(DEPARTMENTS),
        branch: branch||pick(BRANCHES), supplier:s,
        item:pick(ITEMS), qty:rand(5,200),
        estCost:rand(500,50000), status:st
      });
    }
  });
  return rows.sort((a,b)=>b.date-a.date);
}

/* PO Summary */
function genPO(df,dt,branch,dept,sup,status){
  const rows=[];
  (sup?SUPPLIERS.filter(s=>s.name===sup):SUPPLIERS).forEach(s=>{
    for(let i=0;i<rand(2,5);i++){
      const st = status || pick(PO_STATUSES);
      const qty=rand(10,200), unit=rand(50,5000);
      rows.push({
        poNum:'PO-'+rand(2024001,2024999), date:randDate(df,dt),
        branch:branch||pick(BRANCHES), dept:dept||pick(DEPARTMENTS),
        supplier:s, item:pick(ITEMS), qty, unitCost:unit,
        totalAmt:qty*unit, status:st, deliveryDays:rand(2,15)
      });
    }
  });
  return rows.sort((a,b)=>b.date-a.date);
}

/* Supplier Spend */
function genSpend(df,dt,branch,dept,sup,status){
  const rows=[];
  (sup?SUPPLIERS.filter(s=>s.name===sup):SUPPLIERS).forEach(s=>{
    const n=rand(2,5);
    for(let i=0;i<n;i++){
      const orders=rand(2,12), spend=rand(10000,300000);
      rows.push({
        supplier:s, branch:branch||pick(BRANCHES), dept:dept||pick(DEPARTMENTS),
        date:randDate(df,dt), orders, spend, avgOrderValue:Math.round(spend/orders)
      });
    }
  });
  return rows.sort((a,b)=>b.spend-a.spend);
}

/* RFQ Cycle Time */
function genRFQ(df,dt,branch,dept,sup,status){
  const rows=[];
  (sup?SUPPLIERS.filter(s=>s.name===sup):SUPPLIERS).forEach(s=>{
    for(let i=0;i<rand(2,5);i++){
      const issued=randDate(df,dt);
      const deadline=new Date(issued.getTime()+rand(3,14)*86400000);
      const closed=new Date(issued.getTime()+rand(4,18)*86400000);
      const cycleTime=Math.round((closed-issued)/86400000);
      const responses=rand(1,5);
      rows.push({
        rfqNum:'RFQ-'+rand(2024001,2024999),
        prRef:'PR-'+rand(2024001,2024999),
        issued, deadline, closed,
        supplier:s, branch:branch||pick(BRANCHES), dept:dept||pick(DEPARTMENTS),
        suppliersInvited:rand(2,6), responses, cycleTime,
        status:status||pick(RFQ_STATUSES), targetDays:rand(7,14)
      });
    }
  });
  return rows.sort((a,b)=>b.issued-a.issued);
}

/* Contract Expiry */
function genContracts(df,dt,branch,dept,sup,status){
  const rows=[];
  (sup?SUPPLIERS.filter(s=>s.name===sup):SUPPLIERS).forEach(s=>{
    for(let i=0;i<rand(1,3);i++){
      const start=randDate(df,dt);
      const end=new Date(start.getTime()+rand(180,730)*86400000);
      const today=new Date();
      const daysLeft=Math.round((end-today)/86400000);
      let st = status || (daysLeft<0?'Expired':daysLeft<30?'Expiring Soon':'Active');
      rows.push({
        contractNo:'CTR-'+rand(2024001,2024999),
        supplier:s, branch:branch||pick(BRANCHES), dept:dept||pick(DEPARTMENTS),
        startDate:start, endDate:end, value:rand(50000,2000000),
        daysLeft, status:st
      });
    }
  });
  return rows.sort((a,b)=>a.daysLeft-b.daysLeft);
}

/* Receiving & Inspection */
function genReceiving(df,dt,branch,dept,sup,status){
  const rows=[];
  (sup?SUPPLIERS.filter(s=>s.name===sup):SUPPLIERS).forEach(s=>{
    for(let i=0;i<rand(2,5);i++){
      const exp=rand(10,200), rec=rand(0,exp);
      rows.push({
        receiptNo:'REC-'+rand(2024001,2024999),
        poRef:'PO-'+rand(2024001,2024999),
        date:randDate(df,dt),
        supplier:s, branch:branch||pick(BRANCHES), dept:dept||pick(DEPARTMENTS),
        itemsExpected:exp, itemsReceived:rec,
        fulfillPct:exp>0?+((rec/exp)*100).toFixed(1):100,
        condition:pick(['Good','Minor Damage','Damaged','Mixed']),
        inspectedBy:'Inspector '+rand(1,8),
        status:status||pick(RECV_STATUSES)
      });
    }
  });
  return rows.sort((a,b)=>b.date-a.date);
}

/* Supplier Performance */
function genSupplierPerf(df,dt,branch,dept,sup,status){
  return (sup?SUPPLIERS.filter(s=>s.name===sup):SUPPLIERS).map(s=>{
    const total=rand(10,50), completed=rand(Math.floor(total*.6),total);
    const onTime=rand(Math.floor(completed*.6),completed), rejected=rand(0,Math.floor(total*.15));
    const spend=rand(80000,1000000);
    return {
      ...s, branch:branch||pick(BRANCHES),
      totalOrders:total, completed, onTime, rejected,
      totalSpend:spend, avgLead:randF(s.leadDays-1,s.leadDays+3),
      fillRate:+((completed/total)*100).toFixed(1),
      onTimePct:+((onTime/completed)*100).toFixed(1)
    };
  });
}

/* Audit Trail */
const AUDIT_ACTIONS=['Created PR','Approved PR','Rejected PR','Generated PO','Sent RFQ','Evaluated Quotation','Recorded Receipt','Flagged Issue','Terminated Contract','Overrode Inspection','Blacklisted Supplier','Updated PO','Cancelled PR'];
const MODULES=['Purchase Requests','Purchase Orders','RFQ','Quotation Evaluation','Receiving & Inspection','Contract Management','Supplier Management'];
function genAudit(df,dt,branch,dept,sup,status){
  const rows=[];
  for(let i=0;i<rand(20,40);i++){
    rows.push({
      logId:'LOG-'+rand(100000,999999),
      user:'User '+rand(1,30),
      role:pick(['Super Admin','Admin','Manager','Staff']),
      action:pick(AUDIT_ACTIONS),
      module:pick(MODULES),
      record:'REC-'+rand(1000,9999),
      branch:branch||pick(BRANCHES),
      dept:dept||pick(DEPARTMENTS),
      date:randDate(df,dt),
      ip:'192.168.'+rand(1,5)+'.'+rand(1,254)
    });
  }
  return rows.sort((a,b)=>b.date-a.date);
}

/* ═══════════════ STATE ═══════════════ */
let currentData=[], currentType='pr_summary', sortCol=null, sortDir='asc';

/* ═══════════════ INIT ═══════════════ */
(function(){
  const to=new Date(), from=new Date();
  from.setDate(from.getDate()-30);
  const fmt=d=>d.toISOString().split('T')[0];
  document.getElementById('dateTo').value  =fmt(to);
  document.getElementById('dateFrom').value=fmt(from);
})();

/* ═══════════════ TABS ═══════════════ */
function setTab(el, type){
  document.querySelectorAll('.rpt-tab').forEach(t=>t.classList.remove('active'));
  el.classList.add('active');
  currentType=type; sortCol=null; sortDir='asc';
  // sync schedule modal type
  document.getElementById('schedType').value=type;
  generateReport();
}

/* ═══════════════ GENERATE ═══════════════ */
function generateReport(){
  const df=document.getElementById('dateFrom').value;
  const dt=document.getElementById('dateTo').value;
  if(!df||!dt)              return toast('Please select a date range','warning');
  if(new Date(df)>new Date(dt)) return toast('Date From must be before Date To','warning');

  const branch  =document.getElementById('fBranch').value;
  const dept    =document.getElementById('fDept').value;
  const supplier=document.getElementById('fSupplier').value;
  const status  =document.getElementById('fStatus').value;

  sortCol=null; sortDir='asc';
  document.getElementById('rptLoading').style.display='block';
  document.getElementById('rptTableWrap').innerHTML='';
  document.getElementById('rptResultHdr').style.display='none';
  document.getElementById('rptStats').style.display='none';
  ['exportPdfBtn','exportXlsxBtn','exportCsvBtn'].forEach(id=>document.getElementById(id).disabled=true);

  setTimeout(()=>{
    document.getElementById('rptLoading').style.display='none';
    const handlers={
      pr_summary:   ()=>{ currentData=genPR(df,dt,branch,dept,supplier,status);       renderPR(currentData,df,dt); },
      po_summary:   ()=>{ currentData=genPO(df,dt,branch,dept,supplier,status);       renderPO(currentData,df,dt); },
      supplier_spend:()=>{ currentData=genSpend(df,dt,branch,dept,supplier,status);   renderSpend(currentData,df,dt); },
      rfq_cycle:    ()=>{ currentData=genRFQ(df,dt,branch,dept,supplier,status);      renderRFQ(currentData,df,dt); },
      contract_expiry:()=>{ currentData=genContracts(df,dt,branch,dept,supplier,status); renderContracts(currentData,df,dt); },
      receiving:    ()=>{ currentData=genReceiving(df,dt,branch,dept,supplier,status);renderReceiving(currentData,df,dt); },
      supplier_perf:()=>{ currentData=genSupplierPerf(df,dt,branch,dept,supplier,status); renderSupplierPerf(currentData,df,dt); },
      audit_trail:  ()=>{ currentData=genAudit(df,dt,branch,dept,supplier,status);    renderAudit(currentData,df,dt); },
    };
    handlers[currentType]?.();
    ['exportPdfBtn','exportXlsxBtn','exportCsvBtn'].forEach(id=>document.getElementById(id).disabled=false);
    toast('Report generated','success');
  },500);
}

/* ═══════════════ RENDERERS ═══════════════ */

/* PR SUMMARY */
function renderPR(data,df,dt){
  const total=data.length, approved=data.filter(r=>r.status==='Approved').length;
  const pending=data.filter(r=>r.status==='Pending Approval').length;
  const rejected=data.filter(r=>r.status==='Rejected').length;
  const totalCost=data.reduce((s,r)=>s+r.estCost,0);
  renderStats([
    {ic:'ic-b',icon:'bx-list-ul',          v:total,               l:'Total PRs'},
    {ic:'ic-g',icon:'bx-check-circle',     v:approved,            l:'Approved'},
    {ic:'ic-a',icon:'bx-time-five',        v:pending,             l:'Pending Approval'},
    {ic:'ic-r',icon:'bx-x-circle',         v:rejected,            l:'Rejected'},
    {ic:'ic-t',icon:'bx-money',            v:'₱'+fmtNum(totalCost),l:'Total Est. Cost'},
  ]);
  setHeader(`<i class='bx bx-list-ul' style="color:var(--primary)"></i> PR Summary`,
    `${total} requests · ${fmtD(df)} – ${fmtD(dt)}`);
  const cols=[
    {key:'prNum',label:'PR Number'},{key:'date',label:'Date Filed'},
    {key:'requestor',label:'Requestor'},{key:'dept',label:'Department'},
    {key:'branch',label:'Branch'},{key:'supplier',label:'Supplier'},
    {key:'item',label:'Item'},{key:'qty',label:'Qty'},
    {key:'estCost',label:'Est. Cost (₱)'},{key:'status',label:'Status'},
  ];
  const sorted=doSort(data,cols);
  const tbody=sorted.map((r,i)=>`<tr style="animation:rowIn .25s ${i*.02}s both">
    <td class="mono" style="color:var(--primary);font-weight:600">${esc(r.prNum)}</td>
    <td class="mono">${fmtD2(r.date)}</td>
    <td style="font-weight:500">${esc(r.requestor)}</td>
    <td><span class="cat-badge">${esc(r.dept)}</span></td>
    <td style="font-size:12px;color:var(--text-2)">${esc(r.branch)}</td>
    <td><div class="item-name">${esc(r.supplier.name)}</div></td>
    <td style="color:var(--text-1)">${esc(r.item)}</td>
    <td style="font-weight:600;text-align:center">${r.qty}</td>
    <td class="mono" style="font-weight:700">₱${fmtNum(r.estCost)}</td>
    <td><span class="status-chip ${sc(r.status)}">${esc(r.status)}</span></td>
  </tr>`).join('');
  const grandTotal=sorted.reduce((s,r)=>s+r.estCost,0);
  renderTable(cols,tbody,`<td colspan="8" style="text-align:right;color:var(--text-2)">GRAND TOTAL</td><td class="mono" style="font-weight:800;color:var(--primary)">₱${fmtNum(grandTotal)}</td><td></td>`);
}

/* PO SUMMARY */
function renderPO(data,df,dt){
  const total=data.length;
  const fulfilled=data.filter(r=>r.status==='Fulfilled').length;
  const pending=data.filter(r=>['Draft','Sent','Confirmed'].includes(r.status)).length;
  const totalAmt=data.reduce((s,r)=>s+r.totalAmt,0);
  renderStats([
    {ic:'ic-b',icon:'bx-purchase-tag-alt',v:total,               l:'Total POs'},
    {ic:'ic-g',icon:'bx-money',           v:'₱'+fmtNum(totalAmt),l:'Total Amount'},
    {ic:'ic-g',icon:'bx-check-circle',    v:fulfilled,           l:'Fulfilled'},
    {ic:'ic-a',icon:'bx-time-five',       v:pending,             l:'In Progress'},
    {ic:'ic-r',icon:'bx-x-circle',        v:data.filter(r=>r.status==='Cancelled'||r.status==='Voided').length, l:'Cancelled / Voided'},
  ]);
  setHeader(`<i class='bx bx-purchase-tag-alt' style="color:var(--blue)"></i> PO Summary`,
    `${total} orders · ${fmtD(df)} – ${fmtD(dt)} · Total: ₱${fmtNum(totalAmt)}`);
  const cols=[
    {key:'poNum',label:'PO Number'},{key:'date',label:'Date'},
    {key:'branch',label:'Branch'},{key:'dept',label:'Department'},
    {key:'supplier',label:'Supplier'},{key:'item',label:'Item'},
    {key:'qty',label:'Qty'},{key:'unitCost',label:'Unit Cost (₱)'},
    {key:'totalAmt',label:'Total (₱)'},{key:'status',label:'Status'},
    {key:'deliveryDays',label:'Lead Days'},
  ];
  const sorted=doSort(data,cols);
  const grand=sorted.reduce((s,r)=>s+r.totalAmt,0);
  const tbody=sorted.map((r,i)=>`<tr style="animation:rowIn .25s ${i*.02}s both">
    <td class="mono" style="color:var(--primary);font-weight:600">${esc(r.poNum)}</td>
    <td class="mono">${fmtD2(r.date)}</td>
    <td style="font-size:12px;color:var(--text-2)">${esc(r.branch)}</td>
    <td><span class="cat-badge">${esc(r.dept)}</span></td>
    <td><div class="item-name">${esc(r.supplier.name)}</div></td>
    <td style="color:var(--text-1)">${esc(r.item)}</td>
    <td style="font-weight:600;text-align:center">${r.qty}</td>
    <td class="mono">₱${fmtNum(r.unitCost)}</td>
    <td class="mono" style="font-weight:700">₱${fmtNum(r.totalAmt)}</td>
    <td><span class="status-chip ${sc(r.status)}">${esc(r.status)}</span></td>
    <td style="text-align:center;color:var(--text-2)">${r.deliveryDays}d</td>
  </tr>`).join('');
  renderTable(cols,tbody,`<td colspan="8" style="text-align:right;color:var(--text-2)">GRAND TOTAL</td><td class="mono" style="font-weight:800;color:var(--primary)">₱${fmtNum(grand)}</td><td colspan="2"></td>`);
}

/* SUPPLIER SPEND */
function renderSpend(data,df,dt){
  const totalSpend=data.reduce((s,r)=>s+r.spend,0);
  const totalOrders=data.reduce((s,r)=>s+r.orders,0);
  const top=Object.entries(data.reduce((m,r)=>{m[r.supplier.name]=(m[r.supplier.name]||0)+r.spend;return m;},{})).sort((a,b)=>b[1]-a[1])[0];
  renderStats([
    {ic:'ic-g',icon:'bx-money',       v:'₱'+fmtNum(totalSpend),                          l:'Total Spend'},
    {ic:'ic-b',icon:'bx-purchase-tag',v:totalOrders,                                       l:'Total Orders'},
    {ic:'ic-t',icon:'bx-buildings',   v:[...new Set(data.map(r=>r.supplier.name))].length, l:'Suppliers'},
    {ic:'ic-a',icon:'bx-trophy',      v:top?top[0]:'—',                                    l:'Top Spend Supplier'},
    {ic:'ic-p',icon:'bx-calculator',  v:'₱'+fmtNum(Math.round(totalSpend/(totalOrders||1))),l:'Avg Order Value'},
  ]);
  setHeader(`<i class='bx bx-money' style="color:var(--teal)"></i> Supplier Spend`,
    `${data.length} records · ${fmtD(df)} – ${fmtD(dt)} · Total: ₱${fmtNum(totalSpend)}`);
  const enriched=data.map(r=>({...r,share:+((r.spend/totalSpend)*100).toFixed(1)}));
  const cols=[
    {key:'supplier',label:'Supplier'},{key:'cat',label:'Category'},
    {key:'branch',label:'Branch'},{key:'dept',label:'Department'},
    {key:'orders',label:'# Orders'},{key:'spend',label:'Total Spend (₱)'},
    {key:'avgOrderValue',label:'Avg Order (₱)'},{key:'share',label:'Share of Spend'},
  ];
  const sorted=doSort(enriched,cols);
  const grand=sorted.reduce((s,r)=>s+r.spend,0);
  const tbody=sorted.map((r,i)=>{
    const clr=r.share>=20?'var(--blue)':r.share>=10?'var(--teal)':'#9CA3AF';
    return `<tr style="animation:rowIn .25s ${i*.02}s both">
      <td><div class="item-name">${esc(r.supplier.name)}</div><div class="item-sub">${esc(r.supplier.id)}</div></td>
      <td><span class="cat-badge"><span style="width:6px;height:6px;border-radius:50%;background:${catClr(r.supplier.cat)};flex-shrink:0"></span>${esc(r.supplier.cat)}</span></td>
      <td style="font-size:12px;color:var(--text-2)">${esc(r.branch)}</td>
      <td><span class="cat-badge">${esc(r.dept)}</span></td>
      <td style="text-align:center;font-weight:600">${r.orders}</td>
      <td class="mono" style="font-weight:700">₱${fmtNum(r.spend)}</td>
      <td class="mono" style="color:var(--text-2)">₱${fmtNum(r.avgOrderValue)}</td>
      <td><div style="display:flex;align-items:center;gap:8px">
        <div class="micro-bar" style="width:90px"><div class="micro-fill" style="width:${Math.min(r.share*3,100)}%;background:${clr}"></div></div>
        <span style="font-size:11px;font-weight:700;color:${clr}">${r.share}%</span>
      </div></td>
    </tr>`;
  }).join('');
  renderTable(cols,tbody,`<td colspan="5" style="text-align:right;color:var(--text-2)">GRAND TOTAL</td><td class="mono" style="font-weight:800;color:var(--primary)">₱${fmtNum(grand)}</td><td colspan="2"></td>`);
}

/* RFQ CYCLE TIME */
function renderRFQ(data,df,dt){
  const avgCycle=(data.reduce((s,r)=>s+r.cycleTime,0)/data.length).toFixed(1);
  const avgResp=(data.reduce((s,r)=>s+r.responses,0)/data.length).toFixed(1);
  const onTarget=data.filter(r=>r.cycleTime<=r.targetDays).length;
  renderStats([
    {ic:'ic-b',icon:'bx-time-five',       v:data.length,          l:'Total RFQs'},
    {ic:'ic-a',icon:'bx-stopwatch',       v:avgCycle+'d',          l:'Avg Cycle Time'},
    {ic:'ic-t',icon:'bx-chat',            v:avgResp,               l:'Avg Responses'},
    {ic:'ic-g',icon:'bx-check-circle',    v:onTarget,              l:'Completed On Target'},
    {ic:'ic-r',icon:'bx-trending-up',     v:data.filter(r=>r.cycleTime>r.targetDays).length, l:'Exceeded Target'},
  ]);
  setHeader(`<i class='bx bx-time-five' style="color:var(--amber)"></i> RFQ Cycle Time`,
    `${data.length} RFQs · ${fmtD(df)} – ${fmtD(dt)} · Avg cycle: ${avgCycle} days`);
  const cols=[
    {key:'rfqNum',label:'RFQ Number'},{key:'prRef',label:'PR Ref'},
    {key:'issued',label:'Date Issued'},{key:'deadline',label:'Deadline'},
    {key:'branch',label:'Branch'},{key:'dept',label:'Department'},
    {key:'supplier',label:'Supplier'},{key:'suppliersInvited',label:'Invited'},
    {key:'responses',label:'Responses'},{key:'cycleTime',label:'Cycle (days)'},
    {key:'targetDays',label:'Target (days)'},{key:'status',label:'Status'},
  ];
  const sorted=doSort(data,cols);
  const tbody=sorted.map((r,i)=>{
    const overTarget=r.cycleTime>r.targetDays;
    return `<tr style="animation:rowIn .25s ${i*.02}s both">
      <td class="mono" style="color:var(--primary);font-weight:600">${esc(r.rfqNum)}</td>
      <td class="mono" style="color:var(--blue)">${esc(r.prRef)}</td>
      <td class="mono">${fmtD2(r.issued)}</td>
      <td class="mono">${fmtD2(r.deadline)}</td>
      <td style="font-size:12px;color:var(--text-2)">${esc(r.branch)}</td>
      <td><span class="cat-badge">${esc(r.dept)}</span></td>
      <td style="font-weight:500">${esc(r.supplier.name)}</td>
      <td style="text-align:center;font-weight:600">${r.suppliersInvited}</td>
      <td style="text-align:center;font-weight:600;color:var(--teal)">${r.responses}</td>
      <td style="text-align:center;font-weight:800;color:${overTarget?'var(--red)':'var(--primary)'}">${r.cycleTime}d</td>
      <td style="text-align:center;color:var(--text-3)">${r.targetDays}d</td>
      <td><span class="status-chip ${r.status==='Closed'?'s-complete':r.status==='Open'?'s-pending':'s-draft'}">${esc(r.status)}</span></td>
    </tr>`;
  }).join('');
  renderTable(cols,tbody,`<td colspan="12" style="text-align:right;color:var(--text-2)">${sorted.length} RFQs · Avg cycle time: ${avgCycle} days</td>`);
}

/* CONTRACT EXPIRY */
function renderContracts(data,df,dt){
  const active=data.filter(r=>r.status==='Active').length;
  const expiring=data.filter(r=>r.status==='Expiring Soon').length;
  const expired=data.filter(r=>r.status==='Expired').length;
  const totalVal=data.reduce((s,r)=>s+r.value,0);
  renderStats([
    {ic:'ic-b',icon:'bx-file-blank',    v:data.length,           l:'Total Contracts'},
    {ic:'ic-g',icon:'bx-check-circle',  v:active,                l:'Active'},
    {ic:'ic-a',icon:'bx-error',         v:expiring,              l:'Expiring Soon (≤30d)'},
    {ic:'ic-r',icon:'bx-x-circle',      v:expired,               l:'Expired'},
    {ic:'ic-t',icon:'bx-money',         v:'₱'+fmtNum(totalVal),  l:'Total Contract Value'},
  ]);
  setHeader(`<i class='bx bx-file-blank' style="color:var(--purple)"></i> Contract Expiry`,
    `${data.length} contracts · ${fmtD(df)} – ${fmtD(dt)}`);
  const cols=[
    {key:'contractNo',label:'Contract No.'},{key:'supplier',label:'Supplier'},
    {key:'branch',label:'Branch'},{key:'dept',label:'Department'},
    {key:'startDate',label:'Start Date'},{key:'endDate',label:'End Date'},
    {key:'value',label:'Value (₱)'},{key:'daysLeft',label:'Days Left'},
    {key:'status',label:'Status'},
  ];
  const sorted=doSort(data,cols);
  const tbody=sorted.map((r,i)=>{
    const dClr=r.daysLeft<0?'var(--red)':r.daysLeft<30?'var(--amber)':'var(--primary)';
    return `<tr style="animation:rowIn .25s ${i*.02}s both">
      <td class="mono" style="color:var(--primary);font-weight:600">${esc(r.contractNo)}</td>
      <td><div class="item-name">${esc(r.supplier.name)}</div><div class="item-sub">${esc(r.supplier.id)}</div></td>
      <td style="font-size:12px;color:var(--text-2)">${esc(r.branch)}</td>
      <td><span class="cat-badge">${esc(r.dept)}</span></td>
      <td class="mono">${fmtD2(r.startDate)}</td>
      <td class="mono">${fmtD2(r.endDate)}</td>
      <td class="mono" style="font-weight:700">₱${fmtNum(r.value)}</td>
      <td style="font-weight:800;color:${dClr};text-align:center">${r.daysLeft<0?'Expired':r.daysLeft+'d'}</td>
      <td><span class="status-chip ${r.status==='Active'?'s-active':r.status==='Expiring Soon'?'s-expiring':'s-expired'}">${esc(r.status)}</span></td>
    </tr>`;
  }).join('');
  renderTable(cols,tbody,`<td colspan="6" style="text-align:right;color:var(--text-2)">TOTAL CONTRACT VALUE</td><td class="mono" style="font-weight:800;color:var(--primary)">₱${fmtNum(totalVal)}</td><td colspan="2"></td>`);
}

/* RECEIVING & INSPECTION */
function renderReceiving(data,df,dt){
  const totalReceipts=data.length;
  const completed=data.filter(r=>r.status==='Completed').length;
  const disputed=data.filter(r=>r.status==='Disputed').length;
  const avgFulfill=(data.reduce((s,r)=>s+r.fulfillPct,0)/data.length).toFixed(1);
  renderStats([
    {ic:'ic-b',icon:'bx-package',         v:totalReceipts,      l:'Total Receipts'},
    {ic:'ic-g',icon:'bx-check-circle',    v:completed,          l:'Completed'},
    {ic:'ic-r',icon:'bx-error-circle',    v:disputed,           l:'Disputed'},
    {ic:'ic-a',icon:'bx-time-five',       v:data.filter(r=>r.status==='Pending').length, l:'Pending'},
    {ic:'ic-t',icon:'bx-bar-chart-alt',   v:avgFulfill+'%',     l:'Avg Fulfillment Rate'},
  ]);
  setHeader(`<i class='bx bx-package' style="color:var(--teal)"></i> Receiving &amp; Inspection`,
    `${totalReceipts} receipts · ${fmtD(df)} – ${fmtD(dt)}`);
  const cols=[
    {key:'receiptNo',label:'Receipt No.'},{key:'poRef',label:'PO Ref'},
    {key:'date',label:'Date'},{key:'supplier',label:'Supplier'},
    {key:'branch',label:'Branch'},{key:'dept',label:'Department'},
    {key:'itemsExpected',label:'Expected'},{key:'itemsReceived',label:'Received'},
    {key:'fulfillPct',label:'Fulfill %'},{key:'condition',label:'Condition'},
    {key:'inspectedBy',label:'Inspector'},{key:'status',label:'Status'},
  ];
  const sorted=doSort(data,cols);
  const tbody=sorted.map((r,i)=>{
    const pClr=r.fulfillPct>=100?'var(--primary)':r.fulfillPct>=80?'var(--amber)':'var(--red)';
    const condCls=r.condition==='Good'?'s-approved':r.condition==='Damaged'?'s-rejected':'s-pending';
    return `<tr style="animation:rowIn .25s ${i*.02}s both">
      <td class="mono" style="color:var(--primary);font-weight:600">${esc(r.receiptNo)}</td>
      <td class="mono" style="color:var(--blue)">${esc(r.poRef)}</td>
      <td class="mono">${fmtD2(r.date)}</td>
      <td><div class="item-name">${esc(r.supplier.name)}</div></td>
      <td style="font-size:12px;color:var(--text-2)">${esc(r.branch)}</td>
      <td><span class="cat-badge">${esc(r.dept)}</span></td>
      <td style="text-align:center;font-weight:600">${r.itemsExpected}</td>
      <td style="text-align:center;font-weight:700;color:${pClr}">${r.itemsReceived}</td>
      <td>
        <div style="display:flex;align-items:center;gap:6px">
          <div class="micro-bar"><div class="micro-fill" style="width:${Math.min(r.fulfillPct,100)}%;background:${pClr}"></div></div>
          <span style="font-size:11px;font-weight:700;color:${pClr}">${r.fulfillPct}%</span>
        </div>
      </td>
      <td><span class="status-chip ${condCls}">${esc(r.condition)}</span></td>
      <td style="font-size:11px;color:var(--text-2)">${esc(r.inspectedBy)}</td>
      <td><span class="status-chip ${sc(r.status)}">${esc(r.status)}</span></td>
    </tr>`;
  }).join('');
  renderTable(cols,tbody,`<td colspan="12" style="text-align:right;color:var(--text-2)">${sorted.length} receipts · Avg fulfillment: ${avgFulfill}%</td>`);
}

/* SUPPLIER PERFORMANCE */
function renderSupplierPerf(data,df,dt){
  const avgFill=(data.reduce((s,r)=>s+r.fillRate,0)/data.length).toFixed(1);
  const avgOT  =(data.reduce((s,r)=>s+r.onTimePct,0)/data.length).toFixed(1);
  const totalSpend=data.reduce((s,r)=>s+r.totalSpend,0);
  const top=[...data].sort((a,b)=>b.onTimePct-a.onTimePct)[0];
  renderStats([
    {ic:'ic-b',icon:'bx-buildings',     v:data.length,              l:'Active Suppliers'},
    {ic:'ic-g',icon:'bx-money',         v:'₱'+fmtNum(totalSpend),   l:'Total Spend'},
    {ic:'ic-t',icon:'bx-bar-chart-alt', v:avgFill+'%',              l:'Avg Fill Rate'},
    {ic:'ic-g',icon:'bx-time-five',     v:avgOT+'%',                l:'Avg On-Time Delivery'},
    {ic:'ic-p',icon:'bx-trophy',        v:top?top.name:'—',          l:'Top Performer'},
  ]);
  setHeader(`<i class='bx bx-bar-chart-alt-2' style="color:var(--purple)"></i> Supplier Performance`,
    `${data.length} suppliers · ${fmtD(df)} – ${fmtD(dt)}`);
  const cols=[
    {key:'id',label:'ID'},{key:'name',label:'Supplier'},{key:'cat',label:'Category'},
    {key:'totalOrders',label:'Orders'},{key:'completed',label:'Completed'},
    {key:'rejected',label:'Rejected'},{key:'fillRate',label:'Fill Rate'},
    {key:'onTimePct',label:'On-Time %'},{key:'avgLead',label:'Avg Lead (d)'},
    {key:'totalSpend',label:'Total Spend (₱)'},{key:'rating',label:'Rating'},
  ];
  const sorted=doSort(data,cols);
  const tbody=sorted.map((r,i)=>{
    const fc=r.fillRate>=90?'#16A34A':r.fillRate>=70?'#F59E0B':'#DC2626';
    const oc=r.onTimePct>=90?'#16A34A':r.onTimePct>=70?'#F59E0B':'#DC2626';
    const stars='★'.repeat(Math.round(r.rating))+'☆'.repeat(5-Math.round(r.rating));
    return `<tr style="animation:rowIn .25s ${i*.02}s both">
      <td class="mono" style="color:var(--text-3)">${esc(r.id)}</td>
      <td><div class="item-name">${esc(r.name)}</div></td>
      <td><span class="cat-badge"><span style="width:6px;height:6px;border-radius:50%;background:${catClr(r.cat)};flex-shrink:0"></span>${esc(r.cat)}</span></td>
      <td style="text-align:center;font-weight:700">${r.totalOrders}</td>
      <td style="text-align:center;color:#16A34A;font-weight:600">${r.completed}</td>
      <td style="text-align:center;color:var(--red);font-weight:600">${r.rejected}</td>
      <td><div style="display:flex;align-items:center;gap:6px">
        <div class="micro-bar"><div class="micro-fill" style="width:${Math.min(r.fillRate,100)}%;background:${fc}"></div></div>
        <span style="font-size:11px;font-weight:700;color:${fc}">${r.fillRate}%</span>
      </div></td>
      <td><div style="display:flex;align-items:center;gap:6px">
        <div class="micro-bar"><div class="micro-fill" style="width:${Math.min(r.onTimePct,100)}%;background:${oc}"></div></div>
        <span style="font-size:11px;font-weight:700;color:${oc}">${r.onTimePct}%</span>
      </div></td>
      <td style="text-align:center;color:var(--text-2)">${r.avgLead}d</td>
      <td class="mono" style="font-weight:700">₱${fmtNum(r.totalSpend)}</td>
      <td style="color:#F59E0B;letter-spacing:1px" title="${r.rating}/5">${stars} <span style="font-size:11px;color:var(--text-2);letter-spacing:0">${r.rating}</span></td>
    </tr>`;
  }).join('');
  renderTable(cols,tbody,`<td colspan="11" style="text-align:right;color:var(--text-2)">${sorted.length} suppliers · Avg fill rate: ${avgFill}% · On-time: ${avgOT}%</td>`);
}

/* AUDIT TRAIL */
function renderAudit(data,df,dt){
  const roleMap={
    'Super Admin':'ic-r','Admin':'ic-a','Manager':'ic-b','Staff':'ic-g'
  };
  const byAction=data.reduce((m,r)=>{m[r.action]=(m[r.action]||0)+1;return m;},{});
  const topAction=Object.entries(byAction).sort((a,b)=>b[1]-a[1])[0];
  renderStats([
    {ic:'ic-b',icon:'bx-shield-quarter',v:data.length,              l:'Total Log Entries'},
    {ic:'ic-r',icon:'bx-user-circle',   v:data.filter(r=>r.role==='Super Admin').length, l:'Super Admin Actions'},
    {ic:'ic-a',icon:'bx-user',          v:data.filter(r=>r.role==='Admin').length,        l:'Admin Actions'},
    {ic:'ic-g',icon:'bx-group',         v:[...new Set(data.map(r=>r.user))].length,       l:'Unique Users'},
    {ic:'ic-t',icon:'bx-trending-up',   v:topAction?topAction[0]:'—',                     l:'Most Common Action'},
  ]);
  setHeader(`<i class='bx bx-shield-quarter' style="color:var(--red)"></i> Audit Trail`,
    `${data.length} entries · ${fmtD(df)} – ${fmtD(dt)}`);
  const cols=[
    {key:'logId',label:'Log ID'},{key:'date',label:'Date & Time'},
    {key:'user',label:'User'},{key:'role',label:'Role'},
    {key:'action',label:'Action'},{key:'module',label:'Module'},
    {key:'record',label:'Record'},{key:'branch',label:'Branch'},
    {key:'dept',label:'Department'},{key:'ip',label:'IP Address'},
  ];
  const sorted=doSort(data,cols);
  const roleChip={
    'Super Admin':'s-rejected','Admin':'s-expiring','Manager':'s-partial','Staff':'s-approved'
  };
  const tbody=sorted.map((r,i)=>`<tr style="animation:rowIn .25s ${i*.015}s both">
    <td class="mono" style="color:var(--text-3);font-size:11px">${esc(r.logId)}</td>
    <td class="mono" style="font-size:11px">${fmtD2(r.date)}</td>
    <td style="font-weight:600">${esc(r.user)}</td>
    <td><span class="status-chip ${roleChip[r.role]||'s-draft'}">${esc(r.role)}</span></td>
    <td style="font-weight:500;color:var(--text-1)">${esc(r.action)}</td>
    <td style="font-size:12px;color:var(--text-2)">${esc(r.module)}</td>
    <td class="mono" style="color:var(--primary)">${esc(r.record)}</td>
    <td style="font-size:12px;color:var(--text-2)">${esc(r.branch)}</td>
    <td><span class="cat-badge">${esc(r.dept)}</span></td>
    <td class="mono" style="font-size:11px;color:var(--text-3)">${esc(r.ip)}</td>
  </tr>`).join('');
  renderTable(cols,tbody,`<td colspan="10" style="text-align:right;color:var(--text-2)">${sorted.length} log entries · Read-only — cannot be edited or deleted</td>`);
}

/* ═══════════════ RENDER HELPERS ═══════════════ */
function renderStats(items){
  const wrap=document.getElementById('rptStats');
  wrap.style.display='grid';
  wrap.innerHTML=items.map(it=>`<div class="rpt-stat">
    <div class="rpt-stat-ic ${it.ic}"><i class='bx ${it.icon}'></i></div>
    <div><div class="rpt-stat-v">${it.v}</div><div class="rpt-stat-l">${it.l}</div></div>
  </div>`).join('');
}
function setHeader(title,meta){
  document.getElementById('rptResultTitle').innerHTML=title;
  document.getElementById('rptResultMeta').textContent=meta;
  document.getElementById('rptResultHdr').style.display='flex';
}
function renderTable(cols,tbody,footer){
  let html=`<div class="rpt-table-wrap"><table class="rpt-table" id="reportTable"><thead><tr>`;
  cols.forEach(c=>{
    const sorted=sortCol===c.key;
    const icon=sorted?(sortDir==='asc'?'bx-sort-up':'bx-sort-down'):'bx-sort';
    html+=`<th class="${sorted?'sorted':''}" onclick="onSort('${c.key}')">${c.label}<i class='bx ${icon} si'></i></th>`;
  });
  html+=`</tr></thead><tbody>${tbody}</tbody><tfoot><tr>${footer}</tr></tfoot></table></div>`;
  document.getElementById('rptTableWrap').innerHTML=html;
}

/* ═══════════════ SORT ═══════════════ */
function onSort(col){
  sortCol===col ? (sortDir=sortDir==='asc'?'desc':'asc') : (sortCol=col,sortDir='asc');
  generateReport();
}
const NUM_KEYS=['qty','estCost','totalAmt','unitCost','deliveryDays','spend','orders','avgOrderValue','share','totalOrders','completed','rejected','fillRate','onTimePct','avgLead','totalSpend','rating','itemsExpected','itemsReceived','fulfillPct','cycleTime','targetDays','suppliersInvited','responses','value','daysLeft'];
function doSort(data,cols){
  if(!sortCol) return data;
  return [...data].sort((a,b)=>{
    let va,vb;
    if(sortCol==='date'||sortCol==='issued'||sortCol==='startDate'||sortCol==='endDate'||sortCol==='deadline'||sortCol==='closed'){
      va=new Date(a[sortCol]||0); vb=new Date(b[sortCol]||0);
      return sortDir==='asc'?va-vb:vb-va;
    }
    if(sortCol==='supplier'){ va=a.supplier?.name||a.name||''; vb=b.supplier?.name||b.name||''; }
    else if(sortCol==='cat'){ va=a.supplier?.cat||a.cat||''; vb=b.supplier?.cat||b.cat||''; }
    else { va=a[sortCol]; vb=b[sortCol]; }
    if(NUM_KEYS.includes(sortCol)) return sortDir==='asc'?va-vb:vb-va;
    va=String(va||'').toLowerCase(); vb=String(vb||'').toLowerCase();
    return sortDir==='asc'?va.localeCompare(vb):vb.localeCompare(va);
  });
}

/* ═══════════════ EXPORT ═══════════════ */
function exportPDF(){
  const {jsPDF}=window.jspdf;
  const doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
  const title=document.getElementById('rptResultTitle').innerText.trim();
  const meta=document.getElementById('rptResultMeta').innerText.trim();
  doc.setFontSize(15); doc.setTextColor(46,125,50);
  doc.text('PSM — Procurement & Sourcing Management',14,14);
  doc.setFontSize(12); doc.setTextColor(30,30,30);
  doc.text(title,14,22);
  doc.setFontSize(9); doc.setTextColor(100,100,100);
  doc.text(meta,14,28);
  doc.text('Generated: '+new Date().toLocaleString(),14,33);
  doc.autoTable({html:'#reportTable',startY:38,styles:{fontSize:7.5},
    headStyles:{fillColor:[46,125,50],textColor:255,fontStyle:'bold'},
    alternateRowStyles:{fillColor:[244,246,245]},margin:{left:14,right:14}});
  doc.save(`psm_${currentType}_${today()}.pdf`);
  toast('PDF exported','success');
}
function exportExcel(){
  const table=document.getElementById('reportTable'); if(!table) return;
  const wb=XLSX.utils.book_new();
  const ws=XLSX.utils.table_to_sheet(table);
  XLSX.utils.book_append_sheet(wb,ws,'Report');
  XLSX.writeFile(wb,`psm_${currentType}_${today()}.xlsx`);
  toast('Excel exported','success');
}
function exportCSV(){
  const table=document.getElementById('reportTable'); if(!table) return;
  const wb=XLSX.utils.book_new();
  const ws=XLSX.utils.table_to_sheet(table);
  const csv=XLSX.utils.sheet_to_csv(ws);
  const a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
  a.download=`psm_${currentType}_${today()}.csv`; a.click();
  toast('CSV exported','success');
}

/* ═══════════════ SCHEDULE MODAL ═══════════════ */
function openScheduleModal(){
  document.getElementById('schedType').value=currentType;
  document.getElementById('schedModal').classList.add('show');
}
function closeScheduleModal(){ document.getElementById('schedModal').classList.remove('show'); }
document.getElementById('schedModal').addEventListener('click',function(e){ if(e.target===this) closeScheduleModal(); });

document.getElementById('schedFreq').addEventListener('change',function(){
  const sel=document.getElementById('schedDay');
  if(this.value==='weekly'){
    sel.innerHTML='<option value="mon">Monday</option><option value="tue">Tuesday</option><option value="wed">Wednesday</option><option value="thu">Thursday</option><option value="fri">Friday</option>';
  } else {
    sel.innerHTML='<option value="1">1st of month</option><option value="7">7th of month</option><option value="15">15th of month</option><option value="last">Last day of month</option>';
  }
});

function saveSchedule(){
  const type   =document.getElementById('schedType').value;
  const freq   =document.getElementById('schedFreq').value;
  const day    =document.getElementById('schedDay').value;
  const fmt    =document.getElementById('schedFormat').value;
  const email  =document.getElementById('schedEmail').value.trim();
  if(!email){ shakeEl('schedEmail'); return toast('Please enter at least one recipient email','warning'); }
  const typeLabel={'pr_summary':'PR Summary','po_summary':'PO Summary','supplier_spend':'Supplier Spend','rfq_cycle':'RFQ Cycle Time','contract_expiry':'Contract Expiry','receiving':'Receiving & Inspection','supplier_perf':'Supplier Performance','audit_trail':'Audit Trail'}[type]||type;
  const fmtLabel={'pdf':'PDF','excel':'Excel','csv':'CSV'}[fmt];
  const freqLabel=freq==='weekly'?`Weekly (${day})`:`Monthly (${day})`;
  closeScheduleModal();
  toast(`Schedule saved: ${typeLabel} · ${freqLabel} · ${fmtLabel} → ${email.split(',')[0].trim()}${email.includes(',')?' +more':''}`, 'teal');
}

/* ═══════════════ UTILS ═══════════════ */
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fmtD(d){ if(!d) return '—'; return new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}); }
function fmtD2(d){ return (d instanceof Date?d:new Date(d)).toLocaleDateString('en-PH',{month:'short',day:'2-digit',year:'numeric'}); }
function fmtNum(n){ return Number(n).toLocaleString('en-PH'); }
function today(){ return new Date().toISOString().split('T')[0]; }
function shakeEl(id){
  const el=document.getElementById(id);
  el.style.borderColor='#DC2626';
  el.style.animation='none'; el.offsetHeight;
  el.style.animation='shake .3s ease';
  setTimeout(()=>{el.style.borderColor='';el.style.animation='';},600);
}

const CAT_COLORS={PPE:'#2E7D32',Tools:'#0D9488',Materials:'#2563EB',Electrical:'#D97706',Chemicals:'#DC2626',Packaging:'#6B7280',Office:'#7C3AED',Equipment:'#92400E'};
function catClr(c){ return CAT_COLORS[c]||'#9CA3AF'; }

function sc(s){
  return {
    'Draft':'s-draft','Pending Approval':'s-pending','Pending':'s-pending',
    'Approved':'s-approved','Sent':'s-partial','Confirmed':'s-partial',
    'Partially Fulfilled':'s-partial','Fulfilled':'s-complete','Completed':'s-complete',
    'Rejected':'s-rejected','Cancelled':'s-draft','Voided':'s-draft',
    'Disputed':'s-disputed','Received':'s-approved','Partially Received':'s-partial',
    'Active':'s-active','Expiring Soon':'s-expiring','Expired':'s-expired',
  }[s]||'s-draft';
}

function toast(msg,type='success'){
  const icons={success:'bx-check-circle',warning:'bx-error',danger:'bx-error-circle',teal:'bx-calendar-check'};
  const el=document.createElement('div');
  el.className=`toast t-${type}`;
  el.innerHTML=`<i class='bx ${icons[type]||'bx-info-circle'}' style="font-size:17px;flex-shrink:0"></i>${esc(msg)}`;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(()=>{el.classList.add('t-out');setTimeout(()=>el.remove(),300);},3800);
}

/* AUTO-LOAD */
(function(){
  const _t=window.toast; window.toast=()=>{};
  generateReport();
  window.toast=_t;
})();
</script>
</main>
</body>
</html>