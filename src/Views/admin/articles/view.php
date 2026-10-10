<?php
/** @var array $a @var string $csrf */
use App\Admin\Page;

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$imp  = (int) $a['impressions'];
$taps = (int) $a['taps'];
$ctr  = $imp > 0 ? round($taps / $imp * 100, 1) . '%' : '—';
?>
<style>
  .dl{display:grid;grid-template-columns:150px 1fr;gap:.45rem 1rem;font-size:.86rem}
  .dl dt{color:var(--faint);font-size:.74rem;text-transform:uppercase;letter-spacing:.05em;font-weight:600;padding-top:.15rem}
  .dl dd{margin:0;word-break:break-word}
  @media(max-width:600px){.dl{grid-template-columns:1fr}.dl dt{padding-top:.5rem}}
  .excerpt{font-size:.9rem;line-height:1.6;color:var(--dim);white-space:pre-wrap}
  .thumb{max-width:100%;max-height:260px;border-radius:10px;display:block}
  .btn.danger{color:#be123c;border-color:#be123c}
  .btn.danger:hover{background:#be123c;color:#fff}
  .btn.primary{background:var(--accent);color:var(--accent-ink);border-color:var(--accent)}
</style>

<div class="head">
  <h1 style="font-size:1.3rem"><?= $e($a['title']) ?></h1>
  <span class="sub"><a href="/articles" class="muted" style="text-decoration:none">&larr; All articles</a></span>
</div>

<div class="kpis">
  <div class="kpi violet">
    <div class="k-l">Readers who saw it</div>
    <div class="k-n"><?= number_format((int) $a['seen_readers']) ?></div>
    <div class="k-s">user_seen_articles rows</div>
  </div>
  <div class="kpi blue">
    <div class="k-l">Impressions</div>
    <div class="k-n"><?= number_format($imp) ?></div>
    <div class="k-s">logged by the app</div>
  </div>
  <div class="kpi green">
    <div class="k-l">Taps</div>
    <div class="k-n"><?= number_format($taps) ?></div>
    <div class="k-s">tap rate <?= $e($ctr) ?></div>
  </div>
  <div class="kpi cyan">
    <div class="k-l">Other events</div>
    <div class="k-n"><?= number_format((int) $a['other_events']) ?></div>
    <div class="k-s">share, hide, not interested</div>
  </div>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h">
      <h2>Article</h2>
      <a class="act" href="<?= $e($a['article_url']) ?>" target="_blank" rel="noopener">Open original &nearr;</a>
    </div>
    <?php if (($a['image_url'] ?? '') !== ''): ?>
      <p><img class="thumb" src="<?= $e($a['image_url']) ?>" alt="" loading="lazy"></p>
    <?php endif; ?>
    <?php if (($a['excerpt'] ?? '') !== ''): ?>
      <p class="excerpt"><?= $e($a['excerpt']) ?></p>
    <?php else: ?>
      <p class="muted">No excerpt stored.</p>
    <?php endif; ?>
    <p class="muted" style="font-size:.78rem;margin-bottom:0">
      Excerpt only, by design — FinePrint never stores the full text. The link above goes to the publisher.
    </p>
  </div>

  <div class="card">
    <div class="card-h"><h2>Record</h2></div>
    <dl class="dl">
      <dt>ID</dt><dd><code><?= (int) $a['id'] ?></code></dd>
      <dt>Source</dt>
      <dd>
        <a href="/sources/view?id=<?= (int) $a['blog_source_id'] ?>"><?= $e($a['blog_name']) ?></a>
        <?php if (!Page::truthy($a['source_active'])): ?> <span class="pill bad">source off</span><?php endif; ?>
        <br><span class="muted" style="font-size:.78rem"><?= $e($a['blog_url']) ?></span>
      </dd>
      <dt>Category</dt>
      <dd><?= $e($a['category_name']) ?> <span class="muted">(<?= $e($a['category_slug']) ?>, id <?= (int) $a['category_id'] ?>)</span></dd>
      <dt>Author</dt><dd><?= ($a['author'] ?? '') !== '' ? $e($a['author']) : '<span class="muted">—</span>' ?></dd>
      <dt>Published</dt>
      <dd><?= $a['published_fmt'] === null ? '<span class="pill warn">undated</span>' : $e($a['published_fmt']) . ' UTC' ?></dd>
      <dt>Fetched</dt><dd><?= $e($a['fetched_fmt']) ?> UTC</dd>
      <dt>Image</dt>
      <dd><?= ($a['image_url'] ?? '') !== '' ? '<span class="pill ok">image</span> <span class="muted" style="font-size:.78rem">' . $e($a['image_url']) . '</span>' : '<span class="pill info">none</span>' ?></dd>
      <dt>URL</dt>
      <dd><a href="<?= $e($a['article_url']) ?>" target="_blank" rel="noopener" style="font-size:.82rem"><?= $e($a['article_url']) ?></a></dd>
      <dt>GUID</dt><dd><code style="font-size:.76rem"><?= $e($a['guid']) ?></code></dd>
      <dt>Trending row</dt><dd><?= (int) $a['stats_rows'] > 0 ? 'yes (article_stats)' : '<span class="muted">none</span>' ?></dd>
    </dl>

    <div style="margin-top:1.2rem;padding-top:1rem;border-top:1px solid var(--line);display:flex;gap:.6rem;align-items:center">
      <a href="/articles/delete-confirm?id=<?= (int) $a['id'] ?>"><button class="btn danger" type="button">Delete this article…</button></a>
      <span class="muted" style="font-size:.78rem">Asks for confirmation first.</span>
    </div>
  </div>
</div>
