<?php
/** @var array $u @var array $values @var array $categories @var array $hidden @var bool $isSelf
 *  @var int $minLen @var ?string $error @var string $csrf */
$e   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$id  = (int) $u['id'];
$sel = 'width:100%;padding:.62rem .8rem;font:inherit;font-size:.92rem;border:1px solid var(--line);'
     . 'border-radius:10px;background:var(--bg);color:var(--ink)';
$chk = 'display:flex;align-items:center;gap:.5rem;margin:0;font-weight:400;color:var(--ink);font-size:.88rem';
$topics = array_map('intval', $values['topics'] ?? []);
$name   = trim((string) ($u['display_name'] ?? '')) ?: (string) $u['email'];
?>
<div class="head">
  <h1>Edit <?= $e($name) ?></h1>
  <span class="sub"><a href="/users/view?id=<?= $id ?>" class="muted" style="text-decoration:none">&larr; Back to account</a> &middot; #<?= $id ?></span>
</div>

<?php if ($isSelf): ?>
  <div class="flash warn"><span>This is your own account. Email, name, password and topics can be changed; role and status are locked so you cannot lock yourself out.</span></div>
<?php endif; ?>

<form method="post" action="/users/update" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="id" value="<?= $id ?>">

  <div class="row2">
    <div class="card">
      <div class="card-h"><h2>Account</h2></div>
      <?php if ($error !== null): ?>
        <div class="flash bad"><strong>!</strong><span><?= $e($error) ?></span></div>
      <?php endif; ?>

      <label for="email" style="margin-top:0">Email</label>
      <input id="email" type="text" name="email" inputmode="email" autocapitalize="none" spellcheck="false"
             required maxlength="255" value="<?= $e($values['email'] ?? '') ?>">

      <label for="display_name">Display name <span class="muted" style="font-weight:400">(optional; an administrator signs in with it)</span></label>
      <input id="display_name" type="text" name="display_name" maxlength="80"
             value="<?= $e($values['display_name'] ?? '') ?>">

      <label for="password">New password <span class="muted" style="font-weight:400">(leave blank to keep the current one)</span></label>
      <input id="password" type="password" name="password" autocomplete="new-password" minlength="<?= (int) $minLen ?>">
      <p class="muted" style="font-size:.78rem;margin:.3rem 0 0">At least <?= (int) $minLen ?> characters. Setting one signs the account out everywhere<?= $isSelf ? ' except this session' : '' ?>.</p>

      <label for="role">Role</label>
      <select id="role" name="role" style="<?= $sel ?>"<?= $isSelf ? ' disabled' : '' ?>>
        <option value="reader"<?= ($values['role'] ?? 'reader') === 'reader' ? ' selected' : '' ?>>Reader — the mobile app only</option>
        <option value="admin"<?= ($values['role'] ?? '') === 'admin' ? ' selected' : '' ?>>Administrator — this dashboard too</option>
      </select>

      <div style="display:flex;flex-direction:column;gap:.55rem;margin-top:1rem">
        <label style="<?= $chk ?>">
          <input type="checkbox" name="is_active" value="1"<?= !empty($values['is_active']) ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
          Active <span class="muted" style="font-size:.78rem">— unticking signs them out everywhere, immediately</span>
        </label>
        <label style="<?= $chk ?>">
          <input type="checkbox" name="email_verified" value="1"<?= !empty($values['email_verified']) ? ' checked' : '' ?>>
          Email verified <span class="muted" style="font-size:.78rem">— you are vouching for the address</span>
        </label>
      </div>
    </div>

    <div class="card">
      <div class="card-h"><h2>Topics</h2><span class="act"><?= count($topics) ?> of <?= count($categories) ?></span></div>
      <p class="muted" style="font-size:.8rem;margin:0 0 .8rem">What the feed is built from. With none chosen the feed falls back to every category.</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.45rem .8rem">
        <?php foreach ($categories as $c): $on = in_array((int) $c['id'], $topics, true); $live = \App\Admin\Page::truthy($c['is_active']); ?>
          <label style="<?= $chk ?><?= $live ? '' : ';opacity:.55' ?>">
            <input type="checkbox" name="topics[]" value="<?= (int) $c['id'] ?>"<?= $on ? ' checked' : '' ?>>
            <?= $e($c['name']) ?><?= $live ? '' : ' <span class="muted" style="font-size:.74rem">(hidden)</span>' ?>
          </label>
        <?php endforeach; ?>
      </div>

      <?php if ($hidden !== []): ?>
        <div class="card-h" style="margin-top:1.4rem"><h2>Hidden sources</h2><span class="act"><?= count($hidden) ?></span></div>
        <p class="muted" style="font-size:.8rem;margin:0 0 .6rem">Blogs this reader muted. Tick one to show it to them again.</p>
        <div style="display:flex;flex-direction:column;gap:.4rem">
          <?php foreach ($hidden as $h): ?>
            <label style="<?= $chk ?>">
              <input type="checkbox" name="unhide[]" value="<?= (int) $h['id'] ?>">
              Unhide <?= $e($h['blog_name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap">
    <button class="btn primary" type="submit">Save changes</button>
    <a href="/users/view?id=<?= $id ?>" class="muted" style="font-size:.84rem">Cancel</a>
    <span class="muted" style="font-size:.78rem;margin-left:auto">Every change is written to the audit log with a before and after.</span>
  </div>
</form>
