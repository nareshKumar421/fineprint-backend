<?php
/** @var array $cat @var array $sources @var array $articles @var string $csrf */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$truthy = fn($v) => \App\Admin\Page::truthy($v);
$active  = $truthy($cat['is_active']);
$readers = (int) $cat['readers'];
$linkBtn = 'display:inline-block;font-size:.8rem;font-weight:600;padding:.35rem .75rem;border-radius:8px;'
         . 'border:1px solid var(--line);background:var(--card);color:var(--dim);text-decoration:none';
$confirm = $active
  ? "Deactivate {$cat['name']}? It will disappear from the topic list of {$readers} subscribed reader(s) "
    . 'and from the feed. Their subscriptions are kept and return if you reactivate it.'
  : "Activate {$cat['name']}? It becomes visible in the topic list and the feed again.";
?>
<div class="head">
  <h1><span style="margin-right:.4rem"><?= $e($cat['icon'] ?? '') ?></span><?= $e($cat['name']) ?></h1>
  <?php if (!$active): ?><span class="pill bad">inactive</span>
  <?php elseif ((int) $cat['sources'] === 0): ?><span class="pill warn">no sources</span>
  <?php else: ?><span class="pill ok">active</span><?php endif; ?>
  <span class="sub" style="display:flex;gap:.5rem;align-items:center">
    <a href="/categories" class="muted">← All categories</a>
    <a href="/categories/edit?id=<?= (int) $cat['id'] ?>" style="<?= $linkBtn ?>">Edit</a>
    <form method="post" action="/categories/toggle" style="display:inline">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
      <button class="btn <?= $active ? 'warn' : '' ?>" data-confirm="<?= $e($confirm) ?>"
              onclick="return confirm(this.dataset.confirm)"><?= $active ? 'Deactivate' : 'Activate' ?></button>
    </form>
    <a href="/categories/delete-confirm?id=<?= (int) $cat['id'] ?>" style="<?= $linkBtn ?>;color:#be123c">Delete…</a>
  </span>
</div>

<div class="kpis">
  <div class="kpi blue">
    <div class="k-l">Mapped sources</div>
    <div class="k-n"><?= (int) $cat['sources'] ?></div>
    <div class="k-s">blogs feeding this category</div>
  </div>
  <div class="kpi cyan">
    <div class="k-l">Articles</div>
    <div class="k-n"><?= number_format((int) $cat['articles']) ?></div>
    <div class="k-s"><?= number_format((int) $cat['in_window']) ?> in the 30-day window</div>
  </div>
  <div class="kpi violet">
    <div class="k-l">Subscribed readers</div>
    <div class="k-n"><?= number_format($readers) ?></div>
    <div class="k-s"><?= $active ? 'see it in their topic list' : 'kept, hidden while inactive' ?></div>
  </div>
  <div class="kpi green">
    <div class="k-l">Display order</div>
    <div class="k-n"><?= (int) $cat['display_order'] ?></div>
    <div class="k-s">position on the topic picker</div>
  </div>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h"><h2>Details</h2></div>
    <table>
      <tr><th style="width:9rem">Name</th><td><?= $e($cat['name']) ?></td></tr>
      <tr><th>Slug</th><td><code><?= $e($cat['slug']) ?></code></td></tr>
      <tr><th>Icon</th><td style="font-size:1.3rem"><?= $cat['icon'] === null || $cat['icon'] === '' ? '<span class="muted" style="font-size:.84rem">none</span>' : $e($cat['icon']) ?></td></tr>
      <tr><th>Description</th><td><?= $cat['description'] === null || $cat['description'] === '' ? '<span class="muted">—</span>' : $e($cat['description']) ?></td></tr>
      <tr><th>Status</th><td><?= $active ? '<span class="pill ok">active</span>' : '<span class="pill bad">inactive</span>' ?></td></tr>
      <tr><th>Created</th><td class="muted"><?= $e(substr((string) $cat['created_at'], 0, 16)) ?> UTC</td></tr>
      <tr><th>ID</th><td class="muted">#<?= (int) $cat['id'] ?></td></tr>
    </table>
  </div>

  <div class="card">
    <div class="card-h"><h2>Mapped sources</h2><a class="act" href="/sources">Sources →</a></div>
    <?php if ($sources === []): ?>
      <p class="muted" style="padding:1.5rem 0;text-align:center">
        No blogs are mapped to this category. Readers who pick it get an empty feed.
      </p>
    <?php else: ?>
    <table>
      <tr><th>Blog</th><th>Feed</th><th class="right">Articles</th></tr>
      <?php foreach ($sources as $s): $sActive = $truthy($s['is_active']); ?>
      <tr>
        <td>
          <?= $e($s['blog_name']) ?>
          <?php if (!$sActive): ?> <span class="pill bad">off</span>
          <?php elseif ((int) $s['failure_count'] > 0): ?> <span class="pill warn">failing</span><?php endif; ?>
        </td>
        <td class="muted" style="font-size:.78rem;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?php if ($s['feed_url_override'] !== null && $s['feed_url_override'] !== ''): ?>
            <span class="pill info">override</span> <?= $e($s['feed_url_override']) ?>
          <?php else: ?>
            <?= $e($s['feed_url']) ?>
          <?php endif; ?>
        </td>
        <td class="right"><?= number_format((int) $s['articles']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>Latest articles</h2><span class="muted" style="font-size:.8rem">newest 10</span></div>
  <?php if ($articles === []): ?>
    <p class="muted" style="padding:1.5rem 0;text-align:center">No articles have been ingested for this category yet.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table>
    <tr><th>Title</th><th>Source</th><th>Published</th></tr>
    <?php foreach ($articles as $a): ?>
    <tr>
      <td>
        <a href="<?= $e($a['article_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:inherit;text-decoration:none;font-weight:500"><?= $e($a['title']) ?></a>
        <?php if ($a['author'] !== null && $a['author'] !== ''): ?>
          <span class="muted" style="font-size:.78rem"> · <?= $e($a['author']) ?></span>
        <?php endif; ?>
      </td>
      <td class="muted" style="white-space:nowrap"><?= $e($a['blog_name']) ?></td>
      <td class="muted" style="white-space:nowrap"><?= $e(substr((string) ($a['published_at'] ?? $a['fetched_at']), 0, 16)) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>
</div>
