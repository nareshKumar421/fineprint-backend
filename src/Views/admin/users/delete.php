<?php
/** @var array $u @var array $c @var bool $lastAdmin @var string $csrf */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

$money = static function (string $amt): string {
    [$int, $dec] = array_pad(explode('.', $amt, 2), 2, '00');
    return number_format((int) ltrim($int, '-')) . '.' . str_pad(substr($dec, 0, 2), 2, '0');
};

$id   = (int) $u['id'];
$name = trim((string) ($u['display_name'] ?? ''));
$gone = [
    'Topics followed'          => $c['topics'],
    'Articles seen'            => $c['seen'],
    'Interaction events'       => $c['events'],
    'Hidden sources'           => $c['hidden'],
    'Mobile tokens'            => $c['tokens'],
    'Dashboard sessions'       => $c['sessions'],
    'Personal ranking stats'   => $c['stats'],
];
?>
<div class="head">
  <h1>Delete <?= $e($name !== '' ? $name : $u['email']) ?>?</h1>
  <span class="sub"><a href="/users/view?id=<?= $id ?>" class="muted" style="text-decoration:none">&larr; Back to user</a></span>
</div>

<?php if ($lastAdmin): ?>
  <div class="flash bad"><strong>!</strong>
    <span>This is the last active administrator. Deleting it would lock everyone out of the dashboard.
          Promote another account first.</span></div>
<?php else: ?>
  <div class="flash bad"><strong>!</strong>
    <span>This cannot be undone. The account <strong><?= $e($u['email']) ?></strong> (#<?= $id ?>,
          <?= $e($u['role']) ?>) and everything listed on the left are removed in one transaction.</span></div>
<?php endif; ?>

<div class="row2">
  <div class="card">
    <div class="card-h"><h2>Deleted with the account</h2></div>
    <table>
      <?php foreach ($gone as $label => $n): ?>
      <tr>
        <td><?= $e($label) ?></td>
        <td class="right"><?= (int) $n === 0 ? '<span class="muted">0</span>' : '<strong>' . number_format((int) $n) . '</strong>' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <p class="muted" style="font-size:.8rem;margin:.9rem 0 0">
      These tables reference the user with <code>ON DELETE CASCADE</code>; the database removes them
      with the row. The feed's aggregate statistics are unaffected — they were rolled up nightly and
      no longer point at this user.
    </p>
  </div>

  <div class="card">
    <div class="card-h"><h2>Retained</h2></div>
    <table>
      <tr>
        <td>Donations <span class="muted">(&#8377;<?= $e($money((string) $c['donated'])) ?> successful)</span></td>
        <td class="right"><strong><?= number_format((int) $c['donations']) ?></strong></td>
      </tr>
      <tr><td>Audit entries they made</td><td class="right"><?= number_format((int) $c['audit_rows']) ?></td></tr>
      <tr><td>Jobs they requested</td><td class="right"><?= number_format((int) $c['jobs']) ?></td></tr>
    </table>
    <p class="muted" style="font-size:.8rem;margin:.9rem 0 0">
      Donations are an accounting record and are <strong>kept</strong> with <code>user_id</code> set to
      NULL (<code>ON DELETE SET NULL</code>). Audit entries keep the actor's email in text. Nothing here
      can be traced back to the account once it is gone.
    </p>

    <?php if (!$lastAdmin): ?>
    <form method="post" action="/users/delete" style="margin-top:1.4rem;padding-top:1rem;border-top:1px solid var(--line)">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <label style="display:flex;align-items:center;gap:.5rem;margin:0 0 1rem;cursor:pointer">
        <input type="checkbox" name="confirm" value="1" required>
        I understand this permanently deletes <?= $e($u['email']) ?>.
      </label>
      <div style="display:flex;gap:.6rem;align-items:center">
        <button class="btn warn" type="submit">Delete this account</button>
        <a href="/users/view?id=<?= $id ?>" class="muted" style="font-size:.84rem">Cancel</a>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
