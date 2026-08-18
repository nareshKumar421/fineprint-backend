<?php
/**
 * pipeline_report.php — what the nightly pipeline actually achieved.
 *
 * Run at the end of .github/workflows/nightly-jobs.yml, and useful by hand:
 *
 *     php jobs/pipeline_report.php
 *
 * Reads only. It exists because a green workflow tick proves the commands
 * exited zero, not that anything useful happened — a run can succeed against
 * a database where every feed is dead and no reader has ever been rolled up.
 *
 * Prints WARN lines for the states that look healthy from outside but are
 * not, and exits non-zero on none of them: this reports, it does not gate.
 */

declare(strict_types=1);

require __DIR__ . '/feedlib.php';

feed_load_env();

$dsn  = feed_env('FEED_DB_DSN');
$user = feed_env('FEED_DB_USER');
$pass = feed_env('FEED_DB_PASS');

if ($dsn === '' || $user === '') {
    fwrite(STDERR, "FATAL: FEED_DB_DSN / FEED_DB_USER not set.\n");
    exit(1);
}

try {
    $db = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'FATAL: cannot connect: ' . $e->getMessage() . "\n");
    exit(1);
}

function row(PDO $db, string $sql): array
{
    return $db->query($sql)->fetch() ?: [];
}

$sync = row($db, 'SELECT run_at, feeds_ok, feeds_failed, articles_added, duration_seconds
                    FROM sync_status ORDER BY run_at DESC LIMIT 1');

$content = row($db, "SELECT
      (SELECT count(*) FROM articles)                                        AS articles,
      (SELECT count(*) FROM articles WHERE published_at > NOW() - INTERVAL '30 days') AS in_window,
      (SELECT count(*) FROM blog_sources WHERE is_active)                    AS live_blogs,
      (SELECT count(*) FROM blog_sources WHERE NOT is_active)                AS dead_blogs,
      (SELECT count(*) FROM category_master WHERE is_active)                 AS categories");

$learn = row($db, "SELECT
      (SELECT count(*) FROM article_events)                                  AS events,
      (SELECT count(*) FROM article_events WHERE created_at > NOW() - INTERVAL '24 hours') AS events_24h,
      (SELECT count(*) FROM user_feed_stats WHERE impressions >= 50)         AS warm_readers,
      (SELECT count(*) FROM users WHERE is_active)                           AS readers,
      (SELECT global_rate FROM feed_globals WHERE id = 1)                    AS global_rate,
      (SELECT max(updated_at) FROM source_stats)                             AS rollup_at");

echo "\n--- content ------------------------------------------------\n";
printf("last fetch    : %s\n", $sync['run_at'] ?? 'never');
printf("feeds         : %d ok, %d failed\n", $sync['feeds_ok'] ?? 0, $sync['feeds_failed'] ?? 0);
printf("added         : %d article(s) in %ds\n",
    $sync['articles_added'] ?? 0, $sync['duration_seconds'] ?? 0);
printf("articles      : %d total, %d inside the 30-day feed window\n",
    $content['articles'], $content['in_window']);
printf("blogs         : %d live, %d deactivated\n",
    $content['live_blogs'], $content['dead_blogs']);
printf("categories    : %d active\n", $content['categories']);

echo "\n--- learning -----------------------------------------------\n";
printf("events        : %d total, %d in the last 24h\n", $learn['events'], $learn['events_24h']);
printf("readers       : %d active, %d warm (personalised, not cold-start)\n",
    $learn['readers'], $learn['warm_readers']);
printf("global rate   : %.4f\n", (float) $learn['global_rate']);
printf("rollup built  : %s\n", $learn['rollup_at'] ?? 'never');

echo "\n";

/*
 * The states that look fine from outside and are not.
 */
$warnings = 0;

if (($sync['feeds_failed'] ?? 0) > 0) {
    printf("WARN  %d feed(s) failed. Diagnose with:\n"
         . "      SELECT blog_name, failure_count, last_error FROM blog_sources WHERE failure_count > 0;\n",
        $sync['feeds_failed']);
    $warnings++;
}

if (($content['dead_blogs'] ?? 0) > 0) {
    printf("WARN  %d blog(s) deactivated after repeated failures and will NEVER be\n"
         . "      retried automatically. Restoring one is a manual UPDATE.\n", $content['dead_blogs']);
    $warnings++;
}

if ($learn['rollup_at'] === null) {
    echo "WARN  the rollup has never run. Every reader is served the cold-start\n"
       . "      formula and no personalisation is possible.\n";
    $warnings++;
}

if ((int) $learn['events'] === 0) {
    echo "WARN  no interaction events have EVER been recorded. Either no build with\n"
       . "      analytics has shipped, or POST /api/events is failing silently —\n"
       . "      the app swallows those errors by design, so nothing surfaces.\n";
    $warnings++;
} elseif ((int) $learn['events_24h'] === 0) {
    echo "WARN  no events in the last 24 hours, though some exist historically.\n";
    $warnings++;
}

if ((int) $learn['warm_readers'] === 0 && (int) $learn['events'] > 0) {
    echo "NOTE  no reader has 50 impressions yet, so everyone is still on the\n"
       . "      cold-start formula. Expected early on; it resolves with use.\n";
}

if ($content['in_window'] < 200) {
    printf("WARN  only %d article(s) inside the 30-day window. The feed will run\n"
         . "      thin and drop into the archive quickly.\n", $content['in_window']);
    $warnings++;
}

echo $warnings === 0 ? "no warnings\n\n" : "\n";

exit(0);
