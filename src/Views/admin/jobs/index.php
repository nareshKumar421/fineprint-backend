<?php
use App\Admin\Paginator;
use App\Controllers\Admin\JobsController as J;
/** @var array $rows @var int $total @var array $p @var array $k @var array $filters @var array $live @var string $csrf */
$e  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$ts = fn($v) => $v === null || $v === '' ? '—' : substr((string) $v, 0, 19);
$liveByType = [];
foreach ($live as $l) {
    $liveByType[$l['type']] = $l;
}
?>
<div class="head">
  <h1>Jobs</h1>
  <span class="sub"><?= number_format($total) ?> job<?= $total === 1 ? '' : 's' ?> recorded</span>
</div>

<div class="kpis">
  <div class="kpi blue">
    <div class="k-l">Queued</div>
    <div class="k-n"><?= (int) ($k['queued'] ?? 0) ?></div>
    <div class="k-s">waiting for a worker</div>
  </div>
  <div class="kpi cyan">
    <div class="k-l">Running</div>
    <div class="k-n"><?= (int) ($k['running'] ?? 0) ?></div>
    <div class="k-s">one per type at most</div>
  </div>
  <div class="kpi green">
    <div class="k-l">Done, last 7 days</div>
    <div class="k-n"><?= (int) ($k['done_7d'] ?? 0) ?></div>
    <div class="k-s">exit code 0</div>
  </div>
  <div class="kpi pink">
    <div class="k-l">Failed, last 7 days</div>
    <div class="k-n"><?= (int) ($k['failed_7d'] ?? 0) ?></div>
    <div class="k-s">retry from the job page</div>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="card-h">
    <h2>Run a job</h2>
    <span class="act">Each starts a worker and returns at once; the job page shows progress.</span>
  </div>
  <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
    <?php foreach (['sync', 'rollup', 'cleanup', 'report'] as $t): $busy = $liveByType[$t] ?? null; ?>
      <?php if ($busy !== null): ?>
        <a class="btn" style="display:inline-block;text-decoration:none;font-size:.8rem;font-weight:600;padding:.35rem .75rem;border-radius:8px;border:1px solid var(--line);color:var(--dim)"
           href="/jobs/view?id=<?= (int) $busy['id'] ?>"><?= $e(J::LABELS[$t]) ?> · <span class="pill <?= $e(J::TONES[$busy['status']]) ?>"><?= $e($busy['status']) ?> #<?= (int) $busy['id'] ?></span></a>
      <?php else: ?>
        <form method="post" action="/jobs/run" style="margin:0"
              onsubmit="return confirm(<?= $e(json_encode(J::CONFIRMS[$t])) ?>)">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="type" value="<?= $e($t) ?>">
          <button class="btn <?= $t === 'cleanup' ? 'warn' : '' ?>"><?= $e(J::LABELS[$t]) ?></button>
        </form>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <form method="post" action="/jobs/run" style="margin:.9rem 0 0;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center"
        onsubmit="return confirm(<?= $e(json_encode(J::CONFIRMS['validate_feed'])) ?>)">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="type" value="validate_feed">
    <input type="text" name="feed_url" placeholder="https://example.com/feed.xml" required
           style="flex:1;min-width:240px;max-width:520px" <?= isset($liveByType['validate_feed']) ? 'disabled' : '' ?>>
    <?php if (isset($liveByType['validate_feed'])): ?>
      <a class="muted" style="font-size:.82rem" href="/jobs/view?id=<?= (int) $liveByType['validate_feed']['id'] ?>">
        a feed check is already running (#<?= (int) $liveByType['validate_feed']['id'] ?>)</a>
    <?php else: ?>
      <button class="btn">Validate feed</button>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="card-h">
    <h2>History</h2>
    <form method="get" action="/jobs" style="margin-left:auto;display:flex;gap:.45rem;flex-wrap:wrap;align-items:center">
      <select name="type" style="font:inherit;font-size:.8rem;padding:.3rem .5rem;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink)">
        <option value="">All types</option>
        <?php foreach (J::TYPES as $t): ?>
          <option value="<?= $e($t) ?>" <?= $filters['type'] === $t ? 'selected' : '' ?>><?= $e($t) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" style="font:inherit;font-size:.8rem;padding:.3rem .5rem;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink)">
        <option value="">All statuses</option>
        <?php foreach (J::STATUSES as $s): ?>
          <option value="<?= $e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= $e($s) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="q" value="<?= $e($filters['q']) ?>" placeholder="summary or email"
             style="width:170px;font-size:.8rem;padding:.3rem .5rem">
      <input type="hidden" name="sort" value="<?= $e($filters['sort']) ?>">
      <input type="hidden" name="dir" value="<?= $e($filters['dir']) ?>">
      <button class="btn">Filter</button>
      <?php if ($filters['type'] !== '' || $filters['status'] !== '' || $filters['q'] !== ''): ?>
        <a class="muted" style="font-size:.8rem" href="/jobs">clear</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($rows === []): ?>
    <p class="muted" style="padding:2rem 0;text-align:center">
      <?= $total === 0 && $filters['type'] === '' && $filters['status'] === '' && $filters['q'] === ''
          ? 'No jobs yet. Use the buttons above to run one.' : 'No jobs match these filters.' ?>
    </p>
  <?php else: ?>
  <?php
    $sortLink = function (string $col, string $label) use ($filters, $e): string {
        $q = $_GET;
        $q['sort'] = $col;
        $q['dir']  = ($filters['sort'] === $col && $filters['dir'] === 'desc') ? 'asc' : 'desc';
        unset($q['page']);
        $mark = $filters['sort'] === $col ? ($filters['dir'] === 'asc' ? ' ↑' : ' ↓') : '';
        return '<a href="?' . $e(http_build_query($q)) . '" style="color:inherit;text-decoration:none">' . $e($label) . $mark . '</a>';
    };
  ?>
  <div style="overflow-x:auto">
  <table>
    <tr>
      <th>#</th><th><?= $sortLink('type', 'Type') ?></th><th><?= $sortLink('status', 'Status') ?></th>
      <th>Requested by</th><th><?= $sortLink('requested_at', 'Requested') ?></th>
      <th class="right">Duration</th><th>Summary</th>
    </tr>
    <?php foreach ($rows as $r): $tone = J::TONES[$r['status']] ?? 'info'; ?>
    <tr>
      <td class="muted"><a href="/jobs/view?id=<?= (int) $r['id'] ?>" style="color:inherit"><?= (int) $r['id'] ?></a></td>
      <td><a href="/jobs/view?id=<?= (int) $r['id'] ?>" style="color:inherit;font-weight:600;text-decoration:none"><?= $e($r['type']) ?></a>
          <?php if ($r['retry_of'] !== null): ?><span class="muted" style="font-size:.76rem">retry of #<?= (int) $r['retry_of'] ?></span><?php endif; ?></td>
      <td><span class="pill <?= $e($tone) ?>"<?= $tone === 'muted' ? ' style="background:var(--line)"' : '' ?>><?= $e($r['status']) ?></span></td>
      <td class="muted" style="font-size:.8rem"><?= $e($r['requested_by_email'] ?? 'system') ?></td>
      <td class="muted" style="font-size:.8rem;white-space:nowrap"><?= $e($ts($r['requested_at'])) ?></td>
      <td class="right muted"><?= $e($r['duration']) ?></td>
      <td style="max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem">
        <?= $e($r['summary'] ?? '') ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>

  <div class="muted" style="display:flex;gap:.8rem;align-items:center;margin-top:1rem;font-size:.82rem;flex-wrap:wrap">
    <span>Showing <?= (int) $p['from'] ?>–<?= (int) $p['to'] ?> of <?= number_format($total) ?></span>
    <?php if ($p['pages'] > 1): ?>
      <span style="margin-left:auto">Page <?= (int) $p['page'] ?> of <?= (int) $p['pages'] ?></span>
      <?php if ($p['page'] > 1): ?><a href="<?= $e(Paginator::link($p['page'] - 1)) ?>">← Prev</a><?php endif; ?>
      <?php if ($p['page'] < $p['pages']): ?><a href="<?= $e(Paginator::link($p['page'] + 1)) ?>">Next →</a><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
