<?php
/** @var string $title @var string $body @var array|null $admin @var string $active @var string $csrf */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($title) ?> — FinePrint</title>
<style>
  /* Light by default, dark when the OS asks. Every colour is a token so the
     dark theme is a redefinition rather than a second stylesheet. */
  :root{
    --bg:#f4f5fa; --card:#fff; --ink:#111827; --dim:#6b7280; --faint:#9ca3af;
    --line:#edeff5; --accent:#2a44d4; --accent-ink:#fff;
    --t-blue:#eaf0ff; --t-blue-ink:#2a44d4;
    --t-cyan:#e6f6fd; --t-cyan-ink:#0369a1;
    --t-green:#e8f8ef; --t-green-ink:#15803d;
    --t-pink:#fdeaef; --t-pink-ink:#be123c;
    --t-violet:#efeafd; --t-violet-ink:#6d28d9;
    --shadow:0 1px 2px rgba(16,24,40,.04), 0 8px 24px -12px rgba(16,24,40,.12);
  }
  @media(prefers-color-scheme:dark){
    :root{
      --bg:#0f1117; --card:#171a21; --ink:#e9eaee; --dim:#9aa0ab; --faint:#6b7280;
      --line:#242832; --accent:#5b7cfa; --accent-ink:#fff;
      --t-blue:#1b2340; --t-blue-ink:#9db4ff;
      --t-cyan:#102a38; --t-cyan-ink:#7dd3fc;
      --t-green:#12301f; --t-green-ink:#6ee7a8;
      --t-pink:#331520; --t-pink-ink:#fda4af;
      --t-violet:#241a3d; --t-violet-ink:#c4b5fd;
      --shadow:0 1px 2px rgba(0,0,0,.4);
    }
  }
  *{box-sizing:border-box}
  html,body{margin:0}
  body{background:var(--bg);color:var(--ink);
       font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",sans-serif;
       -webkit-font-smoothing:antialiased}

  /* ---- top bar ---- */
  .top{max-width:1240px;margin:0 auto;padding:1.1rem 1.5rem;
       display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap}
  .logo{font-weight:800;letter-spacing:-.03em;font-size:1.05rem;color:var(--accent);
        text-decoration:none;white-space:nowrap}
  .logo span{color:var(--ink)}
  .tabs{display:flex;gap:.3rem;background:var(--card);padding:.3rem;border-radius:12px;
        box-shadow:var(--shadow);flex-wrap:wrap}
  .tabs a{display:flex;align-items:center;gap:.4rem;padding:.48rem .85rem;border-radius:9px;
          text-decoration:none;color:var(--dim);font-size:.88rem;font-weight:500;white-space:nowrap}
  .tabs a:hover{color:var(--ink)}
  .tabs a.on{background:var(--accent);color:var(--accent-ink)}
  .tabs svg{width:15px;height:15px;flex:none}
  .me{margin-left:auto;display:flex;align-items:center;gap:.7rem}
  .avatar{width:34px;height:34px;border-radius:50%;background:var(--accent);color:#fff;
          display:grid;place-items:center;font-weight:700;font-size:.82rem;flex:none}
  .me-n{font-size:.86rem;font-weight:600;line-height:1.2}
  .me-r{font-size:.74rem;color:var(--faint)}
  .me form{margin:0}
  .linkbtn{background:none;border:0;color:var(--faint);font:inherit;font-size:.78rem;
           cursor:pointer;padding:0;text-decoration:underline}
  .linkbtn:hover{color:var(--ink)}

  /* ---- shell ---- */
  main{max-width:1240px;margin:0 auto;padding:.5rem 1.5rem 3rem}
  .head{display:flex;align-items:baseline;gap:1rem;flex-wrap:wrap;margin:.5rem 0 1.4rem}
  h1{font-size:1.75rem;font-weight:800;letter-spacing:-.03em;margin:0}
  .sub{color:var(--faint);font-size:.86rem;margin-left:auto}

  /* ---- KPI tiles ---- */
  .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;margin-bottom:1.1rem}
  .kpi{border-radius:14px;padding:1.05rem 1.15rem;box-shadow:var(--shadow);background:var(--card)}
  .kpi .k-l{font-size:.78rem;font-weight:600;opacity:.75;margin-bottom:.45rem}
  .kpi .k-n{font-size:1.85rem;font-weight:800;letter-spacing:-.035em;line-height:1}
  .kpi .k-s{font-size:.76rem;opacity:.72;margin-top:.45rem}
  .kpi.fill{background:var(--accent);color:var(--accent-ink)}
  .kpi.fill .k-l,.kpi.fill .k-s{opacity:.85}
  .kpi.blue{background:var(--t-blue);color:var(--t-blue-ink)}
  .kpi.cyan{background:var(--t-cyan);color:var(--t-cyan-ink)}
  .kpi.green{background:var(--t-green);color:var(--t-green-ink)}
  .kpi.pink{background:var(--t-pink);color:var(--t-pink-ink)}
  .kpi.violet{background:var(--t-violet);color:var(--t-violet-ink)}

  /* ---- cards ---- */
  .card{background:var(--card);border-radius:14px;padding:1.15rem 1.25rem;box-shadow:var(--shadow)}
  .card-h{display:flex;align-items:center;gap:.75rem;margin-bottom:1rem}
  .card-h h2{font-size:1rem;font-weight:700;margin:0;letter-spacing:-.01em}
  .card-h .act{margin-left:auto;font-size:.8rem;color:var(--faint);text-decoration:none}
  .card-h .act:hover{color:var(--accent)}
  .row2{display:grid;grid-template-columns:1.25fr 1fr;gap:1rem;margin-bottom:1.1rem}
  .row3{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:1rem}
  @media(max-width:900px){.row2{grid-template-columns:1fr}}

  /* ---- segmented control (decorative: one range of data exists) ---- */
  .seg{display:flex;gap:.15rem;background:var(--bg);border-radius:9px;padding:.2rem}
  .seg span{font-size:.74rem;padding:.26rem .6rem;border-radius:7px;color:var(--faint)}
  .seg span.on{background:var(--card);color:var(--ink);font-weight:600;box-shadow:var(--shadow)}

  /* ---- donut + legend ---- */
  .donut{display:flex;align-items:center;gap:1.1rem;flex-wrap:wrap}
  .legend{flex:1;min-width:140px;display:flex;flex-direction:column;gap:.42rem}
  .lg{display:flex;align-items:center;gap:.5rem;font-size:.82rem}
  .lg i{width:9px;height:9px;border-radius:3px;flex:none}
  .lg b{margin-left:auto;font-weight:600;color:var(--dim);font-variant-numeric:tabular-nums}

  /* ---- horizontal bars ---- */
  .hbar{display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem;font-size:.83rem}
  .hbar-l{width:40%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .hbar-t{flex:1;height:8px;background:var(--bg);border-radius:99px;overflow:hidden}
  .hbar-f{display:block;height:100%;border-radius:99px}
  .hbar-v{width:58px;text-align:right;font-weight:600;color:var(--dim);font-variant-numeric:tabular-nums}

  /* ---- tables ---- */
  table{width:100%;border-collapse:collapse}
  th,td{text-align:left;padding:.52rem .3rem;font-size:.84rem;border-bottom:1px solid var(--line)}
  th{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--faint);font-weight:600}
  tr:last-child td{border-bottom:0}
  .right{text-align:right;font-variant-numeric:tabular-nums}
  .muted{color:var(--faint)}

  .pill{display:inline-block;padding:.13rem .55rem;border-radius:99px;font-size:.73rem;font-weight:600}
  .pill.ok{background:var(--t-green);color:var(--t-green-ink)}
  .pill.warn{background:var(--t-pink);color:var(--t-pink-ink)}
  .pill.bad{background:var(--t-pink);color:var(--t-pink-ink)}
  .pill.info{background:var(--t-blue);color:var(--t-blue-ink)}

  .flash{padding:.7rem 1rem;border-radius:11px;margin-bottom:.9rem;font-size:.86rem;
         display:flex;gap:.5rem;align-items:flex-start}
  .flash.ok{background:var(--t-green);color:var(--t-green-ink)}
  .flash.bad{background:var(--t-pink);color:var(--t-pink-ink)}

  button.btn{font:inherit;font-size:.8rem;font-weight:600;padding:.35rem .75rem;border-radius:8px;
             border:1px solid var(--line);background:var(--card);color:var(--dim);cursor:pointer}
  button.btn:hover{border-color:var(--accent);color:var(--accent)}
  button.btn.warn:hover{border-color:#be123c;color:#be123c}

  /* ---- login ---- */
  .login{max-width:390px;margin:11vh auto;padding:0 1.5rem}
  .login .card{padding:1.9rem}
  .login h1{font-size:1.45rem}
  label{display:block;font-size:.8rem;font-weight:600;color:var(--dim);margin:1rem 0 .3rem}
  input[type=text],input[type=password]{width:100%;padding:.62rem .8rem;font:inherit;font-size:.92rem;
    border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink)}
  input:focus{outline:2px solid var(--accent);outline-offset:1px;border-color:transparent}
  .login button[type=submit]{width:100%;margin-top:1.35rem;padding:.68rem;background:var(--accent);
    color:var(--accent-ink);border:0;border-radius:10px;font:inherit;font-weight:700;cursor:pointer}
  code{background:var(--bg);padding:.1rem .35rem;border-radius:5px;font-size:.84em}
</style>
</head>
<body>
<?php if ($admin !== null):
  $icon = static fn(string $d): string =>
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
    . ' stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
  $nav = [
    ''        => ['Overview', '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>'],
    'sources' => ['Sources',  '<path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1.5"/>'],
    'sync'    => ['Sync',     '<path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>'],
  ];
  $initials = strtoupper(substr(trim((string) ($admin['name'] ?: $admin['email'])), 0, 2));
?>
<div class="top">
  <a class="logo" href="/">Fine<span>Print</span></a>
  <nav class="tabs">
    <?php foreach ($nav as $slug => [$label, $path]): ?>
      <a class="<?= $active === $slug ? 'on' : '' ?>" href="/<?= $slug ?>"><?= $icon($path) ?><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="me">
    <div class="avatar"><?= htmlspecialchars($initials) ?></div>
    <div>
      <div class="me-n"><?= htmlspecialchars($admin['name'] ?: $admin['email']) ?></div>
      <div class="me-r">Administrator ·
        <form method="post" action="/logout" style="display:inline">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <button class="linkbtn">Sign out</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<main><?= $body ?></main>
</body>
</html>
