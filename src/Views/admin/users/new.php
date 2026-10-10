<?php
/** @var ?string $error @var array $values @var int $minLen @var string $csrf */
$e   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$sel = 'width:100%;padding:.62rem .8rem;font:inherit;font-size:.92rem;border:1px solid var(--line);'
     . 'border-radius:10px;background:var(--bg);color:var(--ink)';
?>
<div class="head">
  <h1>New user</h1>
  <span class="sub"><a href="/users" class="muted" style="text-decoration:none">&larr; All users</a></span>
</div>

<div class="row2">
  <div class="card">
    <?php if ($error !== null): ?>
      <div class="flash bad"><strong>!</strong><span><?= $e($error) ?></span></div>
    <?php endif; ?>

    <form method="post" action="/users/create" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

      <label for="email" style="margin-top:0">Email</label>
      <input id="email" type="text" name="email" inputmode="email" autocapitalize="none" spellcheck="false"
             required maxlength="255" value="<?= $e($values['email'] ?? '') ?>">

      <label for="display_name">Display name <span class="muted" style="font-weight:400">(optional)</span></label>
      <input id="display_name" type="text" name="display_name" maxlength="80"
             value="<?= $e($values['display_name'] ?? '') ?>">

      <label for="password">Password</label>
      <input id="password" type="password" name="password" autocomplete="new-password"
             required minlength="<?= (int) $minLen ?>">
      <p class="muted" style="font-size:.78rem;margin:.3rem 0 0">At least <?= (int) $minLen ?> characters. Stored as bcrypt; never shown again.</p>

      <label for="role">Role</label>
      <select id="role" name="role" style="<?= $sel ?>">
        <option value="reader"<?= ($values['role'] ?? 'reader') === 'reader' ? ' selected' : '' ?>>Reader — the mobile app only</option>
        <option value="admin"<?= ($values['role'] ?? '') === 'admin' ? ' selected' : '' ?>>Administrator — this dashboard too</option>
      </select>

      <div style="margin-top:1.4rem;display:flex;gap:.6rem;align-items:center">
        <button class="btn" type="submit" style="background:var(--accent);color:var(--accent-ink);border-color:var(--accent)">Create account</button>
        <a href="/users" class="muted" style="font-size:.84rem">Cancel</a>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-h"><h2>What happens</h2></div>
    <table>
      <tr><th style="width:38%">Email</th><td>Must be unique, case-insensitively. It is marked verified because you are vouching for it.</td></tr>
      <tr><th>Password</th><td>Hashed with bcrypt before it is stored. The audit log records the creation, never the password.</td></tr>
      <tr><th>Reader</th><td>Can sign in to the app, pick topics and read. Cannot open this dashboard.</td></tr>
      <tr><th>Administrator</th><td>Everything a reader can, plus full access here. Can be demoted later from their page.</td></tr>
    </table>
  </div>
</div>
