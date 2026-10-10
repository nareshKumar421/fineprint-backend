<?php
/** @var array $rows @var array $page @var array $filters @var string $sort @var string $dir
 *  @var array $sources @var array $categories @var bool $filtered @var int $bulkCap @var string $csrf */
use App\Admin\Page;
use App\Admin\Paginator;

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

// A column header link: click to sort by it, click again to flip.
$sortLink = static function (string $col, string $label) use ($sort, $dir, $e): string {
    $next = ($sort === $col && $dir === 'desc') ? 'asc' : 'desc';
    $q = array_merge($_GET, ['sort' => $col, 'dir' => $next, 'page' => 1]);
    $arrow = $sort === $col ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
    return '<a href="?' . $e(http_build_query($q)) . '" style="color:inherit;text-decoration:none">'
         . $e($label) . $arrow . '</a>';
};
?>
<style>
  .filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.6rem .9rem;align-items:end}
  .filters label{margin:0 0 .25rem}
  .filters .wide{grid-column:1/-1}
  .filters input[type=text],.filters input[type=date],.filters select{width:100%;padding:.5rem .7rem;font:inherit;
    font-size:.88rem;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink)}
  .filters .acts{display:flex;gap:.5rem;align-items:center}
  .btn.primary{background:var(--accent);color:var(--accent-ink);border-color:var(--accent)}
  .btn.primary:hover{color:var(--accent-ink);opacity:.9}
  .tbl{overflow-x:auto}
  .tbl table{min-width:760px}
  .tbl td.t{max-width:420px}
  .tbl td.t a{color:inherit;text-decoration:none;font-weight:600}
  .tbl td.t a:hover{color:var(--accent)}
  .pager{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;margin-top:1rem;font-size:.84rem}
  .pager a,.pager span.cur{padding:.3rem .65rem;border-radius:8px;border:1px solid var(--line);text-decoration:none;color:var(--dim)}
  .pager a:hover{border-color:var(--accent);color:var(--accent)}
  .pager span.cur{background:var(--accent);color:var(--accent-ink);border-color:var(--accent)}
  .bulkbar{display:flex;gap:.75rem;align-items:center;margin-bottom:.8rem;font-size:.84rem}
  .empty{text-align:center;padding:3rem 1rem;color:var(--faint)}
</style>

