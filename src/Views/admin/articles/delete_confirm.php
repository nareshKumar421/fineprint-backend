<?php
/** @var array $a @var string $csrf */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$events = (int) $a['events_total'];
$seen   = (int) $a['seen_readers'];
?>
<style>
  .btn.danger{background:#be123c;color:#fff;border-color:#be123c;padding:.5rem 1rem}
  .btn.danger:hover{color:#fff;opacity:.9}
  .cascade{list-style:none;padding:0;margin:.5rem 0 0}
  .cascade li{display:flex;gap:.6rem;align-items:baseline;padding:.4rem 0;border-bottom:1px solid var(--line);font-size:.86rem}
  .cascade li:last-child{border-bottom:0}
  .cascade b{min-width:3.5rem;text-align:right;font-variant-numeric:tabular-nums}
</style>

<div class="head">
  <h1 style="font-size:1.3rem">Delete this article?</h1>
  <span class="sub"><a href="/articles/view?id=<?= (int) $a['id'] ?>" class="muted" style="text-decoration:none">&larr; Back</a></span>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h"><h2><?= $e($a['title']) ?></h2></div>
    <p class="muted" style="font-size:.86rem;margin-top:0">
      <?= $e($a['blog_name']) ?> · <?= $e($a['category_name']) ?> ·
      <?= $a['published_fmt'] === null ? 'undated' : 'published ' . $e($a['published_fmt']) . ' UTC' ?>
      · id <code><?= (int) $a['id'] ?></code>
    </p>

    <p style="font-size:.9rem">This removes the article from every reader's feed and archive permanently.
      It is not sent back to the fetcher's ignore list, so if the publisher's feed still carries it,
      the next nightly sync will store it again as a new row.</p>

    <div class="card-h" style="margin-top:1.2rem"><h2>Deleted with it (foreign-key cascade)</h2></div>
    <ul class="cascade">
      <li><b><?= number_format($events) ?></b>
        <span>article_events rows — <?= (int) $a['impressions'] ?> impression(s),
          <?= (int) $a['taps'] ?> tap(s), <?= (int) $a['other_events'] ?> other. These feed the nightly rollup;
          removing them changes nothing that has already been rolled up.</span></li>
      <li><b><?= number_format($seen) ?></b>
        <span>user_seen_articles rows — the readers who were shown it. They may see nothing in its place.</span></li>
      <li><b><?= (int) $a['stats_rows'] ?></b>
        <span>article_stats rows (trending score).</span></li>
    </ul>
    <p class="muted" style="font-size:.8rem">Nothing else references an article. The source and category are untouched.</p>

    <form method="post" action="/articles/delete" style="margin-top:1.3rem;display:flex;gap:.8rem;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
      <input type="hidden" name="confirm" value="1">
      <button class="btn danger" type="submit">Yes, delete it<?= $events + $seen > 0 ? ' and ' . number_format($events + $seen) . ' related row(s)' : '' ?></button>
      <a href="/articles/view?id=<?= (int) $a['id'] ?>" class="muted" style="font-size:.86rem">Cancel</a>
    </form>
  </div>

  <div class="card">
    <div class="card-h"><h2>Why not just hide it?</h2></div>
    <p class="muted" style="font-size:.86rem;margin:0">
      There is no per-article hide. If the problem is the <em>source</em> — spam, a dead
      blog, a feed that changed hands — disable the source on
      <a href="/sources">Sources</a> instead and its future articles stop too.
      Deleting is for a single bad row: a mis-parsed title, a duplicate, a
      publisher takedown request.
    </p>
  </div>
</div>
