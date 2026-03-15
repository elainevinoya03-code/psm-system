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
<title>Inventory Reports — SWS</title>
<link rel="stylesheet" href="/Log1/css/base.css">
<link rel="stylesheet" href="/Log1/css/sidebar.css">
<link rel="stylesheet" href="/Log1/css/header.css">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#F3F6F2; --s:#FFFFFF; --t1:#1A2B1C; --t2:#5D7263; --t3:#9EB5A4;
  --bd:#E0EBE1; --bdm:#C6D9C8; --grn:#2E7D32; --gdk:#1B5E20; --gxl:#E8F5E9;
  --amb:#D97706; --ambx:#FEF3C7; --red:#DC2626; --redx:#FEE2E2;
  --blu:#2563EB; --blux:#EFF6FF; --pur:#7C3AED; --purx:#F5F3FF;
  --rad:14px; --tr:all .18s ease;
  --shsm:0 1px 4px rgba(46,125,50,.08); --shmd:0 4px 20px rgba(46,125,50,.11);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--t1);font-size:14px;-webkit-font-smoothing:antialiased;}
input,select,textarea,button{font-family:'Inter',sans-serif;}
.mono{font-family:'DM Mono',monospace;}
@keyframes UP{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@keyframes TIN{from{opacity:0;transform:translateX(24px)}to{opacity:1;transform:translateX(0)}}
@keyframes TOUT{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(24px)}}

.wrap{max-width:1440px;margin:0 auto;padding:0 0 4rem;}

/* PAGE HEADER */
.ph{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:24px;animation:UP .4s both;}
.ph-l .ey{font-size:11px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--grn);margin-bottom:5px;}
.ph-l h1{font-size:28px;font-weight:800;color:var(--t1);line-height:1.15;letter-spacing:-.3px;}
.ph-r{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;padding:9px 18px;border-radius:10px;border:none;cursor:pointer;transition:var(--tr);white-space:nowrap;}
.btn i{font-size:16px;}
.btn-primary{background:var(--grn);color:#fff;box-shadow:0 2px 10px rgba(46,125,50,.28);}
.btn-primary:hover{background:var(--gdk);transform:translateY(-1px);}
.btn-ghost{background:var(--s);color:var(--t2);border:1px solid var(--bdm);}
.btn-ghost:hover{background:var(--gxl);color:var(--grn);border-color:var(--grn);}
.btn-sm{font-size:12px;padding:6px 13px;}
.btn:disabled{opacity:.4;pointer-events:none;}

/* REPORT TYPE GRID */
.rt-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px;animation:UP .4s .05s both;}
.rt-card{background:var(--s);border:2px solid var(--bd);border-radius:var(--rad);padding:18px 20px;cursor:pointer;transition:var(--tr);display:flex;flex-direction:column;gap:10px;position:relative;overflow:hidden;}
.rt-card::after{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--grn);opacity:0;transition:var(--tr);}
.rt-card:hover{border-color:var(--bdm);box-shadow:var(--shmd);transform:translateY(-2px);}
.rt-card:hover::after{opacity:1;}
.rt-card.active{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.12);}
.rt-card.active::after{opacity:1;}
.rt-icon{width:38px;height:38px;border-radius:10px;display:grid;place-content:center;font-size:20px;flex-shrink:0;}
.rt-name{font-size:13px;font-weight:700;color:var(--t1);line-height:1.3;}
.rt-desc{font-size:11.5px;color:var(--t2);line-height:1.5;}

/* FILTER CARD */
.filter-card{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:16px 20px;margin-bottom:16px;animation:UP .4s .1s both;box-shadow:var(--shsm);}
.filter-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--t3);margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.filter-grid{display:grid;grid-template-columns:repeat(5,1fr) auto;gap:10px;align-items:end;}
.fg-item{display:flex;flex-direction:column;gap:5px;}
.fg-item label{font-size:11px;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.06em;}
.sel,.fi,.fdate{font-size:13px;padding:9px 11px;border:1px solid var(--bdm);border-radius:10px;background:var(--s);color:var(--t1);outline:none;transition:var(--tr);width:100%;}
.sel{padding-right:28px;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D7263' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center;cursor:pointer;}
.sel:focus,.fi:focus,.fdate:focus{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}
.date-range{display:flex;align-items:center;gap:6px;}
.date-range .fdate{flex:1;}
.date-range span{font-size:12px;color:var(--t3);flex-shrink:0;}

/* EXPORT BAR */
.export-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:12px 20px;margin-bottom:20px;animation:UP .4s .13s both;box-shadow:var(--shsm);}
.export-bar-l{display:flex;align-items:center;gap:8px;}
.export-bar-l span{font-size:13px;font-weight:600;color:var(--t2);}
.export-bar-r{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.exp-btn{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;padding:7px 14px;border-radius:8px;border:1.5px solid var(--bdm);background:var(--s);color:var(--t2);cursor:pointer;transition:var(--tr);}
.exp-btn:hover{border-color:var(--grn);color:var(--grn);background:var(--gxl);}
.exp-btn.pdf:hover{border-color:var(--red);color:var(--red);background:var(--redx);}
.exp-btn.xlsx:hover{border-color:#166534;color:#166534;background:#DCFCE7;}
.exp-btn.csv:hover{border-color:var(--blu);color:var(--blu);background:var(--blux);}
.divider-v{width:1px;height:20px;background:var(--bd);}

/* SUMMARY ROW */
.sum-row{display:grid;gap:12px;margin-bottom:20px;animation:UP .4s .08s both;}
.sum-row.cols-4{grid-template-columns:repeat(4,1fr);}
.sc{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:16px 18px;box-shadow:var(--shsm);display:flex;align-items:center;gap:12px;transition:var(--tr);}
.sc:hover{box-shadow:var(--shmd);transform:translateY(-2px);}
.sc-ico{width:40px;height:40px;border-radius:10px;display:grid;place-content:center;font-size:20px;flex-shrink:0;}
.sc-v{font-size:22px;font-weight:800;color:var(--t1);line-height:1;font-variant-numeric:tabular-nums;}
.sc-l{font-size:11.5px;color:var(--t2);margin-top:3px;}

/* TABLE CARD */
.tbl-card{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);box-shadow:var(--shmd);overflow:hidden;animation:UP .4s .15s both;}
.tbl-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--bd);background:var(--bg);flex-wrap:wrap;gap:10px;}
.tbl-header-l{display:flex;align-items:center;gap:10px;}
.tbl-htitle{font-size:14px;font-weight:700;color:var(--t1);}
.tbl-hsub{font-size:12px;color:var(--t3);}
.tbl-wrap{overflow-x:auto;}
.rep-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.rep-tbl thead th{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--t2);padding:9px 14px;text-align:left;background:var(--bg);border-bottom:1px solid var(--bd);white-space:nowrap;}
.rep-tbl tbody tr{border-bottom:1px solid var(--bd);transition:background .12s;}
.rep-tbl tbody tr:last-child{border-bottom:none;}
.rep-tbl tbody tr:hover{background:var(--gxl);}
.rep-tbl td{padding:10px 14px;vertical-align:middle;white-space:nowrap;color:var(--t1);font-size:13px;}