<div class="head">
  <h1>Articles</h1>
  <span class="sub">
    <?php if ($page['total'] === 0): ?>
      No articles<?= $filtered ? ' match' : '' ?>
    <?php else: ?>
      Showing <?= number_format($page['from']) ?>–<?= number_format($page['to']) ?>
      of <?= number_format($page['total']) ?><?= $filtered ? ' matching' : '' ?>
    <?php endif; ?>
  </span>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="get" action="/articles" class="filters">
    <div class="wide">
      <label for="q">Search title and excerpt</label>
      <input type="text" id="q" name="q" value="<?= $e($filters['q']) ?>" placeholder="e.g. rocket">
    </div>
    <div>
      <label for="source_id">Source</label>
      <select id="source_id" name="source_id">
        <option value="">Any source</option>
        <?php foreach ($sources as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= $filters['source_id'] === (int) $s['id'] ? 'selected' : '' ?>>
            <?= $e($s['name']) ?><?= Page::truthy($s['is_active']) ? '' : ' (off)' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="category_id">Category</label>
      <select id="category_id" name="category_id">
        <option value="">Any category</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $filters['category_id'] === (int) $c['id'] ? 'selected' : '' ?>>
            <?= $e($c['name']) ?><?= Page::truthy($c['is_active']) ? '' : ' (off)' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="from">Published from</label>
      <input type="date" id="from" name="from" value="<?= $e($filters['from']) ?>">
    </div>
    <div>
      <label for="to">Published to</label>
      <input type="date" id="to" name="to" value="<?= $e($filters['to']) ?>">
    </div>
    <div>
      <label for="has_image">Image</label>
      <select id="has_image" name="has_image">
        <option value="">Either</option>
        <option value="1" <?= $filters['has_image'] === '1' ? 'selected' : '' ?>>With image</option>
        <option value="0" <?= $filters['has_image'] === '0' ? 'selected' : '' ?>>Without image</option>
      </select>
    </div>
    <div>
      <label for="undated">Publication date</label>
      <select id="undated" name="undated">
        <option value="">Either</option>
        <option value="1" <?= $filters['undated'] === '1' ? 'selected' : '' ?>>Undated only</option>
        <option value="0" <?= $filters['undated'] === '0' ? 'selected' : '' ?>>Dated only</option>
      </select>
    </div>
    <div>
      <label for="sort">Sort</label>
      <select id="sort" name="sort">
        <option value="published_at" <?= $sort === 'published_at' ? 'selected' : '' ?>>Published</option>
        <option value="fetched_at" <?= $sort === 'fetched_at' ? 'selected' : '' ?>>Fetched</option>
        <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title</option>
      </select>
    </div>
    <div>
      <label for="dir">Direction</label>
      <select id="dir" name="dir">
        <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Newest / Z–A</option>
        <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Oldest / A–Z</option>
      </select>
    </div>
    <div class="acts">
      <button class="btn primary" type="submit">Apply</button>
      <?php if ($filtered || $sort !== 'published_at' || $dir !== 'desc'): ?>
        <a href="/articles" class="muted" style="font-size:.82rem">Clear</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
<?php if ($rows === []): ?>
  <div class="empty">
    <div style="font-size:1.05rem;font-weight:600;color:var(--dim)">No articles<?= $filtered ? ' match these filters' : ' yet' ?></div>
    <div style="font-size:.84rem;margin-top:.3rem">
      <?= $filtered ? 'Try a broader search or <a href="/articles">clear the filters</a>.'
                    : 'The nightly fetch has not stored anything.' ?>
    </div>
  </div>
<?php else: ?>
  <form method="post" action="/articles/bulk-delete" id="bulk">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <div class="bulkbar">
      <button class="btn warn" type="submit" id="bulk-btn" disabled>Delete selected</button>
      <span class="muted" id="bulk-n">Nothing selected. Up to <?= (int) $bulkCap ?> at a time; you will be asked to confirm.</span>
    </div>
    <div class="tbl">
    <table>
      <tr>
        <th style="width:28px"><input type="checkbox" id="all" title="Select all on this page"></th>
        <th><?= $sortLink('title', 'Title') ?></th>
        <th>Source</th>
        <th>Category</th>
        <th><?= $sortLink('published_at', 'Published') ?></th>
        <th>Image</th>
        <th>Author</th>
      </tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" class="pick"></td>
        <td class="t">
          <a href="/articles/view?id=<?= (int) $r['id'] ?>"><?= $e($r['title']) ?></a>
          <?php if ($sort === 'fetched_at'): ?>
            <br><span class="muted" style="font-size:.76rem">fetched <?= $e($r['fetched_fmt']) ?> UTC</span>
          <?php endif; ?>
        </td>
        <td><?= $e($r['blog_name']) ?></td>
        <td><?= $e($r['category_name']) ?></td>
        <td style="white-space:nowrap">
          <?php if ($r['published_fmt'] === null): ?>
            <span class="pill warn">undated</span>
          <?php else: ?>
            <?= $e($r['published_fmt']) ?> <span class="muted">UTC</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (($r['image_url'] ?? '') !== ''): ?>
            <span class="pill ok">image</span>
          <?php else: ?>
            <span class="pill info">none</span>
          <?php endif; ?>
        </td>
        <td class="muted"><?= $e($r['author'] ?? '') ?: '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    </div>
  </form>

  <?php if ($page['pages'] > 1):
    $cur = $page['page'];
    $last = $page['pages'];
    // Pages shown: first, last, and a window of two either side of the current.
    $show = array_unique(array_filter([1, 2, $cur - 2, $cur - 1, $cur, $cur + 1, $cur + 2, $last - 1, $last],
        static fn(int $p): bool => $p >= 1 && $p <= $last));
    sort($show);
    $prev = 0;
  ?>
  <div class="pager">
    <?php if ($cur > 1): ?><a href="<?= $e(Paginator::link($cur - 1)) ?>">&larr; Prev</a><?php endif; ?>
    <?php foreach ($show as $p): ?>
      <?php if ($p - $prev > 1): ?><span class="muted">…</span><?php endif; ?>
      <?php if ($p === $cur): ?>
        <span class="cur"><?= $p ?></span>
      <?php else: ?>
        <a href="<?= $e(Paginator::link($p)) ?>"><?= $p ?></a>
      <?php endif; ?>
      <?php $prev = $p; ?>
    <?php endforeach; ?>
    <?php if ($cur < $last): ?><a href="<?= $e(Paginator::link($cur + 1)) ?>">Next &rarr;</a><?php endif; ?>
    <span class="muted" style="margin-left:auto">Page <?= $cur ?> of <?= $last ?> · <?= (int) $page['per_page'] ?> per page</span>
  </div>
  <?php endif; ?>

  <script>
    (function () {
      var all = document.getElementById('all'), btn = document.getElementById('bulk-btn'),
          n = document.getElementById('bulk-n'), picks = document.querySelectorAll('.pick');
      function sync() {
        var c = 0;
        picks.forEach(function (p) { if (p.checked) c++; });
        btn.disabled = c === 0;
        n.textContent = c === 0 ? 'Nothing selected. Up to <?= (int) $bulkCap ?> at a time; you will be asked to confirm.'
                                : c + ' selected';
        all.checked = c > 0 && c === picks.length;
      }
      all.addEventListener('change', function () { picks.forEach(function (p) { p.checked = all.checked; }); sync(); });
      picks.forEach(function (p) { p.addEventListener('change', sync); });
      sync();
    })();
  </script>
<?php endif; ?>
</div>
