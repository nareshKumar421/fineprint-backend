<?php
require __DIR__ . '/_ui.php';
/** @var array $src @var string $csrf */
$id = (int) $src['id'];
$n  = static fn($v): string => number_format((int) $v);
?>
<div class="head">
  <h1>Delete <?= $e($src['blog_name']) ?>?</h1>
  <span class="sub"><a href="/sources/view?id=<?= $id ?>" style="color:inherit">← back to source</a></span>
</div>

<div class="flash bad"><strong>!</strong><span>This cannot be undone. Everything listed below is removed in one transaction. If the aim is only to stop fetching, <a href="/sources/view?id=<?= $id ?>" style="color:inherit">disable it</a> instead — that keeps every article.</span></div>

<div class="row2">
  <div class="card danger">
    <div class="card-h"><h2>What will be deleted</h2></div>
    <div class="tbl">
    <table>
      <tr><th>Rows</th><th class="right">Count</th><th>Why</th></tr>
      <tr><td>The source itself</td><td class="right">1</td><td class="muted" style="font-size:.8rem"><code>blog_sources</code> #<?= $id ?></td></tr>
      <tr><td>Articles</td><td class="right"><strong><?= $n($src['articles']) ?></strong></td><td class="muted" style="font-size:.8rem"><code>articles.blog_source_id</code> ON DELETE CASCADE</td></tr>
      <tr><td>Reader interactions on them</td><td class="right"><?= $n($src['events']) ?></td><td class="muted" style="font-size:.8rem"><code>article_events</code> cascade through the articles — ranking history is lost</td></tr>
      <tr><td>Seen-article records</td><td class="right"><?= $n($src['seen']) ?></td><td class="muted" style="font-size:.8rem"><code>user_seen_articles</code> cascade through the articles</td></tr>
      <tr><td>Category mappings</td><td class="right"><?= $n($src['mappings']) ?></td><td class="muted" style="font-size:.8rem"><code>category_blog_map</code> cascade</td></tr>
      <tr><td>Readers who hid this source</td><td class="right"><?= $n($src['hidden']) ?></td><td class="muted" style="font-size:.8rem"><code>user_hidden_sources</code> cascade — their preference is forgotten</td></tr>
      <tr><td>Per-reader source stats</td><td class="right"><?= $n($src['reader_stats']) ?></td><td class="muted" style="font-size:.8rem"><code>user_source_stats</code> and <code>source_stats</code> cascade</td></tr>
    </table>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>Source</h2></div>
    <dl class="kv">
      <dt>Name</dt><dd><?= $e($src['blog_name']) ?></dd>
      <dt>Feed</dt><dd><code><?= $e($src['feed_url']) ?></code></dd>
      <dt>Status</dt><dd><?= \App\Admin\Page::truthy($src['is_active']) ? 'active' : 'deactivated' ?>, <?= (int) $src['failure_count'] ?> failure(s)</dd>
      <dt>Last fetched</dt><dd><?= $e($when($src['last_fetched_at'])) ?></dd>
    </dl>
    <form method="post" action="/sources/delete" style="margin-top:1.4rem"
          onsubmit="return confirm('Permanently delete <?= $e(addslashes((string) $src['blog_name'])) ?> and <?= $n($src['articles']) ?> articles?')">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="confirm" value="1">
      <div class="acts">
        <button class="btn danger" type="submit">Yes, delete <?= $e($src['blog_name']) ?></button>
        <a class="btn" href="/sources/view?id=<?= $id ?>">Cancel</a>
      </div>
    </form>
  </div>
</div>
