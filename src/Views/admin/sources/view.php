<?php
require __DIR__ . '/_ui.php';
require_once __DIR__ . '/../_charts.php';
/** @var array $src @var array $mappings @var array $ingest @var array $recent @var int $maxFailures @var string $csrf */
$active = \App\Admin\Page::truthy($src['is_active']);
$fails  = (int) $src['failure_count'];
$id     = (int) $src['id'];
$status = (string) $src['status'];
$ingestTotal = array_sum($ingest);
$peak = $ingestTotal > 0 ? array_search(max($ingest), $ingest, true) : null;
?>
<div class="head">
  <h1><?= $e($src['blog_name']) ?></h1>
  <?= $pill($status, $fails, $maxFailures) ?>
  <span class="sub">source #<?= $id ?> · <a href="/sources" style="color:inherit">all sources</a></span>
</div>

<?php if ($status === 'deactivated'): ?>
  <div class="flash bad"><strong>!</strong><span>Deactivated after <?= $fails ?> consecutive failures. The nightly sync skips it and never retries on its own — Enable below gives it a clean slate.</span></div>
<?php elseif ($status === 'failing'): ?>
  <div class="flash warn"><strong>!</strong><span>Failing: <?= $fails ?> of <?= (int) $maxFailures ?> consecutive failures. One more night like the last and it is deactivated automatically.</span></div>
