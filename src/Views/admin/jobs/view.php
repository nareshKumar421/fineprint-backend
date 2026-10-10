<?php
use App\Controllers\Admin\JobsController as J;
/** @var array $job @var array $params @var bool $liveFor @var bool $stuck @var string $csrf */
$e  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$ts = fn($v) => $v === null || $v === '' ? '—' : substr((string) $v, 0, 19) . ' UTC';
$tone = J::TONES[$job['status']] ?? 'info';
?>
<?php if ($liveFor): ?><meta http-equiv="refresh" content="5"><?php endif; ?>

<div class="head">
  <h1>Job #<?= (int) $job['id'] ?> <span class="muted" style="font-weight:500">· <?= $e($job['type']) ?></span></h1>
  <span class="sub"><a href="/jobs" style="color:inherit">← All jobs</a></span>
</div>

<?php if ($stuck): ?>
  <div class="flash warn"><strong>!</strong><span>
    Queued for over two minutes and no worker has claimed it. The server may not allow
    <code>exec</code>/<code>setsid</code>, or the worker failed before claiming the row. It can be run
    by hand: <code>php jobs/run_job.php <?= (int) $job['id'] ?></code>
  </span></div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi <?= $tone === 'muted' ? '' : $e(['info' => 'blue', 'warn' => 'pink', 'ok' => 'green', 'bad' => 'pink'][$tone] ?? '') ?>">
    <div class="k-l">Status</div>
    <div class="k-n" style="font-size:1.35rem"><?= $e($job['status']) ?></div>
    <div class="k-s"><?= $liveFor ? 'this page refreshes every 5 s' : 'exit code ' . $e($job['exit_code'] ?? '—') ?></div>
  </div>
  <div class="kpi">
    <div class="k-l">Requested</div>
    <div class="k-n" style="font-size:1.05rem"><?= $e($ts($job['requested_at'])) ?></div>
    <div class="k-s">by <?= $e($job['requested_by_email'] ?? 'system') ?></div>
  </div>
  <div class="kpi">
    <div class="k-l">Started</div>
    <div class="k-n" style="font-size:1.05rem"><?= $e($ts($job['started_at'])) ?></div>
    <div class="k-s"><?= $job['started_at'] === null ? 'not yet claimed' : ($job['finished_at'] === null ? 'running for ' : 'took ') . $e($job['duration']) ?></div>
  </div>
  <div class="kpi">
    <div class="k-l">Finished</div>
    <div class="k-n" style="font-size:1.05rem"><?= $e($ts($job['finished_at'])) ?></div>
    <div class="k-s">
      <?php if ($job['retry_of'] !== null): ?>retry of <a href="/jobs/view?id=<?= (int) $job['retry_of'] ?>">#<?= (int) $job['retry_of'] ?></a><?php endif; ?>
      <?php if ($job['retried_by'] !== null): ?>retried as <a href="/jobs/view?id=<?= (int) $job['retried_by'] ?>">#<?= (int) $job['retried_by'] ?></a><?php endif; ?>
      <?php if ($job['retry_of'] === null && $job['retried_by'] === null): ?>&nbsp;<?php endif; ?>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <div class="card-h">
    <h2>Summary</h2>
    <div style="margin-left:auto;display:flex;gap:.5rem">
      <?php if (in_array($job['status'], ['failed', 'cancelled'], true)): ?>
        <form method="post" action="/jobs/retry" style="margin:0"
              onsubmit="return confirm('Run this <?= $e($job['type']) ?> job again as a new job?')">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
          <button class="btn">Retry</button>
        </form>
      <?php endif; ?>
      <?php if ($job['status'] === 'queued'): ?>
        <form method="post" action="/jobs/cancel" style="margin:0"
              onsubmit="return confirm('Cancel job #<?= (int) $job['id'] ?> before it starts?')">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
          <button class="btn warn">Cancel</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <p style="margin:0 0 .6rem"><?= $job['summary'] !== null && $job['summary'] !== '' ? $e($job['summary']) : '<span class="muted">' . ($liveFor ? 'no result yet' : 'no summary recorded') . '</span>' ?></p>
  <table>
    <tr><th style="width:160px">Type</th><td><?= $e($job['type']) ?></td></tr>
    <?php if ($params !== []): ?>
      <?php foreach ($params as $key => $val): ?>
        <tr><th><?= $e($key) ?></th><td><code><?= $e(is_scalar($val) ? $val : json_encode($val)) ?></code></td></tr>
      <?php endforeach; ?>
    <?php endif; ?>
    <tr><th>Exit code</th><td><?= $job['exit_code'] === null ? '<span class="muted">—</span>' : (int) $job['exit_code'] ?></td></tr>
    <tr><th>Duration</th><td><?= $e($job['duration']) ?></td></tr>
  </table>
</div>

<div class="card">
  <div class="card-h"><h2>Output</h2>
    <span class="act"><?= $job['output'] === null ? '' : number_format(strlen((string) $job['output'])) . ' bytes, last 64 KB kept' ?></span>
  </div>
  <?php if ($job['output'] === null || trim((string) $job['output']) === ''): ?>
    <p class="muted" style="padding:1.5rem 0;text-align:center"><?= $liveFor ? 'Output appears here when the job finishes.' : 'The job produced no output.' ?></p>
  <?php else: ?>
    <pre style="margin:0;background:var(--bg);padding:1rem;border-radius:10px;overflow:auto;max-height:65vh;font-size:.78rem;line-height:1.45;white-space:pre-wrap;word-break:break-word"><?= $e($job['output']) ?></pre>
  <?php endif; ?>
</div>
