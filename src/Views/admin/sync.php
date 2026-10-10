<?php /** @var array $runs @var array $failing */ ?>
<h1>Synchronisation</h1>

<div class="grid">
  <div class="card">
    <div class="n" style="font-size:1.05rem"><?= htmlspecialchars($nextRun) ?></div>
    <div class="l">Next scheduled run</div>
    <div class="s muted">host cron, 03:10 UTC</div>
  </div>
  <div class="card">
    <div class="n" style="font-size:1.05rem"><?= htmlspecialchars($lastRun) ?></div>
    <div class="l">Last run</div>
  </div>
</div>

<p class="muted" style="font-size:.88rem">
  Runs are started by <code>~/fineprint/sync.sh</code> on the host and guarded
  by <code>flock</code>, so a late run is skipped rather than overlapping.
  Triggering from here is not wired up yet — a full fetch takes a few minutes,
  which outlasts a web request, so it needs a queue rather than a button.
  Run it by hand with <code>~/fineprint/sync.sh</code>.
</p>

<?php if ($failing !== []): ?>
<h1 style="font-size:1.1rem">Feeds currently failing</h1>
<table style="margin-bottom:1.5rem">
  <tr><th>Blog</th><th class="right">Failures</th><th>Last error</th></tr>
  <?php foreach ($failing as $f): ?>
  <tr>
    <td><?= htmlspecialchars((string) $f['blog_name']) ?></td>
    <td class="right"><span class="pill warn"><?= (int) $f['failure_count'] ?></span></td>
    <td class="muted" style="font-size:.82rem"><?= htmlspecialchars((string) ($f['last_error'] ?? '')) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<h1 style="font-size:1.1rem">History</h1>
<table>
  <tr><th>When</th><th class="right">OK</th><th class="right">Failed</th>
      <th class="right">Added</th><th class="right">Duration</th></tr>
  <?php foreach ($runs as $r): ?>
  <tr>
    <td><?= htmlspecialchars(substr((string) $r['run_at'], 0, 16)) ?></td>
    <td class="right"><?= (int) $r['feeds_ok'] ?></td>
    <td class="right"><?= ((int) $r['feeds_failed']) > 0
        ? '<span class="pill bad">' . (int) $r['feeds_failed'] . '</span>' : '<span class="muted">0</span>' ?></td>
    <td class="right"><?= number_format((int) $r['articles_added']) ?></td>
    <td class="right muted"><?= (int) $r['duration_seconds'] ?>s</td>
  </tr>
  <?php endforeach; ?>
</table>
