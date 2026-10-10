<?php
require_once __DIR__ . '/_charts.php';
/** @var array $m @var array $warnings @var array $ingest @var array $syncSeries
 *  @var array $categories @var array $topSources @var array $health @var array $recentSync */
$colors = fp_palette();
$pct = static fn(int $part, int $whole): string =>
    $whole > 0 ? round($part / $whole * 100) . '% of total' : '—';
?>
<div class="head">
  <h1>Overview</h1>
  <span class="sub"><?= htmlspecialchars($m['window_label']) ?></span>
</div>

<?php foreach ($warnings as $w): ?>
  <div class="flash bad"><strong>!</strong><span><?= htmlspecialchars($w) ?></span></div>
<?php endforeach; ?>

<div class="kpis">
  <div class="kpi fill">
    <div class="k-l">Total articles</div>
    <div class="k-n"><?= number_format($m['articles']) ?></div>
    <div class="k-s"><?= number_format($m['in_window']) ?> in the 30-day window</div>
  </div>
  <div class="kpi cyan">
    <div class="k-l">Active sources</div>
    <div class="k-n"><?= $m['sources_active'] ?></div>
    <div class="k-s"><?= $pct((int) $m['sources_active'], (int) $m['sources_total']) ?></div>
  </div>
  <div class="kpi green">
    <div class="k-l">Ingested today</div>
    <div class="k-n"><?= number_format($m['today']) ?></div>
    <div class="k-s"><?= number_format((int) $m['avg_day']) ?>/day average</div>
  </div>
  <div class="kpi pink">
    <div class="k-l">Needs attention</div>
    <div class="k-n"><?= $m['sources_dead'] + $m['sources_failing'] ?></div>
    <div class="k-s"><?= $m['sources_dead'] ?> off, <?= $m['sources_failing'] ?> failing</div>
  </div>
  <div class="kpi violet">
    <div class="k-l">Readers</div>
    <div class="k-n"><?= number_format($m['users']) ?></div>
    <div class="k-s"><?= number_format($m['events']) ?> interactions logged</div>
  </div>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h">
      <h2>Articles ingested</h2>
      <!-- One range of history exists, so the other two are not offered as
           controls that would do nothing. -->
      <div class="seg"><span class="on">14 days</span></div>
    </div>
    <?= fp_bars($ingest, 200) ?>
  </div>

  <div class="card">
    <div class="card-h">
      <h2>Sync throughput</h2>
      <div class="seg"><span class="on">Per run</span></div>
    </div>
    <?= fp_line($syncSeries, 200, '#34d399') ?>
  </div>
</div>

<div class="row3">
  <div class="card">
    <div class="card-h"><h2>Categories</h2><a class="act" href="/sources">Sources →</a></div>
    <div class="donut">
      <?= fp_donut($categories) ?>
      <div class="legend">
        <?php $i = 0; $catTotal = array_sum($categories); foreach ($categories as $name => $n): ?>
          <div class="lg">
            <i style="background:<?= $colors[$i % count($colors)] ?>"></i>
            <?= htmlspecialchars((string) $name) ?>
            <b><?= $catTotal > 0 ? round($n / $catTotal * 100) : 0 ?>%</b>
          </div>
        <?php $i++; endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>Top sources</h2><a class="act" href="/sources">See all →</a></div>
    <?php $maxSrc = $topSources === [] ? 1 : max($topSources); $i = 0;
          foreach ($topSources as $name => $n):
            echo fp_hbar((string) $name, (int) $n, (int) $maxSrc, $colors[$i % count($colors)]);
            $i++;
          endforeach; ?>
    <?php if ($topSources === []): ?><p class="muted">No articles yet.</p><?php endif; ?>
  </div>

  <div class="card">
    <div class="card-h"><h2>Source health</h2><a class="act" href="/sync">Sync →</a></div>
    <?php
      $hTotal = max(1, array_sum($health));
      $hColors = ['healthy' => '#34d399', 'failing' => '#fbbf24', 'deactivated' => '#f472b6'];
      foreach ($health as $state => $n) {
          echo fp_hbar(ucfirst((string) $state), (int) $n, (int) $hTotal,
                       $hColors[$state] ?? '#94a3b8');
      }
    ?>
    <table style="margin-top:1rem">
      <tr><th>Recent sync</th><th class="right">Added</th><th class="right">Failed</th></tr>
      <?php foreach (array_slice($recentSync, 0, 4) as $s): ?>
      <tr>
        <td class="muted"><?= htmlspecialchars(substr((string) $s['run_at'], 0, 10)) ?></td>
        <td class="right"><?= number_format((int) $s['articles_added']) ?></td>
        <td class="right"><?= ((int) $s['feeds_failed']) > 0
            ? '<span class="pill warn">' . (int) $s['feeds_failed'] . '</span>'
            : '<span class="muted">0</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
