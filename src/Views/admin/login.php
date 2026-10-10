<?php /** @var ?string $error @var string $formToken @var ?string $prefill */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<div class="login">
  <div class="login-logo">
    <span class="brand-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
      stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16"/><path d="M4 12h10"/><path d="M4 18h7"/></svg></span>
    Fine<span>Print</span>
  </div>
  <div class="card">
    <h1>Sign in</h1>
    <p class="muted" style="margin:0 0 .5rem;font-size:.86rem">FinePrint administration</p>

    <?php if ($error !== null): ?>
      <div class="flash bad" style="margin-top:1rem"><span><?= $e($error) ?></span></div>
    <?php endif; ?>

    <form method="post" action="/login">
      <!-- Not a hidden session value: this token is bound to the pre-login
           form, so a cross-site POST cannot forge a sign-in attempt. -->
      <input type="hidden" name="csrf" value="<?= $e($formToken) ?>">
      <label for="u">Username or email</label>
      <input id="u" name="username" type="text" autocomplete="username"
             autocapitalize="none" spellcheck="false" required autofocus
             value="<?= $e($prefill ?? '') ?>">
      <label for="p">Password</label>
      <input id="p" name="password" type="password" autocomplete="current-password" required>
      <button type="submit">Sign in</button>
    </form>
  </div>
  <p class="login-foot">Admin accounts only. Sessions expire automatically.</p>
</div>
