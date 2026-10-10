<?php
use App\Admin\Paginator;
use App\Controllers\Admin\JobsController as J;
/** @var array $m @var ?string $lastRun @var ?string $lastAge @var bool $stale @var string $nextRun
 *  @var ?array $live @var array $runs @var int $total @var array $p @var array $failing @var string $csrf */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<?php if ($live !== null): ?><meta http-equiv="refresh" content="10"><?php endif; ?>

<div class="head">
  <h1>Sync</h1>
  <span class="sub"><?= number_format($total) ?> run<?= $total === 1 ? '' : 's' ?> on record</span>
</div>

<?php if ($stale): ?>
  <div class="flash bad"><strong>!</strong><span>
    <?= $lastRun === null ? 'No sync has ever run.' : 'No sync in over 36 hours.' ?>
    The feed still serves, but it has stopped learning.
  </span></div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi fill">
    <div class="k-l">Last run</div>
    <div class="k-n" style="font-size:1.1rem"><?= $lastRun === null ? 'never' : $e($lastRun) . ' UTC' ?></div>
    <div class="k-s"><?= $lastAge === null ? '&nbsp;' : $e($lastAge) ?></div>
  </div>
  <div class="kpi blue">
    <div class="k-l">Next scheduled</div>
    <div class="k-n" style="font-size:1.1rem"><?= $e($nextRun) ?></div>
    <div class="k-s">nightly workflow, 03:10 UTC</div>
  </div>
  <div class="kpi cyan">
    <div class="k-l">Runs, last 7 days</div>
    <div class="k-n"><?= (int) ($m['runs_7d'] ?? 0) ?></div>
    <div class="k-s"><?= (int) ($m['failed_7d'] ?? 0) ?> feed failure<?= (int) ($m['failed_7d'] ?? 0) === 1 ? '' : 's' ?> across them</div>
  </div>
  <div class="kpi green">
    <div class="k-l">Articles added, 7 days</div>
    <div class="k-n"><?= number_format((int) ($m['added_7d'] ?? 0)) ?></div>
    <div class="k-s"><?= (int) ($m['runs_7d'] ?? 0) > 0 ? number_format((int) ($m['added_7d'] ?? 0) / (int) $m['runs_7d']) . ' per run' : 'no runs' ?></div>
  </div>
  <div class="kpi <?= (int) ($m['failing_now'] ?? 0) + (int) ($m['dead_now'] ?? 0) > 0 ? 'pink' : '' ?>">
    <div class="k-l">Sources needing attention</div>
    <div class="k-n"><?= (int) ($m['failing_now'] ?? 0) + (int) ($m['dead_now'] ?? 0) ?></div>
    <div class="k-s"><?= (int) ($m['failing_now'] ?? 0) ?> failing, <?= (int) ($m['dead_now'] ?? 0) ?> deactivated</div>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="card-h">
    <h2>Run now</h2>
    <div style="margin-left:auto">
      <?php if ($live === null): ?>
        <form method="post" action="/jobs/run" style="margin:0"
              onsubmit="return confirm(<?= $e(json_encode(J::CONFIRMS['sync'])) ?>)">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="type" value="sync">
          <button class="btn">Sync now</button>
        </form>
      <?php else: ?>
        <a href="/jobs/view?id=<?= (int) $live['id'] ?>" class="pill <?= $e(J::TONES[$live['status']]) ?>" style="text-decoration:none">
          <?= $e($live['status']) ?> · job #<?= (int) $live['id'] ?></a>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($live !== null): ?>
    <p style="margin:0;font-size:.88rem">
      A sync is <?= $e($live['status']) ?><?= $live['status'] === 'running' ? ' for ' . $e(J::duration($live['elapsed_s'])) : '' ?>,
      requested by <?= $e($live['requested_by_email'] ?? 'system') ?>.
      This page refreshes every 10 s; the <a href="/jobs/view?id=<?= (int) $live['id'] ?>">job page</a> shows the output when it finishes.
    </p>
  <?php else: ?>
    <p class="muted" style="margin:0;font-size:.86rem">
      Fetches every active source, stores new articles and purges old ones. Takes a few minutes and runs
      detached from this page, so it is safe to navigate away. A second click while one is live is refused.
    </p>
  <?php endif; ?>
</div>

<?php if ($failing !== []): ?>
<div class="card" style="margin-bottom:1.1rem">
  <div class="card-h"><h2>Feeds currently failing</h2><a class="act" href="/sources">Sources →</a></div>
  <table>
    <tr><th>Blog</th><th class="right">Failures</th><th>Last error</th></tr>
    <?php foreach ($failing as $f): ?>
    <tr>
      <td><?= $e($f['blog_name']) ?><?= \App\Admin\Page::truthy($f['is_active']) ? '' : ' <span class="pill bad">deactivated</span>' ?></td>
      <td class="right"><span class="pill warn"><?= (int) $f['failure_count'] ?></span></td>
      <td class="muted" style="font-size:.82rem;max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $e($f['last_error'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h"><h2>History</h2><span class="act">from sync_status, newest first</span></div>
  <?php if ($runs === []): ?>
    <p class="muted" style="padding:2rem 0;text-align:center">No sync has run yet.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table>
    <tr><th>When</th><th class="right">OK</th><th class="right">Failed</th>
        <th class="right">Added</th><th class="right">Duration</th><th></th></tr>
    <?php foreach ($runs as $r): $failed = (int) $r['feeds_failed']; ?>
    <tr>
      <td style="white-space:nowrap"><a href="/sync/view?run_at=<?= $e(rawurlencode($r['key'])) ?>" style="color:inherit;font-weight:600;text-decoration:none"><?= $e(substr((string) $r['run_at'], 0, 16)) ?></a></td>
      <td class="right"><?= (int) $r['feeds_ok'] ?></td>
      <td class="right"><?= $failed > 0 ? '<span class="pill bad">' . $failed . '</span>' : '<span class="pill ok">0</span>' ?></td>
      <td class="right"><?= number_format((int) $r['articles_added']) ?></td>
      <td class="right muted"><?= $e(J::duration($r['duration_seconds'])) ?></td>
      <td class="right"><a href="/sync/view?run_at=<?= $e(rawurlencode($r['key'])) ?>" class="muted" style="font-size:.8rem">details →</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>
  <div class="muted" style="display:flex;gap:.8rem;align-items:center;margin-top:1rem;font-size:.82rem;flex-wrap:wrap">
    <span>Showing <?= (int) $p['from'] ?>–<?= (int) $p['to'] ?> of <?= number_format($total) ?></span>
    <?php if ($p['pages'] > 1): ?>
      <span style="margin-left:auto">Page <?= (int) $p['page'] ?> of <?= (int) $p['pages'] ?></span>
      <?php if ($p['page'] > 1): ?><a href="<?= $e(Paginator::link($p['page'] - 1)) ?>">← Newer</a><?php endif; ?>
      <?php if ($p['page'] < $p['pages']): ?><a href="<?= $e(Paginator::link($p['page'] + 1)) ?>">Older →</a><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
