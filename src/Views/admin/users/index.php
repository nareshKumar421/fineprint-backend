<?php
/** @var array $rows @var array $m @var array $page
 *  @var string $q @var string $role @var string $status @var string $sort @var string $dir @var string $csrf */
$e  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$dt = fn($v) => $v === null || $v === '' ? '—' : substr((string) $v, 0, 16);

// Column-header sort links keep the current filters and go back to page 1.
$sortLink = function (string $col, string $label) use ($sort, $dir, $e): string {
    $next  = $sort === $col && $dir === 'desc' ? 'asc' : 'desc';
    $arrow = $sort === $col ? ($dir === 'desc' ? ' &darr;' : ' &uarr;') : '';
    $href  = '?' . http_build_query(array_merge($_GET, ['sort' => $col, 'dir' => $next, 'page' => 1]));
    return '<a href="' . $e($href) . '" style="color:inherit;text-decoration:none">' . $e($label) . $arrow . '</a>';
};

$sel = 'padding:.55rem .8rem;font:inherit;font-size:.92rem;border:1px solid var(--line);'
     . 'border-radius:10px;background:var(--bg);color:var(--ink)';
?>
<div class="head">
  <h1>Users</h1>
  <span class="sub"><?= number_format((int) ($m['all_users'] ?? 0)) ?> accounts</span>
</div>

<div class="kpis">
  <div class="kpi fill">
    <div class="k-l">Accounts</div>
    <div class="k-n"><?= number_format((int) ($m['all_users'] ?? 0)) ?></div>
    <div class="k-s"><?= number_format((int) ($m['active_users'] ?? 0)) ?> active</div>
  </div>
  <div class="kpi green">
    <div class="k-l">Seen this week</div>
    <div class="k-n"><?= number_format((int) ($m['seen_7d'] ?? 0)) ?></div>
    <div class="k-s">signed in within 7 days</div>
  </div>
  <div class="kpi cyan">
    <div class="k-l">New in 30 days</div>
    <div class="k-n"><?= number_format((int) ($m['new_30d'] ?? 0)) ?></div>
    <div class="k-s">accounts created</div>
  </div>
  <div class="kpi violet">
    <div class="k-l">Administrators</div>
    <div class="k-n"><?= number_format((int) ($m['admins'] ?? 0)) ?></div>
    <div class="k-s"><?= number_format((int) ($m['admins_all'] ?? 0) - (int) ($m['admins'] ?? 0)) ?> disabled</div>
  </div>
</div>

<div class="card">
  <div class="card-h">
    <h2>All users</h2>
    <form method="get" action="/users/new" style="margin-left:auto">
      <button class="btn" type="submit">+ New user</button>
    </form>
  </div>

  <form method="get" action="/users"
        style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem">
    <input type="hidden" name="sort" value="<?= $e($sort) ?>">
    <input type="hidden" name="dir"  value="<?= $e($dir) ?>">
    <div style="flex:2;min-width:200px">
      <label for="q" style="margin:0 0 .3rem">Search</label>
      <input id="q" type="text" name="q" value="<?= $e($q) ?>" placeholder="email or name">
    </div>
    <div style="flex:1;min-width:130px">
      <label for="role" style="margin:0 0 .3rem">Role</label>
      <select id="role" name="role" style="<?= $sel ?>;width:100%">
        <option value=""<?= $role === '' ? ' selected' : '' ?>>Any role</option>
        <option value="reader"<?= $role === 'reader' ? ' selected' : '' ?>>Reader</option>
        <option value="admin"<?= $role === 'admin' ? ' selected' : '' ?>>Admin</option>
      </select>
    </div>
    <div style="flex:1;min-width:130px">
      <label for="status" style="margin:0 0 .3rem">Status</label>
      <select id="status" name="status" style="<?= $sel ?>;width:100%">
        <option value=""<?= $status === '' ? ' selected' : '' ?>>Any status</option>
        <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
        <option value="disabled"<?= $status === 'disabled' ? ' selected' : '' ?>>Disabled</option>
      </select>
    </div>
    <div style="display:flex;gap:.4rem;align-items:center;padding-bottom:.15rem">
      <button class="btn" type="submit">Filter</button>
      <?php if ($q !== '' || $role !== '' || $status !== ''): ?>
        <a href="/users" class="muted" style="font-size:.8rem">Reset</a>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($rows === []): ?>
    <p class="muted" style="padding:2rem 0;text-align:center">
      <?= ($q !== '' || $role !== '' || $status !== '') ? 'No users match these filters.' : 'No users yet.' ?>
    </p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table>
    <tr>
      <th><?= $sortLink('email', 'User') ?></th>
      <th>Role</th>
      <th>Status</th>
      <th class="right">Topics</th>
      <th class="right">Events</th>
      <th class="right">Seen</th>
      <th><?= $sortLink('created_at', 'Created') ?></th>
      <th><?= $sortLink('last_login_at', 'Last login') ?></th>
    </tr>
    <?php foreach ($rows as $r):
      $active = \App\Admin\Page::truthy($r['is_active']);
      $name   = trim((string) ($r['display_name'] ?? ''));
    ?>
    <tr>
      <td>
        <a href="/users/view?id=<?= (int) $r['id'] ?>" style="color:inherit;font-weight:600;text-decoration:none">
          <?= $e($r['email']) ?></a>
        <?php if ($name !== ''): ?>
          <br><span class="muted" style="font-size:.8rem"><?= $e($name) ?></span>
        <?php endif; ?>
      </td>
      <td><span class="pill <?= $r['role'] === 'admin' ? 'info' : 'ok' ?>"><?= $e($r['role']) ?></span></td>
      <td><span class="pill <?= $active ? 'ok' : 'bad' ?>"><?= $active ? 'active' : 'disabled' ?></span></td>
      <td class="right"><?= number_format((int) $r['topics']) ?></td>
      <td class="right"><?= (int) $r['events'] === 0 ? '<span class="muted">0</span>' : number_format((int) $r['events']) ?></td>
      <td class="right"><?= (int) $r['seen'] === 0 ? '<span class="muted">0</span>' : number_format((int) $r['seen']) ?></td>
      <td class="muted"><?= $e($dt($r['created_at'])) ?></td>
      <td class="muted"><?= $e($dt($r['last_login_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>

  <div style="display:flex;align-items:center;gap:1rem;margin-top:1rem;font-size:.82rem" class="muted">
    <span>Showing <?= number_format($page['from']) ?>–<?= number_format($page['to']) ?>
          of <?= number_format($page['total']) ?></span>
    <span style="margin-left:auto">Page <?= $page['page'] ?> of <?= $page['pages'] ?></span>
    <?php if ($page['page'] > 1): ?>
      <a href="<?= $e(\App\Admin\Paginator::link($page['page'] - 1)) ?>">&larr; Prev</a>
    <?php endif; ?>
    <?php if ($page['page'] < $page['pages']): ?>
      <a href="<?= $e(\App\Admin\Paginator::link($page['page'] + 1)) ?>">Next &rarr;</a>
    <?php endif; ?>
  </div>
</div>
