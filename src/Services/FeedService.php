<?php
/**
 * FeedService — build one user's feed.
 *
 * Four steps: pull candidates with the tuned LATERAL query, SCORE them,
 * shuffle weighted by that score, then enforce diversity across the page —
 * and finally record what was shown so it does not repeat.
 *
 * The scoring arithmetic itself lives in Scoring.php, deliberately apart from
 * anything that touches a database. This file decides WHAT to score and in
 * what order to return it; that one decides HOW. See
 * docs/feed-algorithm-simple.md.
 */

declare(strict_types=1);

namespace App\Services;

use App\Db;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class FeedService
{
    /** Candidates taken per category before the seen-articles anti-join. */
    private const PER_CATEGORY_SCAN = 200;

    /**
     * Candidates kept per category after it.
     *
     * Raised from 50 to 100 when scoring arrived. Ranking can only choose
     * from what retrieval hands it, and with five terms deciding the order a
     * wider pool is worth the extra rows — they are discarded by the same
     * LIMIT either way, just later.
     */
    private const PER_CATEGORY_KEEP = 100;

    /**
     * Diversity caps for one page (docs §6).
     *
     * The LATERAL query balances by CATEGORY, which is why a category with
     * one dominant blog still arrives dominated. Only a per-source cap fixes
     * that, and only after scoring — diversity is a property of the page, not
     * of any single article.
     */
    private const MAX_PER_SOURCE   = 2;
    private const MAX_PER_CATEGORY = 4;

    /**
     * The category cap is skipped below this many chosen categories.
     *
     * With two categories selected, a cap of 4 would hold a 20-card page to
     * 8 articles. The refill pass would recover them, but skipping the cap
     * outright is clearer than relying on the safety net.
     */
    private const CATEGORY_CAP_MIN_CATEGORIES = 3;

    /** Recency window. Removing this makes the query SLOWER — see below. */
    private const WINDOW_DAYS = 30;

    /*
     * Undated articles used to need a stand-in age here. They no longer do:
     * Scoring::recency() returns 0.0 for a null date, so they sink rather
     * than float, and the rule lives with the arithmetic it belongs to.
     *
     * Worth remembering either way — the recency window below already
     * excludes them, because `NULL > NOW() - INTERVAL '30 days'` is NULL and
     * not true. A blog whose every post is undated contributes NOTHING to the
     * feed, silently. Heed find_feed.php's "no publish dates" warning.
     */

    /**
     * FRESH phase — unseen articles, shuffled and weighted to recent.
     *
     * Returns [] once the user has seen everything recent. The caller then
     * falls back to archive().
     */
    public function build(int $userId, int $limit): array
    {
        $candidates = $this->queryCandidates($userId);

        if ($candidates === []) {
            return [];                  // caller falls through to archive()
        }

        /*
         * Both scoring inputs ride along on the candidate rows rather than
         * costing a second query.
         *
         * That is not micro-optimisation. This API talks to a managed
         * Postgres in another region, so a round trip is dominated by network
         * latency — measured at ~920 ms from a development machine — and the
         * SQL itself runs in single-digit milliseconds. The number of trips
         * is the thing worth counting, not the work inside them.
         */
        $cold = (int) $candidates[0]['u_impressions'] < Scoring::COLD_START_IMPRESSIONS;

        /*
         * Categories that actually CONTRIBUTED candidates — not categories
         * the user selected. That is the number the diversity cap cares
         * about: someone who chose twenty topics but has articles in two
         * must not have a per-category cap applied to them.
         *
         * Counted here rather than in SQL because Postgres has no
         * `count(DISTINCT ...) OVER ()`, and the rows are already in hand.
         */
        $categoryCount = count(array_unique(array_column($candidates, 'category_id')));

        $scored  = $this->score($candidates, $cold);
        $ordered = $this->weightedShuffle($scored);
        $picked  = $this->diversityPass($ordered, $limit, $categoryCount);

        $this->markSeen($userId, array_column($picked, 'id'));

        return array_map([$this, 'format'], $picked);
    }

    /**
     * ARCHIVE phase — everything in the user's categories, seen or not,
     * strictly newest-first, keyset-paginated.
     *
     * Why this exists: the fresh phase deliberately excludes already-seen
     * articles so the feed does not repeat within a session. Taken alone
     * that means a user who scrolls to the end gets a BLANK screen until
     * the next cron run — which reads as a broken app, not as "you are up
     * to date".
     *
     * So when fresh runs dry we keep scrolling through older articles
     * instead. They are ordered, not shuffled: the user is now browsing a
     * back catalogue, and a stable order is what makes paging through it
     * coherent.
     *
     * KEYSET pagination, not OFFSET. `OFFSET 200` makes PostgreSQL walk and
     * discard 200 rows every page, so deep scrolling gets slower and
     * slower; and a row inserted mid-scroll shifts every subsequent page,
     * duplicating or skipping articles. Comparing against the last row seen
     * has neither problem.
     *
     * @param string|null $cursor "<iso8601>|<id>" from the previous page
     * @return array{articles: array, next_cursor: ?string, has_more: bool}
     */
    public function archive(int $userId, int $limit, ?string $cursor): array
    {
        [$beforeTs, $beforeId] = $this->parseCursor($cursor);

        // Fetch one extra row to learn whether another page exists, without
        // a second COUNT query over the whole table.
        $rows = Db::all(
            "SELECT a.id, a.title, a.excerpt, a.article_url, a.image_url, a.author,
                    a.published_at, b.blog_name, c.name AS category_name
               FROM articles a
               JOIN blog_sources    b ON b.id = a.blog_source_id
               JOIN category_master c ON c.id = a.category_id
              WHERE a.category_id IN (
                        SELECT uc.category_id FROM user_categories uc WHERE uc.user_id = ?
                    )
                AND a.published_at IS NOT NULL
                AND (?::timestamptz IS NULL OR (a.published_at, a.id) < (?::timestamptz, ?::bigint))
              ORDER BY a.published_at DESC, a.id DESC
              LIMIT ?",
            [$userId, $beforeTs, $beforeTs, $beforeId, $limit + 1]
        );

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $next = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[count($rows) - 1];
            $next = self::iso8601($last['published_at']) . '|' . $last['id'];
        }

        return [
            'articles'    => array_map([$this, 'format'], $rows),
            'next_cursor' => $next,
            'has_more'    => $hasMore,
        ];
    }

    /** @return array{0: ?string, 1: ?int} */
    private function parseCursor(?string $cursor): array
    {
        if ($cursor === null || !str_contains($cursor, '|')) {
            return [null, null];
        }

        [$ts, $id] = explode('|', $cursor, 2);
        if (!ctype_digit($id) || strtotime($ts) === false) {
            // A malformed cursor restarts from the top rather than 500ing.
            return [null, null];
        }

        return [$ts, (int) $id];
    }

    /**
     * The query that runs on every app open.
     *
     * This shape was measured against 50,000 articles (docs/02 §5):
     *   naive IN (...)              32.5 ms   Seq Scan
     *   LATERAL, no inner limit     22.2 ms   Bitmap Index Scan
     *   LATERAL with inner LIMIT    18.2 ms   Bitmap Index Scan, early stop
     *
     * Two things make it fast. LATERAL runs one indexed lookup per category
     * instead of one filter across the whole table. The inner LIMIT caps the
     * work before the seen-articles anti-join, which otherwise forces the
     * planner to materialise every candidate row.
     *
     * Counter-intuitively, dropping the published_at window makes it SLOWER
     * (29.5 ms), not faster, even though ORDER BY ... LIMIT seems to make it
     * redundant — it narrows the index range scan. Keep it.
     *
     * Taking N per category is also the right shape for the product: a
     * balanced mix, rather than one prolific blog dominating the feed.
     */
    private function queryCandidates(int $userId): array
    {
        $sql = sprintf(
            "SELECT a.id, a.title, a.excerpt, a.article_url, a.image_url, a.author,
                    a.published_at, a.blog_source_id, a.category_id,
                    b.blog_name, c.name AS category_name,
                    COALESCE(ucs.rate_norm, 0)       AS category_affinity,
                    COALESCE(uss.rate_norm, 0)       AS source_affinity,
                    COALESCE(uss.impressions, 0)     AS source_impressions,
                    COALESCE(uss.is_fatigued, false) AS is_fatigued,
                    COALESCE(ss.quality_norm, 0)     AS source_quality,
                    COALESCE(ast.trending_norm, 0)   AS trending,
                    -- Scoring context, carried on every row so it costs no
                    -- extra round trip. Read from the first row and ignored
                    -- on the rest; identical on all of them by construction.
                    ufs.u_impressions
               FROM user_categories uc
         CROSS JOIN (
                    SELECT COALESCE(MAX(impressions), 0) AS u_impressions
                      FROM user_feed_stats WHERE user_id = ?
                  ) ufs
         CROSS JOIN LATERAL (
                    SELECT r.*
                      FROM (
                            SELECT ar.id, ar.title, ar.excerpt, ar.article_url,
                                   ar.image_url, ar.author, ar.published_at,
                                   ar.blog_source_id, ar.category_id
                              FROM articles ar
                             WHERE ar.category_id = uc.category_id
                               AND ar.published_at > NOW() - INTERVAL '%d days'
                               -- Hidden sources are excluded HERE, inside the
                               -- scan limit, so a muted blog cannot eat the
                               -- per-category budget before ranking sees it.
                               AND NOT EXISTS (
                                     SELECT 1 FROM user_hidden_sources h
                                      WHERE h.user_id   = uc.user_id
                                        AND h.source_id = ar.blog_source_id
                                   )
                             ORDER BY ar.published_at DESC
                             LIMIT %d
                           ) r
                     WHERE NOT EXISTS (
                            SELECT 1 FROM user_seen_articles s
                             WHERE s.user_id = uc.user_id AND s.article_id = r.id
                           )
                       -- Explicit 'not interested' is a HARD filter, not a
                       -- low weight. The weight floor in Scoring keeps a
                       -- demoted article reachable, which is right for
                       -- fatigue and wrong for someone who said no.
                       AND NOT EXISTS (
                            SELECT 1 FROM article_events ne
                             WHERE ne.user_id    = uc.user_id
                               AND ne.article_id = r.id
                               AND ne.event_type = 'not_interested'
                           )
                     LIMIT %d
                  ) a
               JOIN blog_sources    b ON b.id = a.blog_source_id
               JOIN category_master c ON c.id = a.category_id
          -- Rollups, all LEFT: a user with no history, a source nobody has
          -- seen and an article nobody has tapped must still be rankable.
          -- An inner join here would empty the feed for every new user.
          LEFT JOIN user_category_stats ucs ON ucs.user_id = uc.user_id
                                           AND ucs.category_id = a.category_id
          LEFT JOIN user_source_stats   uss ON uss.user_id = uc.user_id
                                           AND uss.source_id = a.blog_source_id
          LEFT JOIN source_stats         ss ON ss.source_id = a.blog_source_id
          LEFT JOIN article_stats       ast ON ast.article_id = a.id
              WHERE uc.user_id = ?",
            self::WINDOW_DAYS,
            self::PER_CATEGORY_SCAN,
            self::PER_CATEGORY_KEEP
        );

        // Two placeholders now: the context subquery in FROM comes first in
        // text order, then the WHERE. Positional parameters follow the SQL,
        // not the logic.
        return Db::all($sql, [$userId, $userId]);
    }

    /**
     * Attach a ranking weight to every candidate.
     *
     * All five terms arrive from the query already normalised to 0..1 by the
     * nightly rollup, so this is addition and one multiplication. The two
     * per-article terms that cannot be precomputed — recency, which depends
     * on the clock, and quality, which depends on the article's own image and
     * excerpt — are computed here.
     */
    private function score(array $rows, bool $cold): array
    {
        $now = time();

        foreach ($rows as &$r) {
            $recency = Scoring::recency($r['published_at'], $now);

            $quality = Scoring::quality(
                (float) $r['source_quality'],
                $r['image_url'] !== null && $r['image_url'] !== '',
                mb_strlen((string) ($r['excerpt'] ?? ''), 'UTF-8') >= Scoring::EXCERPT_MIN_CHARS
            );

            if ($cold) {
                $terms = [
                    'recency'  => $recency,
                    'quality'  => $quality,
                    'trending' => (float) $r['trending'],
                ];
                $score = Scoring::coldScore($terms);
            } else {
                $terms = [
                    'recency'  => $recency,
                    'category' => (float) $r['category_affinity'],
                    'source'   => (float) $r['source_affinity'],
                    'quality'  => $quality,
                    'explore'  => Scoring::explore((int) $r['source_impressions']),
                ];
                $score = Scoring::warmScore($terms);
            }

            $penalty = Scoring::penalty(self::truthy($r['is_fatigued']));

            $r['_weight'] = Scoring::finalWeight($score, $penalty);
            $r['_cold']   = $cold;
            $r['_terms']  = $terms;
        }
        unset($r);

        return $rows;
    }

    /**
     * Weighted sampling without replacement.
     *
     * Each row gets an exponential random key scaled by its weight, then we
     * sort by that key. A strongly scored article is far more likely to
     * surface than a weak one — but nothing is guaranteed first, so the feed
     * looks different on every pull and does not feel like a static list.
     *
     * UNCHANGED from the recency-only version except for what feeds it: the
     * weight is now the full score rather than 1/(1+ageDays). That was the
     * point of keeping this mechanism.
     *
     * mt_rand() is fine here: this is presentation, not security.
     */
    private function weightedShuffle(array $rows): array
    {
        foreach ($rows as &$r) {
            // u in (0,1) exclusive — log(0) is -INF and would sort to the top.
            $u = (mt_rand() + 1) / (mt_getrandmax() + 2);
            $r['_key'] = -log($u) / $r['_weight'];
        }
        unset($r);

        usort($rows, static fn(array $a, array $b): int => $a['_key'] <=> $b['_key']);

        return $rows;
    }

    /**
     * Enforce per-source and per-category caps across the page.
     *
     * Walk the shuffled order, skipping anything that would breach a cap, and
     * then REFILL from what was skipped if the page came up short.
     *
     * The refill is not a nicety. Without it, a user whose categories happen
     * to be dominated by three blogs gets a six-article page and the app
     * reads it as the end of the feed. A cap is a preference about ordering,
     * never a reason to return less than was asked for.
     */
    private function diversityPass(array $rows, int $limit, int $categoryCount): array
    {
        $applyCategoryCap = $categoryCount >= self::CATEGORY_CAP_MIN_CATEGORIES;

        $page       = [];
        $skipped    = [];
        $perSource  = [];
        $perCategory = [];

        foreach ($rows as $r) {
            if (count($page) >= $limit) {
                break;
            }

            $source   = (int) $r['blog_source_id'];
            $category = (int) $r['category_id'];

            $sourceFull   = ($perSource[$source] ?? 0) >= self::MAX_PER_SOURCE;
            $categoryFull = $applyCategoryCap
                && ($perCategory[$category] ?? 0) >= self::MAX_PER_CATEGORY;

            if ($sourceFull || $categoryFull) {
                $skipped[] = $r;
                continue;
            }

            $page[] = $r;
            $perSource[$source]     = ($perSource[$source] ?? 0) + 1;
            $perCategory[$category] = ($perCategory[$category] ?? 0) + 1;
        }

        if (count($page) < $limit && $skipped !== []) {
            foreach ($skipped as $r) {
                if (count($page) >= $limit) {
                    break;
                }
                $page[] = $r;
            }
        }

        return $page;
    }

    /**
     * pdo_pgsql has reported booleans as PHP bool, as 't'/'f', and as '1'/''
     * across versions and build options. Reading one wrong would silently
     * disable the fatigue penalty — or apply it to everybody.
     */
    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 't', 'true', 'y'], true);
    }

    /**
     * Record what we just showed, so it does not come back.
     *
     * Deliberately swallows its own errors. Worst case a user sees an article
     * twice, which is far better than a 500 on the app's main screen.
     */
    private function markSeen(int $userId, array $articleIds): void
    {
        if ($articleIds === []) {
            return;
        }

        try {
            $values = implode(',', array_fill(0, count($articleIds), '(?, ?)'));
            $params = [];
            foreach ($articleIds as $id) {
                $params[] = $userId;
                $params[] = $id;
            }

            Db::exec(
                "INSERT INTO user_seen_articles (user_id, article_id)
                 VALUES $values
                 ON CONFLICT DO NOTHING",
                $params
            );
        } catch (Throwable $e) {
            error_log('FeedService::markSeen failed: ' . $e->getMessage());
        }
    }

    /** Shape one row for the API, per docs/03 §4. */
    private function format(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'title'        => $r['title'],
            'excerpt'      => $r['excerpt'],
            'url'          => $r['article_url'],
            'image_url'    => $r['image_url'],
            'source'       => $r['blog_name'],
            'category'     => $r['category_name'],
            'published_at' => self::iso8601($r['published_at']),
        ];
    }

    /**
     * ISO 8601 UTC, e.g. 2026-07-28T03:44:00Z.
     *
     * The app renders "3 hours ago" itself. Sending a pre-formatted relative
     * string would be wrong the moment the user scrolls for a minute.
     */
    private static function iso8601(?string $ts): ?string
    {
        if ($ts === null) {
            return null;
        }
        return (new DateTimeImmutable($ts))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