/* BADGES */
.badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap;}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0;}
.b-grn{background:var(--gxl);color:var(--grn);}
.b-red{background:var(--redx);color:var(--red);}
.b-amb{background:var(--ambx);color:var(--amb);}
.b-blu{background:var(--blux);color:var(--blu);}
.b-pur{background:var(--purx);color:var(--pur);}
.b-gray{background:#F1F5F9;color:#64748B;}

/* UTIL BAR */
.util-wrap{display:flex;align-items:center;gap:8px;}
.util-track{flex:1;height:5px;background:#e5e7eb;border-radius:3px;overflow:hidden;min-width:60px;}
.util-fill{height:100%;border-radius:3px;}
.util-pct{font-family:'DM Mono',monospace;font-size:11px;font-weight:700;min-width:32px;text-align:right;}

/* TREND */
.trend{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;}
.trend.up{color:#16A34A;}
.trend.down{color:var(--red);}

/* PAGINATION */
.pager{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:14px 20px;border-top:1px solid var(--bd);background:var(--bg);font-size:13px;color:var(--t2);}
.pg-btns{display:flex;gap:5px;}
.pgb{width:32px;height:32px;border-radius:8px;border:1px solid var(--bdm);background:var(--s);font-size:13px;cursor:pointer;display:grid;place-content:center;transition:var(--tr);color:var(--t1);}
.pgb:hover{background:var(--gxl);border-color:var(--grn);color:var(--grn);}
.pgb.active{background:var(--grn);border-color:var(--grn);color:#fff;}
.pgb:disabled{opacity:.4;pointer-events:none;}

/* EMPTY */
.empty{padding:72px 20px;text-align:center;color:var(--t3);}
.empty i{font-size:52px;display:block;margin-bottom:14px;color:#C8E6C9;}
.empty p{font-size:14px;font-weight:600;}
.empty small{font-size:12px;margin-top:4px;display:block;}

/* VIEW TABS */
.view-tabs{display:flex;background:var(--bg);border-bottom:1px solid var(--bd);padding:0 20px;}
.vtab{font-size:13px;font-weight:600;padding:12px 18px;color:var(--t2);cursor:pointer;border-bottom:2px solid transparent;transition:var(--tr);display:flex;align-items:center;gap:6px;}
.vtab i{font-size:15px;}
.vtab:hover{color:var(--t1);}
.vtab.active{color:var(--grn);border-bottom-color:var(--grn);}
.tab-panel{display:none;}
.tab-panel.on{display:block;}

/* SCHEDULED CARDS */
.sch-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.sch-card{background:var(--s);border:1px solid var(--bd);border-radius:var(--rad);padding:18px 20px;box-shadow:var(--shsm);transition:var(--tr);}
.sch-card:hover{box-shadow:var(--shmd);border-color:var(--bdm);}
.sch-header{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px;}
.sch-name{font-size:13px;font-weight:700;color:var(--t1);}
.sch-type{font-size:11.5px;color:var(--t2);margin-top:2px;}
.sch-toggle{position:relative;width:36px;height:20px;flex-shrink:0;}
.sch-toggle input{opacity:0;width:0;height:0;position:absolute;}
.sch-slider{position:absolute;inset:0;border-radius:20px;background:#E2E8F0;cursor:pointer;transition:.2s;}
.sch-slider::before{content:'';position:absolute;width:14px;height:14px;left:3px;top:3px;border-radius:50%;background:#fff;transition:.2s;box-shadow:0 1px 3px rgba(0,0,0,.2);}
.sch-toggle input:checked+.sch-slider{background:var(--grn);}
.sch-toggle input:checked+.sch-slider::before{transform:translateX(16px);}
.sch-meta{display:flex;flex-direction:column;gap:6px;}
.sch-row{display:flex;align-items:flex-start;justify-content:space-between;font-size:12px;}
.sch-row .lbl{color:var(--t3);font-weight:600;}
.sch-row .val{color:var(--t1);font-weight:600;text-align:right;}
.sch-actions{display:flex;gap:6px;margin-top:14px;padding-top:12px;border-top:1px solid var(--bd);}

/* MODAL */
#modalOverlay{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:9000;display:none;align-items:center;justify-content:center;backdrop-filter:blur(3px);}
#modalOverlay.on{display:flex;}
.modal{background:var(--s);border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.2);width:520px;max-width:calc(100vw - 32px);max-height:calc(100vh - 40px);overflow:hidden;display:flex;flex-direction:column;animation:UP .22s both;}
.modal-wide{width:800px;}
.modal-hd{padding:20px 24px 16px;border-bottom:1px solid var(--bd);display:flex;align-items:flex-start;justify-content:space-between;gap:12px;}
.modal-title{font-size:16px;font-weight:700;color:var(--t1);}
.modal-sub{font-size:12px;color:var(--t2);margin-top:3px;}
.modal-cl{width:28px;height:28px;border-radius:8px;border:1px solid var(--bdm);background:transparent;cursor:pointer;display:grid;place-content:center;font-size:16px;color:var(--t2);transition:var(--tr);flex-shrink:0;}
.modal-cl:hover{background:var(--redx);color:var(--red);border-color:#FECACA;}
.modal-bd{padding:20px 24px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:14px;}
.modal-ft{padding:16px 24px;border-top:1px solid var(--bd);display:flex;justify-content:flex-end;gap:10px;background:var(--bg);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-group.full{grid-column:1/-1;}
.form-group label{font-size:11.5px;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.06em;}
.form-group .fi,.form-group .sel,.form-group .fdate{width:100%;}
.check-group{display:flex;flex-direction:column;gap:8px;}
.chk-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--bd);border-radius:10px;cursor:pointer;transition:var(--tr);}
.chk-item:hover{border-color:var(--bdm);background:var(--gxl);}
.chk-item input[type=checkbox]{width:16px;height:16px;accent-color:var(--grn);cursor:pointer;flex-shrink:0;}
.chk-item .chk-lbl{font-size:13px;font-weight:600;color:var(--t1);flex:1;}
.chk-item .chk-sub{font-size:11.5px;color:var(--t2);}
.email-tags{display:flex;flex-wrap:wrap;gap:6px;padding:8px 10px;border:1px solid var(--bdm);border-radius:10px;min-height:42px;cursor:text;transition:var(--tr);background:var(--s);}
.email-tags:focus-within{border-color:var(--grn);box-shadow:0 0 0 3px rgba(46,125,50,.10);}
.etag{display:inline-flex;align-items:center;gap:5px;background:var(--gxl);border:1px solid rgba(46,125,50,.2);border-radius:6px;padding:3px 8px;font-size:12px;font-weight:600;color:var(--grn);}
.etag button{background:none;border:none;cursor:pointer;color:var(--grn);font-size:13px;line-height:1;padding:0;display:flex;}
.etag-input{border:none;outline:none;font-size:13px;color:var(--t1);flex:1;min-width:140px;background:transparent;}
.preview-notice{background:var(--gxl);border:1px solid rgba(46,125,50,.2);border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;font-size:13px;color:var(--grn);font-weight:600;}
.preview-notice i{font-size:18px;flex-shrink:0;}

/* TOAST */
#toastWrap{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none;}
.toast{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:12px;background:var(--t1);color:#fff;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);pointer-events:all;animation:TIN .3s both;min-width:260px;}
.toast i{font-size:18px;flex-shrink:0;}
.toast.success{background:#166534;}
.toast.warning{background:var(--amb);}
.toast.error{background:var(--red);}
.toast.out{animation:TOUT .3s forwards;}
</style>
</head>
<body>
<main class="main-content" id="mainContent">
<div class="wrap">

  <!-- PAGE HEADER -->
  <div class="ph">
    <div class="ph-l">
      <div class="ey">SWS · Smart Warehousing System</div>
      <h1>Inventory Reports</h1>
    </div>
    <div class="ph-r">
      <button class="btn btn-ghost" onclick="openScheduleManager()"><i class="bx bx-time-five"></i> Scheduled Reports</button>
      <button class="btn btn-primary" onclick="generateReport()"><i class="bx bx-play"></i> Generate Report</button>
    </div>
  </div>

  <!-- REPORT TYPE CARDS -->
  <div class="rt-grid" id="reportTypeGrid"></div>

  <!-- FILTER BAR -->
  <div class="filter-card">
    <div class="filter-title"><i class="bx bx-filter-alt" style="font-size:14px;color:var(--grn)"></i> Filters</div>
    <div class="filter-grid">
      <div class="fg-item">
        <label>Zone</label>
        <select class="sel" id="fZone">
          <option value="">All Zones</option>
          <option>Zone A — Raw Materials</option>
          <option>Zone B — Safety & PPE</option>
          <option>Zone C — Fuels & Lubricants</option>
          <option>Zone D — Office Supplies</option>
          <option>Zone E — Electrical & IT</option>
          <option>Zone F — Tools & Equipment</option>
        </select>
      </div>
      <div class="fg-item">
        <label>Category</label>
        <select class="sel" id="fCat">
          <option value="">All Categories</option>
          <option>Construction Materials</option>
          <option>Safety Equipment</option>
          <option>Lubricants & Fuels</option>
          <option>Office Supplies</option>
          <option>Electrical Components</option>
          <option>Tools & Hardware</option>
        </select>
      </div>
      <div class="fg-item">
        <label>Item</label>
        <select class="sel" id="fItem">
          <option value="">All Items</option>
          <option>ITM-0001 · Portland Cement</option>
          <option>ITM-0002 · Safety Helmet</option>
          <option>ITM-0003 · Engine Oil 10W-40</option>
          <option>ITM-0004 · Bond Paper A4</option>
          <option>ITM-0005 · CAT6 Cable</option>
          <option>ITM-0006 · Hard Hat ANSI E</option>
          <option>ITM-0007 · Steel Rebar 10mm</option>
          <option>ITM-0008 · Hydraulic Oil ISO 46</option>
        </select>
      </div>
      <div class="fg-item">
        <label>Status</label>
        <select class="sel" id="fStatus">
          <option value="">All Statuses</option>
          <option>In Stock</option>
          <option>Low Stock</option>
          <option>Out of Stock</option>
          <option>Overstocked</option>
        </select>
      </div>
      <div class="fg-item">
        <label>Date Range</label>
        <div class="date-range">
          <input type="date" class="fdate" id="fDateFrom">
          <span>—</span>
          <input type="date" class="fdate" id="fDateTo">
        </div>
      </div>
      <div style="display:flex;align-items:flex-end;gap:8px">
        <button class="btn btn-primary" onclick="generateReport()"><i class="bx bx-search"></i> Apply</button>
        <button class="btn btn-ghost" onclick="clearFilters()" title="Clear filters"><i class="bx bx-x"></i></button>
      </div>
    </div>
  </div>

  <!-- EXPORT BAR -->
  <div class="export-bar">
    <div class="export-bar-l">
      <i class="bx bx-spreadsheet" style="font-size:18px;color:var(--grn)"></i>
      <span id="exportLabel">Select a report type above to preview results</span>
    </div>
    <div class="export-bar-r">
      <button class="exp-btn pdf" onclick="doExport('PDF')"><i class="bx bxs-file-pdf"></i> PDF</button>
      <button class="exp-btn xlsx" onclick="doExport('Excel')"><i class="bx bxs-spreadsheet"></i> Excel</button>
      <button class="exp-btn csv" onclick="doExport('CSV')"><i class="bx bx-table"></i> CSV</button>
      <div class="divider-v"></div>
      <button class="btn btn-ghost btn-sm" onclick="openScheduleThis()"><i class="bx bx-calendar-plus"></i> Schedule</button>
      <button class="btn btn-ghost btn-sm" onclick="doPrint()"><i class="bx bx-printer"></i> Print</button>
    </div>
  </div>

  <!-- SUMMARY STATS -->
  <div class="sum-row cols-4" id="summaryRow"></div>

  <!-- REPORT TABLE CARD -->
  <div class="tbl-card">
    <div class="view-tabs" id="viewTabs" style="display:none">
      <div class="vtab active" onclick="switchView('table',this)"><i class="bx bx-table"></i> Table</div>
      <div class="vtab" onclick="switchView('chart',this)"><i class="bx bx-bar-chart-alt-2"></i> Chart</div>
    </div>
    <div class="tbl-header">
      <div class="tbl-header-l">
        <i id="repIconHd" class="bx bx-bar-chart-alt-2" style="font-size:20px;color:var(--grn)"></i>
        <div>
          <div class="tbl-htitle" id="repTitleHd">Inventory Reports</div>
          <div class="tbl-hsub" id="repSubHd">Select a report type and apply filters to generate</div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <span style="font-size:12px;color:var(--t3)" id="rowCount"></span>
        <button class="btn btn-ghost btn-sm" onclick="generateReport()"><i class="bx bx-refresh"></i> Refresh</button>
      </div>
    </div>
    <div class="tab-panel on" id="panelTable">
      <div class="tbl-wrap" id="tblWrap">
        <div class="empty">
          <i class="bx bx-bar-chart-alt-2"></i>
          <p>No report generated yet</p>
          <small>Select a report type above and click Generate Report</small>
        </div>
      </div>
      <div class="pager" id="pager" style="display:none">
        <span id="pagerInfo"></span>
        <div class="pg-btns" id="pagerBtns"></div>
      </div>
    </div>
    <div class="tab-panel" id="panelChart">
      <div style="padding:30px 24px" id="chartArea">
        <div class="empty"><i class="bx bx-bar-chart-alt-2"></i><p>Chart view</p><small>Generate a report first, then switch to Chart</small></div>
      </div>
    </div>
  </div>

</div>
</main>

<!-- SCHEDULE MODAL -->
<div id="modalOverlay" onclick="handleOverlayClick(event)">
  <div class="modal" id="schedModal">
    <div class="modal-hd">
      <div>
        <div class="modal-title" id="modalTitle">Schedule Report</div>
        <div class="modal-sub" id="modalSub">Configure automatic report generation and delivery</div>
      </div>
      <button class="modal-cl" onclick="closeModal()"><i class="bx bx-x"></i></button>
    </div>
    <div class="modal-bd" id="modalBody"></div>
    <div class="modal-ft" id="modalFoot"></div>
  </div>
</div>

<!-- SCHEDULE MANAGER MODAL -->
<div id="managerOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:9000;display:none;align-items:center;justify-content:center;backdrop-filter:blur(3px)" onclick="if(event.target===this)closeManager()">
  <div class="modal modal-wide">
    <div class="modal-hd">
      <div>
        <div class="modal-title">Scheduled Reports Manager</div>
        <div class="modal-sub">Manage automated report generation and email delivery</div>
      </div>
      <button class="modal-cl" onclick="closeManager()"><i class="bx bx-x"></i></button>
    </div>
    <div class="modal-bd" style="padding:20px 24px">
      <div class="sch-grid" id="schGrid"></div>
    </div>
    <div class="modal-ft">
      <button class="btn btn-ghost" onclick="closeManager()">Close</button>
      <button class="btn btn-primary" onclick="closeManager();openScheduleThis()"><i class="bx bx-plus"></i> New Schedule</button>
    </div>
  </div>
</div>

<div id="toastWrap"></div>

<script>
/* ── REPORT TYPES ──────────────────────────────────────── */
const REPORT_TYPES = [
  { id:'stock-level',   name:'Stock Level Report',          icon:'bx-layer',         color:'var(--grn)', bg:'var(--gxl)',  badge:'Core',        bc:'b-grn',
    desc:'Current quantities, min/max thresholds and reorder points across all zones.',
    cols:['Item Code','Item Name','Category','Zone','Bin','Current Stock','Min Stock','Max Stock','ROP','Status'],
    sum:[{i:'bx-layer',bg:'var(--gxl)',c:'var(--grn)',l:'Total SKUs',k:'a'},{i:'bx-check-circle',bg:'var(--gxl)',c:'var(--grn)',l:'In Stock',k:'b'},{i:'bx-error',bg:'var(--ambx)',c:'var(--amb)',l:'Low Stock',k:'c'},{i:'bx-x-circle',bg:'var(--redx)',c:'var(--red)',l:'Out of Stock',k:'d'}],
    sv:{a:'142',b:'89',c:'31',d:'22'} },
  { id:'stock-movement', name:'Stock Movement Report',      icon:'bx-transfer-alt',  color:'var(--blu)', bg:'var(--blux)', badge:'Transaction',  bc:'b-blu',
    desc:'All stock in/out transactions with reference documents within the date range.',
    cols:['Txn ID','Date & Time','Type','Item Code','Item Name','Qty','Reference Doc','Zone / Bin','Processed By','Status'],
    sum:[{i:'bx-log-in',bg:'var(--gxl)',c:'var(--grn)',l:'Total Stock In',k:'a'},{i:'bx-log-out',bg:'var(--blux)',c:'var(--blu)',l:'Total Stock Out',k:'b'},{i:'bx-time',bg:'var(--ambx)',c:'var(--amb)',l:'Pending',k:'c'},{i:'bx-error-circle',bg:'var(--redx)',c:'var(--red)',l:'Discrepancies',k:'d'}],
    sv:{a:'1,910 units',b:'631 units',c:'3',d:'2'} },
  { id:'low-stock',     name:'Low Stock & Reorder Report',  icon:'bx-error',         color:'var(--amb)', bg:'var(--ambx)', badge:'Alert',        bc:'b-amb',
    desc:'Items at or below reorder point requiring immediate procurement action.',
    cols:['Item Code','Item Name','Category','Zone','Current Stock','Min Stock','ROP','Shortage','Last Restocked','Action'],
    sum:[{i:'bx-error',bg:'var(--ambx)',c:'var(--amb)',l:'Needs Reorder',k:'a'},{i:'bx-x-circle',bg:'var(--redx)',c:'var(--red)',l:'Out of Stock',k:'b'},{i:'bx-trending-down',bg:'var(--redx)',c:'var(--red)',l:'Critical',k:'c'},{i:'bx-dollar',bg:'var(--purx)',c:'var(--pur)',l:'Est. Reorder Cost',k:'d'}],
    sv:{a:'31 items',b:'22 items',c:'8 items',d:'₱284,500'} },
  { id:'overstocked',   name:'Overstocked Items Report',    icon:'bx-archive',       color:'var(--pur)', bg:'var(--purx)', badge:'Excess',       bc:'b-pur',
    desc:'Items exceeding max levels with holding cost impact and suggested actions.',
    cols:['Item Code','Item Name','Category','Zone','Current Stock','Max Stock','Excess Qty','Holding Cost/mo','Last Movement','Recommendation'],
    sum:[{i:'bx-archive',bg:'var(--purx)',c:'var(--pur)',l:'Overstocked SKUs',k:'a'},{i:'bx-package',bg:'var(--purx)',c:'var(--pur)',l:'Total Excess Units',k:'b'},{i:'bx-dollar',bg:'var(--ambx)',c:'var(--amb)',l:'Holding Cost/mo',k:'c'},{i:'bx-store',bg:'var(--blux)',c:'var(--blu)',l:'Zones Affected',k:'d'}],
    sv:{a:'18 SKUs',b:'4,320 units',c:'₱42,800',d:'4 zones'} },
  { id:'valuation',     name:'Inventory Valuation Report',  icon:'bx-dollar-circle', color:'#059669',    bg:'#D1FAE5',     badge:'Financial',    bc:'b-grn',
    desc:'Financial value of all inventory using cost and market valuation methods.',
    cols:['Item Code','Item Name','Category','Zone','Qty on Hand','Unit Cost','Total Cost Value','Market Value','Variance','Last Updated'],
    sum:[{i:'bx-dollar-circle',bg:'#D1FAE5',c:'#059669',l:'Total Cost Value',k:'a'},{i:'bx-trending-up',bg:'#D1FAE5',c:'#059669',l:'Market Value',k:'b'},{i:'bx-transfer',bg:'var(--purx)',c:'var(--pur)',l:'Variance',k:'c'},{i:'bx-layer',bg:'var(--blux)',c:'var(--blu)',l:'Active SKUs',k:'d'}],
    sv:{a:'₱3,284,600',b:'₱3,512,880',c:'+₱228,280',d:'142'} },
  { id:'bin-util',      name:'Bin Utilization Report',      icon:'bx-grid-alt',      color:'#0891B2',    bg:'#E0F7FA',     badge:'Space',        bc:'b-blu',
    desc:'Space usage per bin and zone with occupancy rates and capacity insights.',
    cols:['Bin Code','Zone','Row','Level','Status','Assigned Items','Used Units','Max Capacity','Utilization %','Last Updated'],
    sum:[{i:'bx-grid-alt',bg:'#E0F7FA',c:'#0891B2',l:'Total Bins',k:'a'},{i:'bx-check-circle',bg:'var(--gxl)',c:'var(--grn)',l:'Occupied',k:'b'},{i:'bx-trending-up',bg:'var(--purx)',c:'var(--pur)',l:'Avg Utilization',k:'c'},{i:'bx-error',bg:'var(--ambx)',c:'var(--amb)',l:'Near Capacity',k:'d'}],
    sv:{a:'48 bins',b:'34',c:'67%',d:'9 bins'} },
  { id:'cycle-count',   name:'Cycle Count Report',          icon:'bx-clipboard',     color:'#7C3AED',    bg:'#F5F3FF',     badge:'Audit',        bc:'b-pur',
    desc:'Physical count reconciliation showing variances between system and actual counts.',
    cols:['Count ID','Count Date','Item Code','Item Name','Zone','Physical Count','System Count','Variance','Variance %','Status','Counted By'],
    sum:[{i:'bx-clipboard',bg:'#F5F3FF',c:'#7C3AED',l:'Total Counts',k:'a'},{i:'bx-check-double',bg:'var(--gxl)',c:'var(--grn)',l:'Matched',k:'b'},{i:'bx-error',bg:'var(--ambx)',c:'var(--amb)',l:'Discrepancies',k:'c'},{i:'bx-x-circle',bg:'var(--redx)',c:'var(--red)',l:'Pending Review',k:'d'}],
    sv:{a:'120',b:'98',c:'16',d:'6'} },
  { id:'audit-trail',   name:'Inventory Audit Trail',       icon:'bx-shield-quarter',color:'#B45309',    bg:'#FEF3C7',     badge:'Super Admin',  bc:'b-amb',
    desc:'Complete system-wide audit log of all inventory actions with user, IP, and timestamp.',
    cols:['Log ID','Timestamp','User','Role','Action','Module','Record Affected','IP Address','Branch','Details'],
    sum:[{i:'bx-shield-quarter',bg:'#FEF3C7',c:'#B45309',l:'Total Events',k:'a'},{i:'bx-user',bg:'var(--blux)',c:'var(--blu)',l:'Active Users',k:'b'},{i:'bx-error-circle',bg:'var(--redx)',c:'var(--red)',l:'Critical Actions',k:'c'},{i:'bx-time-five',bg:'var(--gxl)',c:'var(--grn)',l:'Today',k:'d'}],
    sv:{a:'2,847',b:'14',c:'23',d:'189'} },
];

/* ── SAMPLE DATA ──────────────────────────────────────── */
const ZONES=['Zone A','Zone B','Zone C','Zone D','Zone E','Zone F'];
const CATS=['Construction Materials','Safety Equipment','Lubricants & Fuels','Office Supplies','Electrical Components','Tools & Hardware'];
const ITEMS=[['ITM-0001','Portland Cement 40kg'],['ITM-0002','Safety Helmet Class B'],['ITM-0003','Engine Oil 10W-40'],['ITM-0004','Bond Paper A4 80gsm'],['ITM-0005','CAT6 Cable 305m'],['ITM-0006','Hard Hat ANSI E'],['ITM-0007','Steel Rebar 10mm'],['ITM-0008','Hydraulic Oil ISO 46'],['ITM-0009','Rubber Gloves Medium'],['ITM-0010','Safety Boots Size 42'],['ITM-0011','Fire Extinguisher 10lb'],['ITM-0012','Stapler Heavy Duty'],['ITM-0013','PVC Pipe 1 inch'],['ITM-0014','Circuit Breaker 20A'],['ITM-0015','Drill Bit Set 13pcs']];
const USERS=['R. Dela Cruz','A. Reyes','P. Bautista','M. Santos','J. Garcia','L. Torres','C. Villanueva'];
const rn=(a,b)=>Math.floor(Math.random()*(b-a+1))+a;
const pick=a=>a[rn(0,a.length-1)];
const fmt=n=>n.toLocaleString();
const dt=(d=0)=>new Date(Date.now()-d*864e5).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});

function genRows(type){
  return Array.from({length:45},(_,i)=>{
    const item=ITEMS[i%ITEMS.length], zone=ZONES[i%ZONES.length], cat=CATS[i%CATS.length];
    const cur=rn(10,500),mn=rn(20,80),mx=rn(200,600),rop=rn(30,100),uc=rn(50,2000);
    const stBadge=cur<=0?'<span class="badge b-red">Out of Stock</span>':cur<=mn?'<span class="badge b-amb">Low Stock</span>':cur>=mx?'<span class="badge b-pur">Overstocked</span>':'<span class="badge b-grn">In Stock</span>';
    switch(type){
      case 'stock-level':
        return [item[0],`<span style="font-weight:600">${item[1]}</span>`,cat,zone,`${zone.charAt(5)}01-R${rn(1,3)}-L${rn(1,3)}`,fmt(cur),fmt(mn),fmt(mx),fmt(rop),stBadge];
      case 'stock-movement':
        const isin=rn(0,1), qty=rn(10,200);
        return [`<span class="mono" style="color:var(--grn);font-size:12px">TXN-${isin?'SI':'SO'}-${String(i+1).padStart(4,'0')}</span>`,
          `${dt(rn(0,30))} ${rn(8,17)}:${String(rn(0,59)).padStart(2,'0')}`,
          isin?'<span class="badge b-grn">Stock In</span>':'<span class="badge b-blu">Stock Out</span>',
          item[0],item[1],
          isin?`<span style="color:var(--grn);font-weight:700">+${qty}</span>`:`<span style="color:var(--red);font-weight:700">−${qty}</span>`,
          `PO-2026-${String(rn(1,999)).padStart(4,'0')}`,`${zone} / R${rn(1,3)}-L${rn(1,2)}`,pick(USERS),
          '<span class="badge b-grn">Completed</span>'];
      case 'low-stock':
        const curL=rn(0,mn-1);
        return [item[0],item[1],cat,zone,fmt(curL),fmt(mn),fmt(rop),
          `<span style="color:var(--red);font-weight:700">−${fmt(mn-curL)}</span>`,dt(rn(7,60)),
          curL===0?'<span class="badge b-red">Urgent Order</span>':'<span class="badge b-amb">Reorder Now</span>'];
      case 'overstocked':
        const curO=mx+rn(50,300);
        return [item[0],item[1],cat,zone,fmt(curO),fmt(mx),
          `<span style="color:var(--pur);font-weight:700">+${fmt(curO-mx)}</span>`,
          `₱${fmt(rn(1000,15000))}`,dt(rn(30,90)),'<span class="badge b-blu">Redistribute</span>'];
      case 'valuation':
        const qty2=rn(50,500),cv=qty2*uc,mv=Math.round(cv*(0.88+Math.random()*0.28));
        return [item[0],item[1],cat,zone,fmt(qty2),`₱${fmt(uc)}`,`₱${fmt(cv)}`,`₱${fmt(mv)}`,
          mv>cv?`<span class="trend up"><i class="bx bx-up-arrow-alt"></i>+₱${fmt(mv-cv)}</span>`:`<span class="trend down"><i class="bx bx-down-arrow-alt"></i>−₱${fmt(cv-mv)}</span>`,
          dt(rn(0,14))];
      case 'bin-util':
        const used=rn(0,100),uc2=used>90?'var(--red)':used>70?'var(--amb)':'var(--grn)';
        return [`<span class="mono" style="font-weight:700;color:var(--grn)">${zone.charAt(5)}0${i%6+1}-R${rn(1,3)}-L${rn(1,3)}</span>`,zone,`R${rn(1,3)}`,`L${rn(1,2)}`,
          used===0?'<span class="badge b-gray">Available</span>':'<span class="badge b-grn">Occupied</span>',
          rn(1,4),fmt(used),'100',
          `<div class="util-wrap"><div class="util-track"><div class="util-fill" style="width:${used}%;background:${uc2}"></div></div><span class="util-pct" style="color:${uc2}">${used}%</span></div>`,
          dt(rn(0,7))];
      case 'cycle-count':
        const sys=rn(50,200),phys=sys+rn(-20,20),vr=phys-sys,vpct=Math.abs(Math.round(vr/sys*100));
        return [`<span class="mono" style="font-size:12px">CC-${String(i+1).padStart(3,'0')}</span>`,dt(rn(0,30)),item[0],item[1],zone,
          fmt(phys),fmt(sys),
          vr===0?'<span style="color:var(--grn);font-weight:700">0</span>':vr>0?`<span style="color:var(--blu);font-weight:700">+${vr}</span>`:`<span style="color:var(--red);font-weight:700">${vr}</span>`,
          `${vpct}%`,
          vr===0?'<span class="badge b-grn">Matched</span>':Math.abs(vr)>10?'<span class="badge b-red">Review</span>':'<span class="badge b-amb">Minor Variance</span>',
          pick(USERS)];
      case 'audit-trail':
        const acts=['Stock In Recorded','Stock Out Recorded','Item Deactivated','Bin Reassigned','Cycle Count Approved','Stock Adjusted','Item Transferred'];
        return [`<span class="mono" style="font-size:11.5px">LOG-${String(i+1).padStart(5,'0')}</span>`,
          `${dt(rn(0,30))} ${rn(8,22)}:${String(rn(0,59)).padStart(2,'0')}`,
          pick(USERS),'Super Admin',pick(acts),'SWS — Inventory',item[0],
          `192.168.${rn(1,10)}.${rn(1,254)}`,'Main Branch',
          `<button class="btn btn-ghost btn-sm" style="padding:4px 10px" onclick="showToast('Log details viewed','success')"><i class="bx bx-info-circle"></i> Details</button>`];
      default: return [];
    }
  });
}

/* ── STATE ────────────────────────────────────────────── */
let activeType='stock-level', allRows=[], page=1;
const PAGE=15;

/* ── RENDER TYPE CARDS ────────────────────────────────── */
function renderTypeCards(){
  document.getElementById('reportTypeGrid').innerHTML = REPORT_TYPES.map(rt=>`
    <div class="rt-card ${rt.id===activeType?'active':''}" onclick="selectType('${rt.id}')">
      <div style="display:flex;align-items:center;gap:10px">
        <div class="rt-icon" style="background:${rt.bg};color:${rt.color}"><i class="bx ${rt.icon}"></i></div>
        <span class="badge ${rt.bc}">${rt.badge}</span>
      </div>
      <div class="rt-name">${rt.name}</div>
      <div class="rt-desc">${rt.desc}</div>
    </div>`).join('');
}

function selectType(id){ activeType=id; renderTypeCards(); generateReport(); }

/* ── RENDER SUMMARY ───────────────────────────────────── */
function renderSummary(){
  const rt=REPORT_TYPES.find(r=>r.id===activeType);
  document.getElementById('summaryRow').innerHTML = rt.sum.map(s=>`
    <div class="sc">
      <div class="sc-ico" style="background:${s.bg};color:${s.c}"><i class="bx ${s.i}"></i></div>
      <div><div class="sc-v">${rt.sv[s.k]}</div><div class="sc-l">${s.l}</div></div>
    </div>`).join('');
}

/* ── GENERATE REPORT ──────────────────────────────────── */
function generateReport(){
  const rt=REPORT_TYPES.find(r=>r.id===activeType);
  document.getElementById('repIconHd').className=`bx ${rt.icon}`;
  document.getElementById('repIconHd').style.color=rt.color;
  document.getElementById('repTitleHd').textContent=rt.name;
  document.getElementById('repSubHd').textContent=`Generated ${new Date().toLocaleString('en-US',{dateStyle:'medium',timeStyle:'short'})}`;
  document.getElementById('exportLabel').textContent=`${rt.name} — ${allRows.length||45} records`;
  document.getElementById('viewTabs').style.display='flex';
  renderSummary();
  allRows=genRows(activeType);
  page=1;
  renderTable();
  // reset to table tab
  document.querySelectorAll('.vtab').forEach((t,i)=>t.classList.toggle('active',i===0));
  document.getElementById('panelTable').classList.add('on');
  document.getElementById('panelChart').classList.remove('on');
}

/* ── RENDER TABLE ─────────────────────────────────────── */
function renderTable(){
  const rt=REPORT_TYPES.find(r=>r.id===activeType);
  const total=allRows.length, pages=Math.max(1,Math.ceil(total/PAGE));
  if(page>pages) page=pages;
  const slice=allRows.slice((page-1)*PAGE,page*PAGE);
  document.getElementById('tblWrap').innerHTML=`<table class="rep-tbl"><thead><tr>${rt.cols.map(c=>`<th>${c}</th>`).join('')}</tr></thead><tbody>${slice.map(row=>`<tr>${row.map(cell=>`<td>${cell}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
  const s=(page-1)*PAGE+1,e=Math.min(page*PAGE,total);
  document.getElementById('rowCount').textContent=`${total} records`;
  document.getElementById('pagerInfo').textContent=`Showing ${s}–${e} of ${total} records`;
  document.getElementById('pager').style.display='flex';
  let btns='';
  for(let i=1;i<=pages;i++){
    if(i===1||i===pages||(i>=page-2&&i<=page+2)) btns+=`<button class="pgb ${i===page?'active':''}" onclick="goPage(${i})">${i}</button>`;
    else if(i===page-3||i===page+3) btns+=`<button class="pgb" disabled>…</button>`;
  }
  document.getElementById('pagerBtns').innerHTML=`
    <button class="pgb" onclick="goPage(${page-1})" ${page<=1?'disabled':''}><i class="bx bx-chevron-left"></i></button>
    ${btns}
    <button class="pgb" onclick="goPage(${page+1})" ${page>=pages?'disabled':''}><i class="bx bx-chevron-right"></i></button>`;
}

function goPage(p){ page=p; renderTable(); }
function clearFilters(){ ['fZone','fCat','fItem','fStatus'].forEach(id=>document.getElementById(id).value=''); document.getElementById('fDateFrom').value=''; document.getElementById('fDateTo').value=''; showToast('Filters cleared','success'); }

/* ── VIEW TABS ────────────────────────────────────────── */
function switchView(view,el){
  document.querySelectorAll('.vtab').forEach(t=>t.classList.remove('active')); el.classList.add('active');
  document.getElementById('panelTable').classList.toggle('on',view==='table');
  document.getElementById('panelChart').classList.toggle('on',view==='chart');
  if(view==='chart') renderChart();
}

function renderChart(){
  const rt=REPORT_TYPES.find(r=>r.id===activeType);
  const items=ITEMS.slice(0,10).map(i=>i[1].split(' ').slice(0,2).join(' '));
  const vals=items.map(()=>rn(30,500)), max=Math.max(...vals);
  document.getElementById('chartArea').innerHTML=`
    <div style="padding:4px 0 10px">
      <div style="font-size:13px;font-weight:700;color:var(--t2);margin-bottom:20px;display:flex;align-items:center;gap:8px"><i class="bx bx-bar-chart-alt-2" style="color:${rt.color}"></i>${rt.name} — Visual Overview</div>
      <div style="display:flex;flex-direction:column;gap:12px">
        ${items.map((item,i)=>{
          const pct=Math.round(vals[i]/max*100);
          const clr=pct>80?'var(--red)':pct>60?'var(--amb)':rt.color;
          return `<div style="display:flex;align-items:center;gap:12px">
            <div style="font-size:12px;font-weight:600;color:var(--t2);width:170px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${item}</div>
            <div style="flex:1;height:30px;background:#E5E7EB;border-radius:6px;overflow:hidden">
              <div style="height:100%;width:0;background:${clr};border-radius:6px;display:flex;align-items:center;padding-left:10px;transition:width .7s cubic-bezier(.4,0,.2,1)" data-w="${pct}">
                ${pct>18?`<span style="font-size:11px;font-weight:700;color:#fff">${vals[i].toLocaleString()}</span>`:''}
              </div>
            </div>
            ${pct<=18?`<span style="font-size:11px;font-weight:700;color:var(--t2);min-width:30px">${vals[i].toLocaleString()}</span>`:''}
          </div>`;
        }).join('')}
      </div>
    </div>`;
  setTimeout(()=>document.querySelectorAll('[data-w]').forEach(b=>b.style.width=b.dataset.w+'%'),50);
}

/* ── EXPORT / PRINT ───────────────────────────────────── */
function doExport(f){ const rt=REPORT_TYPES.find(r=>r.id===activeType); if(!allRows.length){showToast('Generate a report first','warning');return;} showToast(`Exporting ${rt.name} as ${f}…`,'success'); setTimeout(()=>showToast(`${f} file downloaded successfully`,'success'),1200); }
function doPrint(){ if(!allRows.length){showToast('Generate a report first','warning');return;} showToast('Preparing print view…','success'); }

/* ── SCHEDULED DATA ───────────────────────────────────── */
const SCHEDULED=[
  {id:1,name:'Stock Level Report',freq:'Weekly',day:'Monday',time:'08:00 AM',fmt:'PDF',emails:['ops@company.com'],active:true},
  {id:2,name:'Low Stock & Reorder Report',freq:'Daily',day:'Every Day',time:'07:00 AM',fmt:'Excel',emails:['procurement@company.com','manager@company.com'],active:true},
  {id:3,name:'Inventory Valuation Report',freq:'Monthly',day:'1st of month',time:'09:00 AM',fmt:'PDF',emails:['finance@company.com'],active:false},
];

/* ── SCHEDULE MODAL ───────────────────────────────────── */
function openScheduleThis(){
  const rt=REPORT_TYPES.find(r=>r.id===activeType);
  document.getElementById('modalTitle').textContent='Schedule Report';
  document.getElementById('modalSub').textContent=rt?`Auto-delivery for: ${rt.name}`:'Configure automatic report generation and delivery';
  document.getElementById('modalBody').innerHTML=`
    <div class="preview-notice"><i class="bx bx-info-circle"></i> Reports will be auto-generated and emailed to recipients at the configured time.</div>
    <div class="form-row">
      <div class="form-group full"><label>Report Type</label>
        <select class="sel" id="sf-type">${REPORT_TYPES.map(r=>`<option value="${r.id}"${rt&&r.id===rt.id?' selected':''}>${r.name}</option>`).join('')}</select>
      </div>
      <div class="form-group"><label>Frequency</label>
        <select class="sel" id="sf-freq" onchange="updateFreqDay()"><option>Daily</option><option selected>Weekly</option><option>Monthly</option></select>
      </div>
      <div class="form-group"><label id="sf-day-lbl">Day of Week</label>
        <select class="sel" id="sf-day"><option>Monday</option><option>Tuesday</option><option>Wednesday</option><option>Thursday</option><option>Friday</option><option>Saturday</option><option>Sunday</option></select>
      </div>
      <div class="form-group"><label>Time</label><input type="time" class="fi" id="sf-time" value="08:00"></div>
      <div class="form-group"><label>Format</label><select class="sel" id="sf-fmt"><option>PDF</option><option>Excel</option><option>CSV</option></select></div>
    </div>
    <div class="form-group"><label>Email Recipients <span style="color:var(--t3);font-weight:400;text-transform:none">(press Enter to add)</span></label>
      <div class="email-tags" id="emailTagsWrap" onclick="document.getElementById('emailInput').focus()">
        <span class="etag">ops@company.com<button type="button" onclick="this.parentElement.remove()"><i class="bx bx-x"></i></button></span>
        <input class="etag-input" id="emailInput" type="email" placeholder="Add email address…" onkeydown="handleEmailKey(event)">
      </div>
    </div>
    <div class="form-group"><label>Include Filters</label>
      <div class="check-group">
        <div class="chk-item"><input type="checkbox" checked><span class="chk-lbl">Apply current zone and category filters</span><span class="chk-sub">Zone: All · Category: All</span></div>
        <div class="chk-item"><input type="checkbox" checked><span class="chk-lbl">Apply relative date range</span><span class="chk-sub">e.g. last 7 days for weekly reports</span></div>
      </div>
    </div>`;
  document.getElementById('modalFoot').innerHTML=`<button class="btn btn-ghost" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="saveSchedule()"><i class="bx bx-check"></i> Save Schedule</button>`;
  document.getElementById('modalOverlay').classList.add('on');
}

function updateFreqDay(){
  const freq=document.getElementById('sf-freq').value, lbl=document.getElementById('sf-day-lbl'), sel=document.getElementById('sf-day');
  if(freq==='Daily'){lbl.textContent='Every Day';sel.innerHTML='<option>Every Day</option>';sel.disabled=true;}
  else if(freq==='Weekly'){lbl.textContent='Day of Week';sel.innerHTML='<option>Monday</option><option>Tuesday</option><option>Wednesday</option><option>Thursday</option><option>Friday</option><option>Saturday</option><option>Sunday</option>';sel.disabled=false;}
  else{lbl.textContent='Day of Month';sel.innerHTML=Array.from({length:28},(_,i)=>`<option>${i+1}${[,'st','nd','rd'][i+1]||'th'} of month</option>`).join('');sel.disabled=false;}
}

function handleEmailKey(e){
  if(e.key==='Enter'||e.key===','){
    e.preventDefault(); const v=e.target.value.trim().replace(',','');
    if(v&&v.includes('@')){
      const t=document.createElement('span'); t.className='etag';
      t.innerHTML=`${v}<button type="button" onclick="this.parentElement.remove()"><i class="bx bx-x"></i></button>`;
      document.getElementById('emailTagsWrap').insertBefore(t,e.target); e.target.value='';
    }
  }
}

function saveSchedule(){ closeModal(); showToast('Schedule saved — report will auto-generate','success'); }

/* ── SCHEDULE MANAGER ─────────────────────────────────── */
function openScheduleManager(){
  document.getElementById('schGrid').innerHTML=SCHEDULED.map(s=>`
    <div class="sch-card">
      <div class="sch-header">
        <div><div class="sch-name">${s.name}</div><div class="sch-type">${s.freq} · ${s.fmt}</div></div>
        <label class="sch-toggle"><input type="checkbox" ${s.active?'checked':''} onchange="showToast('Schedule '+(this.checked?'enabled':'disabled'),'success')"><span class="sch-slider"></span></label>
      </div>
      <div class="sch-meta">
        <div class="sch-row"><span class="lbl">Frequency</span><span class="val">${s.freq}</span></div>
        <div class="sch-row"><span class="lbl">Day / Time</span><span class="val">${s.day} at ${s.time}</span></div>
        <div class="sch-row"><span class="lbl">Format</span><span class="val">${s.fmt}</span></div>
        <div class="sch-row"><span class="lbl">Recipients</span><span class="val" style="font-size:11.5px">${s.emails.map(e=>`<div>${e}</div>`).join('')}</span></div>
      </div>
      <div class="sch-actions">
        <button class="btn btn-ghost btn-sm" style="flex:1" onclick="closeManager();openScheduleThis()"><i class="bx bx-edit"></i> Edit</button>
        <button class="btn btn-ghost btn-sm" onclick="closeManager();showToast('Report queued…','success');setTimeout(()=>showToast('Report generated and emailed','success'),1500)"><i class="bx bx-play"></i> Run Now</button>
        <button class="btn btn-ghost btn-sm" style="border-color:#FECACA;color:var(--red)" onmouseover="this.style.background='#FEE2E2'" onmouseout="this.style.background=''" onclick="showToast('Schedule deleted','warning')"><i class="bx bx-trash"></i></button>
      </div>
    </div>`).join('');
  document.getElementById('managerOverlay').style.display='flex';
}
function closeManager(){ document.getElementById('managerOverlay').style.display='none'; }

/* ── MODAL / TOAST ────────────────────────────────────── */
function closeModal(){ document.getElementById('modalOverlay').classList.remove('on'); }
function handleOverlayClick(e){ if(e.target===document.getElementById('modalOverlay')) closeModal(); }
function showToast(msg,type='success'){
  const icons={success:'bx-check-circle',warning:'bx-error',error:'bx-x-circle'};
  const t=document.createElement('div'); t.className=`toast ${type}`;
  t.innerHTML=`<i class="bx ${icons[type]}"></i>${msg}`;
  document.getElementById('toastWrap').appendChild(t);
  setTimeout(()=>{t.classList.add('out');setTimeout(()=>t.remove(),300);},3000);
}

/* ── INIT ─────────────────────────────────────────────── */
renderTypeCards();
generateReport();
</script>
</body>
</html>