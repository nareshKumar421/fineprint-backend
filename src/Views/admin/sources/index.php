<?php
require __DIR__ . '/_ui.php';
/** @var array $rows @var array $counts @var array $page @var string $q @var string $status
 *  @var string $sort @var string $dir @var int $maxFailures @var string $csrf */
$tile = static function (string $key, string $label, string $tone, string $hint) use ($counts, $status, $e): string {
    $q = $_GET; $q['status'] = $key; unset($q['page']);
    $sel = $status === $key ? ' sel' : '';
    return '<a class="kpi ' . $tone . ' lnk' . $sel . '" href="/sources?' . $e(http_build_query($q)) . '">'
         . '<div class="k-l">' . $e($label) . '</div>'
         . '<div class="k-n">' . number_format((int) ($counts[$key] ?? 0)) . '</div>'
         . '<div class="k-s">' . $e($hint) . '</div></a>';
};
$sortLink = static function (string $key, string $label) use ($sort, $dir, $e): string {
    $q = $_GET; $q['sort'] = $key; unset($q['page']);
    $q['dir'] = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $mark = $sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<a href="/sources?' . $e(http_build_query($q)) . '" style="color:inherit;text-decoration:none">'
         . $e($label) . $mark . '</a>';
};
$tabs = ['' => 'All', 'healthy' => 'Healthy', 'no_recent' => 'No recent', 'failing' => 'Failing', 'deactivated' => 'Deactivated'];
?>
<div class="head">
  <h1>Sources</h1>
  <span class="sub"><?= number_format((int) ($counts['all_total'] ?? 0)) ?> blogs ingested nightly</span>
  <a class="btn primary" href="/sources/new" style="margin-left:.5rem">+ Add source</a>
</div>

<div class="kpis">
  <?= $tile('healthy',     'Healthy',     'green',  'active, no failures, articles in 30 days') ?>
  <?= $tile('no_recent',   'No recent',   'blue',   'green in the logs, contributing nothing') ?>
  <?= $tile('failing',     'Failing',     'pink',   'off after ' . $maxFailures . ' consecutive failures') ?>
  <?= $tile('deactivated', 'Deactivated', 'violet', 'never retried until reactivated here') ?>
</div>

<div class="card">
  <form method="get" action="/sources" class="filters">
    <input type="hidden" name="sort" value="<?= $e($sort) ?>">
    <input type="hidden" name="dir" value="<?= $e($dir) ?>">
    <div class="seg">
      <?php foreach ($tabs as $key => $label):
        $q2 = ['q' => $q, 'sort' => $sort, 'dir' => $dir]; if ($key !== '') { $q2['status'] = $key; } ?>
        <a class="<?= $status === $key ? 'on' : '' ?>" href="/sources?<?= $e(http_build_query($q2)) ?>"><?= $e($label) ?><?php
          if ($key !== ''): ?><b><?= (int) ($counts[$key] ?? 0) ?></b><?php endif; ?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= $e($status) ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= $e($q) ?>" placeholder="Search name or feed URL">
    <button class="btn">Search</button>
    <?php if ($q !== '' || $status !== ''): ?><a class="btn" href="/sources">Clear</a><?php endif; ?>
  </form>

  <?php if ($rows === []): ?>
    <p class="muted" style="padding:2rem 0;text-align:center">
      <?= $q !== '' || $status !== '' ? 'No sources match this filter.' : 'No sources yet — add the first one.' ?>
    </p>
  <?php else: ?>
  <div class="tbl">
  <table>
    <tr>
      <th><?= $sortLink('name', 'Blog') ?></th>
      <th><?= $sortLink('status', 'Status') ?></th>
      <th>Categories</th>
      <th class="right"><?= $sortLink('articles', 'Articles') ?></th>
      <th class="right"><?= $sortLink('recent', '30 days') ?></th>
      <th class="right"><?= $sortLink('failures', 'Failures') ?></th>
      <th><?= $sortLink('fetched', 'Last fetched') ?></th>
      <th>Last error</th>
      <th></th>
    </tr>
    <?php foreach ($rows as $s):
      $active = \App\Admin\Page::truthy($s['is_active']);
      $fails  = (int) $s['failure_count'];
      $recent = (int) $s['articles_30d'];
      $err    = (string) ($s['last_error'] ?? '');
    ?>
    <tr>
      <td>
        <a href="/sources/view?id=<?= (int) $s['id'] ?>" style="font-weight:600;color:inherit;text-decoration:none"><?= $e($s['blog_name']) ?></a><br>
        <span class="muted trunc" style="font-size:.76rem" title="<?= $e($s['feed_url']) ?>"><?= $e($s['feed_url']) ?></span>
      </td>
      <td><?= $pill((string) $s['status'], $fails, $maxFailures) ?></td>
      <td style="font-size:.8rem;max-width:200px"><?= $s['categories'] === null ? '<span class="muted">none</span>' : $e($s['categories']) ?></td>
      <td class="right"><?= number_format((int) $s['articles_total']) ?></td>
      <td class="right"><?= $recent === 0 ? '<span class="muted">0</span>' : number_format($recent) ?></td>
      <td class="right"><?= $fails > 0 ? '<strong>' . $fails . '</strong>' : '<span class="muted">0</span>' ?><span class="muted">/<?= (int) $maxFailures ?></span></td>
      <td class="muted" style="white-space:nowrap;font-size:.8rem"><?= $e($when($s['last_fetched_at'])) ?></td>
      <td><?php if ($err !== ''): ?><span class="muted trunc" style="font-size:.78rem;max-width:200px" title="<?= $e($err) ?>"><?= $e($err) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td class="right">
        <div class="acts" style="justify-content:flex-end">
          <a class="btn" href="/sources/edit?id=<?= (int) $s['id'] ?>">Edit</a>
          <form method="post" action="/sources/toggle"<?= $active ? ' onsubmit="return confirm(\'Disable ' . $e(addslashes((string) $s['blog_name'])) . '? The nightly sync will skip it until it is reactivated.\')"' : '' ?>>
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button class="btn<?= $active ? ' warn' : '' ?>"><?= $active ? 'Disable' : 'Enable' ?></button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>

  <div class="pager">
    <?php if ($page['page'] > 1): ?><a class="btn" href="<?= $e(\App\Admin\Paginator::link($page['page'] - 1)) ?>">← Prev</a><?php endif; ?>
    <?php if ($page['page'] < $page['pages']): ?><a class="btn" href="<?= $e(\App\Admin\Paginator::link($page['page'] + 1)) ?>">Next →</a><?php endif; ?>
    <span class="sp">Showing <?= $page['from'] ?>–<?= $page['to'] ?> of <?= number_format($page['total']) ?> · page <?= $page['page'] ?> of <?= $page['pages'] ?></span>
  </div>
  <?php endif; ?>
</div>
