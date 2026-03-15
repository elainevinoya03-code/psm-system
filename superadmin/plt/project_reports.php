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
<title>Project Reports — PLT</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/Log1/css/base.css">
<link rel="stylesheet" href="/Log1/css/sidebar.css">
<link rel="stylesheet" href="/Log1/css/header.css">
<style>
/* ── CSS VARIABLES ─────────────────────────────────────── */
:root {
  --primary-color:#2E7D32; --primary-dark:#1B5E20;
  --text-primary:#1A2E1C; --text-secondary:#5D6F62;
  --bg-color:#F4F8F4; --hover-bg-light:#EEF5EE;
  --transition:all .18s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Inter',sans-serif; background:var(--bg-color); color:var(--text-primary); min-height:100vh; }

/* ── TOKENS ─────────────────────────────────────────────── */
.page-wrap,#schedModal,#exportModal,#previewModal,.rp-toasts {
  --s:#fff; --bd:rgba(46,125,50,.13); --bdm:rgba(46,125,50,.26);
  --t1:var(--text-primary); --t2:var(--text-secondary); --t3:#9EB0A2;
  --hbg:var(--hover-bg-light); --bg:var(--bg-color);
  --grn:var(--primary-color); --gdk:var(--primary-dark);
  --red:#DC2626; --amb:#D97706; --blu:#2563EB; --tel:#0D9488;
  --shmd:0 4px 20px rgba(46,125,50,.12); --shlg:0 24px 60px rgba(0,0,0,.22);
  --rad:12px; --tr:var(--transition);
}

