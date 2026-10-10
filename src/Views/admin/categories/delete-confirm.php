<?php
/** @var array $cat @var string[] $blockers @var string $csrf */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$blocked = $blockers !== [];
?>
<div class="head">
  <h1>Delete <?= $e($cat['name']) ?></h1>
  <span class="sub"><a href="/categories/view?id=<?= (int) $cat['id'] ?>" class="muted">← Back</a></span>
</div>

<div class="row2">
  <div class="card">
    <?php if ($blocked): ?>
      <div class="flash bad"><strong>!</strong>
        <span>This category cannot be deleted yet. It still has <?= $e(implode(', ', $blockers)) ?>.</span>
      </div>
      <p style="font-size:.9rem">
        Deleting it would cascade through those rows — the foreign keys would silently remove the
        articles and every reader's subscription — so the dashboard refuses until all three counts
        below are zero.
      </p>
      <ul style="font-size:.88rem;line-height:1.8">
        <?php if ((int) $cat['articles'] > 0): ?>
          <li><strong><?= number_format((int) $cat['articles']) ?> article(s)</strong> — these are purged by retention over time, or removed when their source mappings go.</li>
        <?php endif; ?>
        <?php if ((int) $cat['sources'] > 0): ?>
          <li><strong><?= (int) $cat['sources'] ?> mapped source(s)</strong> — unmap the blogs from this category on <a href="/sources">Sources</a>.</li>
        <?php endif; ?>
        <?php if ((int) $cat['readers'] > 0): ?>
          <li><strong><?= (int) $cat['readers'] ?> subscribed reader(s)</strong> — they must drop it from their topics, or you can deactivate the category so it leaves their lists while keeping their data.</li>
        <?php endif; ?>
      </ul>
      <div style="display:flex;gap:.6rem;align-items:center;margin-top:1rem">
        <a href="/categories/view?id=<?= (int) $cat['id'] ?>" class="muted" style="font-size:.85rem">Back to category</a>
        <?php if (\App\Admin\Page::truthy($cat['is_active'])): ?>
          <span class="muted" style="font-size:.85rem">· Consider deactivating it instead.</span>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="flash warn"><strong>!</strong>
        <span>This permanently deletes the category. It cannot be undone.</span>
      </div>
      <p style="font-size:.9rem">
        <strong><?= $e($cat['name']) ?></strong> (<code><?= $e($cat['slug']) ?></code>, #<?= (int) $cat['id'] ?>)
        has no articles, no mapped sources and no subscribed readers, so nothing else is affected.
      </p>
      <form method="post" action="/categories/delete" style="margin-top:1rem;display:flex;gap:.6rem;align-items:center">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
        <input type="hidden" name="confirm" value="1">
        <button class="btn warn" type="submit" style="border-color:#be123c;color:#be123c;padding:.55rem 1.1rem">
          Yes, delete <?= $e($cat['name']) ?>
        </button>
        <a href="/categories/view?id=<?= (int) $cat['id'] ?>" class="muted" style="font-size:.85rem">Cancel</a>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-h"><h2>What would be affected</h2></div>
    <table>
      <tr><th>Articles</th><td class="right"><?= (int) $cat['articles'] > 0 ? '<span class="pill bad">' . number_format((int) $cat['articles']) . '</span>' : '<span class="pill ok">0</span>' ?></td></tr>
      <tr><th>Mapped sources</th><td class="right"><?= (int) $cat['sources'] > 0 ? '<span class="pill bad">' . (int) $cat['sources'] . '</span>' : '<span class="pill ok">0</span>' ?></td></tr>
      <tr><th>Subscribed readers</th><td class="right"><?= (int) $cat['readers'] > 0 ? '<span class="pill bad">' . (int) $cat['readers'] . '</span>' : '<span class="pill ok">0</span>' ?></td></tr>
    </table>
    <p class="muted" style="font-size:.8rem;margin-top:.9rem">
      Counts are read live from the database and checked again when you confirm, so a sync
      landing between the two cannot slip a deletion through.
    </p>
  </div>
</div>
