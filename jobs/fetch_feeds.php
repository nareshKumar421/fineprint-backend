<?php
/**
 * fetch_feeds.php
 *
 * Downloads every active blog feed, parses it, and stores new articles.
 * Half A of the system — runs from cron with no user present.
 *
 *     0 3 * * *  /usr/bin/php /var/www/blogfeed/backend/jobs/fetch_feeds.php \
 *                >> /var/log/feedjob.log 2>&1
 *
 * Safe to run more often than the spec's 3 days — duplicates are rejected by
 * the unique index on (guid, category_id), so extra runs cost bandwidth only.
 *
 * Exit codes:  0 = all feeds OK   2 = at least one failed   1 = fatal
 *
 * This is the client's draft with the six defects in docs/05 §3 fixed.
 * Each fix is marked "DEFECT n" below.
 */

declare(strict_types=1);

require __DIR__ . '/feedlib.php';

feed_load_env();

/* ------------------------------------------------------------------ */
/*  Config — DEFECT 5: credentials come from the environment, never    */
/*  hardcoded. The original had `const DB_PASS = 'change-me';`.        */
/* ------------------------------------------------------------------ */

$DB_DSN  = feed_env('FEED_DB_DSN');
$DB_USER = feed_env('FEED_DB_USER');
$DB_PASS = feed_env('FEED_DB_PASS');

$MAX_FAILURES = (int) feed_env('FEED_MAX_FAILURES', '5');
$SLEEP_US     = 500_000;      // half a second between blogs — be polite

if ($DB_DSN === '' || $DB_USER === '') {
    fwrite(STDERR, "FATAL: FEED_DB_DSN / FEED_DB_USER not set. Is backend/.env present?\n");
    exit(1);
}

function logline(string $level, string $msg): void
{
    printf("[%s] %-5s %s\n", date('Y-m-d H:i:s'), $level, $msg);
}

/* ------------------------------------------------------------------ */
/*  Save                                                               */
/* ------------------------------------------------------------------ */

/**
 * Relies on the unique index for deduplication:
 *   CREATE UNIQUE INDEX articles_guid_cat_uniq ON articles (guid, category_id);
 *
 * ON CONFLICT DO NOTHING means a repeat guid is silently skipped, with no
 * race condition even if two runs overlap. First insert wins — an edited
 * headline upstream does not overwrite what we stored.
 *
 * DEFECT 4: image_url and author are now stored. The original parser never
 * extracted them, so every card would have rendered without a thumbnail.
 */
function save_articles(PDO $db, array $items, int $blogId, int $categoryId): int
{
    $sql = 'INSERT INTO articles
              (guid, title, excerpt, article_url, image_url, author,
               published_at, blog_source_id, category_id, fetched_at)
            VALUES
              (:guid, :title, :excerpt, :url, :image_url, :author,
               :published_at, :blog_id, :category_id, NOW())
            ON CONFLICT (guid, category_id) DO NOTHING';

    $stmt     = $db->prepare($sql);
    $inserted = 0;

    foreach ($items as $it) {
        $stmt->execute([
            ':guid'         => $it['guid'],
            ':title'        => $it['title'],
            ':excerpt'      => $it['excerpt'],
            ':url'          => $it['url'],
            ':image_url'    => $it['image_url'],
            ':author'       => $it['author'],
            ':published_at' => $it['published_at'],
            ':blog_id'      => $blogId,
            ':category_id'  => $categoryId,
        ]);
        $inserted += $stmt->rowCount();   // 0 when the row already existed
    }

    return $inserted;
}

/* ------------------------------------------------------------------ */
/*  Main                                                               */
/* ------------------------------------------------------------------ */

$startedAt = time();

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

/*
 * One row per blog+category pair, NOT per blog. The override lets a single
 * blog feed two categories from two different section feeds, e.g.
 *   Health -> /category/fitness/feed
 *   Food   -> /category/recipes/feed
 */
