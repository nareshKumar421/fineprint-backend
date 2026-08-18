<?php
/**
 * test_feed_integration.php — the whole scoring loop, against a real database.
 *
 * Run:  php backend/tests/test_feed_integration.php
 *
 * UNLIKE the other two suites this one NEEDS a database, because what it
 * checks is precisely the part unit tests cannot reach: that events written
 * by EventService are rolled up by rollup_stats.php into numbers FeedService
 * actually ranks on. Each of those three works in isolation; the interesting
 * failures live between them.
 *
 * It creates its OWN throwaway user and deletes it at the end, including on
 * failure. It never reads or writes another user's rows.
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Db;
use App\Services\EventService;
use App\Services\FeedService;
use App\Services\Scoring;

// save_articles() lives in the cron job, not the app, so it is pulled in
// directly. feedlib.php is already required by fetch_feeds.php; requiring the
// job itself would RUN it.
require_once __DIR__ . '/../jobs/feedlib.php';

$pass = 0;
$fail = 0;

function ok(string $m): void  { global $pass; $pass++; printf("  \033[32m[PASS]\033[0m %s\n", $m); }
function bad(string $m): void { global $fail; $fail++; printf("  \033[31m[FAIL]\033[0m %s\n", $m); }

function assert_true(string $desc, bool $cond, string $detail = ''): void
{
    $cond ? ok($desc . ($detail !== '' ? " ($detail)" : '')) : bad($desc . ($detail !== '' ? " — $detail" : ''));
}

$userId = null;

try {
    /* ---- a throwaway user ---------------------------------------- */

    $userId = (int) Db::scalar(
        "INSERT INTO users (email, password_hash, is_active)
         VALUES (?, 'x', true) RETURNING id",
        ['feedtest-' . bin2hex(random_bytes(6)) . '@example.invalid']
    );
    echo "\nthrowaway user id {$userId}\n\n";

    // Two categories that actually hold recent articles, so the feed has
    // something to rank. Picking them dynamically keeps this test working as
    // the corpus changes.
    $cats = Db::all(
        "SELECT category_id, count(*) AS n
           FROM articles
          WHERE published_at > NOW() - INTERVAL '30 days'
          GROUP BY category_id HAVING count(*) >= 40
          ORDER BY n DESC LIMIT 2"
    );
    if (count($cats) < 2) {
        echo "  SKIP — needs two categories with 40+ recent articles\n";
        exit(0);
    }

    $loved   = (int) $cats[0]['category_id'];
    $ignored = (int) $cats[1]['category_id'];

    Db::exec(
        'INSERT INTO user_categories (user_id, category_id) VALUES (?, ?), (?, ?)',
        [$userId, $loved, $userId, $ignored]
    );

    /* ---- cold start ---------------------------------------------- */
    echo "cold start\n";

    $feed = (new FeedService())->build($userId, 20);
    assert_true('a brand-new user still gets a full page', count($feed) === 20,
        count($feed) . ' articles');

    Db::exec('DELETE FROM user_seen_articles WHERE user_id = ?', [$userId]);

    /* ---- synthesise a reading history ---------------------------- */
    echo "\nevents\n";

    $lovedArticles = Db::all(
        "SELECT id, blog_source_id FROM articles
          WHERE category_id = ? AND published_at > NOW() - INTERVAL '30 days'
          LIMIT 40", [$loved]
    );
    $ignoredArticles = Db::all(
        "SELECT id, blog_source_id FROM articles
          WHERE category_id = ? AND published_at > NOW() - INTERVAL '30 days'
          LIMIT 40", [$ignored]
    );

    // Every event carries a client id, exactly as the app sends them. That id
    // is what makes a retry distinguishable from a genuine repeat.
    $events = [];
    // Loved category: shown 40, opened 30 of them properly.
    foreach ($lovedArticles as $i => $a) {
        $events[] = ['id' => "imp-loved-$i", 'type' => 'impression',
                     'article_id' => (int) $a['id'], 'session_id' => 'sess-' . $i];
        if ($i < 30) {
            $events[] = ['id' => "tap-loved-$i", 'type' => 'tap',
                         'article_id' => (int) $a['id'], 'dwell_ms' => 45000];
        }
    }
    // Ignored category: shown 40, never opened at all.
    foreach ($ignoredArticles as $i => $a) {
        $events[] = ['id' => "imp-ign-$i", 'type' => 'impression',
                     'article_id' => (int) $a['id'], 'session_id' => 'ign-' . $i];
    }

    $svc = new EventService();
    $accepted = 0;
    foreach (array_chunk($events, 100) as $chunk) {
        $accepted += $svc->record($userId, $chunk)['accepted'];
    }
    assert_true('events stored', $accepted === count($events), "$accepted of " . count($events));

    // A retried flush must not double-count ANYTHING — taps included. Before
    // client ids existed this re-inserted 10 taps and inflated the good-tap
    // count by a third, with nothing anywhere to show it had happened.
    $again = $svc->record($userId, array_slice($events, 0, 20));
    assert_true('a replayed batch inserts nothing', $again['accepted'] === 0,
        "accepted {$again['accepted']}");

    // ...but a genuine second open of the same article is not a retry, and
    // must still be recorded.
    $reopen = $svc->record($userId, [[
        'id' => 'tap-loved-0-second-visit', 'type' => 'tap',
        'article_id' => (int) $lovedArticles[0]['id'], 'dwell_ms' => 60000,
    ]]);
    assert_true('a genuine repeat tap is still stored', $reopen['accepted'] === 1,
        "accepted {$reopen['accepted']}");

    // A bounce is not a success.
    $bounced = Db::scalar(
        "SELECT count(*) FROM article_events
          WHERE user_id = ? AND event_type = 'tap' AND dwell_ms >= ?",
        [$userId, Scoring::GOOD_TAP_MS]
    );
    assert_true('exactly the real good taps are counted', (int) $bounced === 31,
        "got $bounced (30 first reads + 1 genuine re-open)");

    /* ---- roll up -------------------------------------------------- */
    echo "\nrollup\n";

    exec('php ' . escapeshellarg(__DIR__ . '/../jobs/rollup_stats.php') . ' 2>&1', $out, $code);
    assert_true('rollup job exits 0', $code === 0, implode(' | ', array_slice($out, -1)));

    $lovedRate = (float) Db::scalar(
        'SELECT rate_norm FROM user_category_stats WHERE user_id = ? AND category_id = ?',
        [$userId, $loved]
    );
    $ignoredRate = (float) Db::scalar(
        'SELECT rate_norm FROM user_category_stats WHERE user_id = ? AND category_id = ?',
        [$userId, $ignored]
    );

    assert_true('the opened category normalises to 1.0', abs($lovedRate - 1.0) < 0.001,
        sprintf('%.3f', $lovedRate));
    assert_true('the ignored category scores far lower', $ignoredRate < 0.3,
        sprintf('%.3f', $ignoredRate));

    $impressions = (int) Db::scalar('SELECT impressions FROM user_feed_stats WHERE user_id = ?', [$userId]);
    assert_true('user is now warm', $impressions >= Scoring::COLD_START_IMPRESSIONS,
        "$impressions impressions");

    /* ---- does it change the ranking? ------------------------------ */
    echo "\nranking\n";

    // Averaged over several pulls, because the shuffle is deliberately random
    // — a single page proves nothing either way.
    // Resolved ONCE. A lookup inside the loop would be 160 round trips to a
    // database in another region — the test would take minutes and measure
    // the network rather than the ranking.
    $lovedName = (string) Db::scalar('SELECT name FROM category_master WHERE id = ?', [$loved]);

    $lovedSeen = 0;
    $total     = 0;
    for ($run = 0; $run < 4; $run++) {
        Db::exec('DELETE FROM user_seen_articles WHERE user_id = ?', [$userId]);
        foreach ((new FeedService())->build($userId, 20) as $a) {
            $total++;
            if ($a['category'] === $lovedName) {
                $lovedSeen++;
            }
        }
    }
    $share = $total > 0 ? $lovedSeen / $total : 0;
    assert_true('the opened category now dominates the feed', $share > 0.55,
        sprintf('%.0f%% of %d slots', $share * 100, $total));

    /* ---- negative feedback ---------------------------------------- */
    echo "\nnegative feedback\n";

    Db::exec('DELETE FROM user_seen_articles WHERE user_id = ?', [$userId]);
    $before = (new FeedService())->build($userId, 20);
    $victim = $before[0];

    $svc->record($userId, [['type' => 'not_interested', 'article_id' => $victim['id']]]);
    Db::exec('DELETE FROM user_seen_articles WHERE user_id = ?', [$userId]);

    $stillThere = false;
    for ($run = 0; $run < 3; $run++) {
        Db::exec('DELETE FROM user_seen_articles WHERE user_id = ?', [$userId]);
        foreach ((new FeedService())->build($userId, 20) as $a) {
            if ($a['id'] === $victim['id']) {
                $stillThere = true;
            }
        }
    }
    assert_true('a not_interested article never comes back', !$stillThere);

    // Hiding a source must take effect immediately, not at the next rollup.
    $sourceId = (int) Db::scalar('SELECT blog_source_id FROM articles WHERE id = ?', [$victim['id']]);
    $svc->setHidden($userId, $sourceId, true);

    $hiddenSeen = false;
    for ($run = 0; $run < 3; $run++) {
        Db::exec('DELETE FROM user_seen_articles WHERE user_id = ?', [$userId]);
        foreach ((new FeedService())->build($userId, 20) as $a) {
            if ($a['source'] === $victim['source']) {
                $hiddenSeen = true;
            }
        }
    }
    assert_true('a hidden source disappears without waiting for the rollup', !$hiddenSeen);

    $listed = $svc->hiddenSources($userId);
    assert_true('the hidden source is listed for Profile', count($listed) === 1
        && $listed[0]['source_id'] === $sourceId);

    $svc->setHidden($userId, $sourceId, false);
    assert_true('unhiding empties the list', $svc->hiddenSources($userId) === []);

    /* ---- diversity ------------------------------------------------ */
    echo "\ndiversity\n";

    /*
     * The cap and the full-page guarantee CONFLICT when the candidate pool is
     * narrow: two categories carrying five sources cannot fill twenty slots at
     * two per source, and the doc is explicit that a full page wins. So the
     * two properties are checked under the conditions where each applies.
     */

    // Widen the pool first, so the cap is actually reachable.
    Db::exec(
        "INSERT INTO user_categories (user_id, category_id)
         SELECT ?, id FROM category_master WHERE is_active = true LIMIT 8
         ON CONFLICT DO NOTHING",
        [$userId]
    );
    Db::exec('DELETE FROM user_seen_articles WHERE user_id = ?', [$userId]);

    $page = (new FeedService())->build($userId, 20);

    $perSource = [];
    foreach ($page as $a) {
        $perSource[$a['source']] = ($perSource[$a['source']] ?? 0) + 1;
    }

    assert_true('a full page is returned', count($page) === 20, count($page) . ' articles');
    assert_true('with a wide pool, no source exceeds the cap of 2', max($perSource) <= 2,
        'max ' . max($perSource) . ' across ' . count($perSource) . ' sources');
    assert_true('the page draws on many sources', count($perSource) >= 10,
        count($perSource) . ' distinct sources');

    /*
     * FOUR categories — the case that shipped broken.
     *
     * A fixed cap of 4 per category allows at most 4 x 4 = 16 of 20 slots, so
     * the cap could never be met, the relaxation path ran on every request,
     * and because it dropped the source cap at the same time one blog took 4
     * of 20 slots in production. The cap now scales to ceil(20/4) = 5.
     */
    Db::exec('DELETE FROM user_categories WHERE user_id = ?', [$userId]);
    Db::exec(
        "INSERT INTO user_categories (user_id, category_id)
         SELECT ?, id FROM category_master WHERE is_active = true LIMIT 4
         ON CONFLICT DO NOTHING",
        [$userId]
    );
    Db::exec('DELETE FROM user_seen_articles WHERE user_id = ?', [$userId]);

    $four = (new FeedService())->build($userId, 20);
    $fourSources = [];
    $fourCats    = [];
    foreach ($four as $a) {
        $fourSources[$a['source']]  = ($fourSources[$a['source']]  ?? 0) + 1;
        $fourCats[$a['category']]   = ($fourCats[$a['category']]   ?? 0) + 1;
    }

    assert_true('four categories still fill the page', count($four) === 20,
        count($four) . ' articles');
    assert_true('four categories still respect the source cap', max($fourSources) <= 2,
        'max ' . max($fourSources) . ' per source');
    /*
     * The category cap is BEST EFFORT and is surrendered first, by design —
     * so this asserts the property that actually matters to a reader rather
     * than the cap number. Four chosen topics should all appear, and none
     * should swallow half the page.
     *
     * The cap genuinely cannot always hold: 4 categories x cap 5 is exactly
     * 20, which requires every category to field 5 articles from at least 3
     * distinct blogs. A category with only two blogs caps out at 4 and the
     * shortfall has to come from somewhere.
     */
    assert_true('all four chosen topics appear', count($fourCats) === 4,
        implode(', ', array_map(
            fn($k, $v) => "$k=$v", array_keys($fourCats), $fourCats)));
    assert_true('no single topic swallows the page', max($fourCats) <= 10,
        'max ' . max($fourCats) . ' per category');

    // Now the opposite case: narrow the pool right down and confirm the page
    // is STILL full. A cap must never be a reason to return less than asked.
    Db::exec('DELETE FROM user_categories WHERE user_id = ?', [$userId]);
    Db::exec('INSERT INTO user_categories (user_id, category_id) VALUES (?, ?)', [$userId, $loved]);
    Db::exec('DELETE FROM user_seen_articles WHERE user_id = ?', [$userId]);

    $narrow = (new FeedService())->build($userId, 20);
    $narrowSources = count(array_unique(array_column($narrow, 'source')));

    assert_true('a narrow pool still fills the page via refill', count($narrow) === 20,
        count($narrow) . " articles from $narrowSources source(s)");

    /* ---- the fetch job's insert path ------------------------------ */
    echo "\narticle ingestion\n";

    /*
     * save_articles() batches its INSERTs and drops anything the 60-day purge
     * would delete moments later. Both behaviours are checked here because
     * neither is visible from the outside: the old version inserted expired
     * articles and deleted them in the same run, reporting "2446 added" into
     * a table that held 1740.
     */
    $blogId = (int) Db::scalar('SELECT id FROM blog_sources ORDER BY id LIMIT 1');
    $catId  = $loved;
    $stamp  = bin2hex(random_bytes(6));

    $mk = static fn(string $key, ?string $publishedAt): array => [
        'guid'         => "test-$key",
        'title'        => "Test article $key",
        'excerpt'      => 'An excerpt long enough to be a real summary rather than a stub.',
        'url'          => "https://example.invalid/$key",
        'image_url'    => null,
        'author'       => null,
        'published_at' => $publishedAt,
    ];

    $items = [
        $mk("$stamp-fresh1", gmdate('Y-m-d H:i:s', time() - 3600)),
        $mk("$stamp-fresh2", gmdate('Y-m-d H:i:s', time() - 86400)),
        $mk("$stamp-old1",   gmdate('Y-m-d H:i:s', time() - 90 * 86400)),
        $mk("$stamp-old2",   gmdate('Y-m-d H:i:s', time() - 400 * 86400)),
        $mk("$stamp-undated", null),
    ];
    // A feed that lists the same guid twice — one statement cannot insert the
    // same conflicting row twice, so this must be collapsed before the INSERT.
    $items[] = $mk("$stamp-fresh1", gmdate('Y-m-d H:i:s', time() - 3600));

    $saved = save_articles(Db::conn(), $items, $blogId, $catId);

    assert_true('articles past the retention window are never inserted',
        $saved['too_old'] === 2, "skipped {$saved['too_old']} of 2");
    assert_true('fresh and undated articles are inserted once each',
        $saved['inserted'] === 3, "inserted {$saved['inserted']} (2 fresh + 1 undated)");

    // Re-running the job must add nothing — the unique index is what makes it
    // safe to run more often than scheduled.
    $again = save_articles(Db::conn(), $items, $blogId, $catId);
    assert_true('a repeat run inserts nothing', $again['inserted'] === 0,
        "inserted {$again['inserted']}");

    Db::exec("DELETE FROM articles WHERE guid LIKE ?", ["test-$stamp%"]);

} finally {
    if ($userId !== null) {
        Db::exec('DELETE FROM users WHERE id = ?', [$userId]);
        echo "\ncleaned up user {$userId}\n";
    }
}

echo "\n---------------------------\n";
printf("passed: \033[32m%d\033[0m   failed: \033[31m%d\033[0m\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
