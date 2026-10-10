<?php
require_once __DIR__ . '/../_charts.php';
/** @var array $u @var array $topics @var array $hidden @var array $byType @var array $daily
 *  @var array $trail @var bool $isSelf @var string $csrf */
$e  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$dt = fn($v) => $v === null || $v === '' ? '—' : substr((string) $v, 0, 16);

// NUMERIC arrives as text ("1500.00"). Group the integer part and keep the
// decimals as Postgres wrote them — the value never becomes a float.
$money = static function (string $amt): string {
    [$int, $dec] = array_pad(explode('.', $amt, 2), 2, '00');
    $neg = str_starts_with($int, '-');
    $int = ltrim($int, '-');
    return ($neg ? '-' : '') . number_format((int) $int) . '.' . str_pad(substr($dec, 0, 2), 2, '0');
};

$active   = \App\Admin\Page::truthy($u['is_active']);
$isAdmin  = $u['role'] === 'admin';
$verified = \App\Admin\Page::truthy($u['email_verified']);
$name     = trim((string) ($u['display_name'] ?? ''));
$id       = (int) $u['id'];

$impressions = $u['impressions'] === null ? null : (int) $u['impressions'];
$goodTaps    = $u['good_taps']   === null ? null : (int) $u['good_taps'];
$rate        = $impressions !== null && $impressions > 0 ? round($goodTaps / $impressions * 100, 1) : null;

$colors = fp_palette();
$typeColour = ['impression' => '#94a3b8', 'tap' => '#2563eb', 'share' => '#34d399',
               'hide_source' => '#fbbf24', 'not_interested' => '#f472b6'];
?>
<div class="head">
  <h1><?= $e($name !== '' ? $name : $u['email']) ?></h1>
  <span class="pill <?= $isAdmin ? 'info' : 'ok' ?>"><?= $e($u['role']) ?></span>
  <span class="pill <?= $active ? 'ok' : 'bad' ?>"><?= $active ? 'active' : 'disabled' ?></span>
  <span class="sub"><a href="/users" class="muted" style="text-decoration:none">&larr; All users</a> &middot; #<?= $id ?></span>
</div>

