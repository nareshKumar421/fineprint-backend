<?php
require_once __DIR__ . '/../_charts.php';
/** @var string $php @var string $os @var string $sapi @var array $db @var array $tables
 *  @var ?array $lastSync @var array $jobs @var ?int $uptime
 *  @var bool $syncEnabled @var array $env @var array $checks @var string $appEnv */
use App\Controllers\Admin\SystemController as S;

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$colors = fp_palette();
$totalRows = array_sum($tables);
$maxRows   = $tables === [] ? 1 : max(1, max($tables));
$passed    = count(array_filter($checks, static fn($c) => $c['state'] === 'ok'));
$jobTone   = static fn(string $s): string => match ($s) {
    'done' => 'ok', 'failed', 'cancelled' => 'bad', 'running', 'queued' => 'info', default => 'info',
};
?>
<div class="head">
  <h1>System</h1>
  <span class="sub">
    <?= $e($appEnv) ?> · <?= $syncEnabled ? 'sync enabled' : 'sync DISABLED' ?>
    · <?= $passed ?>/<?= count($checks) ?> checks passing
  </span>
</div>

<?php foreach ($checks as $c): if ($c['state'] === 'bad'): ?>
  <div class="flash bad"><strong>!</strong><span><?= $e($c['label']) ?> — <?= $e($c['detail']) ?></span></div>
<?php endif; endforeach; ?>

<div class="kpis">
  <div class="kpi fill">
    <div class="k-l">Database size</div>
    <div class="k-n"><?= $e(S::bytes($db['db_size'] ?? 0)) ?></div>
    <div class="k-s"><?= $e($db['db_name'] ?? '?') ?> as <?= $e($db['db_user'] ?? '?') ?> · <?= (int) ($db['connections'] ?? 0) ?> connection(s)</div>
  </div>
  <div class="kpi cyan">
    <div class="k-l">Tables</div>
    <div class="k-n"><?= count($tables) ?></div>
    <div class="k-s"><?= number_format($totalRows) ?> rows in all</div>
  </div>
  <div class="kpi violet">
    <div class="k-l">PostgreSQL</div>
    <div class="k-n" style="font-size:1.4rem"><?= $e($db['server_version'] ?? '?') ?></div>
    <div class="k-s">server version</div>
  </div>
  <div class="kpi green">
    <div class="k-l">PHP</div>
    <div class="k-n" style="font-size:1.4rem"><?= $e($php) ?></div>
    <div class="k-s"><?= $e($sapi) ?> · <?= $e($os) ?></div>
  </div>
  <div class="kpi pink">
    <div class="k-l">Container uptime</div>
    <div class="k-n" style="font-size:1.4rem"><?= $uptime === null ? '—' : $e(S::ago($uptime)) ?></div>
    <div class="k-s"><?= $uptime === null ? '/proc/1 not readable' : 'since PID 1 started (mtime of /proc/1)' ?></div>
  </div>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h">
      <h2>Checks</h2>
      <span class="act"><?= $passed ?> of <?= count($checks) ?> passing</span>
    </div>
    <table>
      <?php foreach ($checks as $c): ?>
      <tr>
        <td style="width:70px"><span class="pill <?= $e($c['state']) ?>"><?= $c['state'] === 'ok' ? 'pass' : $e($c['state']) ?></span></td>
        <td>
          <?= $e($c['label']) ?><br>
          <span class="muted" style="font-size:.8rem"><?= $e($c['detail']) ?></span>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <div class="card-h">
      <h2>Latest sync</h2>
      <span class="pill <?= $syncEnabled ? 'ok' : 'bad' ?>"><?= $syncEnabled ? 'sync.enabled = true' : 'sync.enabled = false' ?></span>
      <a class="act" href="/sync">Sync →</a>
    </div>
    <?php if ($lastSync === null): ?>
      <p class="muted">No sync recorded.</p>
    <?php else: ?>
    <table style="margin-bottom:1rem">
      <tr><th>Run at (UTC)</th><th class="right">OK</th><th class="right">Failed</th><th class="right">Added</th><th class="right">Duration</th></tr>
      <tr>
        <td><?= $e(substr((string) $lastSync['run_at'], 0, 16)) ?></td>
        <td class="right"><?= (int) ($lastSync['feeds_ok'] ?? 0) ?></td>
        <td class="right"><?= (int) ($lastSync['feeds_failed'] ?? 0) > 0
            ? '<span class="pill bad">' . (int) $lastSync['feeds_failed'] . '</span>' : '<span class="muted">0</span>' ?></td>
        <td class="right"><?= number_format((int) ($lastSync['articles_added'] ?? 0)) ?></td>
        <td class="right muted"><?= (int) ($lastSync['duration_seconds'] ?? 0) ?>s</td>
      </tr>
    </table>
    <?php endif; ?>

    <div class="card-h" style="margin-top:.5rem"><h2 style="font-size:.9rem">Latest job per type</h2></div>
    <?php if ($jobs === []): ?>
      <p class="muted" style="margin:0;font-size:.86rem">No dashboard jobs have run yet.</p>
    <?php else: ?>
    <table>
      <tr><th>Type</th><th>Status</th><th>Requested</th><th>Summary</th></tr>
      <?php foreach ($jobs as $j): ?>
      <tr>
        <td><code><?= $e($j['type']) ?></code></td>
        <td>
          <span class="pill <?= $jobTone((string) $j['status']) ?>"><?= $e($j['status']) ?></span>
          <?php if ($j['exit_code'] !== null): ?><span class="muted" style="font-size:.76rem"> exit <?= (int) $j['exit_code'] ?></span><?php endif; ?>
        </td>
        <td class="muted" style="white-space:nowrap"><?= $e(substr((string) $j['requested_at'], 0, 16)) ?></td>
        <td class="muted" style="font-size:.8rem;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $e($j['summary'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="row3">
  <div class="card">
    <div class="card-h"><h2>Row counts</h2><span class="act"><?= count($tables) ?> tables</span></div>
    <?php if ($tables === []): ?>
      <p class="muted">No tables in the public schema.</p>
    <?php else: ?>
    <ol class="rank">
      <?php $i = 0; foreach ($tables as $name => $n): $i++; ?>
      <li>
        <span class="rank-n<?= $i <= 3 ? ' top' : '' ?>"><?= $i ?></span>
        <span class="rank-l">
          <?= $e($name) ?>
          <span class="hbar-t" style="display:block;margin-top:.25rem"><span class="hbar-f" style="width:<?= max(2, (int) round($n / $maxRows * 100)) ?>%;background:<?= $colors[($i - 1) % count($colors)] ?>"></span></span>
        </span>
        <span class="rank-v"><?= number_format((int) $n) ?></span>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-h"><h2>Environment</h2><span class="act"><?= count($env) ?> variables</span></div>
    <p class="muted" style="margin:0 0 .6rem;font-size:.8rem">
      Values are never shown here — only whether each is set, and its length.
    </p>
    <?php if ($env === []): ?>
      <p class="muted">No FEED_, APP_, TOKEN_, PAYMENTS_ or DONATION_ variables in the environment.</p>
    <?php else: ?>
    <table>
      <tr><th>Name</th><th>Status</th><th class="right">Length</th></tr>
      <?php foreach ($env as $name => $v): ?>
      <tr>
        <td><code><?= $e($name) ?></code></td>
        <td><span class="pill <?= $v['set'] ? 'ok' : 'warn' ?>"><?= $v['set'] ? 'set' : 'empty' ?></span></td>
        <td class="right muted"><?= (int) $v['length'] ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
