<?php
/**
 * decode_entities.php — one-off repair for titles that reached the database
 * as HTML entities.
 *
 * RSS titles often arrive inside CDATA, which XML delivers verbatim, so a
 * WordPress "he&#8217;ll" was stored — and shown in the app and on the
 * dashboard — as seven characters of entity. feedlib's clean_title() now
 * decodes at ingest; this script fixes the rows that were stored before it
 * did. Excerpts get the same pass for the rarer double-encoded feed.
 *
 *     php jobs/decode_entities.php            apply
 *     php jobs/decode_entities.php --dry-run  report only, change nothing
 *
 * Idempotent: a row is rewritten only when decoding changes it, so running
 * it twice does nothing the second time. Reads .env directly, like every job.
 */

declare(strict_types=1);

require __DIR__ . '/feedlib.php';

feed_load_env();

$dryRun = in_array('--dry-run', $argv, true);

function logline(string $level, string $msg): void
{
    printf("[%s] %-5s %s\n", date('Y-m-d H:i:s'), $level, $msg);
}

$DB_DSN  = feed_env('FEED_DB_DSN');
$DB_USER = feed_env('FEED_DB_USER');
$DB_PASS = feed_env('FEED_DB_PASS');

if ($DB_DSN === '' || $DB_USER === '') {
    fwrite(STDERR, "FATAL: FEED_DB_DSN / FEED_DB_USER not set. Is backend/.env present?\n");
    exit(1);
}

try {
    $db = new PDO($DB_DSN, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    logline('FATAL', 'cannot connect to database: ' . $e->getMessage());
    exit(1);
}

// Named, numeric and hex entities. Anything else is a literal ampersand.
const ENTITY_RE = '&(#[0-9]+|#[xX][0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);';

$select = $db->prepare('SELECT id, title, excerpt FROM articles WHERE title ~ ? OR excerpt ~ ? ORDER BY id');
$select->execute([ENTITY_RE, ENTITY_RE]);
$rows = $select->fetchAll();

$update = $db->prepare('UPDATE articles SET title = ?, excerpt = ? WHERE id = ?');

$seen = count($rows);
$changed = 0;
$samples = [];
$db->beginTransaction();
foreach ($rows as $r) {
    $title   = clean_title((string) $r['title']);
    $excerpt = $r['excerpt'] === null ? null
             : trim(html_entity_decode((string) $r['excerpt'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    // A title must never decode to nothing — the row would be unusable.
    if ($title === '') {
        $title = (string) $r['title'];
    }
    if ($title === (string) $r['title'] && $excerpt === $r['excerpt']) {
        continue;
    }
    $changed++;
    if (count($samples) < 5 && $title !== (string) $r['title']) {
        $samples[] = [(string) $r['title'], $title];
    }
    if (!$dryRun) {
        $update->execute([$title, $excerpt, (int) $r['id']]);
    }
}
$dryRun ? $db->rollBack() : $db->commit();

logline('INFO', sprintf('%d row(s) carried entities, %d %s', $seen, $changed, $dryRun ? 'would change' : 'rewritten'));
foreach ($samples as [$before, $after]) {
    logline('INFO', "  before: {$before}");
    logline('INFO', "  after:  {$after}");
}
exit(0);