<?php if ($isSelf): ?>
  <div class="flash warn"><span>This is your own account. You cannot disable, demote or delete it from here.</span></div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi fill">
    <div class="k-l">Interactions</div>
    <div class="k-n"><?= number_format((int) $u['events']) ?></div>
    <div class="k-s">last <?= $e($dt($u['last_event_at'])) ?></div>
  </div>
  <div class="kpi blue">
    <div class="k-l">Good taps</div>
    <div class="k-n"><?= $goodTaps === null ? '—' : number_format($goodTaps) ?></div>
    <div class="k-s"><?= $impressions === null
        ? 'no rollup yet'
        : number_format($impressions) . ' impressions' . ($rate !== null ? " · {$rate}%" : '') ?></div>
  </div>
  <div class="kpi green">
    <div class="k-l">Articles seen</div>
    <div class="k-n"><?= number_format((int) $u['seen']) ?></div>
    <div class="k-s"><?= number_format((int) $u['topics']) ?> topic(s) followed</div>
  </div>
  <div class="kpi pink">
    <div class="k-l">Donated</div>
    <div class="k-n">&#8377;<?= $e($money((string) $u['donated'])) ?></div>
    <div class="k-s"><?= number_format((int) $u['donations_ok']) ?> of <?= number_format((int) $u['donations']) ?> succeeded</div>
  </div>
  <div class="kpi violet">
    <div class="k-l">Live sessions</div>
    <div class="k-n"><?= number_format((int) $u['live_tokens'] + (int) $u['live_sessions']) ?></div>
    <div class="k-s"><?= (int) $u['live_tokens'] ?> mobile · <?= (int) $u['live_sessions'] ?> dashboard</div>
  </div>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h"><h2>Profile</h2></div>
    <table>
      <tr><th style="width:40%">Email</th><td><?= $e($u['email']) ?>
          <?= $verified ? '<span class="pill ok">verified</span>' : '<span class="pill warn">unverified</span>' ?></td></tr>
      <tr><th>Display name</th><td><?= $name !== '' ? $e($name) : '<span class="muted">not set</span>' ?></td></tr>
      <tr><th>Role</th><td><span class="pill <?= $isAdmin ? 'info' : 'ok' ?>"><?= $e($u['role']) ?></span></td></tr>
      <tr><th>Status</th><td><span class="pill <?= $active ? 'ok' : 'bad' ?>"><?= $active ? 'active' : 'disabled' ?></span></td></tr>
      <tr><th>Created</th><td class="muted"><?= $e($dt($u['created_at'])) ?> UTC</td></tr>
      <tr><th>Last login</th><td class="muted"><?= $e($dt($u['last_login_at'])) ?><?= $u['last_login_at'] ? ' UTC' : '' ?></td></tr>
      <tr><th>Hidden sources</th><td><?= number_format((int) $u['hidden']) ?></td></tr>
      <tr><th>Mobile sessions</th><td><?= number_format((int) $u['live_tokens']) ?> <span class="muted">unexpired token(s)</span></td></tr>
      <tr><th>Dashboard sessions</th><td><?= number_format((int) $u['live_sessions']) ?> <span class="muted">unexpired</span></td></tr>
    </table>
  </div>

  <div class="card">
    <div class="card-h"><h2>Actions</h2></div>
    <div style="display:flex;flex-direction:column;gap:.6rem">

      <div style="padding-bottom:.8rem;border-bottom:1px solid var(--line)">
        <a class="btn primary" href="/users/edit?id=<?= $id ?>">Edit account</a>
        <span class="muted" style="font-size:.78rem;margin-left:.5rem">Email, name, password, role, status, topics, hidden sources — in one form.</span>
      </div>

      <form method="post" action="/users/toggle-active"
            onsubmit="return confirm(<?= $e(json_encode($active
                ? "Disable {$u['email']}? Every mobile token and dashboard session is revoked at once."
                : "Enable {$u['email']}? They will be able to sign in again.")) ?>)">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn <?= $active ? 'warn' : '' ?>" type="submit"<?= $isSelf ? ' disabled' : '' ?>>
          <?= $active ? 'Disable account' : 'Enable account' ?>
        </button>
        <span class="muted" style="font-size:.78rem;margin-left:.5rem">
          <?= $active ? 'Signs them out everywhere, immediately.' : 'Restores sign-in; nothing else changes.' ?>
        </span>
      </form>

      <form method="post" action="/users/role"
            onsubmit="return confirm(<?= $e(json_encode($isAdmin
                ? "Demote {$u['email']} to reader? Their dashboard sessions end now."
                : "Promote {$u['email']} to administrator? They will be able to sign in to this dashboard.")) ?>)">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="role" value="<?= $isAdmin ? 'reader' : 'admin' ?>">
        <button class="btn <?= $isAdmin ? 'warn' : '' ?>" type="submit"<?= $isSelf ? ' disabled' : '' ?>>
          <?= $isAdmin ? 'Demote to reader' : 'Promote to admin' ?>
        </button>
        <span class="muted" style="font-size:.78rem;margin-left:.5rem">
          <?= $isAdmin ? 'The last active administrator cannot be demoted.' : 'Full access to this dashboard.' ?>
        </span>
      </form>

      <form method="post" action="/users/revoke-sessions"
            onsubmit="return confirm(<?= $e(json_encode("Revoke every session for {$u['email']}? They will need to sign in again"
                . ($isSelf ? ' (your current session is kept).' : '.'))) ?>)">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn" type="submit">Revoke all sessions</button>
        <span class="muted" style="font-size:.78rem;margin-left:.5rem">Mobile tokens and dashboard sessions.</span>
      </form>

      <form method="get" action="/users/delete-confirm" style="margin-top:.4rem;padding-top:.8rem;border-top:1px solid var(--line)">
        <input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn warn" type="submit"<?= $isSelf ? ' disabled' : '' ?>>Delete account&hellip;</button>
        <span class="muted" style="font-size:.78rem;margin-left:.5rem">Shows what goes first. Donations are kept.</span>
      </form>
    </div>
  </div>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h">
      <h2>Activity</h2>
      <div class="seg"><span class="on">14 days</span></div>
    </div>
    <?= fp_bars($daily, 170) ?>
  </div>

  <div class="card">
    <div class="card-h"><h2>Interactions by type</h2></div>
    <?php if ($byType === []): ?>
      <p class="muted">No interactions logged yet.</p>
    <?php else:
      $max = max($byType); $i = 0;
      foreach ($byType as $type => $n) {
          echo fp_hbar(str_replace('_', ' ', (string) $type), $n, $max,
                       $typeColour[$type] ?? $colors[$i % count($colors)]);
          $i++;
      }
    endif; ?>
  </div>
</div>

<div class="row3">
  <div class="card">
    <div class="card-h"><h2>Topics</h2><span class="act"><?= count($topics) ?></span></div>
    <?php if ($topics === []): ?>
      <p class="muted">No topics chosen — the feed falls back to every category.</p>
    <?php else: ?>
      <div style="display:flex;flex-wrap:wrap;gap:.35rem">
        <?php foreach ($topics as $t): ?><span class="pill info"><?= $e($t) ?></span><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-h"><h2>Hidden sources</h2><span class="act"><?= count($hidden) ?></span></div>
    <?php if ($hidden === []): ?>
      <p class="muted">Nothing muted.</p>
    <?php else: ?>
      <div style="display:flex;flex-wrap:wrap;gap:.35rem">
        <?php foreach ($hidden as $h): ?><span class="pill warn"><?= $e($h) ?></span><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-h"><h2>Audit trail</h2></div>
    <?php if ($trail === []): ?>
      <p class="muted">No administrative changes recorded.</p>
    <?php else: ?>
    <table>
      <tr><th>When</th><th>Action</th><th>By</th></tr>
      <?php foreach ($trail as $t): ?>
      <tr>
        <td class="muted"><?= $e($dt($t['created_at'])) ?></td>
        <td><code><?= $e($t['action']) ?></code></td>
        <td class="muted" style="max-width:160px;overflow:hidden;text-overflow:ellipsis"><?= $e($t['actor_email'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
