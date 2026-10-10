<?php
/** @var array $rows @var array $page @var array $filters @var string $sort @var string $dir
 *  @var array $sortKeys @var array $entityOptions @var array $entityRoutes */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$pretty = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '—';
    }
    $d = json_decode($json);
    return $d === null && $json !== 'null'
        ? $json
        : (string) json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};
// A column header that toggles the sort, keeping every filter.
$sortLink = static function (string $key, string $label) use ($sort, $dir, $e): string {
    $q = $_GET;
    $q['sort'] = $key;
    $q['dir']  = ($sort === $key && $dir === 'desc') ? 'asc' : 'desc';
    unset($q['page']);
    $mark = $sort === $key ? ($dir === 'desc' ? ' ↓' : ' ↑') : '';
    return '<a href="?' . $e(http_build_query($q)) . '" style="color:inherit;text-decoration:none">'
         . $e($label) . $mark . '</a>';
};
$anyFilter = implode('', $filters) !== '';
?>
<div class="head">
  <h1>Audit log</h1>
  <span class="sub"><?= number_format($page['total']) ?> entr<?= $page['total'] === 1 ? 'y' : 'ies' ?><?= $anyFilter ? ' match' : '' ?></span>
</div>

<div class="card" style="margin-bottom:1.1rem">
  <form method="get" action="/audit">
    <div class="inline-form">
      <div>
        <label for="f-actor" style="margin-top:0">Actor</label>
        <input id="f-actor" type="text" name="actor" value="<?= $e($filters['actor']) ?>" placeholder="email contains">
      </div>
      <div>
        <label for="f-action" style="margin-top:0">Action</label>
        <input id="f-action" type="text" name="action" value="<?= $e($filters['action']) ?>" placeholder="e.g. source.disable">
      </div>
      <div>
        <label for="f-entity" style="margin-top:0">Entity</label>
        <input id="f-entity" type="text" name="entity" list="entities" value="<?= $e($filters['entity']) ?>" placeholder="table">
        <datalist id="entities">
          <?php foreach ($entityOptions as $opt): ?><option value="<?= $e($opt) ?>"></option><?php endforeach; ?>
        </datalist>
      </div>
      <div>
        <label for="f-from" style="margin-top:0">From</label>
        <input id="f-from" type="date" name="from" value="<?= $e($filters['from']) ?>">
      </div>
      <div>
        <label for="f-to" style="margin-top:0">To</label>
        <input id="f-to" type="date" name="to" value="<?= $e($filters['to']) ?>">
      </div>
      <div style="display:flex;gap:.4rem">
        <input type="hidden" name="sort" value="<?= $e($sort) ?>">
        <input type="hidden" name="dir" value="<?= $e($dir) ?>">
        <button class="btn primary" type="submit">Filter</button>
        <?php if ($anyFilter): ?><a class="btn" href="/audit">Clear</a><?php endif; ?>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-h">
    <h2>Entries</h2>
    <span class="act">
      <?php if ($page['total'] > 0): ?>
        <?= number_format($page['from']) ?>–<?= number_format($page['to']) ?> of <?= number_format($page['total']) ?>
      <?php endif; ?>
    </span>
  </div>

  <?php if ($rows === []): ?>
    <p class="muted" style="padding:1.5rem 0;text-align:center">
      <?= $anyFilter ? 'Nothing matches these filters.' : 'No administrative actions recorded yet.' ?>
    </p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table>
    <tr>
      <th><?= $sortLink('created_at', 'When (UTC)') ?></th>
      <th><?= $sortLink('actor_email', 'Actor') ?></th>
      <th><?= $sortLink('action', 'Action') ?></th>
      <th><?= $sortLink('entity', 'Entity') ?></th>
      <th>IP</th>
      <th>Details</th>
    </tr>
    <?php foreach ($rows as $r):
      $entity = (string) ($r['entity'] ?? '');
      $eid    = (string) ($r['entity_id'] ?? '');
      $href   = ($entity !== '' && $eid !== '' && isset($entityRoutes[$entity]))
              ? $entityRoutes[$entity] . rawurlencode($eid) : null;
      $hasDetails = $r['before_json'] !== null || $r['after_json'] !== null;
    ?>
    <tr>
      <td class="muted" style="white-space:nowrap"><?= $e(substr((string) $r['created_at'], 0, 19)) ?></td>
      <td><?= $r['actor_email'] !== null ? $e($r['actor_email']) : '<span class="muted">—</span>' ?></td>
      <td><code><?= $e($r['action']) ?></code></td>
      <td>
        <?php if ($entity === ''): ?>
          <span class="muted">—</span>
        <?php elseif ($href !== null): ?>
          <a href="<?= $e($href) ?>" style="color:var(--accent);text-decoration:none"><?= $e($entity) ?> #<?= $e($eid) ?></a>
        <?php else: ?>
          <?= $e($entity) ?><?= $eid !== '' ? ' #' . $e($eid) : '' ?>
        <?php endif; ?>
      </td>
      <td class="muted" style="font-size:.8rem"><?= $e($r['ip'] ?? '') ?></td>
      <td>
        <?php if ($hasDetails): ?>
        <details>
          <summary style="cursor:pointer;color:var(--dim);font-size:.8rem">before / after</summary>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.6rem;margin-top:.4rem">
            <div>
              <div class="muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">Before</div>
              <pre style="margin:.2rem 0 0;padding:.5rem .6rem;background:var(--bg);border-radius:8px;font-size:.76rem;white-space:pre-wrap;word-break:break-word;max-height:260px;overflow:auto"><?= $e($pretty($r['before_json'])) ?></pre>
            </div>
            <div>
              <div class="muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em">After</div>
              <pre style="margin:.2rem 0 0;padding:.5rem .6rem;background:var(--bg);border-radius:8px;font-size:.76rem;white-space:pre-wrap;word-break:break-word;max-height:260px;overflow:auto"><?= $e($pretty($r['after_json'])) ?></pre>
            </div>
          </div>
        </details>
        <?php else: ?>
          <span class="muted" style="font-size:.8rem">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>

  <?php if ($page['pages'] > 1): ?>
  <div style="display:flex;align-items:center;gap:.6rem;margin-top:1rem;font-size:.84rem">
    <?php if ($page['page'] > 1): ?>
      <a class="btn sm" href="<?= $e(\App\Admin\Paginator::link($page['page'] - 1)) ?>">← Previous</a>
    <?php endif; ?>
    <span class="muted">Page <?= $page['page'] ?> of <?= $page['pages'] ?></span>
    <?php if ($page['page'] < $page['pages']): ?>
      <a class="btn sm" href="<?= $e(\App\Admin\Paginator::link($page['page'] + 1)) ?>">Next →</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
