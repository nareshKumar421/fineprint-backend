<?php
require_once __DIR__ . '/_charts.php';
/** @var array $m @var array $warnings @var array $ingest @var array $syncSeries
 *  @var array $categories @var array $topSources @var array $health @var array $recentSync */
$e      = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$colors = fp_palette();
$num    = static fn($v): string => number_format((int) $v);
$pct    = static fn(int $part, int $whole): string => $whole > 0 ? round($part / $whole * 100) . '%' : '—';

$articles     = (int) ($m['articles'] ?? 0);
$inWindow     = (int) ($m['in_window'] ?? 0);
$today        = (int) ($m['today'] ?? 0);
$avgDay       = (int) ($m['avg_day'] ?? 0);
$srcTotal     = (int) ($m['sources_total'] ?? 0);
$srcActive    = (int) ($m['sources_active'] ?? 0);
$srcDead      = (int) ($m['sources_dead'] ?? 0);
$srcFailing   = (int) ($m['sources_failing'] ?? 0);
$users        = (int) ($m['users'] ?? 0);
$events       = (int) ($m['events'] ?? 0);
$ingestTotal  = array_sum($ingest);
$ingestDays   = count($ingest);
$lastDay      = $ingest === [] ? 0 : (int) end($ingest);
$todayVsAvg   = $avgDay > 0 ? (int) round(($today - $avgDay) / $avgDay * 100) : null;
$perReader    = $users > 0 ? round($events / $users, 1) : 0;
$info = '<svg class="info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'
      . '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg>';
?>
<div class="head">
  <h1>Overview</h1>
  <span class="sub"><?= $e($m['window_label'] ?? '') ?></span>
</div>

<?php foreach ($warnings as $w): ?>
  <div class="flash warn"><strong>!</strong><span><?= $e($w) ?></span></div>
<?php endforeach; ?>

<div class="stats">
  <!-- 1. Total articles, with the fortnight's ingestion as a sparkline -->
  <div class="card stat">
    <div class="stat-l">Total articles <?= $info ?></div>
    <div class="stat-n"><?= $num($articles) ?></div>
    <div class="stat-body">
      <div class="spark" title="Articles ingested per day, last <?= (int) $ingestDays ?> days">
        <?= fp_spark($ingest, 240, 46, $colors[0]) ?>
      </div>
    </div>
    <div class="stat-foot">In the 30-day window <b><?= $num($inWindow) ?></b>
      <span class="muted">(<?= $e($pct($inWindow, $articles)) ?>)</span></div>
  </div>

  <!-- 2. Active sources, with the health split as secondary stats -->
  <div class="card stat">
    <div class="stat-l">Active sources <?= $info ?></div>
    <div class="stat-n"><?= $num($srcActive) ?></div>
    <div class="stat-body">
      <div class="stat-s">Healthy <b><?= $num($health['healthy'] ?? 0) ?></b></div>
      <div class="stat-s">Failing
        <b class="<?= $srcFailing > 0 ? 'down' : '' ?>"><?= $num($srcFailing) ?></b>
        <?php if ($srcFailing > 0): ?><span class="down"><i class="arrow"></i></span><?php endif; ?>
      </div>
    </div>
    <div class="stat-foot">Deactivated <b><?= $num($srcDead) ?></b>
      <span class="muted">of <?= $num($srcTotal) ?> sources</span></div>
  </div>

  <!-- 3. Ingested today, against the daily average -->
  <div class="card stat">
    <div class="stat-l">Ingested today <?= $info ?></div>
    <div class="stat-n"><?= $num($today) ?></div>
    <div class="stat-body">
      <div class="stat-s">Daily average <b><?= $num($avgDay) ?></b>
        <?php if ($todayVsAvg !== null): ?>
          <span class="<?= $todayVsAvg >= 0 ? 'up' : 'down' ?>" title="Today against the daily average"><?= $e(abs($todayVsAvg)) ?>% <i class="arrow"></i></span>
          <span class="muted">vs avg</span>
        <?php endif; ?>
      </div>
      <div class="stat-s">Last recorded day <b><?= $num($lastDay) ?></b>
        <span class="muted">of ingestion</span></div>
    </div>
    <div class="stat-foot">Last <?= (int) $ingestDays ?> days <b><?= $num($ingestTotal) ?></b></div>
  </div>

  <!-- 4. Readers, with interactions -->
  <div class="card stat">
    <div class="stat-l">Readers <?= $info ?></div>
    <div class="stat-n"><?= $num($users) ?></div>
    <div class="stat-body">
      <div class="stat-s">Interactions logged <b><?= $num($events) ?></b></div>
      <div class="stat-s">Per reader <b><?= $e($perReader) ?></b></div>
    </div>
    <div class="stat-foot">Active accounts <b><?= $num($users) ?></b></div>
  </div>
