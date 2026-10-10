<?php
require __DIR__ . '/_ui.php';
/** @var string $url @var array|null $result @var string|null $error @var string $back */
?>
<div class="head">
  <h1>Check feed</h1>
  <span class="sub"><a href="<?= $e($back) ?>" style="color:inherit">← back to the form</a></span>
</div>

<div class="card" style="max-width:720px">
  <div class="kv" style="margin-bottom:1rem"><dt>URL</dt><dd><code><?= $e($url) ?></code></dd></div>

  <?php if ($error !== null): ?>
    <div class="flash bad"><strong>✕</strong><span><strong>Not usable:</strong> <?= $e($error) ?></span></div>
    <p class="muted" style="font-size:.8rem;margin:0">This is the same fetch and parse the nightly job runs, so the job would record the same error. Common causes: a page URL instead of the feed, a host that blocks unknown user agents (http 403), or a feed whose items have no title or link.</p>
  <?php else: ?>
    <div class="flash ok"><strong>✓</strong><span>
      <strong><?= $e($result['title'] !== '' ? $result['title'] : '(untitled feed)') ?></strong>
      — <?= (int) $result['items'] ?> usable item<?= (int) $result['items'] === 1 ? '' : 's' ?><?= $result['malformed'] ? ', with XML warnings the parser recovered from' : '' ?>.
    </span></div>
    <?php if ($result['final_url'] !== $url): ?>
      <p style="font-size:.84rem"><span class="pill info">redirect</span> resolves to <code><?= $e($result['final_url']) ?></code> — that address is what gets stored.</p>
    <?php endif; ?>
    <div class="rank">
      <?php foreach ($result['first'] as $i => $t): ?>
        <div class="r"><i><?= $i + 1 ?></i><span><?= $e($t) ?></span></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="acts" style="margin-top:1.2rem">
    <a class="btn primary" href="<?= $e($back) ?>">Back to the form</a>
    <?php if ($error !== null && $url !== ''): ?><a class="btn" href="/sources/validate?<?= $e(http_build_query(['feed_url' => $url, 'back' => $back])) ?>">Try again</a><?php endif; ?>
  </div>
</div>
