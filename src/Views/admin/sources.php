<?php /** @var array $sources @var string $csrf @var ?string $flash @var ?string $flashTone */ ?>
<h1>Sources</h1>
<?php if ($flash !== null): ?>
  <div class="flash <?= $flashTone === 'bad' ? 'bad' : 'ok' ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<p class="muted" style="margin-top:-.5rem">
  The blogs FinePrint ingests from. A source is deactivated automatically
  after <?= (int) $maxFailures ?> consecutive failures and is never retried
  on its own — reactivating one is what this page is for.
</p>

<table>
  <tr>
    <th>Blog</th><th>Status</th><th class="right">Articles</th>
    <th class="right">In window</th><th>Last error</th><th></th>
  </tr>
  <?php foreach ($sources as $s):
    $active  = $s['is_active'] === true || $s['is_active'] === 't' || $s['is_active'] === '1';
    $fails   = (int) $s['failure_count'];
    $inWin   = (int) $s['in_window'];
  ?>
  <tr>
    <td>
      <?= htmlspecialchars((string) $s['blog_name']) ?><br>
      <span class="muted" style="font-size:.8rem"><?= htmlspecialchars((string) $s['feed_url']) ?></span>
    </td>
    <td>
      <?php if (!$active): ?>
        <span class="pill bad">deactivated</span>
      <?php elseif ($fails > 0): ?>
        <span class="pill warn">failing <?= $fails ?>/<?= (int) $maxFailures ?></span>
      <?php elseif ($inWin === 0): ?>
        <!-- Green in every log and contributing nothing: the failure mode
             that is invisible without a screen like this. -->
        <span class="pill warn">no recent articles</span>
      <?php else: ?>
        <span class="pill ok">healthy</span>
      <?php endif; ?>
    </td>
    <td class="right"><?= number_format((int) $s['articles']) ?></td>
    <td class="right"><?= $inWin === 0 ? '<span class="muted">0</span>' : number_format($inWin) ?></td>
    <td class="muted" style="font-size:.82rem;max-width:260px;overflow:hidden;text-overflow:ellipsis">
      <?= htmlspecialchars((string) ($s['last_error'] ?? '')) ?>
    </td>
    <td class="right">
      <form method="post" action="/sources/toggle" style="display:inline">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        <button class="<?= $active ? 'danger' : '' ?>">
          <?= $active ? 'Disable' : 'Reactivate' ?>
        </button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
