<?php
/** @var string $title @var string $body @var array|null $admin @var string $active @var string $csrf
 *  @var array{tone:string,text:string}|null $flash */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> — FinePrint</title>
<style>
  /* Light by default, dark when the OS asks. Every colour is a token so the
     dark theme is a redefinition rather than a second stylesheet. The sidebar
     is navy in both — dark mode only flips the content area and the cards. */
  :root{
    --bg:#f0f2f5; --card:#fff; --ink:#1f1f1f; --dim:#595959; --faint:#8c8c8c;
    --line:#f0f0f0; --line2:#e5e7eb; --accent:#1677ff; --accent-ink:#fff; --accent-soft:#e6f4ff;
    --side:#001529; --side-ink:#a6adb4; --side-ink-on:#fff; --side-line:rgba(255,255,255,.08);
    --t-blue:#e6f4ff; --t-blue-ink:#0958d9;
    --t-cyan:#e6fffb; --t-cyan-ink:#08979c;
    --t-green:#f6ffed; --t-green-ink:#389e0d;
    --t-pink:#fff1f0; --t-pink-ink:#cf1322;
    --t-violet:#f9f0ff; --t-violet-ink:#722ed1;
    --t-amber:#fffbe6; --t-amber-ink:#d48806;
    --danger:#ff4d4f; --rank-top:#314659; --rank-top-ink:#fff;
    --shadow:0 1px 2px rgba(0,0,0,.03);
    --radius:8px;
  }
  @media(prefers-color-scheme:dark){
    :root{
      --bg:#0f1117; --card:#171a21; --ink:#e9eaee; --dim:#b4b9c3; --faint:#7c8290;
      --line:#262a33; --line2:#30353f; --accent:#4096ff; --accent-ink:#fff; --accent-soft:#111d2c;
      --side:#000f1f; --side-ink:#9aa3ad; --side-ink-on:#fff; --side-line:rgba(255,255,255,.08);
      --t-blue:#111d2c; --t-blue-ink:#85b5ff;
      --t-cyan:#0e2b2b; --t-cyan-ink:#5cdbd3;
      --t-green:#132a13; --t-green-ink:#95de64;
      --t-pink:#2c1618; --t-pink-ink:#ff7875;
      --t-violet:#211533; --t-violet-ink:#b37feb;
      --t-amber:#2b2111; --t-amber-ink:#ffd666;
      --danger:#ff4d4f; --rank-top:#dfe4ea; --rank-top-ink:#0f1117;
      --shadow:0 1px 2px rgba(0,0,0,.4);
    }
  }
  *{box-sizing:border-box}
  html,body{margin:0}
  body{background:var(--bg);color:var(--ink);
       font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",sans-serif;
       -webkit-font-smoothing:antialiased}
  a{color:var(--accent)}
  svg{display:inline-block;vertical-align:middle}

  /* ================= shell: sidebar + topbar + content ================= */
  .nav-toggle{position:absolute;opacity:0;width:0;height:0;pointer-events:none}
  .shell{display:flex;min-height:100vh}

  .sidebar{width:220px;flex:none;background:var(--side);color:var(--side-ink);
           position:sticky;top:0;height:100vh;overflow-y:auto;overflow-x:hidden;
           display:flex;flex-direction:column;transition:width .18s ease}
  .brand{display:flex;align-items:center;gap:.6rem;height:56px;padding:0 1.25rem;
         color:var(--side-ink-on);text-decoration:none;font-weight:800;font-size:1.1rem;
         letter-spacing:-.02em;white-space:nowrap;border-bottom:1px solid var(--side-line)}
  .brand-mark{width:28px;height:28px;border-radius:7px;background:var(--accent);flex:none;
              display:grid;place-items:center}
  .brand-mark svg{width:16px;height:16px;stroke:#fff}
  .brand span{color:var(--accent)}
  .sidenav{padding:.6rem 0 1.5rem;flex:1}
  .nav-g{font-size:.68rem;text-transform:uppercase;letter-spacing:.09em;color:var(--side-ink);
         opacity:.6;padding:1rem 1.25rem .35rem;white-space:nowrap}
  .nav-i{display:flex;align-items:center;gap:.75rem;padding:.62rem 1.25rem;color:var(--side-ink);
         text-decoration:none;font-size:.9rem;white-space:nowrap;border-left:3px solid transparent;
         margin-left:-3px}
  .nav-i:hover{color:var(--side-ink-on)}
  .nav-i.on{background:var(--accent);color:var(--side-ink-on);font-weight:500}
  .nav-i svg{width:18px;height:18px;flex:none}
  .side-foot{padding:.9rem 1.25rem;border-top:1px solid var(--side-line);font-size:.74rem;
             opacity:.55;white-space:nowrap}

  .wrap{flex:1;min-width:0;display:flex;flex-direction:column}
  .topbar{height:56px;background:var(--card);border-bottom:1px solid var(--line2);
          display:flex;align-items:center;gap:1rem;padding:0 1.25rem;position:sticky;top:0;z-index:5}
  .burger{width:36px;height:36px;border-radius:6px;display:grid;place-items:center;cursor:pointer;
          color:var(--dim);margin-left:-.5rem}
  .burger:hover{background:var(--bg);color:var(--ink)}
  .burger svg{width:20px;height:20px}
  .crumb{font-size:.86rem;color:var(--faint)}
  .crumb b{color:var(--ink);font-weight:500}
  .me{margin-left:auto;display:flex;align-items:center;gap:.65rem}
  .avatar{width:32px;height:32px;border-radius:50%;background:var(--accent);color:#fff;
          display:grid;place-items:center;font-weight:700;font-size:.78rem;flex:none}
  .me-n{font-size:.88rem;font-weight:600;line-height:1.2}
  .me-r{font-size:.72rem;color:var(--faint)}
  .me form{margin:0}
  .signout{display:inline}
  .linkbtn{background:none;border:0;color:var(--faint);font:inherit;font-size:.72rem;
           cursor:pointer;padding:0;text-decoration:underline}
  .linkbtn:hover{color:var(--accent)}

  main,.content{padding:1.25rem 1.25rem 3rem;width:100%;max-width:1400px}
  .scrim{display:none}

  /* Desktop: the hamburger collapses the sidebar to an icon rail. */
  @media(min-width:901px){
    .nav-toggle:checked ~ .shell .sidebar{width:64px}
    .nav-toggle:checked ~ .shell .brand{padding:0 1.1rem}
    .nav-toggle:checked ~ .shell .brand-t,
    .nav-toggle:checked ~ .shell .nav-g,
    .nav-toggle:checked ~ .shell .nav-i span,
    .nav-toggle:checked ~ .shell .side-foot{display:none}
    .nav-toggle:checked ~ .shell .nav-i{justify-content:center;padding:.7rem 0}
  }
  /* Mobile: the sidebar is an off-canvas drawer the hamburger opens. */
  @media(max-width:900px){
    .sidebar{position:fixed;left:0;top:0;bottom:0;height:100vh;z-index:30;
             transform:translateX(-100%);transition:transform .18s ease;box-shadow:0 0 0 rgba(0,0,0,0)}
    .nav-toggle:checked ~ .shell .sidebar{transform:none;box-shadow:0 0 40px rgba(0,0,0,.45)}
    .scrim{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:25;display:none}
    .nav-toggle:checked ~ .shell .scrim{display:block}
    main,.content{padding:1rem 1rem 3rem}
    .topbar{padding:0 1rem}
    .me-r{display:none} .signout .linkbtn{font-size:.8rem}
  }

  /* ================= page furniture ================= */
  .head{display:flex;align-items:baseline;gap:1rem;flex-wrap:wrap;margin:.1rem 0 1.1rem}
  h1{font-size:1.35rem;font-weight:600;letter-spacing:-.01em;margin:.2rem 0 1rem}
  .head h1{margin:0}
  .sub{color:var(--faint);font-size:.84rem;margin-left:auto}

  /* ---- KPI tiles (tinted variants kept for modules that use them) ---- */
  .kpis,.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;margin-bottom:1rem}
  .kpi{border-radius:var(--radius);padding:1rem 1.15rem;background:var(--card);border:1px solid var(--line2);
       box-shadow:var(--shadow)}
  .kpi .k-l{font-size:.8rem;color:var(--faint);margin-bottom:.4rem}
  .kpi .k-n{font-size:1.7rem;font-weight:600;letter-spacing:-.02em;line-height:1.15;font-variant-numeric:tabular-nums}
  .kpi .k-s{font-size:.78rem;color:var(--dim);margin-top:.4rem}
  .kpi.fill{background:var(--accent);color:var(--accent-ink);border-color:var(--accent)}
  .kpi.fill .k-l,.kpi.fill .k-s{color:inherit;opacity:.85}
  .kpi.blue{background:var(--t-blue);color:var(--t-blue-ink);border-color:transparent}
  .kpi.cyan{background:var(--t-cyan);color:var(--t-cyan-ink);border-color:transparent}
  .kpi.green{background:var(--t-green);color:var(--t-green-ink);border-color:transparent}
  .kpi.pink{background:var(--t-pink);color:var(--t-pink-ink);border-color:transparent}
  .kpi.violet{background:var(--t-violet);color:var(--t-violet-ink);border-color:transparent}
  .kpi.blue .k-l,.kpi.cyan .k-l,.kpi.green .k-l,.kpi.pink .k-l,.kpi.violet .k-l,
  .kpi.blue .k-s,.kpi.cyan .k-s,.kpi.green .k-s,.kpi.pink .k-s,.kpi.violet .k-s{color:inherit;opacity:.8}
  /* legacy stat markup (.grid > .card > .n/.l/.s) used by older views */
  .grid .card .n{font-size:1.5rem;font-weight:600;font-variant-numeric:tabular-nums}
  .grid .card .l{font-size:.8rem;color:var(--faint)}
  .grid .card .s{font-size:.76rem}

  /* ---- stat cards (reference style: label, number, secondary, hairline, caption) ---- */
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;margin-bottom:1rem}
  .stat{display:flex;flex-direction:column;min-height:170px;padding:1rem 1.25rem .75rem}
  .stat-l{display:flex;align-items:center;font-size:.86rem;color:var(--faint)}
  .stat-l .info{margin-left:auto;width:16px;height:16px;color:var(--faint);opacity:.7}
  .stat-n{font-size:1.75rem;font-weight:500;letter-spacing:-.01em;line-height:1.25;margin:.35rem 0 .4rem;
          font-variant-numeric:tabular-nums}
  .stat-body{flex:1;display:flex;flex-direction:column;justify-content:flex-end;min-height:46px}
  .stat-s{display:flex;align-items:center;gap:.6rem;font-size:.84rem;color:var(--dim);line-height:1.9}
  .stat-s b{font-weight:500;color:var(--ink);font-variant-numeric:tabular-nums}
  .stat-s .up{color:var(--t-green-ink)} .stat-s .down{color:var(--t-pink-ink)}
  .stat-s .arrow{width:0;height:0;border-left:4px solid transparent;border-right:4px solid transparent;display:inline-block}
  .stat-s .up .arrow{border-bottom:6px solid currentColor}
  .stat-s .down .arrow{border-top:6px solid currentColor}
  .spark{display:block;width:100%;height:46px}
  .spark svg{width:100%;height:46px;display:block}
  .stat-foot{border-top:1px solid var(--line2);margin-top:.6rem;padding-top:.6rem;font-size:.84rem;
             color:var(--dim);display:flex;gap:.4rem;align-items:center}
  .stat-foot b{color:var(--ink);font-weight:500;font-variant-numeric:tabular-nums}

  /* ---- cards ---- */
  .card{background:var(--card);border-radius:var(--radius);padding:1.15rem 1.25rem;
        border:1px solid var(--line2);box-shadow:var(--shadow)}
  .card-h{display:flex;align-items:center;gap:.75rem;margin-bottom:1rem}
  .card-h h2{font-size:.95rem;font-weight:500;margin:0}
  .card-h .act{margin-left:auto;font-size:.8rem;color:var(--faint);text-decoration:none}
  .card-h .act:hover{color:var(--accent)}
  .row2{display:grid;grid-template-columns:1.25fr 1fr;gap:1rem;margin-bottom:1rem}
  .row3{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:1rem;margin-bottom:1rem}
  @media(max-width:900px){.row2{grid-template-columns:1fr}}

  /* ---- tabbed card (CSS-only, radio-driven) ---- */
  .tabcard{padding:0;margin-bottom:1rem}
  .tabcard > input{position:absolute;opacity:0;width:0;height:0;pointer-events:none}
  .tabs-h{display:flex;align-items:center;gap:0;border-bottom:1px solid var(--line2);padding:0 1.25rem;
          flex-wrap:wrap}
  .tabs-h label{padding:.9rem .4rem;margin-right:1.5rem;font-size:.92rem;color:var(--dim);cursor:pointer;
                border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap}
  .tabs-h label:hover{color:var(--accent)}
  .tabs-h .seg{margin-left:auto}
  .tab-grid{display:grid;grid-template-columns:1fr 300px;gap:1.5rem;padding:1.25rem}
  .tab-pane{display:none;min-width:0}
  .tab-pane h3,.rank-wrap h3{font-size:.95rem;font-weight:500;margin:0 0 1rem}
  @media(max-width:1100px){.tab-grid{grid-template-columns:1fr}}
  #tab-a:checked ~ .tabs-h label[for=tab-a],
  #tab-b:checked ~ .tabs-h label[for=tab-b]{color:var(--accent);border-bottom-color:var(--accent)}
  #tab-a:checked ~ .tab-grid .pane-a,
  #tab-b:checked ~ .tab-grid .pane-b{display:block}

  /* ---- numbered ranking ---- */
  .rank{list-style:none;margin:0;padding:0}
  .rank li{display:flex;align-items:center;gap:.9rem;padding:.42rem 0;font-size:.88rem}
  .rank-n{width:20px;height:20px;border-radius:50%;flex:none;display:grid;place-items:center;
          font-size:.72rem;font-weight:600;background:var(--bg);color:var(--dim)}
  .rank-n.top{background:var(--rank-top);color:var(--rank-top-ink)}
  .rank-l{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .rank-v{color:var(--dim);font-variant-numeric:tabular-nums}

  /* ---- segmented control (decorative: one range of data exists) ---- */
  .seg{display:flex;gap:.15rem;background:var(--bg);border-radius:6px;padding:.2rem}
  .seg span{font-size:.74rem;padding:.22rem .6rem;border-radius:4px;color:var(--faint)}
  .seg span.on{background:var(--card);color:var(--ink);font-weight:500;box-shadow:var(--shadow)}

  /* ---- donut + legend ---- */
  .donut{display:flex;align-items:center;gap:1.1rem;flex-wrap:wrap}
  .legend{flex:1;min-width:140px;display:flex;flex-direction:column;gap:.42rem}
  .lg{display:flex;align-items:center;gap:.5rem;font-size:.84rem}
  .lg i{width:9px;height:9px;border-radius:2px;flex:none}
  .lg b{margin-left:auto;font-weight:500;color:var(--dim);font-variant-numeric:tabular-nums}

  /* ---- horizontal bars ---- */
  .hbar{display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem;font-size:.84rem}
  .hbar-l{width:40%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .hbar-t{flex:1;height:8px;background:var(--bg);border-radius:4px;overflow:hidden}
  .hbar-f{display:block;height:100%;border-radius:4px}
  .hbar-v{width:58px;text-align:right;font-weight:500;color:var(--dim);font-variant-numeric:tabular-nums}

  /* ---- tables ---- */
  table{width:100%;border-collapse:collapse}
  th,td{text-align:left;padding:.6rem .5rem;font-size:.86rem;border-bottom:1px solid var(--line)}
  th{font-size:.74rem;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);font-weight:600;
     background:var(--bg)}
  thead th:first-child,tr:first-child th:first-child{border-top-left-radius:6px}
  thead th:last-child,tr:first-child th:last-child{border-top-right-radius:6px}
  tr:last-child td{border-bottom:0}
  tbody tr:hover td,table tr:hover td{background:color-mix(in srgb,var(--bg) 55%,transparent)}
  .right{text-align:right;font-variant-numeric:tabular-nums}
  .muted{color:var(--faint)}

  .pill{display:inline-block;padding:.1rem .5rem;border-radius:4px;font-size:.74rem;font-weight:500;
        line-height:1.5;border:1px solid transparent;white-space:nowrap}
  .pill.ok{background:var(--t-green);color:var(--t-green-ink);border-color:color-mix(in srgb,var(--t-green-ink) 35%,transparent)}
  .pill.warn{background:var(--t-amber);color:var(--t-amber-ink);border-color:color-mix(in srgb,var(--t-amber-ink) 35%,transparent)}
  .pill.bad{background:var(--t-pink);color:var(--t-pink-ink);border-color:color-mix(in srgb,var(--t-pink-ink) 35%,transparent)}
  .pill.info{background:var(--t-blue);color:var(--t-blue-ink);border-color:color-mix(in srgb,var(--t-blue-ink) 35%,transparent)}

  .flash{padding:.65rem 1rem;border-radius:var(--radius);margin-bottom:.9rem;font-size:.86rem;
         display:flex;gap:.5rem;align-items:flex-start;border:1px solid transparent}
  .flash.ok{background:var(--t-green);color:var(--t-green-ink);border-color:color-mix(in srgb,var(--t-green-ink) 35%,transparent)}
  .flash.bad{background:var(--t-pink);color:var(--t-pink-ink);border-color:color-mix(in srgb,var(--t-pink-ink) 35%,transparent)}
  .flash.warn{background:var(--t-amber);color:var(--t-amber-ink);border-color:color-mix(in srgb,var(--t-amber-ink) 35%,transparent)}
  .flash.info{background:var(--t-blue);color:var(--t-blue-ink);border-color:color-mix(in srgb,var(--t-blue-ink) 35%,transparent)}

  /* ---- buttons ---- */
  .btn,button.btn,a.btn{display:inline-block;font:inherit;font-size:.82rem;font-weight:500;padding:.38rem .85rem;
       border-radius:6px;border:1px solid var(--line2);background:var(--card);color:var(--ink);
       cursor:pointer;text-decoration:none;line-height:1.5;white-space:nowrap;box-shadow:var(--shadow)}
  .btn:hover,button.btn:hover{border-color:var(--accent);color:var(--accent)}
  .btn.warn:hover,button.btn.warn:hover{border-color:var(--danger);color:var(--danger)}
  .btn.primary,button.btn.primary{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
  .btn.primary:hover,button.btn.primary:hover{background:color-mix(in srgb,var(--accent) 85%,#000);color:var(--accent-ink)}
  .btn.danger,button.btn.danger{background:var(--danger);border-color:var(--danger);color:#fff}
  .btn.danger:hover,button.btn.danger:hover{background:color-mix(in srgb,var(--danger) 85%,#000);color:#fff}
  .btn.sm{font-size:.76rem;padding:.22rem .6rem}
  .btn[disabled],button.btn[disabled]{opacity:.5;cursor:not-allowed}
  /* older views use a bare <button> inside a form */
  td form button:not(.btn){font:inherit;font-size:.78rem;padding:.25rem .6rem;border-radius:6px;
      border:1px solid var(--line2);background:var(--card);color:var(--ink);cursor:pointer}
  td form button:not(.btn):hover{border-color:var(--accent);color:var(--accent)}
  td form button.danger:not(.btn):hover{border-color:var(--danger);color:var(--danger)}

  /* ---- forms ---- */
  label{display:block;font-size:.82rem;font-weight:500;color:var(--dim);margin:.9rem 0 .3rem}
  input[type=text],input[type=password],input[type=search],input[type=email],input[type=url],
  input[type=number],input[type=date],select,textarea{
    width:100%;padding:.5rem .75rem;font:inherit;font-size:.9rem;
    border:1px solid var(--line2);border-radius:6px;background:var(--card);color:var(--ink)}
  select{appearance:none;-webkit-appearance:none;padding-right:2rem;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238c8c8c' stroke-width='2'><path d='M6 9l6 6 6-6'/></svg>");
    background-repeat:no-repeat;background-position:right .6rem center;background-size:14px}
  textarea{min-height:110px;resize:vertical;line-height:1.5}
  input:focus,select:focus,textarea:focus{outline:0;border-color:var(--accent);
    box-shadow:0 0 0 2px color-mix(in srgb,var(--accent) 20%,transparent)}
  .field{margin-bottom:.4rem}
  .inline-form{display:flex;gap:.5rem;align-items:end;flex-wrap:wrap}
  .inline-form label{margin:0 0 .3rem}
  .inline-form > div{min-width:140px}
  code{background:var(--bg);padding:.1rem .35rem;border-radius:4px;font-size:.84em;border:1px solid var(--line)}

  /* ---- login (no sidebar) ---- */
  body.is-login{display:flex;flex-direction:column;min-height:100vh}
  body.is-login main{margin:auto;max-width:100%;padding:2rem 1rem}
  .login{max-width:380px;margin:0 auto}
  .login-logo{display:flex;align-items:center;justify-content:center;gap:.6rem;margin-bottom:1.5rem;
              font-weight:800;font-size:1.5rem;letter-spacing:-.02em;color:var(--ink)}
  .login-logo .brand-mark{width:36px;height:36px;border-radius:9px}
  .login-logo .brand-mark svg{width:20px;height:20px}
  .login-logo span{color:var(--accent)}
  .login .card{padding:1.9rem 2rem}
  .login h1{font-size:1.25rem;margin:0 0 .2rem}
  .login button[type=submit]{width:100%;margin-top:1.3rem;padding:.6rem;background:var(--accent);
    color:var(--accent-ink);border:1px solid var(--accent);border-radius:6px;font:inherit;font-weight:500;
    font-size:.95rem;cursor:pointer}
  .login button[type=submit]:hover{background:color-mix(in srgb,var(--accent) 85%,#000)}
  .login-foot{text-align:center;color:var(--faint);font-size:.78rem;margin-top:1.25rem}
</style>
</head>
<?php
$icon = static fn(string $d): string =>
  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
  . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$mark = $icon('<path d="M4 6h16"/><path d="M4 12h10"/><path d="M4 18h7"/>');
$flashHtml = '';
if (!empty($flash)) {
  $flashHtml = '<div class="flash ' . $e($flash['tone']) . '"><span>' . $e($flash['text']) . '</span></div>';
}
if ($admin === null): ?>
<body class="is-login">
<main>
<?= $flashHtml ?>
<?= $body ?>
</main>
</body>
</html>
<?php return; endif;

$initials = strtoupper(substr(trim((string) ($admin['name'] ?? $admin['email'] ?? '')), 0, 2));
$groups = [];
foreach (\App\Admin\Nav::items() as $it) {
  $groups[$it['group'] ?? ''][] = $it;
}
?>
<body>
<input type="checkbox" id="nav-toggle" class="nav-toggle" aria-hidden="true">
<div class="shell">
  <aside class="sidebar">
    <a class="brand" href="/">
      <span class="brand-mark"><?= $mark ?></span>
      <span class="brand-t">Fine<span>Print</span></span>
    </a>
    <nav class="sidenav" aria-label="Main">
      <?php foreach ($groups as $g => $items): ?>
        <?php if ($g !== ''): ?><div class="nav-g"><?= $e($g) ?></div><?php endif; ?>
        <?php foreach ($items as $it): ?>
          <a class="nav-i<?= $active === $it['slug'] ? ' on' : '' ?>" href="/<?= $e($it['slug']) ?>"
             <?= $active === $it['slug'] ? 'aria-current="page"' : '' ?>>
            <?= $icon($it['icon']) ?><span><?= $e($it['label']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">FinePrint admin</div>
  </aside>
  <label for="nav-toggle" class="scrim" aria-hidden="true"></label>

  <div class="wrap">
    <header class="topbar">
      <label for="nav-toggle" class="burger" title="Toggle navigation">
        <?= $icon('<path d="M3 6h18"/><path d="M3 12h18"/><path d="M3 18h18"/>') ?>
      </label>
      <div class="crumb">Admin / <b><?= $e($title) ?></b></div>
      <div class="me">
        <div class="avatar"><?= $e($initials) ?></div>
        <div>
          <div class="me-n"><?= $e($admin['name'] ?: $admin['email']) ?></div>
          <div class="me-r">Administrator</div>
        <form method="post" action="/logout" class="signout">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <button class="linkbtn">Sign out</button>
            </form>
          </div>
        </div>
      </div>
    </header>

    <main class="content">
<?= $flashHtml ?>
<?= $body ?>
    </main>
  </div>
</div>
</body>
</html>