</div>

<!-- Wide tabbed card: chart on the left, ranking on the right. CSS-only
     tabs — two radios ahead of the panes, so :checked ~ reaches both the
     tab heading and the pane. -->
<div class="card tabcard">
  <input type="radio" name="dash-tab" id="tab-a" checked>
  <input type="radio" name="dash-tab" id="tab-b">
  <div class="tabs-h">
    <label for="tab-a">Ingestion</label>
    <label for="tab-b">Sync throughput</label>
    <div class="seg"><span class="on">14 days</span><span>Per run</span></div>
  </div>
  <div class="tab-grid">
    <div class="tab-pane pane-a">
      <h3>Articles ingested per day</h3>
      <?= fp_bars($ingest, 300, $colors[0]) ?>
    </div>
    <div class="tab-pane pane-b">
      <h3>Articles added per sync run</h3>
      <?= fp_line($syncSeries, 300, $colors[2]) ?>
    </div>
    <div class="rank-wrap">
      <h3>Source ranking</h3>
      <?php if ($topSources === []): ?>
        <p class="muted">No articles yet.</p>
      <?php else: ?>
        <ol class="rank">
          <?php $i = 0; foreach ($topSources as $name => $n): $i++; ?>
            <li>
              <span class="rank-n<?= $i <= 3 ? ' top' : '' ?>"><?= $i ?></span>
              <span class="rank-l" title="<?= $e($name) ?>"><?= $e($name) ?></span>
              <span class="rank-v"><?= $num($n) ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
      <p style="margin:.8rem 0 0"><a class="act" href="/sources" style="font-size:.8rem;color:var(--faint);text-decoration:none">See all sources →</a></p>
    </div>
  </div>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h"><h2>Categories</h2><a class="act" href="/sources">Sources →</a></div>
    <div class="donut">
      <?= fp_donut($categories) ?>
      <div class="legend">
        <?php $i = 0; $catTotal = array_sum($categories); foreach ($categories as $name => $n): ?>
          <div class="lg">
            <i style="background:<?= $e($colors[$i % count($colors)]) ?>"></i>
            <?= $e($name) ?>
            <b><?= $catTotal > 0 ? round($n / $catTotal * 100) : 0 ?>%</b>
          </div>
        <?php $i++; endforeach; ?>
        <?php if ($categories === []): ?><p class="muted" style="margin:0">No categories with articles yet.</p><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>Source health</h2><a class="act" href="/sync">Sync →</a></div>
    <?php
      $hTotal  = max(1, array_sum($health));
      $hColors = ['healthy' => $colors[2], 'failing' => $colors[3], 'deactivated' => $colors[9]];
      foreach ($health as $state => $n) {
          echo fp_hbar(ucfirst((string) $state), (int) $n, (int) $hTotal, $hColors[$state] ?? $colors[8]);
      }
    ?>
    <table style="margin-top:1rem">
      <tr><th>Recent sync</th><th class="right">Added</th><th class="right">Failed</th></tr>
      <?php foreach (array_slice($recentSync, 0, 4) as $s): ?>
      <tr>
        <td class="muted"><?= $e(substr((string) $s['run_at'], 0, 10)) ?></td>
        <td class="right"><?= $num($s['articles_added']) ?></td>
        <td class="right"><?= ((int) $s['feeds_failed']) > 0
            ? '<span class="pill warn">' . (int) $s['feeds_failed'] . '</span>'
            : '<span class="muted">0</span>' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if ($recentSync === []): ?><tr><td colspan="3" class="muted">No sync recorded yet.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