<?php elseif ($status === 'no_recent'): ?>
  <div class="flash warn"><strong>!</strong><span>Every fetch succeeds but nothing has arrived in 30 days. The publisher may have stopped writing, or the feed is serving old items.</span></div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi fill"><div class="k-l">Articles</div><div class="k-n"><?= number_format((int) $src['articles_total']) ?></div><div class="k-s"><?= number_format((int) $src['today']) ?> ingested today</div></div>
  <div class="kpi cyan"><div class="k-l">Last 30 days</div><div class="k-n"><?= number_format((int) $src['articles_30d']) ?></div><div class="k-s"><?= number_format($ingestTotal) ?> fetched in 14 days</div></div>
  <div class="kpi <?= $fails > 0 ? 'pink' : 'green' ?>"><div class="k-l">Failures</div><div class="k-n"><?= $fails ?><span style="font-size:1rem;opacity:.6">/<?= (int) $maxFailures ?></span></div><div class="k-s"><?= $fails > 0 ? 'consecutive, resets on a good run' : 'no consecutive failures' ?></div></div>
  <div class="kpi violet"><div class="k-l">Last fetched</div><div class="k-n" style="font-size:1.15rem;padding-top:.3rem"><?= $e($when($src['last_fetched_at'])) ?></div><div class="k-s"><?= $src['last_fetched_at'] === null ? 'never' : 'UTC' ?></div></div>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h">
      <h2>Articles ingested</h2>
      <div class="seg"><span class="on">14 days</span></div>
      <?php if ($peak !== null): ?><span class="act">peak <?= $e($peak) ?> · <?= max($ingest) ?></span><?php endif; ?>
    </div>
    <?= $ingestTotal > 0 ? fp_bars($ingest, 200) : '<p class="muted" style="padding:2rem 0;text-align:center">Nothing fetched from this source in the last 14 days.</p>' ?>
  </div>

  <div class="card">
    <div class="card-h"><h2>Categories</h2><a class="act" href="/sources/edit?id=<?= $id ?>">Edit mapping →</a></div>
    <?php if ($mappings === []): ?>
      <p class="muted">Mapped to no category — the fetch job iterates the mapping, so this source is never fetched.</p>
    <?php else: ?>
      <div class="rank">
        <?php foreach ($mappings as $i => $m): ?>
          <div class="r" style="flex-wrap:wrap">
            <i><?= $i + 1 ?></i>
            <span><?= $e($m['name']) ?><?= \App\Admin\Page::truthy($m['is_active']) ? '' : ' <span class="pill bad">inactive</span>' ?></span>
            <?php if ($m['feed_url_override'] !== null && $m['feed_url_override'] !== ''): ?>
              <b style="font-weight:500;font-size:.76rem;max-width:100%;word-break:break-all" title="Feed override for this category">↳ <?= $e($m['feed_url_override']) ?></b>
            <?php else: ?>
              <b class="muted" style="font-weight:500;font-size:.76rem">main feed</b>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="muted" style="font-size:.78rem;margin:1rem 0 0">Articles are stored once per category, so a blog in three categories keeps three copies by design.</p>
  </div>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h"><h2>Details</h2></div>
    <dl class="kv">
      <dt>Blog URL</dt><dd><a href="<?= $e($src['blog_url']) ?>" rel="noopener noreferrer" target="_blank"><?= $e($src['blog_url']) ?></a></dd>
      <dt>Feed URL</dt><dd><code><?= $e($src['feed_url']) ?></code> <a href="/sources/validate?<?= $e(http_build_query(['feed_url' => $src['feed_url'], 'back' => '/sources/view?id=' . $id])) ?>" style="font-size:.8rem">check now</a></dd>
      <dt>Active</dt><dd><?= $active ? 'yes' : 'no' ?></dd>
      <dt>Failure count</dt><dd><?= $fails ?> of <?= (int) $maxFailures ?></dd>
      <dt>Last fetched</dt><dd><?= $e($when($src['last_fetched_at'])) ?></dd>
      <dt>Last error</dt><dd><?php if (($src['last_error'] ?? '') !== ''): ?><code style="white-space:pre-wrap;color:var(--t-pink-ink)"><?= $e($src['last_error']) ?></code><?php else: ?><span class="muted">none</span><?php endif; ?></dd>
      <dt>Hidden by</dt><dd><?= number_format((int) $src['hidden_by']) ?> reader<?= (int) $src['hidden_by'] === 1 ? '' : 's' ?></dd>
      <dt>Added by</dt><dd><?= $src['added_by'] === null || $src['added_by'] === '' ? '<span class="muted">—</span>' : $e($src['added_by']) ?></dd>
      <dt>Created</dt><dd><?= $e($when($src['created_at'])) ?></dd>
    </dl>
  </div>

  <div class="card">
    <div class="card-h"><h2>Actions</h2></div>
    <div class="acts">
      <a class="btn primary" href="/sources/edit?id=<?= $id ?>">Edit</a>
      <form method="post" action="/sources/toggle"<?= $active ? ' onsubmit="return confirm(\'Disable ' . $e(addslashes((string) $src['blog_name'])) . '? The nightly sync will skip it until it is reactivated.\')"' : '' ?>>
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="back" value="view">
        <button class="btn<?= $active ? ' warn' : '' ?>"><?= $active ? 'Disable' : 'Enable (clears failures)' ?></button>
      </form>
      <a class="btn warn" href="/sources/delete-confirm?id=<?= $id ?>">Delete…</a>
    </div>
    <p class="muted" style="font-size:.8rem;margin:1rem 0 0">
      Disable keeps every article and the mapping; the sync just skips it. Delete removes the source, its
      <?= number_format((int) $src['articles_total']) ?> articles and the readers' interaction history on them — the confirmation page lists exactly what goes.
    </p>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>Latest articles</h2><span class="act">newest 10</span></div>
  <?php if ($recent === []): ?>
    <p class="muted" style="padding:1.5rem 0;text-align:center">No articles stored for this source.</p>
  <?php else: ?>
  <div class="tbl">
  <table>
    <tr><th>Title</th><th>Category</th><th>Published</th><th>Fetched</th><th></th></tr>
    <?php foreach ($recent as $a): ?>
    <tr>
      <td><a href="/articles/view?id=<?= (int) $a['id'] ?>" style="color:inherit"><?= $e($a['title']) ?></a></td>
      <td class="muted" style="font-size:.8rem;white-space:nowrap"><?= $e($a['category']) ?></td>
      <td class="muted" style="white-space:nowrap;font-size:.8rem"><?= $e($when($a['published_at'])) ?></td>
      <td class="muted" style="white-space:nowrap;font-size:.8rem"><?= $e($when($a['fetched_at'])) ?></td>
      <td class="right"><a href="<?= $e($a['article_url']) ?>" rel="noopener noreferrer" target="_blank" style="font-size:.8rem">publisher ↗</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>
</div>
