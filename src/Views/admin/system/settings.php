<?php
/** @var array $rows @var string $csrf */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<div class="head">
  <h1>Settings</h1>
  <span class="sub"><?= count($rows) ?> value<?= count($rows) === 1 ? '' : 's' ?></span>
</div>

<div class="row2">
  <div class="card">
    <div class="card-h"><h2>What these change</h2></div>
    <p class="muted" style="margin:0 0 .6rem;font-size:.86rem">
      A change here takes effect on the next request — no deploy, no restart.
      Which code reads each value today:
    </p>
    <table>
      <tr><th>Key</th><th>Read live by</th></tr>
      <tr>
        <td><code>admin.session_hours</code></td>
        <td><code>AdminAuth</code> — lifetime of every new dashboard session.</td>
      </tr>
      <tr>
        <td><code>feed.max_failures</code></td>
        <td>The Sources page threshold. The nightly <code>fetch_feeds.php</code> job
            still reads <code>FEED_MAX_FAILURES</code> from the environment, so
            keep the two in step until the job is wired to this table.</td>
      </tr>
      <tr>
        <td><code>sync.enabled</code></td>
        <td>Shown on the System page as the master switch; the scheduled job
            honours it once the job runner lands.</td>
      </tr>
      <tr>
        <td class="muted">others</td>
        <td class="muted">Registered so the dashboard can show what the system
            runs on. Wiring each up is a separate, deliberate step.</td>
      </tr>
    </table>
  </div>

  <div class="card">
    <div class="card-h"><h2>Not here, on purpose</h2></div>
    <p style="margin:0 0 .6rem;font-size:.86rem">
      The ranking constants are <strong>not settings</strong>. The score is one
      formula — five weighted terms (recency 0.40, category 0.25, source 0.15,
      quality 0.15, explore 0.05) and a multiplicative penalty, floored at
      0.01 so nothing divides by zero in the shuffle.
    </p>
    <p class="muted" style="margin:0;font-size:.84rem">
      Those weights live in <code>src/Services/Scoring.php</code> and are
      duplicated in <code>jobs/rollup_stats.php</code>, and the two must agree.
      An editable copy in this table would let them drift silently, so they
      stay in code where a change is reviewed. See
      <code>docs/feed-algorithm-simple.md</code> §4.
    </p>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>Values</h2></div>
  <?php if ($rows === []): ?>
    <p class="muted">No settings registered. Apply <code>db/010_admin.sql</code> to seed them.</p>
  <?php else: ?>
  <table>
    <tr>
      <th>Key</th><th>Value</th><th>Type</th><th>Updated</th><th></th>
    </tr>
    <?php foreach ($rows as $r):
      $type = (string) $r['value_type'];
      $val  = (string) $r['value'];
      $fid  = 'v-' . preg_replace('/[^a-z0-9]+/i', '-', (string) $r['key']);
    ?>
    <tr id="<?= $e($r['key']) ?>">
      <td style="vertical-align:top">
        <!-- The form element sits inside one cell; the input and button
             reference it by id, because a form wrapping table cells is
             invalid HTML and browsers hoist it out of the row. -->
        <form id="<?= $e($fid) ?>-f" method="post" action="/settings/save">
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="key" value="<?= $e($r['key']) ?>">
        </form>
        <code><?= $e($r['key']) ?></code><br>
        <span class="muted" style="font-size:.8rem"><?= $e($r['description'] ?? '') ?></span>
      </td>
      <td style="vertical-align:top;min-width:180px">
        <?php if ($type === 'bool'): ?>
          <select id="<?= $e($fid) ?>" form="<?= $e($fid) ?>-f" name="value">
            <option value="true"  <?= strtolower($val) === 'true'  ? 'selected' : '' ?>>true</option>
            <option value="false" <?= strtolower($val) === 'false' ? 'selected' : '' ?>>false</option>
          </select>
        <?php elseif ($type === 'int'): ?>
          <input id="<?= $e($fid) ?>" form="<?= $e($fid) ?>-f" type="number" name="value" min="0" step="1" required
                 value="<?= $e($val) ?>" style="max-width:160px">
        <?php else: ?>
          <textarea id="<?= $e($fid) ?>" form="<?= $e($fid) ?>-f" name="value" rows="<?= $type === 'json' ? 4 : 2 ?>"
                    style="min-height:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.82rem"><?= $e($val) ?></textarea>
        <?php endif; ?>
      </td>
      <td style="vertical-align:top"><span class="pill info"><?= $e($type) ?></span></td>
      <td style="vertical-align:top" class="muted">
        <?= $e(substr((string) $r['updated_at'], 0, 16)) ?><br>
        <span style="font-size:.8rem"><?= $r['updated_by_email'] !== null ? $e($r['updated_by_email']) : 'seed' ?></span>
      </td>
      <td style="vertical-align:top" class="right">
        <button class="btn primary sm" form="<?= $e($fid) ?>-f" type="submit">Save</button>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
