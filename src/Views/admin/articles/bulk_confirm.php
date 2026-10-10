<?php
/** @var array $rows @var array $ids @var int $missing @var int $events @var int $seen @var string $csrf */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$n = count($rows);
?>
<style>
  .btn.danger{background:#be123c;color:#fff;border-color:#be123c;padding:.5rem 1rem}
  .btn.danger:hover{color:#fff;opacity:.9}
  .tbl{overflow-x:auto;max-height:60vh;overflow-y:auto}
</style>

<div class="head">
  <h1 style="font-size:1.3rem">Delete <?= $n ?> article<?= $n === 1 ? '' : 's' ?>?</h1>
  <span class="sub"><a href="/articles" class="muted" style="text-decoration:none">&larr; Back to the list</a></span>
</div>

<?php if ($missing > 0): ?>
  <div class="flash warn"><span><?= $missing ?> of the selected id(s) no longer exist and are skipped.</span></div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi pink">
    <div class="k-l">Articles</div>
    <div class="k-n"><?= number_format($n) ?></div>
    <div class="k-s">permanently removed</div>
  </div>
  <div class="kpi blue">
    <div class="k-l">Events cascade</div>
    <div class="k-n"><?= number_format($events) ?></div>
    <div class="k-s">article_events rows</div>
  </div>
  <div class="kpi violet">
    <div class="k-l">Seen records cascade</div>
    <div class="k-n"><?= number_format($seen) ?></div>
    <div class="k-s">user_seen_articles rows</div>
  </div>
</div>

<div class="card">
  <div class="card-h">
    <h2>What will be deleted</h2>
    <span class="act">One transaction — all or nothing. article_stats rows cascade too.</span>
  </div>
  <div class="tbl">
    <table>
      <tr><th>Title</th><th>Source</th><th>Published</th><th class="right">Events</th><th class="right">Seen</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/articles/view?id=<?= (int) $r['id'] ?>" style="color:inherit;text-decoration:none"><?= $e($r['title']) ?></a></td>
        <td class="muted"><?= $e($r['blog_name']) ?></td>
        <td style="white-space:nowrap"><?= $r['published_fmt'] === null ? '<span class="pill warn">undated</span>' : $e($r['published_fmt']) ?></td>
        <td class="right"><?= (int) $r['events_total'] ?></td>
        <td class="right"><?= (int) $r['seen_readers'] ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <form method="post" action="/articles/bulk-delete" style="margin-top:1.3rem;display:flex;gap:.8rem;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="confirm" value="1">
    <?php foreach ($ids as $id): ?>
      <input type="hidden" name="ids[]" value="<?= (int) $id ?>">
    <?php endforeach; ?>
    <button class="btn danger" type="submit">Yes, delete <?= $n ?> article<?= $n === 1 ? '' : 's' ?></button>
    <a href="/articles" class="muted" style="font-size:.86rem">Cancel</a>
  </form>
</div>
