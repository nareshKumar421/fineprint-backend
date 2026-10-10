<?php /** @var ?string $error */ ?>
<div class="login">
  <div class="card">
    <h1 style="margin-bottom:.25rem">Sign in</h1>
    <p class="muted" style="margin:0;font-size:.88rem">FinePrint administration</p>

    <?php if ($error !== null): ?>
      <div class="flash bad" style="margin-top:1rem"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login">
      <!-- Not a hidden session value: this token is bound to the pre-login
           form, so a cross-site POST cannot forge a sign-in attempt. -->
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($formToken) ?>">
      <label for="u">Username or email</label>
      <input id="u" name="username" type="text" autocomplete="username"
             autocapitalize="none" spellcheck="false" required autofocus
             value="<?= htmlspecialchars($prefill ?? '') ?>">
      <label for="p">Password</label>
      <input id="p" name="password" type="password" autocomplete="current-password" required>
      <button type="submit">Sign in</button>
    </form>
  </div>
</div>
