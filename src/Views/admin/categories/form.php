<?php
/** @var string $mode 'new'|'edit' @var array $cat @var string[] $errors @var string $csrf */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$isNew  = $mode === 'new';
$active = \App\Admin\Page::truthy($cat['is_active'] ?? true);
$field  = 'width:100%;padding:.62rem .8rem;font:inherit;font-size:.92rem;border:1px solid var(--line);'
        . 'border-radius:10px;background:var(--bg);color:var(--ink)';
?>
<div class="head">
  <h1><?= $isNew ? 'New category' : 'Edit ' . $e($cat['name']) ?></h1>
  <span class="sub">
    <a href="<?= $isNew ? '/categories' : '/categories/view?id=' . (int) $cat['id'] ?>" class="muted">← Back</a>
  </span>
</div>

<?php foreach ($errors as $err): ?>
  <div class="flash bad"><strong>!</strong><span><?= $e($err) ?></span></div>
<?php endforeach; ?>

<div class="row2">
  <div class="card">
    <form method="post" action="<?= $isNew ? '/categories/create' : '/categories/update' ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $cat['id'] ?>"><?php endif; ?>

      <label for="f-name">Name <span class="muted">(required, up to 80 characters)</span></label>
      <input type="text" id="f-name" name="name" maxlength="80" required value="<?= $e($cat['name'] ?? '') ?>">

      <label for="f-slug">Slug <span class="muted">(leave blank to derive from the name)</span></label>
      <input type="text" id="f-slug" name="slug" maxlength="100" value="<?= $e($cat['slug'] ?? '') ?>"
             pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="lowercase-with-dashes">
      <?php if (!$isNew): ?>
        <div class="muted" style="font-size:.76rem;margin-top:.25rem">
          Changing the slug changes the identifier the app receives; readers' subscriptions are by id and are unaffected.
        </div>
      <?php endif; ?>

      <label for="f-desc">Description <span class="muted">(optional)</span></label>
      <textarea id="f-desc" name="description" rows="3" maxlength="500" style="<?= $field ?>;resize:vertical"><?= $e($cat['description'] ?? '') ?></textarea>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <div>
          <label for="f-icon">Icon <span class="muted">(one emoji)</span></label>
          <input type="text" id="f-icon" name="icon" maxlength="8" value="<?= $e($cat['icon'] ?? '') ?>" placeholder="🧭">
        </div>
        <div>
          <label for="f-order">Display order</label>
          <input type="number" id="f-order" name="display_order" min="0" max="100000" step="1"
                 value="<?= $e($cat['display_order'] ?? '') ?>" style="<?= $field ?>">
        </div>
      </div>

      <label style="display:flex;align-items:center;gap:.5rem;margin-top:1.1rem;cursor:pointer">
        <input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>>
        Active — shown in the topic list and eligible for the feed
      </label>

      <div style="display:flex;gap:.6rem;align-items:center;margin-top:1.4rem">
        <button class="btn" type="submit" style="background:var(--accent);color:var(--accent-ink);border-color:var(--accent);padding:.55rem 1.1rem">
          <?= $isNew ? 'Create category' : 'Save changes' ?>
        </button>
        <a href="<?= $isNew ? '/categories' : '/categories/view?id=' . (int) $cat['id'] ?>" class="muted" style="font-size:.85rem">Cancel</a>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-h"><h2>Notes</h2></div>
    <ul class="muted" style="font-size:.84rem;padding-left:1.1rem;margin:0;line-height:1.7">
      <li>The icon is the glyph itself — the app renders whatever is stored here and keeps no list of its own, so a new category never needs an app release.</li>
      <li>The slug must be unique. It is checked on save and reported here if it clashes.</li>
      <li>Display order is the position on the reader's topic picker; lower comes first. Use the arrows on the list to nudge it.</li>
      <li>An active category with no mapped sources gives readers an empty feed. Map blogs to it on Sources before switching it on.</li>
      <?php if (!$isNew): ?>
      <li>Need to remove it entirely? <a href="/categories/delete-confirm?id=<?= (int) $cat['id'] ?>">Delete this category</a> — only possible once nothing references it.</li>
      <?php endif; ?>
    </ul>
  </div>
</div>
