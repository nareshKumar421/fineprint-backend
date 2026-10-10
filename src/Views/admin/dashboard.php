<?php
/** @var array $m metrics @var array $warnings @var array $recentSync @var array $recentAudit */
$pill = static fn(string $tone, string $text): string =>
    '<span class="pill ' . $tone . '">' . htmlspecialchars($text) . '</span>';
?>
<h1>Overview</h1>

<?php foreach ($warnings as $w): ?>
  <div class="flash bad"><?= htmlspecialchars($w) ?></div>
<?php endforeach; ?>

<div class="grid">
  <div class="card">
    <div class="n"><?= number_format($m['articles']) ?></div>
    <div class="l">Articles</div>
    <div class="s muted"><?= number_format($m['in_window']) ?> in the 30-day window</div>
  </div>
  <div class="card">
    <div class="n"><?= $m['sources_active'] ?></div>
    <div class="l">Active sources</div>
    <div class="s">
      <?= $m['sources_dead'] > 0 ? $pill('bad', $m['sources_dead'] . ' deactivated') : $pill('ok', 'none deactivated') ?>
    </div>
  </div>
  <div class="card">
    <div class="n"><?= $m['sources_failing'] ?></div>
    <div class="l">Failing feeds</div>
    <div class="s"><?= $m['sources_failing'] > 0 ? $pill('warn', 'needs attention') : $pill('ok', 'all healthy') ?></div>
  </div>
  <div class="card">
    <div class="n"><?= $m['users'] ?></div>
    <div class="l">Readers</div>
    <div class="s muted"><?= $m['warm'] ?> personalised</div>
  </div>
  <div class="card">
    <div class="n"><?= number_format($m['events']) ?></div>
    <div class="l">Interactions</div>
    <div class="s muted"><?= number_format($m['events_24h']) ?> in 24h</div>
  </div>
  <div class="card">
    <div class="n" style="font-size:1.05rem"><?= htmlspecialchars($m['last_sync_human']) ?></div>
    <div class="l">Last sync</div>
    <div class="s"><?= $m['sync_stale'] ? $pill('warn', 'stale') : $pill('ok', 'current') ?></div>
  </div>
</div>

<h1 style="font-size:1.1rem">Recent syncs</h1>
<table style="margin-bottom:1.5rem">
  <tr><th>When</th><th class="right">OK</th><th class="right">Failed</th>
      <th class="right">Added</th><th class="right">Duration</th></tr>
  <?php foreach ($recentSync as $s): ?>
  <tr>
    <td><?= htmlspecialchars(substr((string) $s['run_at'], 0, 16)) ?></td>
    <td class="right"><?= (int) $s['feeds_ok'] ?></td>
    <td class="right"><?= ((int) $s['feeds_failed']) > 0
        ? $pill('bad', (string) $s['feeds_failed']) : '<span class="muted">0</span>' ?></td>
    <td class="right"><?= number_format((int) $s['articles_added']) ?></td>
    <td class="right muted"><?= (int) $s['duration_seconds'] ?>s</td>
  </tr>
  <?php endforeach; ?>
  <?php if ($recentSync === []): ?><tr><td colspan="5" class="muted">No syncs recorded yet.</td></tr><?php endif; ?>
</table>

<h1 style="font-size:1.1rem">Recent administrative activity</h1>
<table>
  <tr><th>When</th><th>Who</th><th>Action</th><th>Entity</th></tr>
  <?php foreach ($recentAudit as $a): ?>
  <tr>
    <td class="muted"><?= htmlspecialchars(substr((string) $a['created_at'], 0, 16)) ?></td>
    <td><?= htmlspecialchars((string) ($a['actor_email'] ?? 'system')) ?></td>
    <td><code><?= htmlspecialchars((string) $a['action']) ?></code></td>
    <td class="muted"><?= htmlspecialchars((string) ($a['entity'] ?? '—')) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if ($recentAudit === []): ?><tr><td colspan="4" class="muted">Nothing recorded yet.</td></tr><?php endif; ?>
</table>
