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
    <title>Asset Reports — ALMS</title>
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/Log1/css/base.css">
    <link rel="stylesheet" href="/Log1/css/sidebar.css">
    <link rel="stylesheet" href="/Log1/css/header.css">
    <style>
    /* ── TOKENS ─────────────────────────────────────────────── */
    #mainContent, #scheduleModal, #previewModal, .ar-toasts {
      --s: #fff;
      --bd: rgba(46,125,50,.13);
      --bdm: rgba(46,125,50,.26);
      --t1: var(--text-primary);
      --t2: var(--text-secondary);
      --t3: #9EB0A2;
      --hbg: var(--hover-bg-light);
      --bg: var(--bg-color);
      --grn: var(--primary-color);
      --gdk: var(--primary-dark);
      --red: #DC2626;
      --amb: #D97706;
      --blu: #2563EB;
      --tel: #0D9488;
      --pur: #7C3AED;
      --shmd: 0 4px 20px rgba(46,125,50,.12);
      --shlg: 0 24px 60px rgba(0,0,0,.22);
      --rad: 12px;
      --tr: var(--transition);
    }
    #mainContent *, #scheduleModal *, #previewModal *, .ar-toasts * { box-sizing: border-box; }

    /* ── LAYOUT ───────────────────────────────────────────────── */
    .ar-wrap { max-width: 1440px; margin: 0 auto; padding: 0 0 4rem; }
    .ar-ph { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 26px; animation: UP .4s both; }
    .ar-ph .ey { font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: var(--grn); margin-bottom: 4px; }
    .ar-ph h1  { font-size: 26px; font-weight: 800; color: var(--t1); line-height: 1.15; }
    .ar-ph-r   { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

    /* ── BUTTONS ─────────────────────────────────────────────── */
    .btn { display: inline-flex; align-items: center; gap: 7px; font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600; padding: 9px 18px; border-radius: 10px; border: none; cursor: pointer; transition: var(--tr); white-space: nowrap; }
    .btn-primary { background: var(--grn); color: #fff; box-shadow: 0 2px 8px rgba(46,125,50,.32); }
    .btn-primary:hover { background: var(--gdk); transform: translateY(-1px); }
    .btn-ghost   { background: var(--s); color: var(--t2); border: 1px solid var(--bdm); }
    .btn-ghost:hover { background: var(--hbg); color: var(--t1); }
    .btn-pdf  { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
    .btn-pdf:hover  { background: #FECACA; }
    .btn-excel{ background: #DCFCE7; color: #166534; border: 1px solid #BBF7D0; }
    .btn-excel:hover{ background: #BBF7D0; }
    .btn-csv  { background: #EFF6FF; color: #1D4ED8; border: 1px solid #BFDBFE; }
    .btn-csv:hover  { background: #DBEAFE; }
    .btn-sched{ background: #F5F3FF; color: #6D28D9; border: 1px solid #DDD6FE; }
    .btn-sched:hover{ background: #EDE9FE; }
    .btn-sm { font-size: 12px; padding: 6px 13px; }
    .btn-xs { font-size: 11px; padding: 4px 9px; border-radius: 7px; }
    .btn-danger { background: #FEE2E2; color: var(--red); border: 1px solid #FECACA; }
    .btn-danger:hover { background: #FECACA; }

    /* ── MAIN LAYOUT ─────────────────────────────────────────── */
    .ar-grid { display: flex; flex-direction: column; gap: 16px; }

    /* ── LEFT PANEL (now top bar) ─────────────────────────────── */
    .ar-left { display: flex; flex-direction: column; gap: 12px; }

    /* Report type picker — horizontal pill row */
    .panel-card { background: var(--s); border: 1px solid var(--bd); border-radius: 16px; overflow: hidden; box-shadow: var(--shmd); animation: UP .4s both; }
    .panel-hdr { padding: 12px 18px; border-bottom: 1px solid var(--bd); background: var(--bg); display: flex; align-items: center; gap: 8px; }
    .panel-hdr-icon { width: 26px; height: 26px; border-radius: 7px; background: #DCFCE7; color: #166534; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; }
    .panel-hdr-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--t2); }
    /* Horizontal scrollable pill row */
    .report-type-list { padding: 10px 14px; display: flex; flex-direction: row; gap: 6px; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
    .report-type-list::-webkit-scrollbar { display: none; }
    .rt-item { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 22px; cursor: pointer; transition: all .15s; border: 1.5px solid var(--bdm); background: var(--s); white-space: nowrap; flex-shrink: 0; }
    .rt-item:hover { background: var(--hbg); border-color: rgba(46,125,50,.3); }
    .rt-item.active { background: linear-gradient(135deg,#F0FDF4,#DCFCE7); border-color: rgba(46,125,50,.4); box-shadow: 0 1px 6px rgba(46,125,50,.12); }
    .rt-ic { width: 24px; height: 24px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
    .rt-item.active .rt-ic { transform: scale(1.05); }
    .rt-info { flex: 1; min-width: 0; }
    .rt-name { font-size: 12.5px; font-weight: 600; color: var(--t1); }
    .rt-desc { display: none; }
    .rt-arrow { display: none; }

    /* Filters panel — horizontal bar */
    .filter-body { padding: 12px 14px; display: flex; flex-direction: row; align-items: flex-end; gap: 10px; flex-wrap: wrap; }
    .fg { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 130px; }
    .fg.fg-date { min-width: 200px; }
    .fg.fg-cost { min-width: 190px; }
    .fl { font-size: 10.5px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--t2); }
    .fi,.fs { font-family: 'Inter', sans-serif; font-size: 13px; padding: 8px 11px; border: 1px solid var(--bdm); border-radius: 10px; background: var(--s); color: var(--t1); outline: none; transition: var(--tr); width: 100%; }
    .fi:focus,.fs:focus { border-color: var(--grn); box-shadow: 0 0 0 3px rgba(46,125,50,.10); }
    .fs { appearance: none; cursor: pointer; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%235D6F62' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 8px center; padding-right: 26px; }
    .date-row { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .cost-row { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .cost-row .fi { padding-left: 22px; }
    .cost-prefix { position: relative; }
    .cost-prefix::before { content: '₱'; position: absolute; left: 8px; top: 50%; transform: translateY(-50%); font-size: 11.5px; color: var(--t3); font-weight: 600; pointer-events: none; z-index: 1; }
    .filter-actions { padding: 10px 14px; border-top: 1px solid var(--bd); background: var(--bg); display: flex; gap: 8px; justify-content: flex-end; }
    .filter-actions .btn { flex: 0; }

    /* ── RIGHT PANEL (now below) ──────────────────────────────── */
    .ar-right { display: flex; flex-direction: column; gap: 16px; }

    /* Quick stats */
    .ar-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; animation: UP .4s .05s both; }
    .sc { background: var(--s); border: 1px solid var(--bd); border-radius: var(--rad); padding: 14px 16px; box-shadow: 0 1px 4px rgba(46,125,50,.07); display: flex; align-items: center; gap: 12px; }
    .sc-ic { width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 17px; }
    .ic-b{background:#EFF6FF;color:var(--blu)} .ic-a{background:#FEF3C7;color:var(--amb)}
    .ic-g{background:#DCFCE7;color:#166534}    .ic-r{background:#FEE2E2;color:var(--red)}
    .ic-t{background:#CCFBF1;color:var(--tel)} .ic-p{background:#F5F3FF;color:#6D28D9}
    .sc-v { font-size: 20px; font-weight: 800; color: var(--t1); line-height: 1; }
    .sc-l { font-size: 11px; color: var(--t2); margin-top: 2px; }

    /* Report workspace */
    .report-workspace { background: var(--s); border: 1px solid var(--bd); border-radius: 16px; overflow: hidden; box-shadow: var(--shmd); animation: UP .4s .1s both; }
    .rw-header { padding: 18px 22px; border-bottom: 1px solid var(--bd); background: var(--bg); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
    .rw-title-group { display: flex; align-items: center; gap: 12px; }
    .rw-type-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 5px 12px; border-radius: 20px; }
    .rw-meta { font-size: 12px; color: var(--t3); margin-top: 3px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .rw-meta span { display: inline-flex; align-items: center; gap: 4px; }
    .rw-meta i { font-size: 13px; color: var(--t3); }
    .rw-actions { display: flex; gap: 8px; flex-wrap: wrap; }

    /* Report content area */
    .rw-body { padding: 22px; }

    /* Summary bar */
    .report-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px,1fr)); gap: 10px; margin-bottom: 22px; padding: 16px; background: linear-gradient(135deg,#F0FDF4,#F8FFF8); border: 1px solid rgba(46,125,50,.15); border-radius: 12px; }
    .rs-kv { display: flex; flex-direction: column; gap: 2px; }
    .rs-kv .kv-v { font-size: 18px; font-weight: 800; color: var(--t1); line-height: 1; }
    .rs-kv .kv-v.mono { font-family: 'DM Mono', monospace; font-size: 14px; color: var(--grn); }
    .rs-kv .kv-l { font-size: 10.5px; color: var(--t3); text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }

    /* Chart area */
    .chart-area { margin-bottom: 22px; }
    .chart-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--t3); margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
    .chart-title i { font-size: 14px; }
    .bar-chart { display: flex; flex-direction: column; gap: 8px; }
    .bar-row { display: flex; align-items: center; gap: 10px; font-size: 12px; }
    .bar-label { min-width: 130px; color: var(--t2); font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .bar-track { flex: 1; height: 8px; background: var(--bd); border-radius: 4px; overflow: hidden; }
    .bar-fill  { height: 100%; border-radius: 4px; transition: width .6s ease; }
    .bar-val   { min-width: 60px; text-align: right; font-family: 'DM Mono', monospace; font-size: 11.5px; font-weight: 600; color: var(--t1); }

    /* Report data table */
    .report-tbl-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid var(--bd); }
    .report-tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .report-tbl thead th { font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--t2); padding: 10px 14px; background: var(--bg); border-bottom: 1px solid var(--bd); text-align: left; white-space: nowrap; }
    .report-tbl tbody tr { border-bottom: 1px solid var(--bd); transition: background .12s; }
    .report-tbl tbody tr:last-child { border-bottom: none; }
    .report-tbl tbody tr:hover { background: var(--hbg); }
    .report-tbl tbody td { padding: 11px 14px; vertical-align: middle; }
    .report-tbl tfoot td { padding: 11px 14px; font-weight: 700; border-top: 2px solid var(--bd); background: var(--bg); font-size: 12px; }
    .cell-mono { font-family: 'DM Mono', monospace; font-size: 11.5px; font-weight: 600; color: var(--t1); }
    .cell-id   { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--t3); }
    .cell-name { font-weight: 600; color: var(--t1); font-size: 12.5px; }
    .cell-sub  { font-size: 11px; color: var(--t3); margin-top: 1px; }
    .badge { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
    .badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
    .b-active  { background: #DCFCE7; color: #166534; }
    .b-idle    { background: #FEF3C7; color: #92400E; }
    .b-retired { background: #F3F4F6; color: #374151; }
    .b-pending { background: #FEF3C7; color: #92400E; }
    .b-done    { background: #CCFBF1; color: #115E59; }
    .b-overdue { background: #FEE2E2; color: #991B1B; }
    .b-comp    { background: #DCFCE7; color: #166534; }
    .zone-dot  { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; }
    .rw-empty  { padding: 72px 20px; text-align: center; color: var(--t3); }
    .rw-empty i { font-size: 52px; display: block; margin-bottom: 12px; color: #C8E6C9; }

    /* Pagination */
    .tbl-footer { display: flex; align-items: center; justify-content: space-between; padding: 12px 0 0; font-size: 13px; color: var(--t2); flex-wrap: wrap; gap: 8px; }
    .pg-btns { display: flex; gap: 5px; }
    .pgb { width: 30px; height: 30px; border-radius: 7px; border: 1px solid var(--bdm); background: var(--s); font-family: 'Inter',sans-serif; font-size: 12px; cursor: pointer; display: grid; place-content: center; transition: var(--tr); color: var(--t1); }
    .pgb:hover  { background: var(--hbg); border-color: var(--grn); color: var(--grn); }
    .pgb.active { background: var(--grn); border-color: var(--grn); color: #fff; }
    .pgb:disabled { opacity: .4; pointer-events: none; }

    /* ── SCHEDULED REPORTS ────────────────────────────────────── */
    .sched-wrap { background: var(--s); border: 1px solid var(--bd); border-radius: 16px; overflow: hidden; box-shadow: var(--shmd); animation: UP .4s .15s both; }
    .sched-hdr { padding: 16px 22px; border-bottom: 1px solid var(--bd); background: var(--bg); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
    .sched-hdr-left { display: flex; align-items: center; gap: 10px; }
    .sched-hdr-icon { width: 32px; height: 32px; border-radius: 9px; background: #F5F3FF; color: #6D28D9; display: flex; align-items: center; justify-content: center; font-size: 16px; }
    .sched-hdr-title { font-size: 14px; font-weight: 700; color: var(--t1); }
    .sched-hdr-sub   { font-size: 11px; color: var(--t3); margin-top: 1px; }
    .sa-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; background: linear-gradient(135deg,#FEF3C7,#FDE68A); color: #92400E; border: 1px solid #FCD34D; border-radius: 6px; padding: 3px 9px; }
    .sched-list { padding: 12px; display: flex; flex-direction: column; gap: 8px; }
    .sched-item { background: var(--bg); border: 1px solid var(--bd); border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; gap: 14px; transition: all .15s; }
    .sched-item:hover { border-color: rgba(46,125,50,.3); background: #F8FFF8; }
    .sched-ic { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .sched-info { flex: 1; min-width: 0; }
    .sched-name { font-size: 13px; font-weight: 600; color: var(--t1); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .sched-meta { font-size: 11.5px; color: var(--t3); margin-top: 3px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .sched-meta span { display: inline-flex; align-items: center; gap: 3px; }
    .sched-meta i { font-size: 13px; }
    .sched-freq { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; }
    .sf-weekly  { background: #EFF6FF; color: #1D4ED8; }
    .sf-monthly { background: #F5F3FF; color: #6D28D9; }
    .sf-daily   { background: #DCFCE7; color: #166534; }
    .sched-toggle { position: relative; width: 38px; height: 22px; flex-shrink: 0; }
    .sched-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
    .sched-toggle-track { position: absolute; inset: 0; border-radius: 11px; background: #D1D5DB; cursor: pointer; transition: all .2s; }
    .sched-toggle input:checked + .sched-toggle-track { background: var(--grn); }
    .sched-toggle-track::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.2); transition: all .2s; }
    .sched-toggle input:checked + .sched-toggle-track::after { transform: translateX(16px); }
    .sched-actions { display: flex; gap: 6px; flex-shrink: 0; }
    .sched-empty { padding: 32px 20px; text-align: center; color: var(--t3); font-size: 13px; }

    /* ── SCHEDULE MODAL ───────────────────────────────────────── */
    #scheduleModal {
      position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9050;
      display: flex; align-items: center; justify-content: center; padding: 20px;
      opacity: 0; pointer-events: none; transition: opacity .25s;
    }
    #scheduleModal.on { opacity: 1; pointer-events: all; }
    .sm-box {
      background: #fff; border-radius: 20px;
      width: 520px; max-width: 100%; max-height: 90vh;
      display: flex; flex-direction: column;
      box-shadow: 0 20px 60px rgba(0,0,0,.22); overflow: hidden;
    }
    .sm-hdr { padding: 22px 24px 20px; border-bottom: 1px solid rgba(46,125,50,.14); background: var(--bg-color); display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
    .sm-title { font-size: 17px; font-weight: 700; color: var(--t1); }
    .sm-sub   { font-size: 12px; color: var(--t3); margin-top: 3px; }
    .sm-close { width: 34px; height: 34px; border-radius: 8px; border: 1px solid rgba(46,125,50,.22); background: #fff; cursor: pointer; display: grid; place-content: center; font-size: 19px; color: var(--t2); transition: all .15s; flex-shrink: 0; }
    .sm-close:hover { background: #FEE2E2; color: var(--red); border-color: #FECACA; }
    .sm-body { flex: 1; overflow-y: auto; padding: 22px 24px; display: flex; flex-direction: column; gap: 14px; }
    .sm-body::-webkit-scrollbar { width: 4px; }
    .sm-body::-webkit-scrollbar-thumb { background: rgba(46,125,50,.2); border-radius: 4px; }
    .sm-foot { padding: 14px 24px; border-top: 1px solid rgba(46,125,50,.14); background: var(--bg-color); display: flex; gap: 8px; justify-content: flex-end; }
    .fr { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .fta { font-family: 'Inter',sans-serif; font-size: 13px; padding: 9px 12px; border: 1px solid var(--bdm); border-radius: 10px; background: var(--s); color: var(--t1); outline: none; transition: var(--tr); width: 100%; resize: vertical; min-height: 68px; }
    .fta:focus { border-color: var(--grn); box-shadow: 0 0 0 3px rgba(46,125,50,.10); }
    .email-tags { display: flex; flex-wrap: wrap; gap: 5px; padding: 8px 10px; border: 1px solid var(--bdm); border-radius: 10px; background: var(--s); cursor: text; min-height: 42px; transition: var(--tr); }
    .email-tags:focus-within { border-color: var(--grn); box-shadow: 0 0 0 3px rgba(46,125,50,.10); }
    .email-tag { background: #DCFCE7; color: #166534; border: 1px solid #BBF7D0; border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
    .email-tag .et-rm { cursor: pointer; font-size: 14px; opacity: .6; transition: opacity .1s; }
    .email-tag .et-rm:hover { opacity: 1; }
    .email-input { border: none; outline: none; font-family: 'Inter',sans-serif; font-size: 13px; color: var(--t1); background: transparent; min-width: 160px; flex: 1; padding: 2px 2px; }
    .export-fmt-row { display: flex; gap: 8px; flex-wrap: wrap; }
    .fmt-opt { display: flex; align-items: center; gap: 6px; padding: 7px 12px; border: 1.5px solid var(--bdm); border-radius: 8px; cursor: pointer; font-size: 12.5px; font-weight: 600; color: var(--t2); transition: all .15s; }
    .fmt-opt input { width: 14px; height: 14px; accent-color: var(--grn); cursor: pointer; }
    .fmt-opt:has(input:checked) { border-color: var(--grn); background: #F0FDF4; color: var(--grn); }

    /* ── PREVIEW MODAL ────────────────────────────────────────── */
    #previewModal {
      position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9100;
      display: flex; align-items: center; justify-content: center; padding: 20px;
      opacity: 0; pointer-events: none; transition: opacity .25s;
    }
    #previewModal.on { opacity: 1; pointer-events: all; }
    .pm-box {
      background: #fff; border-radius: 20px;
      width: 680px; max-width: 100%; max-height: 88vh;
      display: flex; flex-direction: column;
      box-shadow: 0 24px 60px rgba(0,0,0,.24); overflow: hidden;
    }
    .pm-hdr { padding: 20px 24px 18px; border-bottom: 1px solid rgba(46,125,50,.14); background: var(--bg-color); display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .pm-title { font-size: 16px; font-weight: 700; color: var(--t1); }
    .pm-body { flex: 1; overflow-y: auto; padding: 24px; }
    .pm-body::-webkit-scrollbar { width: 4px; }
    .pm-body::-webkit-scrollbar-thumb { background: rgba(46,125,50,.2); border-radius: 4px; }
    /* Simulated PDF/report preview */
    .preview-doc { background: #fff; border: 1px solid #E5E7EB; border-radius: 10px; padding: 28px 32px; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
    .preview-doc-hdr { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #E5E7EB; }
    .preview-logo { display: flex; align-items: center; gap: 10px; }
    .preview-logo-ic { width: 38px; height: 38px; border-radius: 10px; background: var(--primary-color, #2E7D32); display: flex; align-items: center; justify-content: center; }
    .preview-logo-ic i { font-size: 20px; color: #fff; }
    .preview-logo-text .plt1 { font-size: 14px; font-weight: 800; color: #1A2E1C; }
    .preview-logo-text .plt2 { font-size: 11px; color: #9EB0A2; }
    .preview-doc-meta { text-align: right; font-size: 11px; color: #9EB0A2; line-height: 1.7; }
    .preview-doc-meta strong { color: #374151; font-size: 12px; }
    .preview-section { margin-bottom: 20px; }
    .preview-section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #9EB0A2; margin-bottom: 10px; }
    .preview-kpis { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-bottom: 20px; }
    .preview-kpi { background: #F4F8F4; border-radius: 8px; padding: 12px 14px; }
    .preview-kpi .pk-v { font-size: 20px; font-weight: 800; color: #1A2E1C; }
    .preview-kpi .pk-v.money { font-family: 'DM Mono',monospace; font-size: 14px; color: #2E7D32; }
    .preview-kpi .pk-l { font-size: 10px; color: #9EB0A2; text-transform: uppercase; letter-spacing: .06em; margin-top: 2px; }
    .preview-mini-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
    .preview-mini-tbl th { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #9EB0A2; padding: 7px 10px; background: #F4F8F4; border-bottom: 1px solid #E5E7EB; text-align: left; }
    .preview-mini-tbl td { padding: 8px 10px; border-bottom: 1px solid #F3F4F6; color: #374151; }
    .preview-mini-tbl tr:last-child td { border-bottom: none; }
    .pm-foot { padding: 14px 24px; border-top: 1px solid rgba(46,125,50,.14); background: var(--bg-color); display: flex; gap: 8px; justify-content: flex-end; }

    /* ── TOAST ───────────────────────────────────────────────── */
    .ar-toasts { position: fixed; bottom: 28px; right: 28px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; pointer-events: none; }
    .toast { background: #0A1F0D; color: #fff; padding: 12px 18px; border-radius: 10px; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 10px; box-shadow: var(--shlg); pointer-events: all; min-width: 220px; animation: TIN .3s ease; }
    .toast.ts { background: var(--grn); } .toast.tw { background: var(--amb); } .toast.td { background: var(--red); }
    .toast.out { animation: TOUT .3s ease forwards; }

    @keyframes UP   { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
    @keyframes TIN  { from{opacity:0;transform:translateY(8px)}  to{opacity:1;transform:translateY(0)} }
    @keyframes TOUT { from{opacity:1;transform:translateY(0)}    to{opacity:0;transform:translateY(8px)} }
    @keyframes SHK  { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-5px)} 40%,80%{transform:translateX(5px)} }

    @media(max-width:900px){
      .ar-stats { grid-template-columns: repeat(2,1fr); }
      .preview-kpis { grid-template-columns: repeat(2,1fr); }
      .filter-body { gap: 8px; }
      .fg { min-width: 120px; }
    }
    @media(max-width:600px){
      .fr { grid-template-columns: 1fr; }
      .ar-stats { grid-template-columns: repeat(2,1fr); }
      .rw-actions { gap: 6px; }
      .fg { min-width: 100%; }
    }
    </style>
</head>
<body>
<?php /* sidebar and header are already included above */ ?>
<main class="main-content" id="mainContent">

<div class="ar-wrap">

  <!-- PAGE HEADER -->
  <div class="ar-ph">
    <div>
      <p class="ey">ALMS · Asset Lifecycle &amp; Maintenance</p>
      <h1>Asset Reports</h1>
    </div>
    <div class="ar-ph-r">
      <button class="btn btn-sched" id="openScheduleBtn"><i class="bx bx-calendar-plus"></i> New Scheduled Report</button>
      <button class="btn btn-primary" id="generateBtn"><i class="bx bx-play"></i> Generate Report</button>
    </div>
  </div>

  <!-- MAIN GRID -->
  <div class="ar-grid">

      <!-- Report Type Selector -->
      <div class="panel-card" style="animation-delay:.02s">
        <div class="panel-hdr">
          <div class="panel-hdr-icon"><i class="bx bx-file-blank"></i></div>
          <span class="panel-hdr-title">Report Type</span>
        </div>
        <div class="report-type-list" id="reportTypeList"></div>
      </div>

      <!-- Filters -->
      <div class="panel-card" style="animation-delay:.06s">
        <div class="panel-hdr">
          <div class="panel-hdr-icon"><i class="bx bx-filter-alt"></i></div>
          <span class="panel-hdr-title">Filters</span>
        </div>
        <div class="filter-body">
          <div class="fg fg-date">
            <label class="fl">Date Range</label>
            <div class="date-row">
              <input type="date" class="fi" id="fDateFrom" title="From">
              <input type="date" class="fi" id="fDateTo"   title="To">
            </div>
          </div>
          <div class="fg">
            <label class="fl">Zone / Department</label>
            <select class="fs" id="fZone">
              <option value="">All Zones</option>
              <option>Zone A – Warehouse</option>
              <option>Zone B – Field Operations</option>
              <option>Zone C – Admin Complex</option>
              <option>Zone D – Construction Site</option>
              <option>Zone E – Fleet Depot</option>
              <option>Zone F – IT Infrastructure</option>
            </select>
          </div>
          <div class="fg">
            <label class="fl">Category</label>
            <select class="fs" id="fCategory">
              <option value="">All Categories</option>
              <option>Heavy Equipment</option>
              <option>Vehicles</option>
              <option>IT Infrastructure</option>
              <option>Power Systems</option>
              <option>Safety Equipment</option>
              <option>Tools &amp; Machinery</option>
              <option>Office Furniture</option>
            </select>
          </div>
          <div class="fg">
            <label class="fl">Status</label>
            <select class="fs" id="fStatus">
              <option value="">All Statuses</option>
              <option>Active</option>
              <option>Under Maintenance</option>
              <option>Idle</option>
              <option>Retired</option>
              <option>Disposed</option>
            </select>
          </div>
          <div class="fg">
            <label class="fl">Technician</label>
            <select class="fs" id="fTech">
              <option value="">All Technicians</option>
              <option>Rodel Bautista</option>
              <option>Mark Ocampo</option>
              <option>Carlo Mendoza</option>
              <option>Pedro Reyes</option>
              <option>Jun Santos</option>
              <option>Arnel Cruz</option>
            </select>
          </div>
          <div class="fg fg-cost">
            <label class="fl">Cost Range (₱)</label>
            <div class="cost-row">
              <div class="cost-prefix"><input type="number" class="fi" id="fCostMin" placeholder="Min" min="0"></div>
              <div class="cost-prefix"><input type="number" class="fi" id="fCostMax" placeholder="Max" min="0"></div>
            </div>
          </div>
        </div>
        <div class="filter-actions">
          <button class="btn btn-ghost btn-sm" id="clearFiltersBtn"><i class="bx bx-x"></i> Clear</button>
          <button class="btn btn-primary btn-sm" id="applyFiltersBtn"><i class="bx bx-check"></i> Apply</button>
        </div>
      </div>

      <!-- Quick Stats -->
      <div class="ar-stats" id="statsBar"></div>

      <!-- Report Workspace -->
      <div class="report-workspace">
        <div class="rw-header">
          <div>
            <div class="rw-title-group">
              <span class="rw-type-badge" id="rwBadge"></span>
              <span style="font-size:16px;font-weight:700;color:var(--t1)" id="rwTitle">Asset Inventory Report</span>
            </div>
            <div class="rw-meta" id="rwMeta"></div>
          </div>
          <div class="rw-actions">
            <button class="btn btn-ghost btn-sm" id="previewBtn"><i class="bx bx-file-find"></i> Preview</button>
            <button class="btn btn-pdf btn-sm" id="exportPdfBtn"><i class="bx bx-file-pdf"></i> PDF</button>
            <button class="btn btn-excel btn-sm" id="exportExcelBtn"><i class="bx bxs-file-export"></i> Excel</button>
            <button class="btn btn-csv btn-sm" id="exportCsvBtn"><i class="bx bx-export"></i> CSV</button>
          </div>
        </div>
        <div class="rw-body" id="reportBody"></div>
      </div>

      <!-- Scheduled Reports -->
      <div class="sched-wrap">
        <div class="sched-hdr">
          <div class="sched-hdr-left">
            <div class="sched-hdr-icon"><i class="bx bx-calendar-check"></i></div>
            <div>
              <div class="sched-hdr-title">Scheduled Reports</div>
              <div class="sched-hdr-sub">Auto-generated &amp; emailed to stakeholders</div>
            </div>
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <span class="sa-badge"><i class="bx bx-shield-quarter"></i> Super Admin</span>
            <button class="btn btn-sched btn-sm" id="addSchedBtn"><i class="bx bx-plus"></i> Add</button>
          </div>
        </div>
        <div class="sched-list" id="schedList"></div>
      </div><!-- /.sched-wrap -->

  </div><!-- /.ar-grid -->

</div><!-- /.ar-wrap -->

<!-- TOAST CONTAINER -->
<div class="ar-toasts" id="toastWrap"></div>

<!-- ═══════════════════════════════════════
     SCHEDULE REPORT MODAL
     ═══════════════════════════════════════ -->
<div id="scheduleModal">
  <div class="sm-box">
    <div class="sm-hdr">
      <div>
        <div class="sm-title" id="smTitle">New Scheduled Report</div>
        <div class="sm-sub">Configure auto-generation &amp; email delivery</div>
      </div>
      <button class="sm-close" id="smClose"><i class="bx bx-x"></i></button>
    </div>
    <div class="sm-body">
      <div class="fg">
        <label class="fl">Schedule Name <span style="color:#DC2626">*</span></label>
        <input type="text" class="fi" id="smName" placeholder="e.g. Monthly Maintenance Summary">
      </div>
      <div class="fr">
        <div class="fg">
          <label class="fl">Report Type <span style="color:#DC2626">*</span></label>
          <select class="fs" id="smReportType"></select>
        </div>
        <div class="fg">
          <label class="fl">Frequency <span style="color:#DC2626">*</span></label>
          <select class="fs" id="smFreq">
            <option value="Daily">Daily</option>
            <option value="Weekly" selected>Weekly</option>
            <option value="Monthly">Monthly</option>
          </select>
        </div>
      </div>
      <div class="fr">
        <div class="fg">
          <label class="fl">Day / Date</label>
          <select class="fs" id="smDay">
            <option>Monday</option><option>Tuesday</option><option>Wednesday</option>
            <option>Thursday</option><option>Friday</option><option>Saturday</option><option>Sunday</option>
          </select>
        </div>
        <div class="fg">
          <label class="fl">Send Time</label>
          <input type="time" class="fi" id="smTime" value="08:00">
        </div>
      </div>
      <div class="fg">
        <label class="fl">Export Format</label>
        <div class="export-fmt-row">
          <label class="fmt-opt"><input type="checkbox" name="smFmt" value="PDF" checked> PDF</label>
          <label class="fmt-opt"><input type="checkbox" name="smFmt" value="Excel"> Excel</label>
          <label class="fmt-opt"><input type="checkbox" name="smFmt" value="CSV"> CSV</label>
        </div>
      </div>
      <div class="fg">
        <label class="fl">Email Recipients <span style="color:#DC2626">*</span></label>
        <div class="email-tags" id="emailTagsWrap">
          <input type="text" class="email-input" id="emailInput" placeholder="Type email and press Enter…">
        </div>
        <div style="font-size:11px;color:var(--t3);margin-top:4px">Press Enter or comma to add each recipient</div>
      </div>
      <div class="fg">
        <label class="fl">Zone / Department Filter</label>
        <select class="fs" id="smZone">
          <option value="">All Zones</option>
          <option>Zone A – Warehouse</option><option>Zone B – Field Operations</option>
          <option>Zone C – Admin Complex</option><option>Zone D – Construction Site</option>
          <option>Zone E – Fleet Depot</option><option>Zone F – IT Infrastructure</option>
        </select>
      </div>
      <div class="fg">
        <label class="fl">Notes</label>
        <textarea class="fta" id="smNotes" placeholder="Additional instructions for report recipients…"></textarea>
      </div>
    </div>
    <div class="sm-foot">
      <button class="btn btn-ghost btn-sm" id="smCancel">Cancel</button>
      <button class="btn btn-primary btn-sm" id="smSave"><i class="bx bx-save"></i> Save Schedule</button>
    </div>
  </div>
</div>

<!-- PREVIEW MODAL -->
<div id="previewModal">
  <div class="pm-box">
    <div class="pm-hdr">
      <div>
        <div class="pm-title" id="pmTitle">Report Preview</div>
        <div style="font-size:11.5px;color:var(--t3);margin-top:3px" id="pmSub"></div>
      </div>
      <button class="sm-close" id="pmClose"><i class="bx bx-x"></i></button>
    </div>
    <div class="pm-body" id="pmBody"></div>
    <div class="pm-foot">
      <button class="btn btn-ghost btn-sm" id="pmCancel">Close</button>
      <button class="btn btn-pdf btn-sm" id="pmExportPdf"><i class="bx bx-file-pdf"></i> Export PDF</button>
    </div>
  </div>
</div>

</main>

<script>
/* ══════════════════════════════════════════════════════════
   STATIC DATA
   ══════════════════════════════════════════════════════════ */
const ZONE_COLORS = {
  'Zone A – Warehouse':'#2E7D32','Zone B – Field Operations':'#2563EB',
  'Zone C – Admin Complex':'#D97706','Zone D – Construction Site':'#DC2626',
  'Zone E – Fleet Depot':'#0D9488','Zone F – IT Infrastructure':'#7C3AED',
};
const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const fM  = n => '₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const fD  = d => { if(!d)return'—'; return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); };
const nowStr = () => new Date().toISOString().split('T')[0];
const nowTS  = () => new Date().toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'});

/* Report Types */
const REPORT_TYPES = [
  { id:'inventory',    name:'Asset Inventory',         desc:'Full register of all assets',          icon:'bx-list-ul',      ic:'ic-b', color:'#2563EB' },
  { id:'assignment',   name:'Asset Assignment',         desc:'Ownership & custodianship records',    icon:'bx-user-pin',     ic:'ic-g', color:'#166534' },
  { id:'maintenance',  name:'Maintenance Schedule',     desc:'Upcoming & overdue maintenance',       icon:'bx-wrench',       ic:'ic-a', color:'#D97706' },
  { id:'repair_cost',  name:'Repair Cost',              desc:'Repair expenditure by asset/zone',     icon:'bx-money-withdraw',ic:'ic-r',color:'#DC2626' },
  { id:'disposal',     name:'Asset Disposal',           desc:'Disposal records & value recovery',    icon:'bx-trash',        ic:'ic-t', color:'#0D9488' },
  { id:'depreciation', name:'Asset Depreciation',       desc:'Carrying value & depreciation rates',  icon:'bx-trending-down',ic:'ic-p', color:'#6D28D9' },
  { id:'audit',        name:'Asset Audit Trail',        desc:'Full log of changes & actions',        icon:'bx-shield-quarter',ic:'ic-d',color:'#374151' },
  { id:'utilization',  name:'Cross-Zone Utilization',   desc:'Usage rates across all zones',         icon:'bx-map-alt',      ic:'ic-b', color:'#1D4ED8' },
  { id:'ra9184',       name:'RA 9184 Compliance',       desc:'Govt procurement compliance status',   icon:'bx-shield-alt-2', ic:'ic-a', color:'#92400E' },
];

/* Seed data for each report type */
const ASSETS_POOL = [
  {id:'ASSET-0042',name:'Forklift Unit 3',        zone:'Zone A – Warehouse',         cat:'Heavy Equipment',   status:'Active',    value:280000,  tech:'Rodel Bautista', acqDate:'2021-03-15', depr:14,  assigned:'Juan Dela Cruz'},
  {id:'ASSET-0091',name:'Dump Truck T-12',         zone:'Zone D – Construction Site', cat:'Vehicles',          status:'Active',    value:1200000, tech:'Carlo Mendoza',  acqDate:'2020-07-22', depr:20,  assigned:'Pedro Reyes'},
  {id:'ASSET-0017',name:'Generator Set GS-2',      zone:'Zone B – Field Operations',  cat:'Power Systems',     status:'Idle',      value:450000,  tech:'Mark Ocampo',    acqDate:'2019-11-10', depr:10,  assigned:'Ana Cruz'},
  {id:'ASSET-0055',name:'Air Compressor AC-05',    zone:'Zone A – Warehouse',         cat:'Tools & Machinery', status:'Active',    value:85000,   tech:'Jun Santos',     acqDate:'2022-01-05', depr:10,  assigned:'Juan Dela Cruz'},
  {id:'ASSET-0103',name:'Excavator EX-01',         zone:'Zone D – Construction Site', cat:'Heavy Equipment',   status:'Under Maintenance', value:3500000, tech:'Arnel Cruz', acqDate:'2018-06-30', depr:20, assigned:'Pedro Reyes'},
  {id:'ASSET-0028',name:'CCTV Array Block C',      zone:'Zone C – Admin Complex',     cat:'IT Infrastructure', status:'Active',    value:42000,   tech:'Mark Ocampo',    acqDate:'2023-02-18', depr:10,  assigned:'Liza Tan'},
  {id:'ASSET-0039',name:'Network Switch NS-12',    zone:'Zone F – IT Infrastructure', cat:'IT Infrastructure', status:'Active',    value:38000,   tech:'Mark Ocampo',    acqDate:'2022-09-01', depr:33,  assigned:'Mark Ocampo'},
  {id:'ASSET-0061',name:'Pickup Truck PU-07',      zone:'Zone E – Fleet Depot',       cat:'Vehicles',          status:'Active',    value:850000,  tech:'Carlo Mendoza',  acqDate:'2021-08-14', depr:20,  assigned:'Carlo Mendoza'},
  {id:'ASSET-0084',name:'Pallet Jack PJ-02',       zone:'Zone A – Warehouse',         cat:'Tools & Machinery', status:'Retired',   value:28000,   tech:'Rodel Bautista', acqDate:'2017-04-20', depr:10,  assigned:'—'},
  {id:'ASSET-0110',name:'UPS System UPS-04',       zone:'Zone F – IT Infrastructure', cat:'Power Systems',     status:'Active',    value:55000,   tech:'Mark Ocampo',    acqDate:'2022-11-30', depr:20,  assigned:'Mark Ocampo'},
  {id:'ASSET-0022',name:'Scissor Lift SL-01',      zone:'Zone B – Field Operations',  cat:'Heavy Equipment',   status:'Active',    value:320000,  tech:'Jun Santos',     acqDate:'2020-05-12', depr:20,  assigned:'Ana Cruz'},
  {id:'ASSET-0133',name:'Server Rack SR-01',       zone:'Zone F – IT Infrastructure', cat:'IT Infrastructure', status:'Idle',      value:180000,  tech:'Mark Ocampo',    acqDate:'2021-01-25', depr:33,  assigned:'—'},
];

const REPAIR_POOL = [
  {logId:'RSL-2024-1001',assetId:'ASSET-0042',name:'Forklift Unit 3',        zone:'Zone A – Warehouse',         tech:'Rodel Bautista',date:'2024-05-01',cost:18500,status:'Completed'},
  {logId:'RSL-2024-1002',assetId:'ASSET-0091',name:'Dump Truck T-12',         zone:'Zone D – Construction Site', tech:'Carlo Mendoza', date:'2024-05-03',cost:42000,status:'Completed'},
  {logId:'RSL-2024-1003',assetId:'ASSET-0017',name:'Generator Set GS-2',      zone:'Zone B – Field Operations',  tech:'Mark Ocampo',   date:'2024-05-05',cost:9800, status:'Completed'},
  {logId:'RSL-2024-1004',assetId:'ASSET-0103',name:'Excavator EX-01',         zone:'Zone D – Construction Site', tech:'Arnel Cruz',    date:'2024-05-07',cost:85000,status:'In Progress'},
  {logId:'RSL-2024-1005',assetId:'ASSET-0061',name:'Pickup Truck PU-07',      zone:'Zone E – Fleet Depot',       tech:'Carlo Mendoza', date:'2024-05-09',cost:14200,status:'Completed'},
  {logId:'RSL-2024-1006',assetId:'ASSET-0022',name:'Scissor Lift SL-01',      zone:'Zone B – Field Operations',  tech:'Jun Santos',    date:'2024-05-11',cost:7600, status:'Completed'},
  {logId:'RSL-2024-1007',assetId:'ASSET-0042',name:'Forklift Unit 3',         zone:'Zone A – Warehouse',         tech:'Rodel Bautista',date:'2024-05-14',cost:22000,status:'Completed'},
  {logId:'RSL-2024-1008',assetId:'ASSET-0055',name:'Air Compressor AC-05',    zone:'Zone A – Warehouse',         tech:'Jun Santos',    date:'2024-05-16',cost:5400, status:'Completed'},
];

const DISPOSAL_POOL = [
  {id:'DSP-2024-1001',assetId:'ASSET-0084',name:'Pallet Jack PJ-02',       zone:'Zone A – Warehouse',         method:'Scrapped',   date:'2024-04-10',bookVal:28000,  dispVal:1400,  status:'Completed'},
  {id:'DSP-2024-1002',assetId:'ASSET-0133',name:'Old Server Rack SR-00',   zone:'Zone F – IT Infrastructure', method:'Sold',       date:'2024-04-22',bookVal:120000, dispVal:38000, status:'Completed'},
  {id:'DSP-2024-1003',assetId:'ASSET-0071',name:'Concrete Mixer CM-2',     zone:'Zone D – Construction Site', method:'Auctioned',  date:'2024-05-05',bookVal:95000,  dispVal:42000, status:'Completed'},
  {id:'DSP-2024-1004',assetId:'ASSET-0015',name:'Office Chairs (Batch)',    zone:'Zone C – Admin Complex',     method:'Donated',    date:'2024-05-12',bookVal:36000,  dispVal:0,     status:'Approved'},
  {id:'DSP-2024-1005',assetId:'ASSET-0099',name:'Survey Equipment SE-03',  zone:'Zone B – Field Operations',  method:'Transferred',date:'2024-05-18',bookVal:180000, dispVal:0,     status:'Pending Approval'},
];

const MAINTENANCE_POOL = [
  {assetId:'ASSET-0042',name:'Forklift Unit 3',        zone:'Zone A – Warehouse',         type:'Preventive',   lastDate:'2024-03-01',nextDate:'2024-06-01',status:'Upcoming',  tech:'Rodel Bautista'},
  {assetId:'ASSET-0091',name:'Dump Truck T-12',         zone:'Zone D – Construction Site', type:'Oil Change',   lastDate:'2024-02-15',nextDate:'2024-05-15',status:'Overdue',   tech:'Carlo Mendoza'},
  {assetId:'ASSET-0017',name:'Generator Set GS-2',      zone:'Zone B – Field Operations',  type:'Full Overhaul',lastDate:'2023-11-01',nextDate:'2024-11-01',status:'Upcoming',  tech:'Mark Ocampo'},
  {assetId:'ASSET-0103',name:'Excavator EX-01',         zone:'Zone D – Construction Site', type:'Track Service',lastDate:'2024-01-20',nextDate:'2024-04-20',status:'Overdue',   tech:'Arnel Cruz'},
  {assetId:'ASSET-0061',name:'Pickup Truck PU-07',      zone:'Zone E – Fleet Depot',       type:'Tire Rotation',lastDate:'2024-04-01',nextDate:'2024-07-01',status:'Upcoming',  tech:'Carlo Mendoza'},
  {assetId:'ASSET-0022',name:'Scissor Lift SL-01',      zone:'Zone B – Field Operations',  type:'Safety Check', lastDate:'2024-03-20',nextDate:'2024-06-20',status:'Upcoming',  tech:'Jun Santos'},
  {assetId:'ASSET-0110',name:'UPS System UPS-04',       zone:'Zone F – IT Infrastructure', type:'Battery Test', lastDate:'2024-04-15',nextDate:'2024-07-15',status:'Upcoming',  tech:'Mark Ocampo'},
  {assetId:'ASSET-0028',name:'CCTV Array Block C',      zone:'Zone C – Admin Complex',     type:'Lens Clean',   lastDate:'2024-02-01',nextDate:'2024-05-01',status:'Overdue',   tech:'Mark Ocampo'},
];

const AUDIT_POOL = [
  {ts:'2024-05-20 09:14',assetId:'ASSET-0042',name:'Forklift Unit 3',        action:'Status Updated',      by:'Juan Dela Cruz',  role:'Requestor',   detail:'Status changed: Idle → Active'},
  {ts:'2024-05-19 14:32',assetId:'ASSET-0091',name:'Dump Truck T-12',         action:'Repair Completed',    by:'Super Admin',     role:'Super Admin', detail:'RSL-2024-1002 force-completed'},
  {ts:'2024-05-18 11:05',assetId:'ASSET-0103',name:'Excavator EX-01',         action:'Disposal Initiated',  by:'Property Officer',role:'Admin',        detail:'DSP-2024-1004 created'},
  {ts:'2024-05-17 16:48',assetId:'ASSET-0017',name:'Generator Set GS-2',      action:'Assignment Changed',  by:'Super Admin',     role:'Super Admin', detail:'Reassigned to Ana Cruz'},
  {ts:'2024-05-16 10:22',assetId:'ASSET-0061',name:'Pickup Truck PU-07',      action:'Maintenance Logged',  by:'Carlo Mendoza',   role:'Technician',  detail:'Preventive maintenance completed'},
  {ts:'2024-05-15 13:55',assetId:'ASSET-0039',name:'Network Switch NS-12',    action:'Value Updated',       by:'Super Admin',     role:'Super Admin', detail:'Book value adjusted ₱38,000'},
  {ts:'2024-05-14 09:00',assetId:'ASSET-0022',name:'Scissor Lift SL-01',      action:'Zone Transfer',       by:'Operations Lead', role:'Ops Lead',    detail:'Transferred: Zone D → Zone B'},
  {ts:'2024-05-13 15:30',assetId:'ASSET-0084',name:'Pallet Jack PJ-02',       action:'Disposal Completed',  by:'Super Admin',     role:'Super Admin', detail:'Scrapped. PDR filed with COA'},
];

const UTILIZATION_POOL = [
  {zone:'Zone A – Warehouse',         total:4,active:3,idle:0,maintenance:1,utilRate:75},
  {zone:'Zone B – Field Operations',  total:3,active:2,idle:1,maintenance:0,utilRate:67},
  {zone:'Zone C – Admin Complex',     total:2,active:2,idle:0,maintenance:0,utilRate:100},
  {zone:'Zone D – Construction Site', total:3,active:1,idle:0,maintenance:2,utilRate:33},
  {zone:'Zone E – Fleet Depot',       total:2,active:2,idle:0,maintenance:0,utilRate:100},
  {zone:'Zone F – IT Infrastructure', total:4,active:3,idle:1,maintenance:0,utilRate:75},
];

const RA9184_POOL = [
  {id:'DSP-2024-1001',name:'Pallet Jack PJ-02',       method:'Scrapped',  certUnservice:'Met',pdr:'Met',   appraisal:'Met',  bacRes:'Met',  notice:'N/A',remittance:'N/A',  overall:'Compliant'},
  {id:'DSP-2024-1002',name:'Old Server Rack SR-00',   method:'Sold',      certUnservice:'Met',pdr:'Met',   appraisal:'Met',  bacRes:'Met',  notice:'N/A',remittance:'Met',   overall:'Compliant'},
  {id:'DSP-2024-1003',name:'Concrete Mixer CM-2',     method:'Auctioned', certUnservice:'Met',pdr:'Met',   appraisal:'Met',  bacRes:'Met',  notice:'Met',remittance:'Met',   overall:'Compliant'},
  {id:'DSP-2024-1004',name:'Office Chairs (Batch)',   method:'Donated',   certUnservice:'Met',pdr:'Met',   appraisal:'Pending',bacRes:'Pending',notice:'N/A',remittance:'N/A',overall:'Pending'},
  {id:'DSP-2024-1005',name:'Survey Equipment SE-03',  method:'Transferred',certUnservice:'Met',pdr:'Pending',appraisal:'Pending',bacRes:'Pending',notice:'N/A',remittance:'N/A',overall:'Pending'},
];

/* ── SCHEDULED REPORTS SEED ── */
let scheduledReports = [
  {id:1,name:'Weekly Maintenance Summary',  type:'maintenance', freq:'Weekly',  day:'Monday',   time:'08:00',formats:['PDF'],      zone:'',  recipients:['ops@microfinance.ph','maintenance@microfinance.ph'], enabled:true,  lastRun:'2024-05-20',nextRun:'2024-05-27'},
  {id:2,name:'Monthly Asset Inventory',      type:'inventory',   freq:'Monthly', day:'1st',       time:'07:00',formats:['Excel','PDF'],zone:'',  recipients:['admin@microfinance.ph','cfo@microfinance.ph'],        enabled:true,  lastRun:'2024-05-01',nextRun:'2024-06-01'},
  {id:3,name:'Monthly Repair Cost Report',  type:'repair_cost', freq:'Monthly', day:'1st',       time:'08:30',formats:['PDF','CSV'],zone:'',  recipients:['finance@microfinance.ph'],                            enabled:false, lastRun:'2024-05-01',nextRun:'2024-06-01'},
  {id:4,name:'Weekly RA 9184 Compliance',   type:'ra9184',      freq:'Weekly',  day:'Friday',    time:'17:00',formats:['PDF'],      zone:'',  recipients:['compliance@microfinance.ph','superadmin@microfinance.ph'],enabled:true,lastRun:'2024-05-17',nextRun:'2024-05-24'},
];
let schedIdSeq = 5;

/* ── STATE ── */
let activeType  = 'inventory';
let activeFilters = {};
let reportPage  = 1;
const PAGE_SIZE = 8;
let editSchedId = null;
let emailTags   = [];

/* ══════════════════════════════════════════════════════════
   REPORT TYPE LIST
   ══════════════════════════════════════════════════════════ */
function renderReportTypeList() {
  const el = document.getElementById('reportTypeList');
  el.innerHTML = REPORT_TYPES.map(rt => `
    <div class="rt-item ${rt.id===activeType?'active':''}" onclick="selectType('${rt.id}')">
      <div class="rt-ic ${rt.ic}"><i class="bx ${rt.icon}"></i></div>
      <div class="rt-info">
        <div class="rt-name">${rt.name}</div>
        <div class="rt-desc">${rt.desc}</div>
      </div>
      <i class="bx bx-chevron-right rt-arrow"></i>
    </div>`).join('');
}
function selectType(id) {
  activeType = id; reportPage = 1;
  renderReportTypeList();
  renderReport();
}

/* ══════════════════════════════════════════════════════════
   STATS BAR
   ══════════════════════════════════════════════════════════ */
function renderStats() {
  const total   = ASSETS_POOL.length;
  const active  = ASSETS_POOL.filter(a=>a.status==='Active').length;
  const totalRepairCost = REPAIR_POOL.reduce((s,r)=>s+r.cost,0);
  const overdue = MAINTENANCE_POOL.filter(m=>m.status==='Overdue').length;
  document.getElementById('statsBar').innerHTML = `
    <div class="sc"><div class="sc-ic ic-g"><i class="bx bx-cube"></i></div><div><div class="sc-v">${total}</div><div class="sc-l">Total Assets</div></div></div>
    <div class="sc"><div class="sc-ic ic-b"><i class="bx bx-check-circle"></i></div><div><div class="sc-v">${active}</div><div class="sc-l">Active</div></div></div>
    <div class="sc"><div class="sc-ic ic-r"><i class="bx bx-time-five"></i></div><div><div class="sc-v">${overdue}</div><div class="sc-l">Maint. Overdue</div></div></div>
    <div class="sc"><div class="sc-ic ic-a"><i class="bx bx-money-withdraw"></i></div><div><div class="sc-v" style="font-size:12px">${fM(totalRepairCost)}</div><div class="sc-l">Total Repair Cost</div></div></div>`;
}

/* ══════════════════════════════════════════════════════════
   REPORT WORKSPACE HEADER
   ══════════════════════════════════════════════════════════ */
function updateWorkspaceHeader() {
  const rt = REPORT_TYPES.find(r=>r.id===activeType);
  const badgeEl = document.getElementById('rwBadge');
  badgeEl.textContent = rt.name;
  badgeEl.style.background = rt.ic==='ic-b'?'#EFF6FF':rt.ic==='ic-g'?'#DCFCE7':rt.ic==='ic-a'?'#FEF3C7':rt.ic==='ic-r'?'#FEE2E2':rt.ic==='ic-t'?'#CCFBF1':rt.ic==='ic-p'?'#F5F3FF':'#F3F4F6';
  badgeEl.style.color = rt.color;
  document.getElementById('rwTitle').textContent = rt.name + ' Report';
  const now = new Date();
  document.getElementById('rwMeta').innerHTML = `
    <span><i class="bx bx-calendar"></i>Generated ${now.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})}</span>
    <span><i class="bx bx-time"></i>${now.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'})}</span>
    <span><i class="bx bx-filter-alt"></i>${Object.keys(activeFilters).filter(k=>activeFilters[k]).length} filter(s) active</span>`;
}

/* ══════════════════════════════════════════════════════════
   MAIN REPORT RENDERER
   ══════════════════════════════════════════════════════════ */
function renderReport() {
  updateWorkspaceHeader();
  const body = document.getElementById('reportBody');
  const renders = {
    inventory:    renderInventory,
    assignment:   renderAssignment,
    maintenance:  renderMaintenance,
    repair_cost:  renderRepairCost,
    disposal:     renderDisposal,
    depreciation: renderDepreciation,
    audit:        renderAudit,
    utilization:  renderUtilization,
    ra9184:       renderRA9184,
  };
  body.innerHTML = (renders[activeType]||renderInventory)();
}

/* ── Helpers for table pagination ── */
function paginate(arr) {
  const total = arr.length, pages = Math.max(1, Math.ceil(total/PAGE_SIZE));
  if (reportPage > pages) reportPage = pages;
  const slice = arr.slice((reportPage-1)*PAGE_SIZE, reportPage*PAGE_SIZE);
  return { slice, total, pages };
}
function pagerHTML(total, pages) {
  if (total === 0) return '';
  const s=(reportPage-1)*PAGE_SIZE+1, e=Math.min(reportPage*PAGE_SIZE,total);
  let btns='';
  for(let i=1;i<=pages;i++) {
    if(i===1||i===pages||(i>=reportPage-1&&i<=reportPage+1)) btns+=`<button class="pgb ${i===reportPage?'active':''}" onclick="goReportPage(${i})">${i}</button>`;
    else if(i===reportPage-2||i===reportPage+2) btns+=`<button class="pgb" disabled>…</button>`;
  }
  return `<div class="tbl-footer">
    <span>Showing ${s}–${e} of ${total} records</span>
    <div class="pg-btns">
      <button class="pgb" onclick="goReportPage(${reportPage-1})" ${reportPage<=1?'disabled':''}><i class="bx bx-chevron-left"></i></button>
      ${btns}
      <button class="pgb" onclick="goReportPage(${reportPage+1})" ${reportPage>=pages?'disabled':''}><i class="bx bx-chevron-right"></i></button>
    </div></div>`;
}
function goReportPage(p) { reportPage=p; renderReport(); }

/* ── INVENTORY ── */
function renderInventory() {
  const {slice,total,pages} = paginate(ASSETS_POOL);
  const sb = {
    total:ASSETS_POOL.length,
    active:ASSETS_POOL.filter(a=>a.status==='Active').length,
    totalVal:ASSETS_POOL.reduce((s,a)=>s+a.value,0),
    cats:[...new Set(ASSETS_POOL.map(a=>a.cat))].length,
  };
  const sbH = `<div class="report-summary">
    <div class="rs-kv"><div class="kv-v">${sb.total}</div><div class="kv-l">Total Assets</div></div>
    <div class="rs-kv"><div class="kv-v">${sb.active}</div><div class="kv-l">Active</div></div>
    <div class="rs-kv"><div class="kv-v">${ASSETS_POOL.filter(a=>a.status==='Idle').length}</div><div class="kv-l">Idle</div></div>
    <div class="rs-kv"><div class="kv-v">${ASSETS_POOL.filter(a=>a.status==='Under Maintenance').length}</div><div class="kv-l">Under Maint.</div></div>
    <div class="rs-kv"><div class="kv-v mono">${fM(sb.totalVal)}</div><div class="kv-l">Total Value</div></div>
    <div class="rs-kv"><div class="kv-v">${sb.cats}</div><div class="kv-l">Categories</div></div>
  </div>`;
  const tH = `<div class="report-tbl-wrap"><table class="report-tbl">
    <thead><tr><th>Asset ID</th><th>Asset Name</th><th>Zone</th><th>Category</th><th>Status</th><th>Acquisition Date</th><th>Book Value</th><th>Assigned To</th></tr></thead>
    <tbody>${slice.map(a=>`<tr>
      <td class="cell-id">${esc(a.id)}</td>
      <td><div class="cell-name">${esc(a.name)}</div></td>
      <td><span class="zone-dot"><span style="width:6px;height:6px;border-radius:50%;background:${ZONE_COLORS[a.zone]||'#9CA3AF'};display:inline-block;flex-shrink:0"></span>${esc(a.zone.split('–')[0].trim())}</span></td>
      <td style="font-size:12px;color:var(--t2)">${esc(a.cat)}</td>
      <td>${statusBadge(a.status)}</td>
      <td class="cell-id">${fD(a.acqDate)}</td>
      <td class="cell-mono">${fM(a.value)}</td>
      <td style="font-size:12px">${esc(a.assigned)}</td>
    </tr>`).join('')}</tbody>
    <tfoot><tr><td colspan="6" style="text-align:right;color:#9EB0A2;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em">Total Book Value</td><td class="cell-mono">${fM(sb.totalVal)}</td><td></td></tr></tfoot>
  </table></div>${pagerHTML(total,pages)}`;
  return sbH+tH;
}

/* ── ASSIGNMENT ── */
function renderAssignment() {
  const {slice,total,pages}=paginate(ASSETS_POOL);
  const sbH=`<div class="report-summary">
    <div class="rs-kv"><div class="kv-v">${ASSETS_POOL.filter(a=>a.assigned&&a.assigned!=='—').length}</div><div class="kv-l">Assigned</div></div>
    <div class="rs-kv"><div class="kv-v">${ASSETS_POOL.filter(a=>!a.assigned||a.assigned==='—').length}</div><div class="kv-l">Unassigned</div></div>
    <div class="rs-kv"><div class="kv-v">${[...new Set(ASSETS_POOL.map(a=>a.assigned).filter(x=>x&&x!=='—'))].length}</div><div class="kv-l">Custodians</div></div>
    <div class="rs-kv"><div class="kv-v">${[...new Set(ASSETS_POOL.map(a=>a.zone))].length}</div><div class="kv-l">Zones</div></div>
  </div>`;
  const tH=`<div class="report-tbl-wrap"><table class="report-tbl">
    <thead><tr><th>Asset ID</th><th>Asset Name</th><th>Zone</th><th>Category</th><th>Assigned To</th><th>Assigned Technician</th><th>Status</th></tr></thead>
    <tbody>${slice.map(a=>`<tr>
      <td class="cell-id">${esc(a.id)}</td>
      <td class="cell-name">${esc(a.name)}</td>
      <td><span class="zone-dot"><span style="width:6px;height:6px;border-radius:50%;background:${ZONE_COLORS[a.zone]||'#9CA3AF'};display:inline-block"></span>${esc(a.zone.split('–')[0].trim())}</span></td>
      <td style="font-size:12px;color:var(--t2)">${esc(a.cat)}</td>
      <td style="font-weight:600;font-size:12.5px">${a.assigned&&a.assigned!=='—'?esc(a.assigned):'<span style="color:#9EB0A2">Unassigned</span>'}</td>
      <td style="font-size:12px;color:var(--t2)">${esc(a.tech)}</td>
      <td>${statusBadge(a.status)}</td>
    </tr>`).join('')}</tbody>
  </table></div>${pagerHTML(total,pages)}`;
  return sbH+tH;
}

/* ── MAINTENANCE ── */
function renderMaintenance() {
  const {slice,total,pages}=paginate(MAINTENANCE_POOL);
  const overdue=MAINTENANCE_POOL.filter(m=>m.status==='Overdue').length;
  const sbH=`<div class="report-summary">
    <div class="rs-kv"><div class="kv-v">${MAINTENANCE_POOL.length}</div><div class="kv-l">Scheduled</div></div>
    <div class="rs-kv"><div class="kv-v" style="color:#DC2626">${overdue}</div><div class="kv-l">Overdue</div></div>
    <div class="rs-kv"><div class="kv-v">${MAINTENANCE_POOL.filter(m=>m.status==='Upcoming').length}</div><div class="kv-l">Upcoming</div></div>
    <div class="rs-kv"><div class="kv-v">${[...new Set(MAINTENANCE_POOL.map(m=>m.tech))].length}</div><div class="kv-l">Technicians</div></div>
  </div>`;
  const tH=`<div class="report-tbl-wrap"><table class="report-tbl">
    <thead><tr><th>Asset</th><th>Zone</th><th>Maint. Type</th><th>Last Done</th><th>Next Due</th><th>Assigned Tech</th><th>Status</th></tr></thead>
    <tbody>${slice.map(m=>`<tr>
      <td><div class="cell-name">${esc(m.name)}</div><div class="cell-sub">${esc(m.assetId)}</div></td>
      <td><span class="zone-dot"><span style="width:6px;height:6px;border-radius:50%;background:${ZONE_COLORS[m.zone]||'#9CA3AF'};display:inline-block"></span>${esc(m.zone.split('–')[0].trim())}</span></td>
      <td style="font-size:12px;font-weight:600">${esc(m.type)}</td>
      <td class="cell-id">${fD(m.lastDate)}</td>
      <td class="cell-id" style="color:${m.status==='Overdue'?'#DC2626':'var(--t2)'};font-weight:${m.status==='Overdue'?'700':'400'}">${fD(m.nextDate)}</td>
      <td style="font-size:12px">${esc(m.tech)}</td>
      <td>${m.status==='Overdue'?'<span class="badge b-overdue">Overdue</span>':'<span class="badge b-pending">Upcoming</span>'}</td>
    </tr>`).join('')}</tbody>
  </table></div>${pagerHTML(total,pages)}`;
  return sbH+tH;
}

/* ── REPAIR COST ── */
function renderRepairCost() {
  const {slice,total,pages}=paginate(REPAIR_POOL);
  const totalCost=REPAIR_POOL.reduce((s,r)=>s+r.cost,0);
  const byZone = Object.keys(ZONE_COLORS).map(z=>({label:z.split('–')[0].trim(),val:REPAIR_POOL.filter(r=>r.zone===z).reduce((s,r)=>s+r.cost,0),color:ZONE_COLORS[z]})).filter(z=>z.val>0).sort((a,b)=>b.val-a.val);
  const maxZ=Math.max(...byZone.map(z=>z.val),1);
  const sbH=`<div class="report-summary">
    <div class="rs-kv"><div class="kv-v">${REPAIR_POOL.length}</div><div class="kv-l">Total Repairs</div></div>
    <div class="rs-kv"><div class="kv-v">${REPAIR_POOL.filter(r=>r.status==='Completed').length}</div><div class="kv-l">Completed</div></div>
    <div class="rs-kv"><div class="kv-v mono">${fM(totalCost)}</div><div class="kv-l">Total Cost</div></div>
    <div class="rs-kv"><div class="kv-v mono">${fM(Math.round(totalCost/REPAIR_POOL.length))}</div><div class="kv-l">Avg. Cost</div></div>
  </div>`;
  const chartH=`<div class="chart-area"><div class="chart-title"><i class="bx bx-bar-chart-alt-2" style="color:var(--grn)"></i> Cost by Zone</div><div class="bar-chart">${byZone.map(z=>`<div class="bar-row"><div class="bar-label">${z.label}</div><div class="bar-track"><div class="bar-fill" style="width:${Math.round(z.val/maxZ*100)}%;background:${z.color}"></div></div><div class="bar-val">${fM(z.val)}</div></div>`).join('')}</div></div>`;
  const tH=`<div class="report-tbl-wrap"><table class="report-tbl">
    <thead><tr><th>Log ID</th><th>Asset</th><th>Zone</th><th>Technician</th><th>Date</th><th>Repair Cost</th><th>Status</th></tr></thead>
    <tbody>${slice.map(r=>`<tr>
      <td class="cell-id">${esc(r.logId)}</td>
      <td><div class="cell-name">${esc(r.name)}</div><div class="cell-sub">${esc(r.assetId)}</div></td>
      <td><span class="zone-dot"><span style="width:6px;height:6px;border-radius:50%;background:${ZONE_COLORS[r.zone]||'#9CA3AF'};display:inline-block"></span>${esc(r.zone.split('–')[0].trim())}</span></td>
      <td style="font-size:12px">${esc(r.tech)}</td>
      <td class="cell-id">${fD(r.date)}</td>
      <td class="cell-mono">${fM(r.cost)}</td>
      <td>${r.status==='Completed'?'<span class="badge b-comp">Completed</span>':'<span class="badge b-pending">In Progress</span>'}</td>
    </tr>`).join('')}</tbody>
    <tfoot><tr><td colspan="5" style="text-align:right;color:#9EB0A2;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em">Total</td><td class="cell-mono">${fM(totalCost)}</td><td></td></tr></tfoot>
  </table></div>${pagerHTML(total,pages)}`;
  return sbH+chartH+tH;
}

/* ── DISPOSAL ── */
function renderDisposal() {
  const {slice,total,pages}=paginate(DISPOSAL_POOL);
  const comp=DISPOSAL_POOL.filter(d=>d.status==='Completed');
  const totalBook=comp.reduce((s,d)=>s+d.bookVal,0);
  const totalDisp=comp.reduce((s,d)=>s+d.dispVal,0);
  const sbH=`<div class="report-summary">
    <div class="rs-kv"><div class="kv-v">${DISPOSAL_POOL.length}</div><div class="kv-l">Total Disposals</div></div>
    <div class="rs-kv"><div class="kv-v">${comp.length}</div><div class="kv-l">Completed</div></div>
    <div class="rs-kv"><div class="kv-v mono">${fM(totalDisp)}</div><div class="kv-l">Value Recovered</div></div>
    <div class="rs-kv"><div class="kv-v">${totalBook>0?Math.round(totalDisp/totalBook*100):0}%</div><div class="kv-l">Recovery Rate</div></div>
  </div>`;
  const tH=`<div class="report-tbl-wrap"><table class="report-tbl">
    <thead><tr><th>Disposal ID</th><th>Asset</th><th>Zone</th><th>Method</th><th>Date</th><th>Book Value</th><th>Disposal Value</th><th>Status</th></tr></thead>
    <tbody>${slice.map(d=>`<tr>
      <td class="cell-id">${esc(d.id)}</td>
      <td><div class="cell-name">${esc(d.name)}</div><div class="cell-sub">${esc(d.assetId)}</div></td>
      <td><span class="zone-dot"><span style="width:6px;height:6px;border-radius:50%;background:${ZONE_COLORS[d.zone]||'#9CA3AF'};display:inline-block"></span>${esc(d.zone.split('–')[0].trim())}</span></td>
      <td style="font-size:12px;font-weight:600">${esc(d.method)}</td>
      <td class="cell-id">${fD(d.date)}</td>
      <td class="cell-mono" style="color:#9EB0A2">${fM(d.bookVal)}</td>
      <td class="cell-mono">${d.dispVal>0?fM(d.dispVal):'—'}</td>
      <td>${statusBadge(d.status)}</td>
    </tr>`).join('')}</tbody>
  </table></div>${pagerHTML(total,pages)}`;
  return sbH+tH;
}

/* ── DEPRECIATION ── */
function renderDepreciation() {
  const data = ASSETS_POOL.map(a => {
    const years = (new Date()-new Date(a.acqDate+'T00:00:00'))/(365.25*24*3600*1000);
    const accumulated = Math.min(a.value, Math.round(a.value*(a.depr/100)*years));
    const carrying = Math.max(0, a.value - accumulated);
    return {...a, years:Math.round(years*10)/10, accumulated, carrying};
  });
  const {slice,total,pages}=paginate(data);
  const totalAcc=data.reduce((s,a)=>s+a.accumulated,0);
  const totalCarrying=data.reduce((s,a)=>s+a.carrying,0);
  const sbH=`<div class="report-summary">
    <div class="rs-kv"><div class="kv-v mono">${fM(data.reduce((s,a)=>s+a.value,0))}</div><div class="kv-l">Total Orig. Value</div></div>
    <div class="rs-kv"><div class="kv-v mono" style="color:#DC2626">${fM(totalAcc)}</div><div class="kv-l">Accumulated Depr.</div></div>
    <div class="rs-kv"><div class="kv-v mono">${fM(totalCarrying)}</div><div class="kv-l">Net Book Value</div></div>
    <div class="rs-kv"><div class="kv-v">${data.filter(a=>a.carrying<a.value*.1).length}</div><div class="kv-l">Near Zero Value</div></div>
  </div>`;
  const tH=`<div class="report-tbl-wrap"><table class="report-tbl">
    <thead><tr><th>Asset</th><th>Zone</th><th>Acq. Date</th><th>Age (Yrs)</th><th>Depr. Rate</th><th>Orig. Value</th><th>Accumulated Depr.</th><th>Carrying Value</th></tr></thead>
    <tbody>${slice.map(a=>`<tr>
      <td><div class="cell-name">${esc(a.name)}</div><div class="cell-sub">${esc(a.id)}</div></td>
      <td><span class="zone-dot"><span style="width:6px;height:6px;border-radius:50%;background:${ZONE_COLORS[a.zone]||'#9CA3AF'};display:inline-block"></span>${esc(a.zone.split('–')[0].trim())}</span></td>
      <td class="cell-id">${fD(a.acqDate)}</td>
      <td style="font-family:'DM Mono',monospace;font-size:12px">${a.years}y</td>
      <td style="font-family:'DM Mono',monospace;font-size:12px">${a.depr}%</td>
      <td class="cell-mono" style="color:#9EB0A2">${fM(a.value)}</td>
      <td class="cell-mono" style="color:#DC2626">(${fM(a.accumulated)})</td>
      <td class="cell-mono" style="color:${a.carrying<a.value*.1?'#D97706':'var(--grn)'}">${fM(a.carrying)}</td>
    </tr>`).join('')}</tbody>
    <tfoot><tr><td colspan="5" style="text-align:right;color:#9EB0A2;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em">Totals</td><td class="cell-mono" style="color:#9EB0A2">${fM(data.reduce((s,a)=>s+a.value,0))}</td><td class="cell-mono" style="color:#DC2626">(${fM(totalAcc)})</td><td class="cell-mono">${fM(totalCarrying)}</td></tr></tfoot>
  </table></div>${pagerHTML(total,pages)}`;
  return sbH+tH;
}

/* ── AUDIT TRAIL ── */
function renderAudit() {
  const {slice,total,pages}=paginate(AUDIT_POOL);
  const sbH=`<div class="report-summary">
    <div class="rs-kv"><div class="kv-v">${AUDIT_POOL.length}</div><div class="kv-l">Total Events</div></div>
    <div class="rs-kv"><div class="kv-v">${AUDIT_POOL.filter(a=>a.role==='Super Admin').length}</div><div class="kv-l">By Super Admin</div></div>
    <div class="rs-kv"><div class="kv-v">${[...new Set(AUDIT_POOL.map(a=>a.assetId))].length}</div><div class="kv-l">Assets Affected</div></div>
    <div class="rs-kv"><div class="kv-v">${[...new Set(AUDIT_POOL.map(a=>a.by))].length}</div><div class="kv-l">Users</div></div>
  </div>`;
  const tH=`<div class="report-tbl-wrap"><table class="report-tbl">
    <thead><tr><th>Timestamp</th><th>Asset</th><th>Action</th><th>Performed By</th><th>Role</th><th>Detail</th></tr></thead>
    <tbody>${slice.map(a=>`<tr>
      <td style="font-family:'DM Mono',monospace;font-size:11px;white-space:nowrap;color:var(--t2)">${esc(a.ts)}</td>
      <td><div class="cell-name">${esc(a.name)}</div><div class="cell-sub">${esc(a.assetId)}</div></td>
      <td style="font-weight:600;font-size:12.5px">${esc(a.action)}</td>
      <td style="font-size:12px">${esc(a.by)}</td>
      <td><span style="font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:5px;background:${a.role==='Super Admin'?'#FEF3C7':'#F3F4F6'};color:${a.role==='Super Admin'?'#92400E':'#374151'}">${esc(a.role)}</span></td>
      <td style="font-size:11.5px;color:var(--t2)">${esc(a.detail)}</td>
    </tr>`).join('')}</tbody>
  </table></div>${pagerHTML(total,pages)}`;
  return sbH+tH;
}

/* ── UTILIZATION ── */
function renderUtilization() {
  const maxR=Math.max(...UTILIZATION_POOL.map(z=>z.utilRate),1);
  const avgUtil=Math.round(UTILIZATION_POOL.reduce((s,z)=>s+z.utilRate,0)/UTILIZATION_POOL.length);
  const sbH=`<div class="report-summary">
    <div class="rs-kv"><div class="kv-v">${UTILIZATION_POOL.reduce((s,z)=>s+z.total,0)}</div><div class="kv-l">Total Assets</div></div>
    <div class="rs-kv"><div class="kv-v">${UTILIZATION_POOL.reduce((s,z)=>s+z.active,0)}</div><div class="kv-l">Active</div></div>
    <div class="rs-kv"><div class="kv-v">${avgUtil}%</div><div class="kv-l">Avg. Utilization</div></div>
    <div class="rs-kv"><div class="kv-v">${UTILIZATION_POOL.length}</div><div class="kv-l">Zones Tracked</div></div>
  </div>`;
  const chartH=`<div class="chart-area"><div class="chart-title"><i class="bx bx-map-alt" style="color:var(--blu)"></i> Utilization Rate by Zone</div><div class="bar-chart">${UTILIZATION_POOL.map(z=>`<div class="bar-row"><div class="bar-label">${z.zone.split('–')[0].trim()}</div><div class="bar-track"><div class="bar-fill" style="width:${z.utilRate}%;background:${z.utilRate>=80?'#16A34A':z.utilRate>=50?'#D97706':'#DC2626'}"></div></div><div class="bar-val">${z.utilRate}%</div></div>`).join('')}</div></div>`;
  const tH=`<div class="report-tbl-wrap"><table class="report-tbl">
    <thead><tr><th>Zone</th><th>Total Assets</th><th>Active</th><th>Idle</th><th>Under Maintenance</th><th>Utilization Rate</th></tr></thead>
    <tbody>${UTILIZATION_POOL.map(z=>`<tr>
      <td><span class="zone-dot"><span style="width:6px;height:6px;border-radius:50%;background:${ZONE_COLORS[z.zone]||'#9CA3AF'};display:inline-block"></span><span style="font-weight:600">${esc(z.zone)}</span></span></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700">${z.total}</td>
      <td><span style="font-weight:700;color:#166534">${z.active}</span></td>
      <td><span style="font-weight:700;color:#D97706">${z.idle}</span></td>
      <td><span style="font-weight:700;color:#2563EB">${z.maintenance}</span></td>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <div style="flex:1;height:6px;background:var(--bd);border-radius:3px;overflow:hidden"><div style="width:${z.utilRate}%;height:100%;background:${z.utilRate>=80?'#16A34A':z.utilRate>=50?'#D97706':'#DC2626'};border-radius:3px"></div></div>
          <span style="font-family:'DM Mono',monospace;font-size:12px;font-weight:700;min-width:38px;text-align:right">${z.utilRate}%</span>
        </div>
      </td>
    </tr>`).join('')}</tbody>
  </table></div>`;
  return sbH+chartH+tH;
}

/* ── RA 9184 ── */
function renderRA9184() {
  const {slice,total,pages}=paginate(RA9184_POOL);
  const compliant=RA9184_POOL.filter(r=>r.overall==='Compliant').length;
  const sbH=`<div class="report-summary">
    <div class="rs-kv"><div class="kv-v">${RA9184_POOL.length}</div><div class="kv-l">Total Records</div></div>
    <div class="rs-kv"><div class="kv-v" style="color:#166534">${compliant}</div><div class="kv-l">Compliant</div></div>
    <div class="rs-kv"><div class="kv-v" style="color:#D97706">${RA9184_POOL.length-compliant}</div><div class="kv-l">Pending</div></div>
    <div class="rs-kv"><div class="kv-v">${Math.round(compliant/RA9184_POOL.length*100)}%</div><div class="kv-l">Compliance Rate</div></div>
  </div>`;
  const statusCell=(v)=>{
    if(v==='Met')     return `<span style="font-size:10.5px;font-weight:700;background:#DCFCE7;color:#166534;border-radius:4px;padding:2px 6px">✓</span>`;
    if(v==='Pending') return `<span style="font-size:10.5px;font-weight:700;background:#FEF3C7;color:#92400E;border-radius:4px;padding:2px 6px">⏳</span>`;
    return `<span style="font-size:10.5px;color:#9CA3AF;background:#F3F4F6;border-radius:4px;padding:2px 6px">N/A</span>`;
  };
  const tH=`<div class="report-tbl-wrap"><table class="report-tbl">
    <thead><tr><th>Disposal ID</th><th>Asset</th><th>Method</th><th>Sec.79 Unservice</th><th>Sec.80 PDR</th><th>Sec.81 Appraisal</th><th>Sec.82 BAC Res.</th><th>Sec.83 Notice</th><th>Sec.84 Remittance</th><th>Overall</th></tr></thead>
    <tbody>${slice.map(r=>`<tr>
      <td class="cell-id">${esc(r.id)}</td>
      <td class="cell-name">${esc(r.name)}</td>
      <td style="font-size:12px;font-weight:600">${esc(r.method)}</td>
      <td style="text-align:center">${statusCell(r.certUnservice)}</td>
      <td style="text-align:center">${statusCell(r.pdr)}</td>
      <td style="text-align:center">${statusCell(r.appraisal)}</td>
      <td style="text-align:center">${statusCell(r.bacRes)}</td>
      <td style="text-align:center">${statusCell(r.notice)}</td>
      <td style="text-align:center">${statusCell(r.remittance)}</td>
      <td><span class="badge ${r.overall==='Compliant'?'b-comp':'b-pending'}">${r.overall}</span></td>
    </tr>`).join('')}</tbody>
  </table></div>${pagerHTML(total,pages)}`;
  return sbH+tH;
}

function statusBadge(s) {
  const m={'Active':'b-active','Idle':'b-idle','Retired':'b-retired','Under Maintenance':'b-pending','Disposed':'b-retired','Completed':'b-comp','Approved':'b-comp','Pending Approval':'b-pending','Cancelled':'b-retired','Rejected':'b-overdue'};
  return `<span class="badge ${m[s]||'b-idle'}">${s}</span>`;
}

/* ══════════════════════════════════════════════════════════
   SCHEDULED REPORTS
   ══════════════════════════════════════════════════════════ */
const SCHED_IC = {
  inventory:'ic-g',assignment:'ic-b',maintenance:'ic-a',repair_cost:'ic-r',
  disposal:'ic-t',depreciation:'ic-p',audit:'ic-d',utilization:'ic-b',ra9184:'ic-a',
};
const SCHED_ICON = {
  inventory:'bx-list-ul',assignment:'bx-user-pin',maintenance:'bx-wrench',repair_cost:'bx-money-withdraw',
  disposal:'bx-trash',depreciation:'bx-trending-down',audit:'bx-shield-quarter',utilization:'bx-map-alt',ra9184:'bx-shield-alt-2',
};

function renderScheduledList() {
  const el = document.getElementById('schedList');
  if (!scheduledReports.length) {
    el.innerHTML = `<div class="sched-empty"><i class="bx bx-calendar-x" style="font-size:38px;display:block;margin-bottom:8px;color:#C8E6C9"></i>No scheduled reports yet</div>`;
    return;
  }
  el.innerHTML = scheduledReports.map(s => {
    const rt = REPORT_TYPES.find(r=>r.id===s.type)||REPORT_TYPES[0];
    return `<div class="sched-item">
      <div class="sched-ic ${SCHED_IC[s.type]||'ic-b'}"><i class="bx ${SCHED_ICON[s.type]||'bx-file-blank'}"></i></div>
      <div class="sched-info">
        <div class="sched-name">
          ${esc(s.name)}
          <span class="sched-freq sf-${s.freq.toLowerCase()}">${s.freq}</span>
          ${s.formats.map(f=>`<span style="font-size:10px;font-weight:700;padding:1px 6px;border-radius:4px;background:#F3F4F6;color:#374151">${f}</span>`).join('')}
        </div>
        <div class="sched-meta">
          <span><i class="bx bx-file-blank"></i>${rt.name}</span>
          <span><i class="bx bx-time"></i>${s.day} at ${s.time}</span>
          <span><i class="bx bx-envelope"></i>${s.recipients.length} recipient${s.recipients.length!==1?'s':''}</span>
          <span><i class="bx bx-calendar-check"></i>Next: ${fD(s.nextRun)}</span>
        </div>
      </div>
      <label class="sched-toggle" title="${s.enabled?'Enabled — click to disable':'Disabled — click to enable'}">
        <input type="checkbox" ${s.enabled?'checked':''} onchange="toggleSched(${s.id},this.checked)">
        <div class="sched-toggle-track"></div>
      </label>
      <div class="sched-actions">
        <button class="btn btn-ghost btn-xs" onclick="editSched(${s.id})" title="Edit"><i class="bx bx-edit" style="font-size:14px"></i></button>
        <button class="btn btn-danger btn-xs" onclick="deleteSched(${s.id})" title="Delete"><i class="bx bx-trash" style="font-size:14px"></i></button>
      </div>
    </div>`;
  }).join('');
}

function toggleSched(id, val) {
  const s = scheduledReports.find(r=>r.id===id);
  if(s){ s.enabled=val; toast(`"${s.name}" ${val?'enabled':'disabled'}.`,val?'s':'w'); }
}
function deleteSched(id) {
  const s=scheduledReports.find(r=>r.id===id); if(!s) return;
  if(!confirm(`Delete scheduled report "${s.name}"?`)) return;
  scheduledReports=scheduledReports.filter(r=>r.id!==id);
  renderScheduledList(); toast(`Scheduled report deleted.`,'s');
}
function editSched(id) {
  const s=scheduledReports.find(r=>r.id===id); if(!s) return;
  editSchedId=id;
  document.getElementById('smTitle').textContent='Edit Scheduled Report';
  document.getElementById('smName').value=s.name;
  document.getElementById('smReportType').value=s.type;
  document.getElementById('smFreq').value=s.freq;
  document.getElementById('smDay').value=s.day;
  document.getElementById('smTime').value=s.time;
  document.getElementById('smZone').value=s.zone||'';
  document.getElementById('smNotes').value=s.notes||'';
  emailTags=[...s.recipients]; renderEmailTags();
  document.querySelectorAll('input[name="smFmt"]').forEach(cb=>{ cb.checked=s.formats.includes(cb.value); });
  openScheduleModal();
}

/* ── Schedule Modal ── */
function openScheduleModal() {
  document.getElementById('scheduleModal').classList.add('on');
}
function closeScheduleModal() {
  document.getElementById('scheduleModal').classList.remove('on');
  editSchedId=null; emailTags=[]; renderEmailTags();
  document.getElementById('smTitle').textContent='New Scheduled Report';
  document.getElementById('smName').value='';
  document.getElementById('smReportType').value='';
  document.getElementById('smFreq').value='Weekly';
  document.getElementById('smDay').value='Monday';
  document.getElementById('smTime').value='08:00';
  document.getElementById('smZone').value='';
  document.getElementById('smNotes').value='';
  document.querySelectorAll('input[name="smFmt"]').forEach(cb=>cb.checked=cb.value==='PDF');
}

// Populate report type select in modal
function populateSmReportType() {
  document.getElementById('smReportType').innerHTML = REPORT_TYPES.map(rt=>`<option value="${rt.id}">${rt.name}</option>`).join('');
}

// Email tag input
function renderEmailTags() {
  const wrap=document.getElementById('emailTagsWrap');
  const input=document.getElementById('emailInput');
  wrap.innerHTML='';
  emailTags.forEach((email,i)=>{
    const tag=document.createElement('span');
    tag.className='email-tag';
    tag.innerHTML=`${esc(email)}<span class="et-rm" onclick="removeEmailTag(${i})"><i class="bx bx-x"></i></span>`;
    wrap.appendChild(tag);
  });
  wrap.appendChild(input);
  input.value='';
}
function removeEmailTag(i){ emailTags.splice(i,1); renderEmailTags(); }

document.getElementById('emailInput').addEventListener('keydown', function(e){
  if(e.key==='Enter'||e.key===','){
    e.preventDefault();
    const v=this.value.trim().replace(/,$/,'');
    if(v&&v.includes('@')&&!emailTags.includes(v)){ emailTags.push(v); renderEmailTags(); }
    else if(v) { this.style.borderColor='var(--red)'; setTimeout(()=>this.style.borderColor='',800); }
  }
  if(e.key==='Backspace'&&!this.value&&emailTags.length){ emailTags.pop(); renderEmailTags(); }
});

document.getElementById('smSave').addEventListener('click', () => {
  const name=document.getElementById('smName').value.trim();
  const type=document.getElementById('smReportType').value;
  const freq=document.getElementById('smFreq').value;
  const day =document.getElementById('smDay').value;
  const time=document.getElementById('smTime').value;
  const zone=document.getElementById('smZone').value;
  const notes=document.getElementById('smNotes').value.trim();
  const formats=[...document.querySelectorAll('input[name="smFmt"]:checked')].map(c=>c.value);
  if(!name){ document.getElementById('smName').style.borderColor='var(--red)'; return toast('Schedule name is required.','w'); }
  if(!type){ return toast('Please select a report type.','w'); }
  if(!emailTags.length){ return toast('Add at least one email recipient.','w'); }
  if(!formats.length){ return toast('Select at least one export format.','w'); }

  const nextRun = freq==='Weekly' ? new Date(Date.now()+7*24*3600*1000).toISOString().split('T')[0] : new Date(Date.now()+30*24*3600*1000).toISOString().split('T')[0];

  if(editSchedId) {
    const s=scheduledReports.find(r=>r.id===editSchedId);
    if(s) Object.assign(s,{name,type,freq,day,time,zone,notes,formats,recipients:[...emailTags],nextRun});
    toast(`"${name}" updated.`,'s');
  } else {
    scheduledReports.push({id:schedIdSeq++,name,type,freq,day,time,zone,notes,formats,recipients:[...emailTags],enabled:true,lastRun:'—',nextRun});
    toast(`"${name}" scheduled successfully.`,'s');
  }
  closeScheduleModal(); renderScheduledList();
});

document.getElementById('smCancel').addEventListener('click', closeScheduleModal);
document.getElementById('smClose').addEventListener('click', closeScheduleModal);
document.getElementById('scheduleModal').addEventListener('click', function(e){ if(e.target===this) closeScheduleModal(); });
document.getElementById('openScheduleBtn').addEventListener('click', ()=>{ editSchedId=null; openScheduleModal(); });
document.getElementById('addSchedBtn').addEventListener('click', ()=>{ editSchedId=null; openScheduleModal(); });

/* ── Generate / Apply Filters ── */
document.getElementById('generateBtn').addEventListener('click', () => {
  const fv={zone:document.getElementById('fZone').value, category:document.getElementById('fCategory').value, status:document.getElementById('fStatus').value, tech:document.getElementById('fTech').value, dateFrom:document.getElementById('fDateFrom').value, dateTo:document.getElementById('fDateTo').value, costMin:document.getElementById('fCostMin').value, costMax:document.getElementById('fCostMax').value};
  activeFilters=Object.fromEntries(Object.entries(fv).filter(([,v])=>v));
  reportPage=1; renderReport(); toast('Report generated with current filters.','s');
});
document.getElementById('applyFiltersBtn').addEventListener('click', () => document.getElementById('generateBtn').click());
document.getElementById('clearFiltersBtn').addEventListener('click', () => {
  ['fZone','fCategory','fStatus','fTech'].forEach(id=>document.getElementById(id).value='');
  ['fDateFrom','fDateTo','fCostMin','fCostMax'].forEach(id=>document.getElementById(id).value='');
  activeFilters={}; reportPage=1; renderReport(); toast('Filters cleared.','w');
});

/* ── Export buttons ── */
function fakeExport(fmt){
  const rt=REPORT_TYPES.find(r=>r.id===activeType);
  toast(`Exporting "${rt.name} Report" as ${fmt}…`,'s');
  setTimeout(()=>toast(`${fmt} exported successfully.`,'s'),1200);
}
document.getElementById('exportPdfBtn').addEventListener('click',()=>fakeExport('PDF'));
document.getElementById('exportExcelBtn').addEventListener('click',()=>fakeExport('Excel'));
document.getElementById('exportCsvBtn').addEventListener('click',()=>fakeExport('CSV'));
document.getElementById('pmExportPdf').addEventListener('click',()=>fakeExport('PDF'));

/* ── Preview Modal ── */
document.getElementById('previewBtn').addEventListener('click', () => {
  const rt=REPORT_TYPES.find(r=>r.id===activeType);
  document.getElementById('pmTitle').textContent = rt.name + ' — Report Preview';
  document.getElementById('pmSub').textContent   = `Generated ${new Date().toLocaleDateString('en-PH',{month:'long',day:'numeric',year:'numeric'})} · ${Object.keys(activeFilters).filter(k=>activeFilters[k]).length} filter(s) applied`;
  document.getElementById('pmBody').innerHTML    = buildPreviewDoc(rt);
  document.getElementById('previewModal').classList.add('on');
});
document.getElementById('pmClose').addEventListener('click',()=>document.getElementById('previewModal').classList.remove('on'));
document.getElementById('pmCancel').addEventListener('click',()=>document.getElementById('previewModal').classList.remove('on'));
document.getElementById('previewModal').addEventListener('click',function(e){ if(e.target===this) this.classList.remove('on'); });

function buildPreviewDoc(rt) {
  const kpis = {
    inventory:   [{v:ASSETS_POOL.length,l:'Total Assets'},{v:ASSETS_POOL.filter(a=>a.status==='Active').length,l:'Active'},{v:fM(ASSETS_POOL.reduce((s,a)=>s+a.value,0)),l:'Total Value',money:true}],
    assignment:  [{v:ASSETS_POOL.filter(a=>a.assigned&&a.assigned!=='—').length,l:'Assigned'},{v:ASSETS_POOL.filter(a=>!a.assigned||a.assigned==='—').length,l:'Unassigned'},{v:[...new Set(ASSETS_POOL.map(a=>a.zone))].length,l:'Zones'}],
    maintenance: [{v:MAINTENANCE_POOL.length,l:'Scheduled'},{v:MAINTENANCE_POOL.filter(m=>m.status==='Overdue').length,l:'Overdue'},{v:MAINTENANCE_POOL.filter(m=>m.status==='Upcoming').length,l:'Upcoming'}],
    repair_cost: [{v:REPAIR_POOL.length,l:'Total Repairs'},{v:fM(REPAIR_POOL.reduce((s,r)=>s+r.cost,0)),l:'Total Cost',money:true},{v:REPAIR_POOL.filter(r=>r.status==='Completed').length,l:'Completed'}],
    disposal:    [{v:DISPOSAL_POOL.length,l:'Disposals'},{v:DISPOSAL_POOL.filter(d=>d.status==='Completed').length,l:'Completed'},{v:fM(DISPOSAL_POOL.filter(d=>d.status==='Completed').reduce((s,d)=>s+d.dispVal,0)),l:'Recovered',money:true}],
    depreciation:[{v:ASSETS_POOL.length,l:'Assets'},{v:fM(ASSETS_POOL.reduce((s,a)=>s+a.value,0)),l:'Orig. Value',money:true},{v:ASSETS_POOL.filter(a=>a.carrying<a.value*.1).length,l:'Near Zero Value'}],
    audit:       [{v:AUDIT_POOL.length,l:'Events'},{v:AUDIT_POOL.filter(a=>a.role==='Super Admin').length,l:'By Super Admin'},{v:[...new Set(AUDIT_POOL.map(a=>a.assetId))].length,l:'Assets Affected'}],
    utilization: [{v:UTILIZATION_POOL.reduce((s,z)=>s+z.total,0),l:'Total Assets'},{v:Math.round(UTILIZATION_POOL.reduce((s,z)=>s+z.utilRate,0)/UTILIZATION_POOL.length)+'%',l:'Avg. Utilization'},{v:UTILIZATION_POOL.length,l:'Zones'}],
    ra9184:      [{v:RA9184_POOL.length,l:'Records'},{v:RA9184_POOL.filter(r=>r.overall==='Compliant').length,l:'Compliant'},{v:Math.round(RA9184_POOL.filter(r=>r.overall==='Compliant').length/RA9184_POOL.length*100)+'%',l:'Rate'}],
  };
  const rowData = {
    inventory:  ASSETS_POOL.slice(0,5).map(a=>[a.id,a.name,a.zone.split('–')[0].trim(),a.status,fM(a.value)]),
    assignment: ASSETS_POOL.slice(0,5).map(a=>[a.id,a.name,a.zone.split('–')[0].trim(),a.assigned||'—',a.tech]),
    maintenance:MAINTENANCE_POOL.slice(0,5).map(m=>[m.assetId,m.name,m.type,fD(m.nextDate),m.status]),
    repair_cost:REPAIR_POOL.slice(0,5).map(r=>[r.logId,r.name,r.tech,fD(r.date),fM(r.cost)]),
    disposal:   DISPOSAL_POOL.slice(0,5).map(d=>[d.id,d.name,d.method,fD(d.date),fM(d.dispVal)]),
    depreciation:ASSETS_POOL.slice(0,5).map(a=>[a.id,a.name,a.depr+'%',fM(a.value),'—']),
    audit:      AUDIT_POOL.slice(0,5).map(a=>[a.ts.split(' ')[0],a.name,a.action,a.by,'']),
    utilization:UTILIZATION_POOL.slice(0,5).map(z=>[z.zone.split('–')[0].trim(),z.total,z.active,z.idle,z.utilRate+'%']),
    ra9184:     RA9184_POOL.slice(0,5).map(r=>[r.id,r.name,r.method,r.overall,'']),
  };
  const hdrs = {
    inventory:['Asset ID','Name','Zone','Status','Value'],
    assignment:['Asset ID','Name','Zone','Assigned To','Technician'],
    maintenance:['Asset ID','Name','Type','Next Due','Status'],
    repair_cost:['Log ID','Asset','Technician','Date','Cost'],
    disposal:['Disposal ID','Asset','Method','Date','Value'],
    depreciation:['Asset ID','Name','Depr. Rate','Orig. Value','Net Value'],
    audit:['Date','Asset','Action','By',''],
    utilization:['Zone','Total','Active','Idle','Util. Rate'],
    ra9184:['Disposal ID','Asset','Method','Status',''],
  };
  const kpiList = (kpis[rt.id]||[]);
  return `<div class="preview-doc">
    <div class="preview-doc-hdr">
      <div class="preview-logo">
        <div class="preview-logo-ic"><i class="bx bx-cube-alt" style="color:#fff;font-size:20px"></i></div>
        <div class="preview-logo-text">
          <div class="plt1">MicroFinancial Management System</div>
          <div class="plt2">Asset Lifecycle &amp; Maintenance — ALMS</div>
        </div>
      </div>
      <div class="preview-doc-meta">
        <strong>${rt.name} Report</strong><br>
        Generated: ${new Date().toLocaleDateString('en-PH',{month:'long',day:'numeric',year:'numeric'})}<br>
        Prepared by: Super Admin<br>
        Reference: RPT-${Date.now().toString().slice(-6)}
      </div>
    </div>
    <div class="preview-kpis">${kpiList.map(k=>`<div class="preview-kpi"><div class="pk-v ${k.money?'money':''}">${k.v}</div><div class="pk-l">${k.l}</div></div>`).join('')}</div>
    <div class="preview-section">
      <div class="preview-section-title">Data Summary (First 5 Records)</div>
      <table class="preview-mini-tbl">
        <thead><tr>${(hdrs[rt.id]||[]).filter(h=>h).map(h=>`<th>${h}</th>`).join('')}</tr></thead>
        <tbody>${(rowData[rt.id]||[]).map(row=>`<tr>${row.filter((_,i)=>(hdrs[rt.id]||[])[i]).map(c=>`<td>${esc(String(c||'—'))}</td>`).join('')}</tr>`).join('')}</tbody>
      </table>
    </div>
    <div style="font-size:10.5px;color:#9EB0A2;text-align:center;padding-top:16px;border-top:1px solid #F3F4F6">
      This is a system-generated report. For the full dataset, export as PDF/Excel/CSV. · RA 9184 Compliant · Confidential
    </div>
  </div>`;
}

/* ══════════════════════════════════════════════════════════
   TOAST
   ══════════════════════════════════════════════════════════ */
function toast(msg, type='s') {
  const ic={s:'bx-check-circle',w:'bx-error',d:'bx-error-circle'};
  const el=document.createElement('div');
  el.className=`toast t${type}`;
  el.innerHTML=`<i class="bx ${ic[type]}" style="font-size:18px;flex-shrink:0"></i>${esc(msg)}`;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(()=>{ el.classList.add('out'); setTimeout(()=>el.remove(),320); },3500);
}

/* ══════════════════════════════════════════════════════════
   INIT
   ══════════════════════════════════════════════════════════ */
populateSmReportType();
renderReportTypeList();
renderStats();
renderReport();
renderScheduledList();
</script>
</body>
</html>