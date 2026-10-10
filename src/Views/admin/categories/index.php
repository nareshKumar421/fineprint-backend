<?php
/** @var array $rows @var array $pg @var array $kpi @var string $q @var string $status
 *  @var string $sort @var string[] $sorts @var string $csrf */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$truthy = fn($v) => \App\Admin\Page::truthy($v);

// Column-header links keep the current filters and reset to page 1.
$sortLink = static function (string $key) use ($sort): string {
    $g = $_GET;
    $g['sort'] = $key;
    unset($g['page']);
    return '?' . http_build_query($g);
};
$th = static function (string $key, string $label, string $cls = '') use ($sort, $sortLink, $e): string {
    $on = $sort === $key;
    return '<th class="' . $cls . '"><a href="' . $e($sortLink($key)) . '" style="color:inherit;text-decoration:none'
         . ($on ? ';color:var(--accent)' : '') . '">' . $e($label) . ($on ? ' ↓' : '') . '</a></th>';
};
$linkBtn = 'display:inline-block;font-size:.8rem;font-weight:600;padding:.35rem .75rem;border-radius:8px;'
         . 'border:1px solid var(--line);background:var(--card);color:var(--dim);text-decoration:none';
?>
<div class="head">
  <h1>Categories</h1>
  <span class="sub">
    <a href="/categories/new" style="<?= $linkBtn ?>;background:var(--accent);color:var(--accent-ink);border-color:var(--accent)">+ New category</a>
  </span>
</div>

<div class="kpis">
  <div class="kpi fill">
    <div class="k-l">Categories</div>
    <div class="k-n"><?= (int) $kpi['all_n'] ?></div>
    <div class="k-s"><?= (int) $kpi['active_n'] ?> active, <?= (int) $kpi['all_n'] - (int) $kpi['active_n'] ?> hidden</div>
  </div>
  <div class="kpi violet">
    <div class="k-l">Subscriptions</div>
    <div class="k-n"><?= number_format((int) $kpi['subs']) ?></div>
    <div class="k-s">reader–category pairs</div>
  </div>
  <div class="kpi <?= (int) $kpi['unsourced'] > 0 ? 'pink' : 'green' ?>">
    <div class="k-l">Without sources</div>
    <div class="k-n"><?= (int) $kpi['unsourced'] ?></div>
    <div class="k-s"><?= (int) $kpi['unsourced'] > 0 ? 'would give readers an empty feed' : 'every category has at least one blog' ?></div>
  </div>
</div>

<div class="card">
  <form method="get" action="/categories" class="card-h" style="flex-wrap:wrap;gap:.5rem">
    <input type="text" name="q" value="<?= $e($q) ?>" placeholder="Search name or slug"
           style="width:auto;flex:1;min-width:160px;padding:.45rem .7rem">
    <select name="status" style="font:inherit;font-size:.86rem;padding:.45rem .6rem;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink)">
      <option value=""         <?= $status === ''         ? 'selected' : '' ?>>All</option>
      <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
    <?php if ($sort !== 'order'): ?><input type="hidden" name="sort" value="<?= $e($sort) ?>"><?php endif; ?>
    <button class="btn" type="submit">Filter</button>
    <?php if ($q !== '' || $status !== ''): ?>
      <a href="/categories" class="muted" style="font-size:.8rem">Clear</a>
    <?php endif; ?>
    <span class="muted" style="margin-left:auto;font-size:.8rem">
      <?= $pg['total'] === 0 ? 'No categories' : "Showing {$pg['from']}–{$pg['to']} of {$pg['total']}" ?>
    </span>
  </form>

  <?php if ($rows === []): ?>
    <p class="muted" style="padding:2rem 0;text-align:center">
      <?= $q !== '' || $status !== '' ? 'Nothing matches those filters.' : 'No categories yet — create the first one.' ?>
    </p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table>
    <tr>
      <?= $th('order', '#', 'right') ?>
      <th></th>
      <?= $th('name', 'Category') ?>
      <th>Status</th>
      <?= $th('sources', 'Sources', 'right') ?>
      <?= $th('articles', 'Articles', 'right') ?>
      <?= $th('readers', 'Readers', 'right') ?>
      <th></th>
    </tr>
    <?php foreach ($rows as $r):
      $active  = $truthy($r['is_active']);
      $readers = (int) $r['readers'];
      $confirm = $active
        ? "Deactivate {$r['name']}? It will disappear from the topic list of {$readers} subscribed reader(s) "
          . 'and from the feed. Their subscriptions are kept and return if you reactivate it.'
        : "Activate {$r['name']}? It becomes visible in the topic list and the feed again.";
    ?>
    <tr>
      <td class="right muted"><?= (int) $r['display_order'] ?></td>
      <td style="font-size:1.2rem;width:2rem;text-align:center"><?= $e($r['icon'] ?? '') ?></td>
      <td>
        <a href="/categories/view?id=<?= (int) $r['id'] ?>" style="color:inherit;font-weight:600;text-decoration:none"><?= $e($r['name']) ?></a><br>
        <code style="font-size:.76rem"><?= $e($r['slug']) ?></code>
      </td>
      <td>
        <?php if (!$active): ?>
          <span class="pill bad">inactive</span>
        <?php elseif ((int) $r['sources'] === 0): ?>
          <span class="pill warn">no sources</span>
        <?php else: ?>
          <span class="pill ok">active</span>
        <?php endif; ?>
      </td>
      <td class="right"><?= (int) $r['sources'] ?></td>
      <td class="right"><?= number_format((int) $r['articles']) ?></td>
      <td class="right"><?= number_format($readers) ?></td>
      <td class="right" style="white-space:nowrap">
        <form method="post" action="/categories/reorder" style="display:inline">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn" name="dir" value="up" title="Move up" aria-label="Move up">↑</button>
          <button class="btn" name="dir" value="down" title="Move down" aria-label="Move down">↓</button>
        </form>
        <a href="/categories/edit?id=<?= (int) $r['id'] ?>" style="<?= $linkBtn ?>">Edit</a>
        <form method="post" action="/categories/toggle" style="display:inline">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn <?= $active ? 'warn' : '' ?>" data-confirm="<?= $e($confirm) ?>"
                  onclick="return confirm(this.dataset.confirm)">
            <?= $active ? 'Deactivate' : 'Activate' ?>
          </button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>

  <?php if ($pg['pages'] > 1): ?>
  <div style="display:flex;gap:.6rem;align-items:center;justify-content:flex-end;margin-top:.9rem;font-size:.82rem">
    <?php if ($pg['page'] > 1): ?>
      <a href="<?= $e(\App\Admin\Paginator::link($pg['page'] - 1)) ?>" style="<?= $linkBtn ?>">← Prev</a>
    <?php endif; ?>
    <span class="muted">Page <?= (int) $pg['page'] ?> of <?= (int) $pg['pages'] ?></span>
    <?php if ($pg['page'] < $pg['pages']): ?>
      <a href="<?= $e(\App\Admin\Paginator::link($pg['page'] + 1)) ?>" style="<?= $linkBtn ?>">Next →</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<p class="muted" style="font-size:.8rem;margin-top:1rem">
  Order is what readers see on the topic picker. Deactivating a category hides it from
  every reader's topic list and from the feed, but keeps their subscriptions; deleting
  one is only allowed once nothing references it.
</p>
