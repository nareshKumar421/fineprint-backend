<?php
/**
 * rollup_stats.php — turn raw events into the numbers the feed ranks on.
 *
 * Runs nightly, right after fetch_feeds.php:
 *
 *     0 3 * * *  php jobs/fetch_feeds.php  >> /var/log/feedjob.log 2>&1
 *     30 3 * * * php jobs/rollup_stats.php >> /var/log/feedjob.log 2>&1
 *
 * WHY A ROLLUP AND NOT A LIVE QUERY
 *
 * The feed request has a ~20 ms budget and runs on every app open. Computing
 * smoothed rates over 30 days of events inside that request would mean
 * aggregating the fastest-growing table in the schema, per user, per pull.
 * Here it happens once a night, and serving becomes a join against six small
 * tables.
 *
 * Every table is rebuilt from scratch. Incremental maintenance would be
 * faster and is not worth the class of bug it invites — a rollup that drifts
 * from its source is nearly impossible to notice, because the feed still
 * looks plausible.
 *
 * Exit codes: 0 = fine, 1 = fatal.
 */

declare(strict_types=1);

require __DIR__ . '/feedlib.php';

feed_load_env();

/* Kept in step with src/Services/Scoring.php. If you change a constant there,
   change it here — these two files are the whole ranking policy. */
const GOOD_TAP_MS     = 10000;   // Scoring::GOOD_TAP_MS
const M_CATEGORY      = 20;      // smoothing, per user per category
const M_SOURCE        = 10;      // smoothing, per user per source
const M_SOURCE_GLOBAL = 50;      // smoothing, per source across all users
const FATIGUE_MIN_IMPRESSIONS = 15;
const DEFAULT_GLOBAL_RATE     = 0.05;

/**
 * Below this many impressions the global rate is noise, so the cold prior is
 * kept instead. Every smoothed rate in the system leans on this number;
 * letting three taps out of eleven set it to 0.27 would distort all of them.
 */
const MIN_IMPRESSIONS_FOR_GLOBAL = 500;

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

$startedAt = time();

/* ------------------------------------------------------------------ */
/*  1. The global prior                                                */
/* ------------------------------------------------------------------ */

$totals = $db->query(
    "SELECT count(*) FILTER (WHERE event_type = 'impression') AS impressions,
            count(*) FILTER (WHERE event_type = 'tap' AND dwell_ms >= " . GOOD_TAP_MS . ") AS good_taps
       FROM article_events
      WHERE created_at > NOW() - INTERVAL '30 days'"
)->fetch();

$impressions = (int) $totals['impressions'];
$goodTaps    = (int) $totals['good_taps'];

$globalRate = $impressions >= MIN_IMPRESSIONS_FOR_GLOBAL && $impressions > 0
    ? $goodTaps / $impressions
    : DEFAULT_GLOBAL_RATE;

$db->prepare(
    'UPDATE feed_globals
        SET global_rate = ?, impressions = ?, good_taps = ?, computed_at = NOW()
      WHERE id = 1'
)->execute([$globalRate, $impressions, $goodTaps]);

logline('INFO', sprintf(
    'global rate %.4f from %d impressions, %d good taps%s',
    $globalRate, $impressions, $goodTaps,
    $impressions < MIN_IMPRESSIONS_FOR_GLOBAL ? ' (below threshold - using cold prior)' : ''
));

/* ------------------------------------------------------------------ */
/*  2. Rebuild every rollup, atomically                                */
/* ------------------------------------------------------------------ */

/*
 * One transaction so the feed never reads a half-built rollup. TRUNCATE
 * takes a brief exclusive lock; at 3.30am with a nightly corpus that is the
 * right trade against the bookkeeping an incremental update would need.
 */
$db->beginTransaction();