$rows = $db->query("
    SELECT  m.category_id,
            b.id   AS blog_id,
            b.blog_name,
            b.failure_count,
            b.feed_url                                  AS stored_url,
            m.feed_url_override,
            COALESCE(m.feed_url_override, b.feed_url)   AS feed_url
    FROM    category_blog_map m
    JOIN    blog_sources b ON b.id = m.blog_source_id
    WHERE   b.is_active = true
    ORDER BY b.id
")->fetchAll();

logline('INFO', count($rows) . ' feed(s) to process');

$totalNew  = 0;
$okCount   = 0;
$failCount = 0;

$markOk = $db->prepare(
    'UPDATE blog_sources
        SET last_fetched_at = NOW(), failure_count = 0, last_error = NULL
      WHERE id = :id'
);

// Note the duplicated :n / :n2 placeholders. With EMULATE_PREPARES off, a
// named parameter cannot be reused within one statement.
$markFail = $db->prepare(
    'UPDATE blog_sources
        SET failure_count   = :n,
            last_error      = :err,
            last_fetched_at = NOW(),
            is_active       = CASE WHEN :n2 >= :max THEN false ELSE is_active END
      WHERE id = :id'
);

$updateUrl = $db->prepare('UPDATE blog_sources SET feed_url = :url WHERE id = :id');

foreach ($rows as $row) {
    $label = "{$row['blog_name']} (cat {$row['category_id']})";

    try {
        $res    = feed_fetch($row['feed_url']);
        // feed_parse() throws when the document is not XML, has no item nodes,
        // or — DEFECT 1, the critical one — parses cleanly but yields ZERO
        // usable articles. The original returned an empty array there, which
        // the caller recorded as SUCCESS: failure_count reset to 0, the blog
        // never deactivated, nobody ever alerted. See docs/05 §2.
        $parsed = feed_parse($res['body']);
        $new    = save_articles($db, $parsed['items'], (int) $row['blog_id'], (int) $row['category_id']);

        $markOk->execute([':id' => $row['blog_id']]);

        /*
         * DEFECT 3: store the post-redirect URL so future runs skip the hop.
         * Only when this mapping had no override — an override is a human's
         * deliberate choice and must not be overwritten.
         */
        if ($row['feed_url_override'] === null && $res['final_url'] !== $row['stored_url']) {
            try {
                $updateUrl->execute([':url' => $res['final_url'], ':id' => $row['blog_id']]);
                logline('INFO', sprintf('%-40s feed_url -> %s', $label, $res['final_url']));
            } catch (PDOException) {
                // feed_url is UNIQUE; another blog may already hold the
                // resolved URL. Not worth failing the run over.
            }
        }

        $totalNew += $new;
        $okCount++;
        logline('OK', sprintf('%-40s %2d found, %2d new', $label, count($parsed['items']), $new));

    } catch (Throwable $e) {
        // One bad blog must never stop the other forty.
        $failCount++;
        $fails = ((int) $row['failure_count']) + 1;

        // DEFECT 2: record last_error. The column existed and the admin query
        // in docs/02 §8 selects it, but the original never wrote it — so every
        // failure diagnosis meant digging through log files.
        $markFail->execute([
            ':n'   => $fails,
            ':err' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
            ':n2'  => $fails,
            ':max' => $MAX_FAILURES,
            ':id'  => $row['blog_id'],
        ]);

        logline('WARN', sprintf('%-40s %s (failure %d/%d)',
                $label, $e->getMessage(), $fails, $MAX_FAILURES));

        if ($fails >= $MAX_FAILURES) {
            logline('ALERT', "{$row['blog_name']} deactivated after {$fails} failures - needs a human");
        }
    }

    usleep($SLEEP_US);
}

/*
 * Housekeeping. DEFECT 6: COALESCE is load-bearing. The job stores NULL when
 * a feed has no date, and `NULL < anything` is NULL, not true — so the
 * original comparison silently never deleted an undated article and they
 * accumulated forever.
 */
$purged = $db->exec(
    "DELETE FROM articles
      WHERE COALESCE(published_at, fetched_at) < NOW() - INTERVAL '60 days'"
);

$db->prepare(
    'INSERT INTO sync_status
       (run_at, feeds_ok, feeds_failed, articles_added, duration_seconds)
     VALUES (NOW(), :ok, :failed, :added, :dur)'
)->execute([
    ':ok'     => $okCount,
    ':failed' => $failCount,
    ':added'  => $totalNew,
    ':dur'    => time() - $startedAt,
]);

logline('INFO', sprintf('done: %d ok, %d failed, %d new articles, %d purged, %ds',
        $okCount, $failCount, $totalNew, $purged, time() - $startedAt));

exit($failCount > 0 ? 2 : 0);
