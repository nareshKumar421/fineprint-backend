<?php
/**
 * Admin layout. Every page renders through this.
 *
 * Server-rendered PHP with no build step and no client framework, matching
 * the project's "no framework, no runtime dependencies" rule. A dashboard
 * used by one or two people does not justify a toolchain.
 *
 * $title, $body and $admin are supplied by the controller. $body is ALREADY
 * escaped by its own template — it is the only value here printed raw.
 */
/** @var string $title @var string $body @var array|null $admin @var string $active */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($title) ?> — FinePrint</title>
<style>
  :root{--bg:#f6f7f9;--card:#fff;--ink:#1a1d21;--dim:#6b7280;--line:#e5e7eb;
        --accent:#2563eb;--ok:#15803d;--warn:#b45309;--bad:#b91c1c;--okbg:#dcfce7;--warnbg:#fef3c7;--badbg:#fee2e2}
  @media(prefers-color-scheme:dark){:root{--bg:#16181c;--card:#1f2228;--ink:#e8eaed;--dim:#9aa0a6;
        --line:#2c3036;--accent:#60a5fa;--okbg:#14321f;--warnbg:#3a2d0b;--badbg:#3a1414}}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
       font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
  header{background:var(--card);border-bottom:1px solid var(--line);padding:.7rem 1.25rem;
         display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap}
  header .brand{font-weight:650;letter-spacing:-.01em}
  nav{display:flex;gap:.25rem;flex-wrap:wrap}
  nav a{color:var(--dim);text-decoration:none;padding:.35rem .7rem;border-radius:6px;font-size:.92rem}
  nav a:hover{background:var(--bg);color:var(--ink)}
  nav a.on{background:var(--accent);color:#fff}
  header .who{margin-left:auto;color:var(--dim);font-size:.88rem;display:flex;gap:.75rem;align-items:center}
  main{max-width:1100px;margin:1.5rem auto;padding:0 1.25rem}
  h1{font-size:1.4rem;margin:0 0 1rem}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:.85rem;margin-bottom:1.5rem}
  .card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.9rem 1rem}
  .card .n{font-size:1.65rem;font-weight:650;letter-spacing:-.02em}
  .card .l{color:var(--dim);font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;margin-top:.15rem}
  .card .s{font-size:.82rem;margin-top:.3rem}
  table{width:100%;border-collapse:collapse;background:var(--card);
        border:1px solid var(--line);border-radius:10px;overflow:hidden}
  th,td{text-align:left;padding:.6rem .8rem;border-bottom:1px solid var(--line);font-size:.9rem}
  th{font-weight:600;color:var(--dim);font-size:.78rem;text-transform:uppercase;letter-spacing:.04em}
  tr:last-child td{border-bottom:0}
  .pill{display:inline-block;padding:.1rem .5rem;border-radius:999px;font-size:.76rem;font-weight:600}
  .pill.ok{background:var(--okbg);color:var(--ok)} .pill.warn{background:var(--warnbg);color:var(--warn)}
  .pill.bad{background:var(--badbg);color:var(--bad)}
  .muted{color:var(--dim)} .right{text-align:right}
  button,.btn{font:inherit;font-size:.85rem;padding:.35rem .7rem;border-radius:6px;
              border:1px solid var(--line);background:var(--card);color:var(--ink);cursor:pointer}
  button:hover{border-color:var(--accent);color:var(--accent)}
  button.danger:hover{border-color:var(--bad);color:var(--bad)}
  .flash{padding:.7rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.9rem}
  .flash.ok{background:var(--okbg);color:var(--ok)} .flash.bad{background:var(--badbg);color:var(--bad)}
  .login{max-width:360px;margin:12vh auto;padding:0 1.25rem}
  .login .card{padding:1.5rem}
  label{display:block;font-size:.85rem;color:var(--dim);margin:.85rem 0 .25rem}
  input[type=text],input[type=password]{width:100%;padding:.55rem .7rem;font:inherit;
    border:1px solid var(--line);border-radius:7px;background:var(--bg);color:var(--ink)}
  .login button{width:100%;margin-top:1.2rem;padding:.6rem;background:var(--accent);
    color:#fff;border-color:transparent;font-weight:600}
  code{background:var(--bg);padding:.1rem .3rem;border-radius:4px;font-size:.85em}
</style>
</head>
<body>
<?php if ($admin !== null): ?>
<header>
  <span class="brand">FinePrint</span>
  <nav>
    <?php foreach (['' => 'Overview', 'sources' => 'Sources', 'sync' => 'Sync'] as $slug => $label): ?>
      <a class="<?= $active === $slug ? 'on' : '' ?>" href="/<?= $slug ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <span class="who">
    <?= htmlspecialchars($admin['name'] ?: $admin['email']) ?>
    <form method="post" action="/logout" style="display:inline">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
      <button>Sign out</button>
    </form>
  </span>
</header>
<?php endif; ?>
<main><?= $body ?></main>
</body>
</html>
