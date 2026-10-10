<?php
require __DIR__ . '/_ui.php';
/** @var array|null $src @var array $values @var array $errors @var array $categories @var string $csrf */
$isNew   = $src === null;
$checked = array_map('intval', $values['categories']);
$self    = $isNew ? '/sources/new' : '/sources/edit?id=' . (int) $src['id'];
$field   = static function (string $name, string $label, string $hint, string $placeholder) use ($values, $errors, $e): string {
    $bad = isset($errors[$name]);
    return '<div class="fld"><label for="f-' . $name . '">' . $e($label) . '</label>'
         . '<input type="text" id="f-' . $name . '" name="' . $name . '" value="' . $e($values[$name]) . '"'
         . ' placeholder="' . $e($placeholder) . '" class="' . ($bad ? 'bad' : '') . '" autocomplete="off">'
         . ($bad ? '<div class="err">' . $e($errors[$name]) . '</div>' : '')
         . '<div class="muted" style="font-size:.76rem;margin-top:.25rem">' . $e($hint) . '</div></div>';
};
?>
<div class="head">
  <h1><?= $isNew ? 'Add source' : 'Edit ' . $e($src['blog_name']) ?></h1>
  <span class="sub"><a href="<?= $isNew ? '/sources' : '/sources/view?id=' . (int) $src['id'] ?>" style="color:inherit">← back</a></span>
</div>

<?php if ($errors !== []): ?>
  <div class="flash bad"><strong>!</strong><span>Nothing was saved. <?= count($errors) === 1 ? 'Fix the field below.' : 'Fix the ' . count($errors) . ' fields below.' ?></span></div>
<?php endif; ?>

<form method="post" action="<?= $isNew ? '/sources/create' : '/sources/update' ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $src['id'] ?>"><?php endif; ?>

  <div class="row2">
    <div class="card">
      <div class="card-h"><h2>The blog</h2></div>
      <?= $field('blog_name', 'Name', 'Shown on every card as the source. Use the publisher\'s own name.', 'The Cloudflare Blog') ?>
      <?= $field('blog_url', 'Blog URL', 'The site readers land on, not the feed.', 'https://blog.cloudflare.com/') ?>
      <?= $field('feed_url', 'Feed URL', 'RSS or Atom. It is downloaded and parsed before anything is saved; a redirect is followed and the final address is what gets stored.', 'https://blog.cloudflare.com/rss/') ?>
      <p style="margin:.6rem 0 0;font-size:.8rem">
        <a href="/sources/validate?<?= $e(http_build_query(['feed_url' => $values['feed_url'], 'back' => $self])) ?>" id="chk">Check feed →</a>
        <span class="muted">opens the result on its own page; what you typed in the form is not kept.</span>
      </p>
    </div>

    <div class="card">
      <div class="card-h"><h2>What happens on save</h2></div>
      <ol style="margin:0;padding-left:1.2rem;font-size:.84rem;color:var(--dim);line-height:1.7">
        <li>The feed is fetched with the same code the nightly job uses.</li>
        <li>It must parse and contain at least one usable item — a feed that parses to nothing is a failure, not a success.</li>
        <li>The source and its category rows are written in one transaction.</li>
        <?php if ($isNew): ?><li>The first articles arrive on the next nightly sync (03:10 UTC).</li>
        <?php else: ?><li>The category mapping is replaced with what is ticked below. Articles already stored are untouched.</li><?php endif; ?>
      </ol>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.1rem">
    <div class="card-h">
      <h2>Categories</h2>
      <span class="act"><label style="margin:0;display:inline-flex;gap:.4rem;align-items:center;cursor:pointer"><input type="checkbox" id="ck-all"> select all</label></span>
    </div>
    <?php if (isset($errors['categories']) || isset($errors['override'])): ?>
      <div class="err" style="margin:0 0 .7rem"><?= $e($errors['categories'] ?? $errors['override']) ?></div>
    <?php endif; ?>
    <p class="muted" style="font-size:.8rem;margin:0 0 .9rem">
      One row is stored per blog + category, and the fetch job iterates those rows. The optional override is a
      per-category feed (a tag or section feed) used instead of the main one for that category.
    </p>
    <div class="ck">
      <?php foreach ($categories as $c): $cid = (int) $c['id']; $on = in_array($cid, $checked, true); ?>
        <label>
          <input type="checkbox" name="categories[]" value="<?= $cid ?>"<?= $on ? ' checked' : '' ?>>
          <span><?= $e(($c['icon'] ?? '') !== '' ? $c['icon'] . ' ' : '') ?><?= $e($c['name']) ?><?= \App\Admin\Page::truthy($c['is_active']) ? '' : ' <span class="muted">(inactive)</span>' ?></span>
          <input type="text" name="override[<?= $cid ?>]" value="<?= $e($values['override'][$cid] ?? '') ?>" placeholder="feed override (optional)" title="Feed URL override for this category">
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="acts">
    <button class="btn primary" type="submit"><?= $isNew ? 'Fetch, verify and add' : 'Save changes' ?></button>
    <a class="btn" href="<?= $isNew ? '/sources' : '/sources/view?id=' . (int) $src['id'] ?>">Cancel</a>
  </div>
</form>
<script>
  // Select-all for the category grid, and keep the "Check feed" link pointing at whatever is in the box.
  (function () {
    var all = document.getElementById('ck-all');
    var boxes = document.querySelectorAll('.ck input[type=checkbox]');
    if (all) { all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); }); }
    var f = document.getElementById('f-feed_url'), chk = document.getElementById('chk');
    if (f && chk) {
      var base = chk.getAttribute('href').split('?')[0];
      var back = <?= json_encode($self) ?>;
      f.addEventListener('input', function () {
        chk.setAttribute('href', base + '?feed_url=' + encodeURIComponent(f.value) + '&back=' + encodeURIComponent(back));
      });
    }
  })();
</script>