/* ── PAGE ─────────────────────────────────────────────── */
.page-wrap { max-width:100%; margin:0; padding:12px 0 4rem; }
.rp-ph { display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:14px; animation:UP .4s both; }
.rp-ph .ey { font-size:11px; font-weight:600; letter-spacing:.14em; text-transform:uppercase; color:var(--grn); margin-bottom:4px; }
.rp-ph h1  { font-size:26px; font-weight:800; color:var(--t1); line-height:1.15; }
.rp-ph-r   { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

/* ── BUTTONS ─────────────────────────────────────────────── */
.btn { display:inline-flex; align-items:center; gap:7px; font-family:'Inter',sans-serif; font-size:13px; font-weight:600; padding:9px 18px; border-radius:10px; border:none; cursor:pointer; transition:var(--tr); white-space:nowrap; }
.btn-primary { background:var(--grn); color:#fff; box-shadow:0 2px 8px rgba(46,125,50,.32); }
.btn-primary:hover { background:var(--gdk); transform:translateY(-1px); }
.btn-ghost { background:var(--s); color:var(--t2); border:1px solid var(--bdm); }
.btn-ghost:hover { background:var(--hbg); color:var(--t1); }
.btn-amber { background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; }
.btn-amber:hover { background:#FDE68A; }
.btn-blue  { background:#EFF6FF; color:var(--blu); border:1px solid #BFDBFE; }
.btn-blue:hover { background:#DBEAFE; }
.btn-teal  { background:#CCFBF1; color:#0F766E; border:1px solid #99F6E4; }
.btn-teal:hover { background:#99F6E4; }
.btn-sm { font-size:12px; padding:6px 13px; }
.btn-xs { font-size:11px; padding:4px 9px; border-radius:7px; }
.btn.ionly { width:30px; height:30px; padding:0; justify-content:center; font-size:15px; flex-shrink:0; border-radius:7px; }

/* ── STAT CARDS ─────────────────────────────────────────── */
.rp-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:22px; animation:UP .4s .05s both; }
.sc { background:var(--s); border:1px solid var(--bd); border-radius:var(--rad); padding:14px 16px; box-shadow:0 1px 4px rgba(46,125,50,.07); display:flex; align-items:center; gap:12px; }
.sc-ic { width:38px; height:38px; border-radius:10px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:18px; }
.ic-b{background:#EFF6FF;color:var(--blu)} .ic-a{background:#FEF3C7;color:var(--amb)}
.ic-g{background:#DCFCE7;color:#166534}    .ic-r{background:#FEE2E2;color:var(--red)}
.ic-t{background:#CCFBF1;color:var(--tel)} .ic-p{background:#F5F3FF;color:#6D28D9}
.ic-d{background:#F3F4F6;color:#374151}    .ic-o{background:#FFF7ED;color:#C2410C}
.sc-v { font-size:22px; font-weight:800; color:var(--t1); line-height:1; }
.sc-l { font-size:11px; color:var(--t2); margin-top:2px; }
.sc-trend { font-size:10px; font-weight:700; margin-top:4px; display:flex; align-items:center; gap:3px; }
.trend-up { color:#16A34A; } .trend-dn { color:var(--red); } .trend-nt { color:var(--t3); }

/* ── TABS ─────────────────────────────────────────────── */
.report-tabs { display:flex; gap:6px; flex-wrap:nowrap; overflow-x:auto; overflow-y:visible; margin-bottom:20px; animation:UP .4s .08s both; padding-bottom:4px; scrollbar-width:none; }
.report-tabs::-webkit-scrollbar { display:none; }
.rt { font-family:'Inter',sans-serif; font-size:12px; font-weight:600; padding:7px 12px; border-radius:9px; cursor:pointer; transition:var(--tr); color:var(--t2); border:1.5px solid var(--bd); background:var(--s); display:flex; align-items:center; gap:5px; white-space:nowrap; flex-shrink:0; }
.rt:hover { border-color:var(--grn); color:var(--grn); background:var(--hbg); }
.rt.active { background:var(--grn); color:#fff; border-color:var(--grn); box-shadow:0 2px 8px rgba(46,125,50,.25); }
.rt i { font-size:13px; }
.rt .badge-ct { font-size:10px; font-weight:700; background:rgba(255,255,255,.25); padding:1px 5px; border-radius:9px; }
.rt.active .badge-ct { background:rgba(255,255,255,.3); }
.rt:not(.active) .badge-ct { background:rgba(46,125,50,.12); color:var(--grn); }

/* ── FILTERS ─────────────────────────────────────────────── */
.rp-tb { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:16px; animation:UP .4s .1s both; }
.sw { position:relative; flex:1; min-width:200px; }
.sw i { position:absolute; left:11px; top:50%; transform:translateY(-50%); font-size:17px; color:var(--t3); pointer-events:none; }
.si { width:100%; padding:9px 11px 9px 36px; font-family:'Inter',sans-serif; font-size:13px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; transition:var(--tr); }
.si:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.10); }
.si::placeholder { color:var(--t3); }
.sel { font-family:'Inter',sans-serif; font-size:13px; padding:9px 28px 9px 11px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); cursor:pointer; outline:none; appearance:none; transition:var(--tr); background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; }
.sel:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.10); }
.date-range-wrap { display:flex; align-items:center; gap:6px; }
.date-range-wrap span { font-size:12px; color:var(--t3); font-weight:500; }
.fi-date { font-family:'Inter',sans-serif; font-size:13px; padding:9px 11px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; transition:var(--tr); }
.fi-date:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.10); }

/* ── BUDGET RANGE FILTER ─────────────────────────────────── */
.budget-range { display:flex; align-items:center; gap:6px; }
.budget-range span { font-size:12px; color:var(--t3); font-weight:500; }
.fi-budget { font-family:'DM Mono',monospace; font-size:12px; padding:9px 10px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; transition:var(--tr); width:110px; }
.fi-budget:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.10); }

/* ── MAIN CARD + TABLE ─────────────────────────────────── */
.rp-card { background:var(--s); border:1px solid var(--bd); border-radius:16px; overflow:hidden; box-shadow:var(--shmd); animation:UP .4s .13s both; }
.tbl-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
.rp-tbl { width:100%; border-collapse:collapse; font-size:12px; table-layout:fixed; min-width:680px; }
.rp-tbl thead th { font-size:10px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--t2); padding:9px 8px; text-align:left; background:var(--bg); border-bottom:1px solid var(--bd); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer; user-select:none; }
.rp-tbl thead th.no-sort { cursor:default; }
.rp-tbl thead th:hover:not(.no-sort) { color:var(--grn); }
.rp-tbl thead th.sorted { color:var(--grn); }
.rp-tbl thead th .sic { margin-left:2px; opacity:.4; font-size:11px; vertical-align:middle; }
.rp-tbl thead th.sorted .sic { opacity:1; }
.rp-tbl tbody tr { border-bottom:1px solid var(--bd); transition:background .13s; cursor:pointer; }
.rp-tbl tbody tr:last-child { border-bottom:none; }
.rp-tbl tbody tr:hover { background:var(--hbg); }
.rp-tbl { table-layout:fixed; }
.rp-tbl tbody td { padding:9px 8px; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; max-width:0; white-space:nowrap; }
.rp-tbl tbody td:last-child { cursor:default; overflow:visible; max-width:none; white-space:nowrap; }
.col-code  { width:110px; } .col-name { width:200px; } .col-region { width:90px; }
.col-type  { width:110px; } .col-pm   { width:130px; } .col-pct    { width:120px; }
.col-ms    { width:75px;  } .col-status{ width:100px;} .col-crit   { width:80px; }
.col-num   { width:70px;  } .col-bva  { width:140px; } .col-date   { width:90px; }
.col-act   { width:70px;  }
.proj-cell { display:flex; align-items:center; gap:7px; min-width:0; overflow:hidden; }
.proj-av   { width:26px; height:26px; border-radius:7px; font-size:9px; font-weight:800; color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.proj-info { min-width:0; overflow:hidden; }
.proj-name { font-weight:700; font-size:12px; color:var(--t1); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.proj-code { font-family:'DM Mono',monospace; font-size:10px; color:var(--t3); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.mono-cell { font-family:'DM Mono',monospace; font-size:11.5px; font-weight:600; color:var(--t1); white-space:nowrap; }
.dim-cell  { font-size:11.5px; color:var(--t2); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; max-width:100%; }
.act-cell  { display:flex; gap:3px; align-items:center; }

/* ── PROGRESS BARS ─────────────────────────────────────── */
.prog-wrap { display:flex; align-items:center; gap:5px; max-width:100%; }
.prog-bar { flex:1; height:5px; background:#E5E7EB; border-radius:3px; overflow:hidden; min-width:40px; }
.prog-fill { height:100%; border-radius:3px; transition:width .4s ease; }
.prog-val { font-family:'DM Mono',monospace; font-size:11px; font-weight:700; color:var(--t1); min-width:33px; text-align:right; }
.pfill-g { background:linear-gradient(90deg,#4CAF50,#2E7D32); }
.pfill-a { background:linear-gradient(90deg,#F59E0B,#D97706); }
.pfill-r { background:linear-gradient(90deg,#EF4444,#DC2626); }
.pfill-b { background:linear-gradient(90deg,#3B82F6,#2563EB); }

/* ── BADGES ─────────────────────────────────────────────── */
.badge { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; white-space:nowrap; }
.badge::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }
.b-ongoing    { background:#EFF6FF; color:#1D4ED8; }
.b-completed  { background:#DCFCE7; color:#166534; }
.b-delayed    { background:#FEE2E2; color:#991B1B; }
.b-critical   { background:#FFF1F2; color:#BE123C; }
.b-ontrack    { background:#F0FDF4; color:#15803D; }
.b-atrisk     { background:#FEF3C7; color:#92400E; }
.b-planning   { background:#F5F3FF; color:#6D28D9; }
.b-suspended  { background:#F3F4F6; color:#374151; }
.risk-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; display:inline-block; }
.rd-low{background:#22C55E} .rd-med{background:#F59E0B} .rd-high{background:#EF4444} .rd-crit{background:#BE123C}

/* ── BUDGET VS ACTUAL MINI VIZ ─────────────────────────── */
.bva-wrap { display:flex; flex-direction:column; gap:3px; width:100%; max-width:150px; }
.bva-row  { display:flex; align-items:center; gap:5px; font-size:10.5px; }
.bva-label { color:var(--t3); width:36px; flex-shrink:0; font-weight:600; }
.bva-bar  { flex:1; height:4px; background:#E5E7EB; border-radius:2px; }
.bva-fill { height:100%; border-radius:2px; }
.bf-budget { background:#93C5FD; }
.bf-actual { background:#2E7D32; }
.bva-pct { font-family:'DM Mono',monospace; font-size:10px; font-weight:700; color:var(--t1); min-width:32px; text-align:right; }

/* ── REPORT TYPE INDICATOR ─────────────────────────────── */
.rtype-tag { display:inline-flex; align-items:center; gap:4px; font-size:10.5px; font-weight:700; padding:3px 8px; border-radius:6px; white-space:nowrap; }

/* ── KPI ROW (above table per-type) ───────────────────── */
.kpi-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; padding:16px 20px; border-bottom:1px solid var(--bd); background:linear-gradient(135deg,rgba(46,125,50,.03),rgba(46,125,50,.07)); }
.kpi-item { text-align:center; }
.kpi-v { font-size:20px; font-weight:800; color:var(--t1); font-family:'DM Mono',monospace; }
.kpi-l { font-size:10.5px; color:var(--t2); margin-top:2px; }
.kpi-d { font-size:10px; font-weight:700; margin-top:2px; }
.kpi-d.up   { color:#16A34A; }
.kpi-d.dn   { color:var(--red); }
.kpi-d.nt   { color:var(--t3); }

/* ── PAGINATION ─────────────────────────────────────────── */
.rp-pager { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:14px 20px; border-top:1px solid var(--bd); background:var(--bg); font-size:13px; color:var(--t2); }
.pg-btns  { display:flex; gap:5px; }
.pgb { width:32px; height:32px; border-radius:8px; border:1px solid var(--bdm); background:var(--s); font-family:'Inter',sans-serif; font-size:13px; cursor:pointer; display:grid; place-content:center; transition:var(--tr); color:var(--t1); }
.pgb:hover   { background:var(--hbg); border-color:var(--grn); color:var(--grn); }
.pgb.active  { background:var(--grn); border-color:var(--grn); color:#fff; }
.pgb:disabled { opacity:.4; pointer-events:none; }
.empty { padding:72px 20px; text-align:center; color:var(--t3); }
.empty i { font-size:54px; display:block; margin-bottom:14px; color:#C8E6C9; }

/* ── SCHEDULE PANEL ─────────────────────────────────────── */
.sched-section { background:var(--s); border:1px solid var(--bd); border-radius:16px; box-shadow:var(--shmd); margin-top:20px; overflow:hidden; animation:UP .4s .16s both; }
.sched-hdr { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--bd); background:linear-gradient(135deg,#F0FDF4,#DCFCE7); }
.sched-title { font-size:14px; font-weight:700; color:var(--t1); display:flex; align-items:center; gap:8px; }
.sched-title i { font-size:18px; color:var(--grn); }
.sched-body { padding:0; }
.sched-list { display:flex; flex-direction:column; }
.sched-item { display:flex; align-items:center; gap:14px; padding:14px 20px; border-bottom:1px solid var(--bd); transition:background .13s; }
.sched-item:last-child { border-bottom:none; }
.sched-item:hover { background:var(--hbg); }
.sched-ic { width:36px; height:36px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
.sched-info { flex:1; min-width:0; }
.sched-name { font-size:13px; font-weight:700; color:var(--t1); }
.sched-meta { font-size:11.5px; color:var(--t2); margin-top:2px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.sched-meta span { display:flex; align-items:center; gap:3px; }
.sched-next { font-size:11px; font-weight:700; color:var(--t3); font-family:'DM Mono',monospace; white-space:nowrap; }
.freq-badge { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; padding:2px 7px; border-radius:6px; }
.fb-w { background:#EFF6FF; color:var(--blu); }
.fb-m { background:#F5F3FF; color:#6D28D9; }
.fb-q { background:#CCFBF1; color:var(--tel); }

/* ── MODALS ─────────────────────────────────────────────── */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9050; display:flex; align-items:center; justify-content:center; padding:20px; opacity:0; pointer-events:none; transition:opacity .25s; }
.modal-overlay.on { opacity:1; pointer-events:all; }
.modal-box { background:#fff; border-radius:20px; width:520px; max-width:100%; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.22); overflow:hidden; }
.modal-hdr { padding:22px 26px 18px; border-bottom:1px solid rgba(46,125,50,.14); background:var(--bg-color); display:flex; align-items:flex-start; justify-content:space-between; }
.modal-hdr-l .mh-icon { font-size:28px; margin-bottom:6px; }
.modal-hdr-l .mh-title { font-size:18px; font-weight:800; color:var(--t1); }
.modal-hdr-l .mh-sub { font-size:12px; color:var(--t2); margin-top:2px; }
.modal-close { width:34px; height:34px; border-radius:8px; border:1px solid rgba(46,125,50,.22); background:#fff; cursor:pointer; display:grid; place-content:center; font-size:20px; color:var(--t2); transition:all .15s; }
.modal-close:hover { background:#FEE2E2; color:#DC2626; border-color:#FECACA; }
.modal-body { flex:1; overflow-y:auto; padding:22px 26px; display:flex; flex-direction:column; gap:16px; }
.modal-body::-webkit-scrollbar { width:4px; }
.modal-body::-webkit-scrollbar-thumb { background:rgba(46,125,50,.22); border-radius:4px; }
.modal-foot { padding:16px 26px; border-top:1px solid rgba(46,125,50,.14); background:var(--bg-color); display:flex; gap:10px; justify-content:flex-end; }

/* ── FORM ─────────────────────────────────────────────── */
.fg { display:flex; flex-direction:column; gap:6px; }
.fr { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.fl { font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:var(--t2); }
.fl span { color:var(--red); margin-left:2px; }
.fi,.fs,.fta { font-family:'Inter',sans-serif; font-size:13px; padding:10px 12px; border:1px solid var(--bdm); border-radius:10px; background:var(--s); color:var(--t1); outline:none; transition:var(--tr); width:100%; }
.fi:focus,.fs:focus,.fta:focus { border-color:var(--grn); box-shadow:0 0 0 3px rgba(46,125,50,.11); }
.fs { appearance:none; cursor:pointer; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; padding-right:30px; }
.fta { resize:vertical; min-height:70px; }
.fd { font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--t3); display:flex; align-items:center; gap:10px; margin:4px 0; }
.fd::after { content:''; flex:1; height:1px; background:var(--bd); }

/* ── CHECKBOX GROUP ─────────────────────────────────────── */
.chk-group { display:flex; flex-wrap:wrap; gap:8px; }
.chk-opt { display:flex; align-items:center; gap:6px; background:var(--bg); border:1.5px solid var(--bd); border-radius:8px; padding:7px 12px; cursor:pointer; transition:var(--tr); font-size:12.5px; font-weight:600; color:var(--t2); user-select:none; }
.chk-opt:hover { border-color:var(--grn); color:var(--grn); }
.chk-opt.selected { background:#F0FDF4; border-color:var(--grn); color:var(--grn); }
.chk-opt input { display:none; }

/* ── RECIPIENT TAGS ─────────────────────────────────────── */
.recipient-tags { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
.rtag { display:inline-flex; align-items:center; gap:5px; background:#EFF6FF; color:#1D4ED8; border:1px solid #BFDBFE; border-radius:20px; font-size:12px; font-weight:600; padding:4px 10px; }
.rtag button { background:none; border:none; cursor:pointer; color:#1D4ED8; font-size:14px; line-height:1; padding:0; display:flex; align-items:center; }
.rtag button:hover { color:var(--red); }

/* ── PREVIEW MODAL ─────────────────────────────────────── */
.prev-box { background:#fff; border-radius:20px; width:860px; max-width:100%; max-height:93vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.22); overflow:hidden; }
.prev-inner { flex:1; overflow-y:auto; padding:32px 36px; background:#F9FBF9; }
.prev-inner::-webkit-scrollbar { width:4px; }
.prev-inner::-webkit-scrollbar-thumb { background:rgba(46,125,50,.2); border-radius:4px; }
.report-preview { background:#fff; border:1px solid rgba(46,125,50,.14); border-radius:16px; overflow:hidden; }
.rp-hd { background:linear-gradient(135deg,#1B5E20,#2E7D32); padding:28px 32px; color:#fff; }
.rp-hd-logo { font-size:12px; font-weight:700; letter-spacing:.2em; text-transform:uppercase; opacity:.7; margin-bottom:10px; }
.rp-hd-title { font-size:24px; font-weight:800; margin-bottom:4px; }
.rp-hd-sub { font-size:13px; opacity:.8; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.rp-hd-chips { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
.rp-hd-chip { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25); border-radius:6px; font-size:11px; font-weight:600; padding:3px 9px; }
.prev-sec { padding:24px 32px; border-bottom:1px solid rgba(46,125,50,.1); }
.prev-sec:last-child { border-bottom:none; }
.prev-sec-title { font-size:12px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--grn); margin-bottom:14px; }
.prev-kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
.prev-kpi { background:var(--bg-color); border:1px solid rgba(46,125,50,.12); border-radius:10px; padding:14px 16px; }
.prev-kpi .v { font-size:20px; font-weight:800; font-family:'DM Mono',monospace; color:var(--t1); }
.prev-kpi .l { font-size:11px; color:var(--t2); margin-top:2px; }
.prev-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.prev-table th { font-size:10.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--t2); padding:9px 12px; text-align:left; background:var(--bg-color); border-bottom:1px solid rgba(46,125,50,.14); }
.prev-table td { padding:10px 12px; border-bottom:1px solid rgba(46,125,50,.08); font-size:12.5px; }
.prev-table tr:last-child td { border-bottom:none; }
.prev-table tr:hover td { background:rgba(46,125,50,.03); }
.prev-chart-bar { display:flex; flex-direction:column; gap:8px; }
.chart-row { display:flex; align-items:center; gap:10px; font-size:12px; }
.chart-label { width:130px; flex-shrink:0; font-weight:500; color:var(--t2); text-align:right; font-size:11.5px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.chart-bar-bg { flex:1; height:16px; background:#E5E7EB; border-radius:3px; overflow:hidden; }
.chart-bar-fill { height:100%; border-radius:3px; display:flex; align-items:center; padding-left:6px; font-size:10px; font-weight:700; color:#fff; transition:width .6s cubic-bezier(.4,0,.2,1); }
.chart-val { font-family:'DM Mono',monospace; font-size:11px; font-weight:700; width:80px; text-align:right; }

/* ── TOAST ─────────────────────────────────────────────── */
.rp-toasts { position:fixed; bottom:28px; right:28px; z-index:9999; display:flex; flex-direction:column; gap:10px; pointer-events:none; }
.toast { background:#0A1F0D; color:#fff; padding:12px 18px; border-radius:10px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:10px; box-shadow:0 20px 60px rgba(0,0,0,.22); pointer-events:all; min-width:220px; animation:TIN .3s ease; }
.toast.ts { background:var(--grn); } .toast.tw { background:var(--amb); } .toast.td { background:var(--red); }
.toast.out { animation:TOUT .3s ease forwards; }

@keyframes UP   { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
@keyframes TIN  { from{opacity:0;transform:translateY(8px)}  to{opacity:1;transform:translateY(0)} }
@keyframes TOUT { from{opacity:1;transform:translateY(0)}    to{opacity:0;transform:translateY(8px)} }

/* ── RA 9184 BANNER ─────────────────────────────────────── */
.ra-banner { display:flex; align-items:center; gap:12px; padding:12px 18px; background:linear-gradient(135deg,#FFF7ED,#FEF3C7); border:1px solid #FCD34D; border-radius:12px; margin-bottom:16px; animation:UP .4s .06s both; }
.ra-banner i { font-size:22px; color:#D97706; flex-shrink:0; }
.ra-banner-text { flex:1; }
.ra-banner-text strong { font-size:13px; color:#92400E; }
.ra-banner-text p { font-size:11.5px; color:#B45309; margin-top:2px; line-height:1.5; }
.ra-badge { font-size:10px; font-weight:700; background:#F59E0B; color:#fff; border-radius:6px; padding:3px 8px; flex-shrink:0; }

@media(max-width:768px){
  .page-wrap { padding:16px 12px 4rem; }
  .fr { grid-template-columns:1fr; }
  .rp-stats { grid-template-columns:repeat(2,1fr); }
  .prev-kpi-row { grid-template-columns:repeat(2,1fr); }
  .modal-box { border-radius:14px; }
  .prev-box { border-radius:14px; }
  .report-tabs { gap:4px; }
  .rt { font-size:11.5px; padding:6px 10px; }
}
</style>
</head>
<body>
<?php /* sidebar and header are already included above */ ?>
<main class="main-content" id="mainContent">
<div class="page-wrap">

  <!-- PAGE HEADER -->
  <div class="rp-ph">
    <div>
      <p class="ey">PLT · Project Logistics Tracker</p>
      <h1>Project Reports</h1>
    </div>
    <div class="rp-ph-r">
      <button class="btn btn-ghost" id="schedBtn"><i class="bx bx-calendar-check"></i> Scheduled Reports</button>
      <button class="btn btn-amber btn-sm" id="exportPdfBtn"><i class="bx bx-file-pdf"></i> PDF</button>
      <button class="btn btn-blue btn-sm" id="exportXlsBtn"><i class="bx bx-spreadsheet"></i> Excel</button>
      <button class="btn btn-ghost btn-sm" id="exportCsvBtn"><i class="bx bx-export"></i> CSV</button>
      <button class="btn btn-primary" id="previewBtn"><i class="bx bx-show"></i> Preview Report</button>
    </div>
  </div>

  <!-- STAT CARDS -->
  <div class="rp-stats" id="statsBar"></div>

  <!-- RA 9184 BANNER (shown on Procurement Linkage tab) -->
  <div class="ra-banner" id="raBanner" style="display:none">
    <i class="bx bx-shield-quarter"></i>
    <div class="ra-banner-text">
      <strong>RA 9184 — Government Procurement Reform Act Linkage Active</strong>
      <p>This report is linked to the PhilGEPS registry and BAC procurement records. Data is cross-referenced with approved procurement plans (APP) and posted bid notices.</p>
    </div>
    <span class="ra-badge">RA 9184</span>
  </div>

  <!-- REPORT TYPE TABS -->
  <div class="report-tabs" id="reportTabs"></div>

  <!-- FILTERS -->
  <div class="rp-tb">
    <div class="sw">
      <i class="bx bx-search"></i>
      <input type="text" class="si" id="srch" placeholder="Search by project name, code, or manager…">
    </div>
    <select class="sel" id="fStatus">
      <option value="">All Statuses</option>
      <option>On Track</option><option>At Risk</option><option>Delayed</option>
      <option>Critical</option><option>Completed</option><option>Suspended</option>
    </select>
    <select class="sel" id="fType">
      <option value="">All Project Types</option>
      <option>Infrastructure</option><option>Road & Bridge</option>
      <option>Building & Vertical</option><option>Flood Control</option>
      <option>Water Supply</option><option>IT Systems</option>
    </select>
    <select class="sel" id="fRegion">
      <option value="">All Zones/Regions</option>
      <option>NCR</option><option>Region I</option><option>Region III</option>
      <option>Region IV-A</option><option>Region VII</option><option>Region XI</option>
    </select>
    <div class="budget-range">
      <input type="number" class="fi-budget" id="fBudgetMin" placeholder="Min ₱" min="0">
      <span>–</span>
      <input type="number" class="fi-budget" id="fBudgetMax" placeholder="Max ₱" min="0">
    </div>
    <div class="date-range-wrap">
      <input type="date" class="fi-date" id="fDateFrom" title="Date From">
      <span>–</span>
      <input type="date" class="fi-date" id="fDateTo" title="Date To">
    </div>
    <select class="sel" id="fCritPath">
      <option value="">Critical Path: All</option>
      <option value="yes">Critical Path Only</option>
      <option value="no">Non-Critical</option>
    </select>
  </div>

  <!-- REPORT TABLE CARD -->
  <div class="rp-card">
    <div class="kpi-strip" id="kpiStrip"></div>
    <div class="tbl-scroll">
      <table class="rp-tbl" id="tbl">
        <thead id="tblHead"></thead>
        <tbody id="tbody"></tbody>
      </table>
    </div>
    <div class="rp-pager" id="pager"></div>
  </div>

  <!-- SCHEDULED REPORTS -->
  <div class="sched-section" id="schedSection">
    <div class="sched-hdr">
      <div class="sched-title"><i class="bx bx-calendar-check"></i> Scheduled Reports</div>
      <button class="btn btn-primary btn-sm" id="addSchedBtn"><i class="bx bx-plus"></i> Add Schedule</button>
    </div>
    <div class="sched-body">
      <div class="sched-list" id="schedList"></div>
    </div>
  </div>

</div>
</main>

<!-- TOAST CONTAINER -->
<div class="rp-toasts" id="toastWrap"></div>

<!-- ═══════════════════════════════════════
     SCHEDULE MODAL
═══════════════════════════════════════ -->
<div class="modal-overlay" id="schedModal">
  <div class="modal-box">
    <div class="modal-hdr">
      <div class="modal-hdr-l">
        <div class="mh-icon">📅</div>
        <div class="mh-title">Add Scheduled Report</div>
        <div class="mh-sub">Configure auto-generation and email delivery</div>
      </div>
      <button class="modal-close" id="schedClose"><i class="bx bx-x"></i></button>
    </div>
    <div class="modal-body">
      <div class="fg">
        <label class="fl">Report Name <span>*</span></label>
        <input type="text" class="fi" id="schName" placeholder="e.g. Weekly Project Status Summary">
      </div>
      <div class="fr">
        <div class="fg">
          <label class="fl">Report Type <span>*</span></label>
          <select class="fs" id="schType">
            <option value="">Select type…</option>
            <option>Project Status</option><option>Delivery Performance</option>
            <option>Milestone Completion</option><option>Logistics Assignment</option>
            <option>Budget vs. Actual</option><option>Delay & Risk Analysis</option>
            <option>Cross-Zone Resource Utilization</option><option>RA 9184 Procurement Linkage</option>
          </select>
        </div>
        <div class="fg">
          <label class="fl">Frequency <span>*</span></label>
          <select class="fs" id="schFreq">
            <option value="Weekly">Weekly</option>
            <option value="Monthly">Monthly</option>
            <option value="Quarterly">Quarterly</option>
          </select>
        </div>
      </div>
      <div class="fr">
        <div class="fg">
          <label class="fl">Day / Time</label>
          <select class="fs" id="schDay">
            <option>Every Monday</option><option>Every Friday</option>
            <option>1st of Month</option><option>Last of Month</option>
            <option>Every Quarter-End</option>
          </select>
        </div>
        <div class="fg">
          <label class="fl">Export Format</label>
          <select class="fs" id="schFmt">
            <option>PDF</option><option>Excel</option><option>CSV</option>
          </select>
        </div>
      </div>
      <div class="fd">Stakeholder Recipients</div>
      <div class="fg">
        <label class="fl">Add Email Recipients</label>
        <input type="email" class="fi" id="schEmailInput" placeholder="Enter email and press Enter…">
        <div class="recipient-tags" id="schTags"></div>
      </div>
      <div class="fg">
        <label class="fl">Notes / Subject Line</label>
        <textarea class="fta" id="schNotes" placeholder="Optional email subject or notes for recipients…"></textarea>
      </div>
      <div class="fg">
        <label class="fl">Applicable Filters</label>
        <div class="chk-group" id="schFilters">
          <label class="chk-opt selected"><input type="checkbox" checked><span>All Projects</span></label>
          <label class="chk-opt"><input type="checkbox"><span>Critical Path Only</span></label>
          <label class="chk-opt"><input type="checkbox"><span>Delayed Only</span></label>
          <label class="chk-opt"><input type="checkbox"><span>At Risk</span></label>
          <label class="chk-opt"><input type="checkbox"><span>RA 9184 Linked</span></label>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost btn-sm" id="schedCancel">Cancel</button>
      <button class="btn btn-primary btn-sm" id="schedSave"><i class="bx bx-save"></i> Save Schedule</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════
     EXPORT MODAL
═══════════════════════════════════════ -->
<div class="modal-overlay" id="exportModal">
  <div class="modal-box" style="max-width:420px">
    <div class="modal-hdr">
      <div class="modal-hdr-l">
        <div class="mh-icon" id="expIcon">📄</div>
        <div class="mh-title" id="expTitle">Export Report</div>
        <div class="mh-sub" id="expSub">Configure export options</div>
      </div>
      <button class="modal-close" id="exportClose"><i class="bx bx-x"></i></button>
    </div>
    <div class="modal-body">
      <div class="fg">
        <label class="fl">Report Type</label>
        <select class="fs" id="expType">
          <option>Current View</option>
          <option>Project Status</option><option>Delivery Performance</option>
          <option>Milestone Completion</option><option>Budget vs. Actual</option>
          <option>Delay & Risk Analysis</option><option>All Reports (Bundle)</option>
        </select>
      </div>
      <div class="fd">Date Range</div>
      <div class="fr">
        <div class="fg">
          <label class="fl">From</label>
          <input type="date" class="fi" id="expFrom">
        </div>
        <div class="fg">
          <label class="fl">To</label>
          <input type="date" class="fi" id="expTo">
        </div>
      </div>
      <div class="fg">
        <label class="fl">Include Sections</label>
        <div class="chk-group" id="expSections">
          <label class="chk-opt selected"><input type="checkbox" checked><span>Summary KPIs</span></label>
          <label class="chk-opt selected"><input type="checkbox" checked><span>Project Table</span></label>
          <label class="chk-opt selected"><input type="checkbox" checked><span>Charts</span></label>
          <label class="chk-opt"><input type="checkbox"><span>Audit Trail</span></label>
          <label class="chk-opt"><input type="checkbox"><span>RA 9184 Annex</span></label>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost btn-sm" id="exportCancel">Cancel</button>
      <button class="btn btn-primary btn-sm" id="exportConfirm"><i class="bx bx-download"></i> Export</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════
     PREVIEW MODAL
═══════════════════════════════════════ -->
<div class="modal-overlay" id="previewModal">
  <div class="prev-box">
    <div class="modal-hdr" style="background:var(--bg-color);border-radius:0;">
      <div class="modal-hdr-l">
        <div class="mh-title">Report Preview</div>
        <div class="mh-sub" id="prevSub">Project Status Report — All Projects</div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;">
        <button class="btn btn-primary btn-sm" id="prevExport"><i class="bx bx-download"></i> Export PDF</button>
        <button class="modal-close" id="prevClose"><i class="bx bx-x"></i></button>
      </div>
    </div>
    <div class="prev-inner" id="prevInner"></div>
  </div>
</div>

<script>
/* ══════════════════════════════════════════
   DATA
══════════════════════════════════════════ */
const PROJECTS = [
  {code:'PROJ-2024-001',name:'Metro Manila Flood Control Phase 3',type:'Flood Control',region:'NCR',pm:'Engr. Ramon Dela Cruz',budget:450000000,actual:312000000,pct:68,milestones:12,done:8,status:'On Track',risk:'Low',critical:true,delivery:94,resources:42,delayed:0,procLinks:3,start:'2023-10-01',end:'2025-03-31'},
  {code:'PROJ-2024-002',name:'Skyway Road Widening — SLEX Segment',type:'Road & Bridge',region:'Region IV-A',pm:'Engr. Sofia Magsaysay',budget:880000000,actual:720000000,pct:82,milestones:18,done:12,status:'At Risk',risk:'High',critical:true,delivery:78,resources:65,delayed:2,procLinks:7,start:'2023-05-15',end:'2025-06-30'},
  {code:'PROJ-2024-003',name:'Iloilo City Government Center',type:'Building & Vertical',region:'Region VII',pm:'Arch. Liza Tan',budget:210000000,actual:98000000,pct:44,milestones:9,done:4,status:'Delayed',risk:'High',critical:false,delivery:61,resources:28,delayed:3,procLinks:2,start:'2024-01-10',end:'2025-12-31'},
  {code:'PROJ-2024-004',name:'Pampanga River Dike Rehabilitation',type:'Flood Control',region:'Region III',pm:'Engr. Carlo Mendoza',budget:340000000,actual:290000000,pct:91,milestones:14,done:13,status:'On Track',risk:'Low',critical:true,delivery:97,resources:38,delayed:0,procLinks:4,start:'2023-02-01',end:'2024-12-31'},
  {code:'PROJ-2024-005',name:'Nueva Ecija Water Supply Expansion',type:'Water Supply',region:'Region III',pm:'Engr. Nora Fernandez',budget:180000000,actual:55000000,pct:29,milestones:10,done:3,status:'Critical',risk:'Critical',critical:true,delivery:45,resources:20,delayed:5,procLinks:1,start:'2024-03-01',end:'2026-02-28'},
  {code:'PROJ-2024-006',name:'Davao Coastal Road Bridge Package',type:'Road & Bridge',region:'Region XI',pm:'Engr. Rodel Bautista',budget:620000000,actual:390000000,pct:63,milestones:22,done:14,status:'On Track',risk:'Med',critical:true,delivery:88,resources:55,delayed:1,procLinks:6,start:'2023-08-01',end:'2026-01-31'},
  {code:'PROJ-2024-007',name:'Pasig River Estero Cleanup Infrastructure',type:'Flood Control',region:'NCR',pm:'Engr. Ana Cruz',budget:95000000,actual:91000000,pct:98,milestones:8,done:8,status:'Completed',risk:'Low',critical:false,delivery:100,resources:12,delayed:0,procLinks:2,start:'2023-01-15',end:'2024-09-30'},
  {code:'PROJ-2024-008',name:'Batangas Port Expansion Phase 2',type:'Infrastructure',region:'Region IV-A',pm:'Engr. Pedro Reyes',budget:760000000,actual:180000000,pct:22,milestones:20,done:5,status:'At Risk',risk:'High',critical:true,delivery:55,resources:47,delayed:4,procLinks:5,start:'2024-04-01',end:'2027-03-31'},
  {code:'PROJ-2024-009',name:'Cebu IT Park Connectivity Roads',type:'IT Systems',region:'Region VII',pm:'Mark Ocampo',budget:75000000,actual:68000000,pct:88,milestones:6,done:6,status:'Completed',risk:'Low',critical:false,delivery:100,resources:8,delayed:0,procLinks:1,start:'2023-06-01',end:'2024-10-31'},
  {code:'PROJ-2024-010',name:'Cagayan Valley Farm-to-Market Road',type:'Road & Bridge',region:'Region I',pm:'Grace Villanueva',budget:140000000,actual:60000000,pct:38,milestones:11,done:4,status:'Suspended',risk:'Med',critical:false,delivery:40,resources:15,delayed:6,procLinks:0,start:'2023-11-01',end:'2025-10-31'},
  {code:'PROJ-2024-011',name:'Las Piñas Drainage Master Plan',type:'Flood Control',region:'NCR',pm:'Engr. Juan Dela Cruz',budget:290000000,actual:145000000,pct:51,milestones:16,done:9,status:'On Track',risk:'Med',critical:true,delivery:82,resources:33,delayed:1,procLinks:3,start:'2023-09-01',end:'2025-08-31'},
  {code:'PROJ-2024-012',name:'Pangasinan Municipal Buildings Batch 2',type:'Building & Vertical',region:'Region I',pm:'Arch. Maria Santos',budget:420000000,actual:200000000,pct:47,milestones:13,done:7,status:'At Risk',risk:'High',critical:false,delivery:71,resources:39,delayed:3,procLinks:4,start:'2024-02-01',end:'2026-06-30'},
];

const REPORT_TYPES = [
  {key:'status',    label:'Project Status',           icon:'bx-bar-chart-alt-2',   ra:false},
  {key:'delivery',  label:'Delivery Performance',     icon:'bx-truck',              ra:false},
  {key:'milestone', label:'Milestone Completion',     icon:'bx-flag-alt',           ra:false},
  {key:'logistics', label:'Logistics Assignment',     icon:'bx-package',            ra:false},
  {key:'budget',    label:'Budget vs. Actual',        icon:'bx-money-withdraw',     ra:false},
  {key:'risk',      label:'Delay & Risk Analysis',    icon:'bx-shield-x',           ra:false},
  {key:'resource',  label:'Cross-Zone Resource',      icon:'bx-world',              ra:false},
  {key:'ra9184',    label:'RA 9184 Procurement',      icon:'bx-file-find',          ra:true},
];

let schedules = [
  {id:1, name:'Weekly Project Status Summary',     type:'Project Status',           freq:'Weekly',  day:'Every Monday',     fmt:'PDF',   recipients:['pmoffice@gov.ph','admin@gov.ph'],       active:true,  next:'Mar 17, 2025'},
  {id:2, name:'Monthly Budget vs. Actual Report',  type:'Budget vs. Actual',        freq:'Monthly', day:'1st of Month',     fmt:'Excel', recipients:['finance@gov.ph','cfo@gov.ph'],           active:true,  next:'Apr 1, 2025'},
  {id:3, name:'Quarterly Procurement Linkage',     type:'RA 9184 Procurement',      freq:'Quarterly',day:'Every Quarter-End',fmt:'PDF',  recipients:['bac@gov.ph','coa@gov.ph','dbm@gov.ph'],  active:false, next:'Jun 30, 2025'},
  {id:4, name:'Weekly Risk & Delay Digest',        type:'Delay & Risk Analysis',    freq:'Weekly',  day:'Every Friday',     fmt:'PDF',   recipients:['rm@gov.ph','director@gov.ph'],           active:true,  next:'Mar 14, 2025'},
];

let activeTab = 'status';
let sortCol = 'code', sortDir = 'asc', page = 1;
const PAGE_SIZE = 8;
let schedRecipients = [];

/* ── HELPERS ─────────────────────────────────────────────── */
const esc  = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fM   = n => '₱' + Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const fMsh = n => { if(n>=1e9) return '₱'+((n/1e9)).toFixed(2)+'B'; if(n>=1e6) return '₱'+((n/1e6)).toFixed(1)+'M'; return '₱'+Number(n||0).toLocaleString(); };
const fD   = d => d ? new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '—';
const dc   = d => ({NCR:'#2E7D32','Region I':'#0D9488','Region III':'#2563EB','Region IV-A':'#D97706','Region VII':'#7C3AED','Region XI':'#DC2626'})[d]||'#6B7280';
const ini  = s => String(s||'').split(' ').filter(w=>/^[A-Z]/.test(w)).map(w=>w[0]).join('').slice(0,2)||'PR';

function badge(s) {
  const m = {'On Track':'b-ontrack','At Risk':'b-atrisk','Delayed':'b-delayed','Critical':'b-critical','Completed':'b-completed','Suspended':'b-suspended','Planning':'b-planning'};
  return `<span class="badge ${m[s]||''}">${s}</span>`;
}
function riskDot(r) {
  const m = {Low:'rd-low',Med:'rd-med',High:'rd-high',Critical:'rd-crit'};
  return `<span class="risk-dot ${m[r]||'rd-low'}"></span><span style="font-size:12px;font-weight:600">${r}</span>`;
}
function progFill(pct) {
  const cls = pct>=80?'pfill-g':pct>=50?'pfill-a':'pfill-r';
  return `<div class="prog-wrap"><div class="prog-bar"><div class="prog-fill ${cls}" style="width:${pct}%"></div></div><span class="prog-val">${pct}%</span></div>`;
}
function bva(budget, actual) {
  const max = Math.max(budget, actual);
  const bp = Math.round(budget/max*100), ap = Math.round(actual/max*100);
  const over = actual > budget;
  return `<div class="bva-wrap">
    <div class="bva-row"><span class="bva-label">Budget</span><div class="bva-bar"><div class="bva-fill bf-budget" style="width:${bp}%"></div></div><span class="bva-pct">${fMsh(budget)}</span></div>
    <div class="bva-row"><span class="bva-label" style="${over?'color:#DC2626':''}>Actual</span><div class="bva-bar"><div class="bva-fill bf-actual" style="width:${ap}%;${over?'background:#DC2626':''}"></div></div><span class="bva-pct" style="${over?'color:#DC2626':''}">${fMsh(actual)}</span></div>
  </div>`;
}

/* ── COLUMN CONFIGS PER REPORT TYPE ─────────────────────── */
function getColumns(tab) {
  const base = [
    {key:'code',  label:'Code',         sort:'code',  cls:'col-code'},
    {key:'name',  label:'Project Name', sort:'name',  cls:'col-name'},
  ];
  const extra = {
    status:    [
      {key:'pm',        label:'Manager',     sort:'pm',       cls:'col-pm'},
      {key:'pct',       label:'Progress',    sort:'pct',      cls:'col-pct'},
      {key:'milestones',label:'Milestones',  sort:'done',     cls:'col-ms'},
      {key:'status',    label:'Status',      sort:'status',   cls:'col-status'},
    ],
    delivery:  [
      {key:'region',   label:'Zone',         sort:'region',   cls:'col-region'},
      {key:'delivery', label:'Delivery',     sort:'delivery', cls:'col-pct'},
      {key:'delayed',  label:'Delays',       sort:'delayed',  cls:'col-num'},
      {key:'status',   label:'Status',       sort:'status',   cls:'col-status'},
    ],
    milestone: [
      {key:'done',     label:'Done',         sort:'done',     cls:'col-num'},
      {key:'milestones',label:'Total',       sort:'milestones',cls:'col-num'},
      {key:'pct',      label:'Completion',   sort:'pct',      cls:'col-pct'},
      {key:'end',      label:'Target End',   sort:'end',      cls:'col-date'},
    ],
    logistics: [
      {key:'region',   label:'Zone',         sort:'region',   cls:'col-region'},
      {key:'pm',       label:'Manager',      sort:'pm',       cls:'col-pm'},
      {key:'resources',label:'Personnel',    sort:'resources',cls:'col-num'},
      {key:'status',   label:'Status',       sort:'status',   cls:'col-status'},
    ],
    budget:    [
      {key:'region',   label:'Zone',         sort:'region',   cls:'col-region'},
      {key:'budget',   label:'Budget vs Actual', sort:'budget', cls:'col-bva'},
      {key:'pct',      label:'Progress',     sort:'pct',      cls:'col-pct'},
      {key:'status',   label:'Status',       sort:'status',   cls:'col-status'},
    ],
    risk:      [
      {key:'risk',     label:'Risk',         sort:'risk',     cls:'col-num'},
      {key:'delayed',  label:'Delays',       sort:'delayed',  cls:'col-num'},
      {key:'delivery', label:'Delivery',     sort:'delivery', cls:'col-pct'},
      {key:'status',   label:'Status',       sort:'status',   cls:'col-status'},
    ],
    resource:  [
      {key:'region',   label:'Zone',         sort:'region',   cls:'col-region'},
      {key:'resources',label:'Personnel',    sort:'resources',cls:'col-num'},
      {key:'delivery', label:'Utilization',  sort:'delivery', cls:'col-pct'},
      {key:'status',   label:'Status',       sort:'status',   cls:'col-status'},
    ],
    ra9184:    [
      {key:'region',   label:'Zone',         sort:'region',   cls:'col-region'},
      {key:'procLinks',label:'Proc. Links',  sort:'procLinks',cls:'col-num'},
      {key:'budget',   label:'Contract Value',sort:'budget',  cls:'col-bva'},
      {key:'status',   label:'Status',       sort:'status',   cls:'col-status'},
    ],
  };
  return [...base, ...(extra[tab]||[])];
}

function getCellVal(p, key) {
  switch(key) {
    case 'code':    return `<span class="mono-cell">${esc(p.code)}</span>`;
    case 'name':    return `<div class="proj-cell"><div class="proj-av" style="background:${dc(p.region)}">${ini(p.name)}</div><div class="proj-info"><div class="proj-name">${esc(p.name)}</div><div class="proj-code">${esc(p.type)}</div></div></div>`;
    case 'region':  return `<span class="dim-cell" style="display:flex;align-items:center;gap:5px"><span style="width:7px;height:7px;border-radius:50%;background:${dc(p.region)};flex-shrink:0"></span>${esc(p.region)}</span>`;
    case 'type':    return `<span class="dim-cell">${esc(p.type)}</span>`;
    case 'pm':      return `<span class="dim-cell">${esc(p.pm)}</span>`;
    case 'pct':     return progFill(p.pct);
    case 'milestones': return `<span class="mono-cell">${p.done}/${p.milestones}</span>`;
    case 'done':    return `<span class="mono-cell">${p.done} / ${p.milestones}</span>`;
    case 'status':  return badge(p.status);
    case 'critical':return p.critical ? `<span class="badge" style="background:#FFF1F2;color:#BE123C"><span style="color:#BE123C">●</span>Critical</span>` : `<span class="dim-cell" style="color:#9CA3AF">—</span>`;
    case 'delivery':return `<div class="prog-wrap"><div class="prog-bar"><div class="prog-fill ${p.delivery>=80?'pfill-g':p.delivery>=60?'pfill-a':'pfill-r'}" style="width:${p.delivery}%"></div></div><span class="prog-val">${p.delivery}%</span></div>`;
    case 'delayed': return p.delayed > 0 ? `<span class="badge b-delayed">${p.delayed} task${p.delayed>1?'s':''}</span>` : `<span style="font-size:12px;color:#16A34A;font-weight:700">None</span>`;
    case 'risk':    return `<div style="display:flex;align-items:center;gap:6px">${riskDot(p.risk)}</div>`;
    case 'resources':return `<span class="mono-cell">${p.resources} pax</span>`;
    case 'budget':  return bva(p.budget, p.actual);
    case 'end':     return `<span class="dim-cell">${fD(p.end)}</span>`;
    case 'procLinks':return p.procLinks > 0 ? `<span class="badge b-ongoing">${p.procLinks} records</span>` : `<span class="dim-cell" style="color:#9CA3AF">—</span>`;
    default: return '';
  }
}

/* ── FILTER & SORT ─────────────────────────────────────── */
function getFiltered() {
  const q   = document.getElementById('srch').value.trim().toLowerCase();
  const st  = document.getElementById('fStatus').value;
  const tp  = document.getElementById('fType').value;
  const rg  = document.getElementById('fRegion').value;
  const bmin= parseFloat(document.getElementById('fBudgetMin').value)||0;
  const bmax= parseFloat(document.getElementById('fBudgetMax').value)||Infinity;
  const cp  = document.getElementById('fCritPath').value;
  return PROJECTS.filter(p => {
    if (q && !p.name.toLowerCase().includes(q) && !p.code.toLowerCase().includes(q) && !p.pm.toLowerCase().includes(q)) return false;
    if (st && p.status !== st) return false;
    if (tp && p.type !== tp)   return false;
    if (rg && p.region !== rg) return false;
    if (bmin && p.budget < bmin) return false;
    if (bmax < Infinity && p.budget > bmax) return false;
    if (cp === 'yes' && !p.critical) return false;
    if (cp === 'no'  && p.critical)  return false;
    return true;
  });
}
function getSorted(list) {
  return [...list].sort((a,b) => {
    const numCols = ['pct','delivery','delayed','resources','budget','actual','done','milestones','procLinks'];
    let va = a[sortCol], vb = b[sortCol];
    if (numCols.includes(sortCol)) return sortDir==='asc' ? va-vb : vb-va;
    va=String(va||'').toLowerCase(); vb=String(vb||'').toLowerCase();
    return sortDir==='asc' ? va.localeCompare(vb) : vb.localeCompare(va);
  });
}

/* ── RENDER TABS ─────────────────────────────────────────── */
function renderTabs() {
  const counts = {};
  REPORT_TYPES.forEach(rt => counts[rt.key] = PROJECTS.length);
  document.getElementById('reportTabs').innerHTML = REPORT_TYPES.map(rt =>
    `<button class="rt ${activeTab===rt.key?'active':''}" onclick="switchTab('${rt.key}')">
      <i class="bx ${rt.icon}"></i>${rt.label}
      ${rt.ra ? '<span class="badge-ct">RA 9184</span>' : ''}
    </button>`
  ).join('');
  document.getElementById('raBanner').style.display = (activeTab==='ra9184') ? 'flex' : 'none';
}

function switchTab(key) {
  activeTab = key; page = 1;
  renderTabs(); renderKpis(); renderTable();
}

/* ── KPI STRIP ─────────────────────────────────────────── */
function renderKpis() {
  const data = getFiltered();
  const kpis = {
    status:    [
      {v:data.length, l:'Total Projects', d:''},
      {v:data.filter(p=>p.status==='On Track').length, l:'On Track', d:'↑ vs last month', dc:'up'},
      {v:data.filter(p=>['Delayed','Critical'].includes(p.status)).length, l:'At Risk / Delayed', d:'', dc:'dn'},
      {v:data.filter(p=>p.status==='Completed').length, l:'Completed', d:'', dc:'nt'},
      {v:Math.round(data.reduce((s,p)=>s+p.pct,0)/Math.max(data.length,1))+'%', l:'Avg Progress', d:'', dc:'nt'},
    ],
    delivery:  [
      {v:Math.round(data.reduce((s,p)=>s+p.delivery,0)/Math.max(data.length,1))+'%', l:'Avg Delivery Rate', d:'', dc:'up'},
      {v:data.filter(p=>p.delivery>=90).length, l:'High Performers (≥90%)', d:'', dc:'up'},
      {v:data.reduce((s,p)=>s+p.delayed,0), l:'Total Delayed Tasks', d:'', dc:'dn'},
      {v:data.filter(p=>p.delayed===0).length, l:'Zero Delays', d:'', dc:'up'},
    ],
    milestone: [
      {v:data.reduce((s,p)=>s+p.milestones,0), l:'Total Milestones', d:''},
      {v:data.reduce((s,p)=>s+p.done,0), l:'Completed', d:'', dc:'up'},
      {v:data.reduce((s,p)=>s+(p.milestones-p.done),0), l:'Remaining', d:'', dc:'nt'},
      {v:Math.round(data.reduce((s,p)=>s+p.pct,0)/Math.max(data.length,1))+'%', l:'Avg Completion', d:'', dc:'up'},
    ],
    logistics: [
      {v:data.reduce((s,p)=>s+p.resources,0), l:'Total Personnel', d:'', dc:'nt'},
      {v:data.filter(p=>p.status==='On Track').length, l:'Active Assignments', d:'', dc:'up'},
      {v:[...new Set(data.map(p=>p.region))].length, l:'Zones Covered', d:'', dc:'nt'},
      {v:data.filter(p=>p.critical).length, l:'Critical Path Projects', d:'', dc:'dn'},
    ],
    budget:    [
      {v:fMsh(data.reduce((s,p)=>s+p.budget,0)), l:'Total Budget', d:''},
      {v:fMsh(data.reduce((s,p)=>s+p.actual,0)), l:'Total Disbursed', d:'', dc:'nt'},
      {v:fMsh(data.reduce((s,p)=>s+p.budget-p.actual,0)), l:'Remaining Balance', d:'', dc:'up'},
      {v:Math.round(data.reduce((s,p)=>s+(p.actual/p.budget*100),0)/Math.max(data.length,1))+'%', l:'Avg Utilization', d:'', dc:'nt'},
    ],
    risk:      [
      {v:data.filter(p=>p.risk==='Critical').length, l:'Critical Risk', d:'', dc:'dn'},
      {v:data.filter(p=>p.risk==='High').length, l:'High Risk', d:'', dc:'dn'},
      {v:data.filter(p=>p.risk==='Med').length, l:'Medium Risk', d:'', dc:'nt'},
      {v:data.reduce((s,p)=>s+p.delayed,0), l:'Total Delays', d:'', dc:'dn'},
    ],
    resource:  [
      {v:data.reduce((s,p)=>s+p.resources,0), l:'Total Personnel', d:'', dc:'nt'},
      {v:[...new Set(data.map(p=>p.region))].length, l:'Active Zones', d:'', dc:'nt'},
      {v:Math.round(data.reduce((s,p)=>s+p.delivery,0)/Math.max(data.length,1))+'%', l:'Avg Utilization', d:'', dc:'up'},
      {v:data.filter(p=>p.resources>40).length, l:'High-Density Sites', d:'', dc:'nt'},
    ],
    ra9184:    [
      {v:data.reduce((s,p)=>s+p.procLinks,0), l:'Proc. Records Linked', d:'', dc:'up'},
      {v:data.filter(p=>p.procLinks>0).length, l:'RA 9184 Compliant', d:'', dc:'up'},
      {v:data.filter(p=>p.procLinks===0).length, l:'Non-Linked Projects', d:'', dc:'dn'},
      {v:fMsh(data.filter(p=>p.procLinks>0).reduce((s,p)=>s+p.budget,0)), l:'Compliant Budget Total', d:'', dc:'up'},
    ],
  };
  const items = kpis[activeTab]||kpis.status;
  document.getElementById('kpiStrip').innerHTML = items.map(k =>
    `<div class="kpi-item">
      <div class="kpi-v">${k.v}</div>
      <div class="kpi-l">${k.l}</div>
      ${k.d ? `<div class="kpi-d ${k.dc||''}">${k.d}</div>` : ''}
    </div>`
  ).join('');
}

/* ── RENDER TABLE ─────────────────────────────────────── */
function renderStats() {
  const p = PROJECTS;
  const total = p.length, done = p.filter(x=>x.status==='Completed').length;
  const totalBudget = p.reduce((s,x)=>s+x.budget,0);
  const avgPct = Math.round(p.reduce((s,x)=>s+x.pct,0)/p.length);
  const delayed = p.reduce((s,x)=>s+x.delayed,0);
  const crit = p.filter(x=>x.critical).length;
  document.getElementById('statsBar').innerHTML = `
    <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-building-house"></i></div><div><div class="sc-v">${total}</div><div class="sc-l">Total Projects</div></div></div>
    <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${done}</div><div class="sc-l">Completed</div></div></div>
    <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-error-circle"></i></div><div><div class="sc-v">${delayed}</div><div class="sc-l">Delayed Tasks</div></div></div>
    <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-trending-up"></i></div><div><div class="sc-v">${avgPct}%</div><div class="sc-l">Avg Completion</div></div></div>
    <div class="sc"><div class="sc-ic ic-t"><i class="bx bx-money-withdraw"></i></div><div><div class="sc-v" style="font-size:13px">${fMsh(totalBudget)}</div><div class="sc-l">Total Budget</div></div></div>
    <div class="sc"><div class="sc-ic ic-p"><i class="bx bx-flag-alt"></i></div><div><div class="sc-v">${crit}</div><div class="sc-l">Critical Path</div></div></div>`;
}

function renderTable() {
  const cols = getColumns(activeTab);

  // Inject colgroup for fixed layout
  const tbl = document.getElementById('tbl');
  let cg = tbl.querySelector('colgroup');
  if (!cg) { cg = document.createElement('colgroup'); tbl.prepend(cg); }
  cg.innerHTML = cols.map(c => `<col class="${c.cls||''}">`).join('') + `<col class="col-act">`;

  // Render headers
  document.getElementById('tblHead').innerHTML = `<tr>
    ${cols.map(c => `<th data-col="${c.sort||''}" class="${c.cls||''} ${c.sort?'':'no-sort'} ${sortCol===c.sort?'sorted':''}">${c.label}${c.sort?` <i class="bx ${sortCol===c.sort?(sortDir==='asc'?'bx-sort-up':'bx-sort-down'):'bx-sort'} sic"></i>`:''}</th>`).join('')}
    <th class="no-sort col-act">Actions</th>
  </tr>`;
  // Attach sort events
  document.querySelectorAll('#tblHead th[data-col]').forEach(th => {
    th.addEventListener('click', () => {
      const c = th.dataset.col; if(!c) return;
      sortDir = sortCol===c ? (sortDir==='asc'?'desc':'asc') : 'asc';
      sortCol = c; page = 1; renderTable();
    });
  });

  const data = getSorted(getFiltered());
  const total = data.length, pages = Math.max(1, Math.ceil(total/PAGE_SIZE));
  if (page > pages) page = pages;
  const slice = data.slice((page-1)*PAGE_SIZE, page*PAGE_SIZE);

  const tb = document.getElementById('tbody');
  if (!slice.length) {
    tb.innerHTML = `<tr><td colspan="${cols.length+1}"><div class="empty"><i class="bx bx-bar-chart-alt-2"></i><p>No projects match your current filters.</p></div></td></tr>`;
  } else {
    tb.innerHTML = slice.map(p => `<tr>
      ${cols.map(c => `<td class="${c.cls||''}">${getCellVal(p, c.key)}</td>`).join('')}
      <td class="col-act">
        <div class="act-cell">
          <button class="btn btn-ghost btn-xs ionly" title="Preview" onclick="openPreview('${p.code}')"><i class="bx bx-show"></i></button>
          <button class="btn btn-ghost btn-xs ionly" title="Export PDF" onclick="toastExport('PDF','${p.code}')"><i class="bx bx-file-pdf" style="color:#DC2626"></i></button>
          <button class="btn btn-ghost btn-xs ionly" title="Export Excel" onclick="toastExport('Excel','${p.code}')"><i class="bx bx-spreadsheet" style="color:#2563EB"></i></button>
        </div>
      </td>
    </tr>`).join('');
  }

  // Pagination
  const s = (page-1)*PAGE_SIZE+1, e = Math.min(page*PAGE_SIZE, total);
  let btns = '';
  for (let i=1; i<=pages; i++) {
    if (i===1||i===pages||(i>=page-2&&i<=page+2)) btns += `<button class="pgb ${i===page?'active':''}" onclick="goPage(${i})">${i}</button>`;
    else if (i===page-3||i===page+3) btns += `<button class="pgb" disabled>…</button>`;
  }
  document.getElementById('pager').innerHTML = `
    <span>${total===0?'No results':`Showing ${s}–${e} of ${total} projects`}</span>
    <div class="pg-btns">
      <button class="pgb" onclick="goPage(${page-1})" ${page<=1?'disabled':''}><i class="bx bx-chevron-left"></i></button>
      ${btns}
      <button class="pgb" onclick="goPage(${page+1})" ${page>=pages?'disabled':''}><i class="bx bx-chevron-right"></i></button>
    </div>`;
}

function goPage(p) { page = p; renderTable(); }

/* ── SCHEDULED REPORTS ─────────────────────────────────── */
function renderSchedules() {
  const freqStyles = {Weekly:'fb-w', Monthly:'fb-m', Quarterly:'fb-q'};
  const icons = {'Project Status':'bx-bar-chart-alt-2','Budget vs. Actual':'bx-money-withdraw','RA 9184 Procurement':'bx-file-find','Delay & Risk Analysis':'bx-shield-x','Delivery Performance':'bx-truck','Milestone Completion':'bx-flag-alt','Cross-Zone Resource Utilization':'bx-world','Logistics Assignment':'bx-package'};
  const icBg = {'Project Status':'ic-b','Budget vs. Actual':'ic-t','RA 9184 Procurement':'ic-a','Delay & Risk Analysis':'ic-r','Delivery Performance':'ic-g','Milestone Completion':'ic-p','Cross-Zone Resource Utilization':'ic-d','Logistics Assignment':'ic-o'};
  document.getElementById('schedList').innerHTML = schedules.map(s => `
    <div class="sched-item">
      <div class="sched-ic ${icBg[s.type]||'ic-b'}"><i class="bx ${icons[s.type]||'bx-file'}"></i></div>
      <div class="sched-info">
        <div class="sched-name">${esc(s.name)} ${s.active ? '' : '<span style="font-size:10px;font-weight:600;background:#F3F4F6;color:#6B7280;padding:2px 7px;border-radius:6px;margin-left:4px">Paused</span>'}</div>
        <div class="sched-meta">
          <span><i class="bx bx-file" style="font-size:12px"></i>${esc(s.type)}</span>
          <span class="freq-badge ${freqStyles[s.freq]||'fb-w'}"><i class="bx bx-time-five" style="font-size:11px"></i>${s.freq}</span>
          <span><i class="bx bx-envelope" style="font-size:12px"></i>${s.recipients.length} recipient${s.recipients.length!==1?'s':''}</span>
          <span><i class="bx bx-export" style="font-size:12px"></i>${s.fmt}</span>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px">
        <div class="sched-next">Next: ${s.next}</div>
        <div style="display:flex;gap:6px">
          <button class="btn btn-ghost btn-xs ionly" title="${s.active?'Pause':'Resume'}" onclick="toggleSched(${s.id})"><i class="bx ${s.active?'bx-pause':'bx-play'}"></i></button>
          <button class="btn btn-ghost btn-xs ionly" title="Run now" onclick="runNow(${s.id})"><i class="bx bx-send"></i></button>
          <button class="btn btn-ghost btn-xs ionly" title="Delete" onclick="deleteSched(${s.id})" style="color:var(--red)"><i class="bx bx-trash"></i></button>
        </div>
      </div>
    </div>`).join('');
}

function toggleSched(id) {
  const s = schedules.find(x=>x.id===id); if (!s) return;
  s.active = !s.active;
  renderSchedules();
  toast(`Schedule "${s.name}" ${s.active?'resumed':'paused'}.`, 's');
}
function runNow(id) {
  const s = schedules.find(x=>x.id===id); if (!s) return;
  toast(`"${s.name}" report dispatched to ${s.recipients.length} recipient${s.recipients.length!==1?'s':''}.`,'s');
}
function deleteSched(id) {
  schedules = schedules.filter(x=>x.id!==id);
  renderSchedules();
  toast('Schedule removed.','s');
}

/* ── PREVIEW MODAL ─────────────────────────────────────── */
function openPreview(code) {
  const proj = PROJECTS.find(p=>p.code===code);
  if (!proj) return;
  document.getElementById('prevSub').textContent = `${REPORT_TYPES.find(r=>r.key===activeTab)?.label} — ${proj.name}`;
  const barData = [
    {label:'Budget Util.', val:Math.round(proj.actual/proj.budget*100), color:'#2E7D32'},
    {label:'Progress', val:proj.pct, color:'#2563EB'},
    {label:'Delivery Rate', val:proj.delivery, color:'#0D9488'},
    {label:'Milestone Done', val:Math.round(proj.done/proj.milestones*100), color:'#7C3AED'},
  ];
  document.getElementById('prevInner').innerHTML = `
    <div class="report-preview">
      <div class="rp-hd">
        <div class="rp-hd-logo">Microfinancial Management System · PLT</div>
        <div class="rp-hd-title">${esc(proj.name)}</div>
        <div class="rp-hd-sub"><span>${esc(proj.code)}</span> · <span>${esc(proj.type)}</span> · <span>${esc(proj.region)}</span></div>
        <div class="rp-hd-chips">
          <span class="rp-hd-chip">${REPORT_TYPES.find(r=>r.key===activeTab)?.label}</span>
          <span class="rp-hd-chip">${proj.status}</span>
          ${proj.critical ? '<span class="rp-hd-chip">Critical Path</span>' : ''}
          <span class="rp-hd-chip">Generated ${new Date().toLocaleDateString('en-PH',{month:'long',day:'numeric',year:'numeric'})}</span>
        </div>
      </div>
      <div class="prev-sec">
        <div class="prev-sec-title">Summary KPIs</div>
        <div class="prev-kpi-row">
          <div class="prev-kpi"><div class="v">${proj.pct}%</div><div class="l">Overall Progress</div></div>
          <div class="prev-kpi"><div class="v">${proj.done}/${proj.milestones}</div><div class="l">Milestones</div></div>
          <div class="prev-kpi"><div class="v">${fMsh(proj.budget)}</div><div class="l">Total Budget</div></div>
          <div class="prev-kpi"><div class="v">${proj.delivery}%</div><div class="l">Delivery Rate</div></div>
        </div>
      </div>
      <div class="prev-sec">
        <div class="prev-sec-title">Performance Overview</div>
        <div class="prev-chart-bar">
          ${barData.map(b => `<div class="chart-row">
            <div class="chart-label">${b.label}</div>
            <div class="chart-bar-bg"><div class="chart-bar-fill" style="width:${b.val}%;background:${b.color}">${b.val}%</div></div>
            <div class="chart-val" style="color:${b.color}">${b.val}%</div>
          </div>`).join('')}
        </div>
      </div>
      <div class="prev-sec">
        <div class="prev-sec-title">Project Details</div>
        <table class="prev-table">
          <thead><tr><th>Field</th><th>Value</th></tr></thead>
          <tbody>
            <tr><td>Project Manager</td><td>${esc(proj.pm)}</td></tr>
            <tr><td>Project Type</td><td>${esc(proj.type)}</td></tr>
            <tr><td>Zone / Region</td><td>${esc(proj.region)}</td></tr>
            <tr><td>Start Date</td><td>${fD(proj.start)}</td></tr>
            <tr><td>Target End Date</td><td>${fD(proj.end)}</td></tr>
            <tr><td>Risk Level</td><td>${proj.risk}</td></tr>
            <tr><td>Delayed Tasks</td><td>${proj.delayed > 0 ? proj.delayed + ' task(s)' : 'None'}</td></tr>
            <tr><td>Budget Disbursed</td><td>${fMsh(proj.actual)} of ${fMsh(proj.budget)}</td></tr>
            <tr><td>Personnel Assigned</td><td>${proj.resources} pax</td></tr>
            ${activeTab==='ra9184' ? `<tr><td>RA 9184 Proc. Records</td><td>${proj.procLinks} linked</td></tr>` : ''}
          </tbody>
        </table>
      </div>
      ${activeTab==='ra9184' ? `<div class="prev-sec" style="background:#FFFBEB">
        <div class="prev-sec-title" style="color:#D97706">RA 9184 Procurement Compliance</div>
        <div style="font-size:12.5px;color:#92400E;line-height:1.7">
          <p>This project has <strong>${proj.procLinks}</strong> procurement records linked to PhilGEPS registry. ${proj.procLinks === 0 ? '<strong>⚠ No records linked — compliance action required.</strong>' : 'All linked records are posted as required under RA 9184 §8 (Procurement Planning) and §21 (Advertisement).'}</p>
          <p style="margin-top:8px">BAC Resolution and Notice of Award are included in the procurement dossier. Annual Procurement Plan (APP) alignment: <strong>${proj.procLinks > 2 ? 'Confirmed' : 'Pending Verification'}</strong>.</p>
        </div>
      </div>` : ''}
    </div>`;
  document.getElementById('previewModal').classList.add('on');
}

/* ── EXPORT MODAL ─────────────────────────────────────── */
function openExportModal(fmt) {
  const icons = {PDF:'📄', Excel:'📊', CSV:'📑'};
  document.getElementById('expIcon').textContent = icons[fmt]||'📄';
  document.getElementById('expTitle').textContent = `Export as ${fmt}`;
  document.getElementById('expSub').textContent = `Configure your ${fmt} export settings`;
  window._exportFmt = fmt;
  document.getElementById('exportModal').classList.add('on');
}

document.getElementById('exportPdfBtn').addEventListener('click', () => openExportModal('PDF'));
document.getElementById('exportXlsBtn').addEventListener('click', () => openExportModal('Excel'));
document.getElementById('exportCsvBtn').addEventListener('click', () => openExportModal('CSV'));
document.getElementById('exportClose').addEventListener('click', () => document.getElementById('exportModal').classList.remove('on'));
document.getElementById('exportCancel').addEventListener('click', () => document.getElementById('exportModal').classList.remove('on'));
document.getElementById('exportModal').addEventListener('click', function(e) { if(e.target===this) this.classList.remove('on'); });
document.getElementById('exportConfirm').addEventListener('click', () => {
  const fmt = window._exportFmt||'PDF';
  document.getElementById('exportModal').classList.remove('on');
  toast(`${fmt} export generated and downloaded successfully.`, 's');
});

// Checkbox groups in modals
document.querySelectorAll('.chk-group').forEach(g => {
  g.querySelectorAll('.chk-opt').forEach(opt => {
    opt.addEventListener('click', function() {
      const cb = this.querySelector('input');
      cb.checked = !cb.checked;
      this.classList.toggle('selected', cb.checked);
    });
  });
});

/* ── PREVIEW MODAL CONTROLS ─────────────────────────────── */
document.getElementById('previewBtn').addEventListener('click', () => {
  const p = getFiltered()[0]||PROJECTS[0];
  openPreview(p.code);
});
document.getElementById('prevClose').addEventListener('click', () => document.getElementById('previewModal').classList.remove('on'));
document.getElementById('previewModal').addEventListener('click', function(e) { if(e.target===this) this.classList.remove('on'); });
document.getElementById('prevExport').addEventListener('click', () => {
  document.getElementById('previewModal').classList.remove('on');
  toast('PDF report downloaded successfully.','s');
});

/* ── SCHEDULE MODAL ─────────────────────────────────────── */
document.getElementById('schedBtn').addEventListener('click', () => openSchedModal());
document.getElementById('addSchedBtn').addEventListener('click', () => openSchedModal());
document.getElementById('schedClose').addEventListener('click', () => document.getElementById('schedModal').classList.remove('on'));
document.getElementById('schedCancel').addEventListener('click', () => document.getElementById('schedModal').classList.remove('on'));
document.getElementById('schedModal').addEventListener('click', function(e) { if(e.target===this) this.classList.remove('on'); });

document.getElementById('schEmailInput').addEventListener('keydown', function(e) {
  if (e.key === 'Enter' || e.key === ',') {
    e.preventDefault();
    const val = this.value.trim().replace(',','');
    if (val && val.includes('@') && !schedRecipients.includes(val)) {
      schedRecipients.push(val);
      renderSchedTags();
    }
    this.value = '';
  }
});

function renderSchedTags() {
  document.getElementById('schTags').innerHTML = schedRecipients.map((r,i) =>
    `<span class="rtag">${esc(r)}<button onclick="removeRecipient(${i})"><i class="bx bx-x"></i></button></span>`
  ).join('');
}
function removeRecipient(i) { schedRecipients.splice(i,1); renderSchedTags(); }

function openSchedModal() {
  schedRecipients = [];
  renderSchedTags();
  document.getElementById('schName').value = '';
  document.getElementById('schType').value = '';
  document.getElementById('schedModal').classList.add('on');
  setTimeout(() => document.getElementById('schName').focus(), 250);
}

document.getElementById('schedSave').addEventListener('click', () => {
  const name = document.getElementById('schName').value.trim();
  const type = document.getElementById('schType').value;
  const freq = document.getElementById('schFreq').value;
  const day  = document.getElementById('schDay').value;
  const fmt  = document.getElementById('schFmt').value;
  if (!name) return toast('Schedule name is required.','w');
  if (!type) return toast('Please select a report type.','w');
  const next = freq==='Weekly' ? 'Mar 17, 2025' : freq==='Monthly' ? 'Apr 1, 2025' : 'Jun 30, 2025';
  schedules.push({ id: Date.now(), name, type, freq, day, fmt, recipients: schedRecipients.length ? [...schedRecipients] : ['default@gov.ph'], active:true, next });
  document.getElementById('schedModal').classList.remove('on');
  renderSchedules();
  toast(`Scheduled report "${name}" created.`,'s');
});

/* ── TOAST ─────────────────────────────────────────────── */
function toastExport(fmt, code) {
  toast(`${fmt} export for ${code} initiated.`,'s');
}
function toast(msg, type='s') {
  const ic = {s:'bx-check-circle',w:'bx-error',d:'bx-error-circle'};
  const el = document.createElement('div');
  el.className = `toast t${type}`;
  el.innerHTML = `<i class="bx ${ic[type]}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(() => { el.classList.add('out'); setTimeout(()=>el.remove(),320); }, 3500);
}

/* ── FILTER LISTENERS ─────────────────────────────────── */
['srch','fStatus','fType','fRegion','fCritPath','fDateFrom','fDateTo','fBudgetMin','fBudgetMax'].forEach(id =>
  document.getElementById(id).addEventListener('input', () => { page=1; renderKpis(); renderTable(); })
);

/* ── INIT ─────────────────────────────────────────────── */
renderStats();
renderTabs();
renderKpis();
renderTable();
renderSchedules();
</script>
</body>
</html>