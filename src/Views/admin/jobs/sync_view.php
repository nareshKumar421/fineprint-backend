<?php
require_once __DIR__ . '/../_charts.php';
use App\Controllers\Admin\JobsController as J;
/** @var array $run @var string $runKey @var array $series @var array $byCategory @var array $failing @var ?array $job */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$failed  = (int) $run['feeds_failed'];
$ok      = (int) $run['feeds_ok'];
$colors  = fp_palette();
?>
<div class="head">
  <h1>Sync run <span class="muted" style="font-weight:500"><?= $e(substr((string) $run['run_at'], 0, 16)) ?> UTC</span></h1>
  <span class="sub"><a href="/sync" style="color:inherit">← All runs</a></span>
</div>

<div class="kpis">
  <div class="kpi green">
    <div class="k-l">Feeds OK</div>
    <div class="k-n"><?= $ok ?></div>
    <div class="k-s"><?= $ok + $failed > 0 ? round($ok / ($ok + $failed) * 100) . '% of ' . ($ok + $failed) . ' attempted' : 'nothing attempted' ?></div>
  </div>
  <div class="kpi <?= $failed > 0 ? 'pink' : '' ?>">
    <div class="k-l">Feeds failed</div>
    <div class="k-n"><?= $failed ?></div>
    <div class="k-s"><?= $failed > 0 ? 'see the sources below' : 'a clean run' ?></div>
  </div>
  <div class="kpi blue">
    <div class="k-l">Articles added</div>
    <div class="k-n"><?= number_format((int) $run['articles_added']) ?></div>
    <div class="k-s">new rows, after dedup</div>
  </div>
  <div class="kpi cyan">
    <div class="k-l">Duration</div>
    <div class="k-n"><?= $e(J::duration($run['duration_seconds'])) ?></div>
    <div class="k-s">finished <?= $e(substr((string) $run['run_at'], 11, 8)) ?> UTC</div>
  </div>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h"><h2>Articles added per run</h2><div class="seg"><span class="on">12 runs to here</span></div></div>
    <?= fp_bars($series, 200, '#34d399') ?>
  </div>
  <div class="card">
    <div class="card-h"><h2>Stored by this run</h2><span class="act">per category, still present</span></div>
    <?php if ($byCategory === []): ?>
      <p class="muted">Nothing from this run is still stored — either it added nothing, or the purge has since removed it.</p>
    <?php else: $max = max($byCategory); $i = 0; foreach ($byCategory as $name => $n): ?>
      <?= fp_hbar((string) $name, (int) $n, (int) $max, $colors[$i % count($colors)]) ?>
    <?php $i++; endforeach; endif; ?>
  </div>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h"><h2>Sources with failures</h2><span class="act">current state, best effort</span></div>
    <?php if ($failing === []): ?>
      <p class="muted">No source is failing or carries an error right now.</p>
    <?php else: ?>
    <p class="muted" style="font-size:.8rem;margin:-.4rem 0 .7rem">
      blog_sources keeps only the latest state, so this is who is failing <em>now</em>, not a log of this run.
      <?= $failed === 0 ? 'This run reported no failures; the entries below come from other runs.' : '' ?>
    </p>
    <div style="overflow-x:auto">
    <table>
      <tr><th>Blog</th><th class="right">Failures</th><th>Last error</th></tr>
      <?php foreach ($failing as $f): ?>
      <tr>
        <td><?= $e($f['blog_name']) ?>
          <?= \App\Admin\Page::truthy($f['is_active']) ? '' : ' <span class="pill bad">deactivated</span>' ?><br>
          <span class="muted" style="font-size:.76rem"><?= $e($f['feed_url']) ?></span></td>
        <td class="right"><?= (int) $f['failure_count'] > 0 ? '<span class="pill warn">' . (int) $f['failure_count'] . '</span>' : '<span class="muted">0</span>' ?></td>
        <td class="muted" style="font-size:.8rem;max-width:300px;overflow:hidden;text-overflow:ellipsis"><?= $e($f['last_error'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-h"><h2>Job record</h2><a class="act" href="/jobs?type=sync">All sync jobs →</a></div>
    <?php if ($job === null): ?>
      <p class="muted">No dashboard job matches this run (started within 5 minutes of it). It was most likely the nightly workflow.</p>
    <?php else: ?>
      <table>
        <tr><th style="width:140px">Job</th><td><a href="/jobs/view?id=<?= (int) $job['id'] ?>">#<?= (int) $job['id'] ?></a>
            <span class="pill <?= $e(J::TONES[$job['status']] ?? 'info') ?>"><?= $e($job['status']) ?></span></td></tr>
        <tr><th>Requested by</th><td><?= $e($job['requested_by_email'] ?? 'system') ?></td></tr>
        <tr><th>Started</th><td><?= $e(substr((string) $job['started_at'], 0, 19)) ?> UTC</td></tr>
        <tr><th>Duration</th><td><?= $e($job['duration']) ?></td></tr>
        <tr><th>Exit code</th><td><?= $job['exit_code'] === null ? '—' : (int) $job['exit_code'] ?></td></tr>
        <tr><th>Summary</th><td style="font-size:.82rem"><?= $e($job['summary'] ?? '') ?></td></tr>
      </table>
    <?php endif; ?>
  </div>
</div>
