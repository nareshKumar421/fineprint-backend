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

/**
 * Is this exception the database connection having gone away?
 *
 * Managed Postgres (Neon and friends) drops long-lived connections, and this
 * job holds one for its whole run — 77 feeds at a few seconds each is several
 * minutes of wall clock, most of it spent waiting on HTTP rather than talking
 * to the database. An idle connection in that window is exactly what gets
 * reaped.
 *
 * Matching on the message is unpleasant, but pdo_pgsql reports every one of
 * these as SQLSTATE HY000, so the code cannot distinguish them.
 */
function db_connection_lost(Throwable $e): bool
{
    return (bool) preg_match(
        '/server closed the connection|no connection to the server|'
        . 'connection not open|SSL connection has been closed|broken pipe/i',
        $e->getMessage()
    );
}

function db_open(string $dsn, string $user, string $pass): PDO
{
    /*
     * Keepalives and timeouts, or a dropped connection HANGS the job.
     *
     * Detecting the drop is not enough. Most of this job's wall clock is
     * spent on HTTP, so the database socket sits idle for long stretches and
     * anything doing NAT in the path silently discards it. The next query
     * then blocks on a socket nobody will ever answer, and with no timeout
     * set that is the OS TCP retry limit — many minutes. Observed: the run
     * sat completely silent for 16 minutes before it was killed.
     *
     *   keepalives    make the kernel notice a dead peer in ~1 minute rather
     *                 than waiting for a write to fail
     *   connect_timeout  bounds the reconnect attempt itself
     *   statement_timeout  bounds any single query server-side
     *
     * These are libpq keywords and are appended only if the DSN does not
     * already set them, so an operator can still override any of them.
     */
    $extra = [
        'connect_timeout'     => '10',
        'keepalives'          => '1',
        'keepalives_idle'     => '30',
        'keepalives_interval' => '10',
        'keepalives_count'    => '3',
        'options'             => "'-c statement_timeout=30000'",
    ];

    foreach ($extra as $key => $value) {
        if (!str_contains($dsn, $key . '=')) {
            $dsn = rtrim($dsn, ';') . ';' . $key . '=' . $value;
        }
    }

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 15,
    ]);
}

try {
    $db = db_open($DB_DSN, $DB_USER, $DB_PASS);
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

/*
 * Prepared statements belong to a connection and die with it, so they are
 * built here and rebuilt after every reconnect rather than once at startup.
 */
$markOk = $markFail = $updateUrl = null;

$prepareStatements = static function (PDO $db) use (&$markOk, &$markFail, &$updateUrl): void {
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
};

$prepareStatements($db);

/**
 * Reopen the connection and rebuild the statements bound to it.
 *
 * Returns false if the database is genuinely unreachable rather than merely
 * having dropped us, so the caller can stop instead of looping.
 */
$reconnect = static function () use (&$db, $DB_DSN, $DB_USER, $DB_PASS, $prepareStatements): bool {
    for ($try = 1; $try <= 3; $try++) {
        try {
            $db = db_open($DB_DSN, $DB_USER, $DB_PASS);
            $prepareStatements($db);
            logline('INFO', "reconnected to database (attempt {$try})");
            return true;
        } catch (Throwable $e) {
            // Managed Postgres that has scaled to zero needs a moment to wake.
            sleep($try * 2);
        }
    }
    return false;
};

$connectionDead = false;

foreach ($rows as $row) {
    $label   = "{$row['blog_name']} (cat {$row['category_id']})";
    $retried = 0;

    // Jump target for the one reconnect-and-retry in the catch below. A goto
    // rather than a nested attempt loop so the body keeps its indentation and
    // the diff stays readable; this is the retry case goto exists for.
    retry_feed:

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
        /*
         * The connection going away is not this blog's fault, and must not be
         * recorded against it. Without this the run dies here: marking the
         * failure needs the very connection that just vanished, so the catch
         * block throws too and the whole job aborts partway through — which is
         * how a 77-feed run was ending after 10 feeds.
         *
         * Reconnect and retry the same feed once. $retried keeps a database
         * that is genuinely down from looping forever.
         */
        if (db_connection_lost($e) && $retried < 3) {
            logline('WARN', sprintf('%-40s database connection lost - reconnecting', $label));
            if ($reconnect()) {
                $retried++;
                goto retry_feed;
            }
            logline('FATAL', 'database unreachable after reconnect attempts - stopping');
            $connectionDead = true;
            break;
        }

        // One bad blog must never stop the other forty.
        $failCount++;
        $fails = ((int) $row['failure_count']) + 1;

        // DEFECT 2: record last_error. The column existed and the admin query
        // in docs/02 §8 selects it, but the original never wrote it — so every
        // failure diagnosis meant digging through log files.
        // Recording the failure must not itself be able to kill the run. If
        // the connection died between the throw and here, reconnect once and
        // write it; if even that fails, the log line below is still emitted
        // and the next blog gets its turn.
        try {
            $markFail->execute([
                ':n'   => $fails,
                ':err' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
                ':n2'  => $fails,
                ':max' => $MAX_FAILURES,
                ':id'  => $row['blog_id'],
            ]);
        } catch (Throwable $inner) {
            if (db_connection_lost($inner) && $reconnect()) {
                try {
                    $markFail->execute([
                        ':n'   => $fails,
                        ':err' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
                        ':n2'  => $fails,
                        ':max' => $MAX_FAILURES,
                        ':id'  => $row['blog_id'],
                    ]);
                } catch (Throwable) {
                    logline('WARN', 'could not record failure for ' . $label);
                }
            }
        }

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
/*
 * Both statements below need a live connection. If the loop gave up because
 * the database went away, one last reconnect is worth trying: the articles
 * already fetched are safely committed, and without this the run leaves no
 * sync_status row — which is what makes the health endpoint report a stale
 * last_sync_at even though fresh content did arrive.
 */
if ($connectionDead && !$reconnect()) {
    logline('FATAL', 'database unreachable - skipping housekeeping and sync_status');
    logline('INFO', sprintf('partial run: %d ok, %d failed, %d new articles, %ds',
            $okCount, $failCount, $totalNew, time() - $startedAt));
    exit(2);
}

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