try {
    $db->exec('TRUNCATE user_category_stats, user_source_stats, source_stats,
                        article_stats, user_feed_stats');

    /* ---- per user, per category (docs §5.2) ---- */
    //
    // rate_norm divides by the user's OWN best category, so the term is
    // relative to that reader rather than to the population. A light user's
    // favourite category scores 1.0 exactly like a heavy user's.
    $db->prepare(
        "INSERT INTO user_category_stats
             (user_id, category_id, impressions, good_taps, rate, rate_norm)
         WITH base AS (
             SELECT e.user_id, a.category_id,
                    count(*) FILTER (WHERE e.event_type = 'impression') AS impressions,
                    count(*) FILTER (WHERE e.event_type = 'tap' AND e.dwell_ms >= ?) AS good_taps
               FROM article_events e
               JOIN articles a ON a.id = e.article_id
              WHERE e.created_at > NOW() - INTERVAL '30 days'
              GROUP BY e.user_id, a.category_id
         ), rated AS (
             SELECT user_id, category_id, impressions, good_taps,
                    (good_taps + ?::int * ?::real) / (impressions + ?::int)::real AS rate
               FROM base
         )
         SELECT user_id, category_id, impressions, good_taps, rate,
                COALESCE(rate / NULLIF(max(rate) OVER (PARTITION BY user_id), 0), 0)
           FROM rated"
    )->execute([GOOD_TAP_MS, M_CATEGORY, $globalRate, M_CATEGORY]);

    /* ---- per user, per source (docs §5.3 and §5.6) ---- */
    //
    // Two windows in one pass: 30 days for affinity, 14 for the fatigue rule.
    // Fatigue is "shown repeatedly, never opened" — the silent version of
    // pressing "show less", and far more common than the explicit one.
    $db->prepare(
        "INSERT INTO user_source_stats
             (user_id, source_id, impressions, good_taps, rate, rate_norm,
              impressions_14d, good_taps_14d, is_fatigued)
         WITH base AS (
             SELECT e.user_id, a.blog_source_id AS source_id,
                    count(*) FILTER (WHERE e.event_type = 'impression') AS impressions,
                    count(*) FILTER (WHERE e.event_type = 'tap' AND e.dwell_ms >= ?) AS good_taps,
                    count(*) FILTER (WHERE e.event_type = 'impression'
                                       AND e.created_at > NOW() - INTERVAL '14 days') AS impressions_14d,
                    count(*) FILTER (WHERE e.event_type = 'tap' AND e.dwell_ms >= ?
                                       AND e.created_at > NOW() - INTERVAL '14 days') AS good_taps_14d
               FROM article_events e
               JOIN articles a ON a.id = e.article_id
              WHERE e.created_at > NOW() - INTERVAL '30 days'
              GROUP BY e.user_id, a.blog_source_id
         ), rated AS (
             SELECT *, (good_taps + ?::int * ?::real) / (impressions + ?::int)::real AS rate
               FROM base
         )
         SELECT user_id, source_id, impressions, good_taps, rate,
                COALESCE(rate / NULLIF(max(rate) OVER (PARTITION BY user_id), 0), 0),
                impressions_14d, good_taps_14d,
                (impressions_14d >= ? AND good_taps_14d = 0)
           FROM rated"
    )->execute([GOOD_TAP_MS, GOOD_TAP_MS, M_SOURCE, $globalRate, M_SOURCE, FATIGUE_MIN_IMPRESSIONS]);

    /* ---- per source, across everyone (docs §5.4) ---- */
    //
    // Every active source gets a row, including ones nobody has seen: a LEFT
    // JOIN, not an inner one. Without it a brand-new blog has no row, reads
    // as quality 0, and can never be shown enough to earn a better number.
    $db->prepare(
        "INSERT INTO source_stats (source_id, impressions, good_taps, rate, quality_norm)
         WITH base AS (
             SELECT b.id AS source_id,
                    count(e.id) FILTER (WHERE e.event_type = 'impression') AS impressions,
                    count(e.id) FILTER (WHERE e.event_type = 'tap' AND e.dwell_ms >= ?) AS good_taps
               FROM blog_sources b
               LEFT JOIN articles a       ON a.blog_source_id = b.id
               LEFT JOIN article_events e ON e.article_id = a.id
                                         AND e.created_at > NOW() - INTERVAL '30 days'
              GROUP BY b.id
         ), rated AS (
             SELECT *, (good_taps + ?::int * ?::real) / (impressions + ?::int)::real AS rate
               FROM base
         )
         SELECT source_id, impressions, good_taps, rate,
                COALESCE(rate / NULLIF(max(rate) OVER (), 0), 0)
           FROM rated"
    )->execute([GOOD_TAP_MS, M_SOURCE_GLOBAL, $globalRate, M_SOURCE_GLOBAL]);

    /* ---- trending, for cold-start users (docs §7) ---- */
    //
    // Only articles with at least one good tap are stored. Everything else
    // reads as trending 0 through the LEFT JOIN in the feed query, so this
    // table stays a handful of rows rather than one per article.
    $db->prepare(
        "INSERT INTO article_stats (article_id, good_taps_24h, trending_norm)
         WITH base AS (
             SELECT e.article_id, count(*) AS good_taps_24h
               FROM article_events e
              WHERE e.event_type = 'tap'
                AND e.dwell_ms >= ?
                AND e.created_at > NOW() - INTERVAL '24 hours'
              GROUP BY e.article_id
         )
         SELECT article_id, good_taps_24h,
                good_taps_24h::real / NULLIF(max(good_taps_24h) OVER (), 0)
           FROM base"
    )->execute([GOOD_TAP_MS]);

    /* ---- per user totals, driving the cold-start switch ---- */
    //
    // Lifetime, not windowed. This measures how much the system knows about
    // a reader, and that knowledge does not expire because they took a month
    // off — the affinity rollups above already handle recency.
    $db->prepare(
        "INSERT INTO user_feed_stats (user_id, impressions, good_taps)
         SELECT user_id,
                count(*) FILTER (WHERE event_type = 'impression'),
                count(*) FILTER (WHERE event_type = 'tap' AND dwell_ms >= ?)
           FROM article_events
          GROUP BY user_id"
    )->execute([GOOD_TAP_MS]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    logline('FATAL', 'rollup failed, nothing changed: ' . $e->getMessage());
    exit(1);
}

/* ------------------------------------------------------------------ */
/*  3. Report                                                          */
/* ------------------------------------------------------------------ */

$counts = $db->query(
    'SELECT (SELECT count(*) FROM user_category_stats) AS categories,
            (SELECT count(*) FROM user_source_stats)   AS sources,
            (SELECT count(*) FROM source_stats)        AS global_sources,
            (SELECT count(*) FROM article_stats)       AS trending,
            (SELECT count(*) FROM user_feed_stats)     AS users,
            (SELECT count(*) FROM user_source_stats WHERE is_fatigued) AS fatigued'
)->fetch();

logline('INFO', sprintf(
    'rollups rebuilt: %d user-category, %d user-source (%d fatigued), %d sources, %d trending, %d users, %ds',
    $counts['categories'], $counts['sources'], $counts['fatigued'],
    $counts['global_sources'], $counts['trending'], $counts['users'],
    time() - $startedAt
));

exit(0);
