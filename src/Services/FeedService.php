<?php
/**
 * FeedService — build one user's feed.
 *
 * Three steps: pull candidates with the tuned LATERAL query, shuffle them
 * weighted toward recent, then record what was shown so it does not repeat.
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

    /** Candidates kept per category after it. */
    private const PER_CATEGORY_KEEP = 50;

    /** Recency window. Removing this makes the query SLOWER — see below. */
    private const WINDOW_DAYS = 30;

    /**
     * Age in days assigned to an article with no publish date.
     *
     * NOTE: with the recency window below, this is currently unreachable.
     * `NULL > NOW() - INTERVAL '30 days'` evaluates to NULL, not true, so an
     * undated article is filtered out before it can be ranked — a blog whose
     * every post is undated contributes NOTHING to the feed, silently.
     *
     * That is consistent with the guidance to heed find_feed.php's
     * "no publish dates" warning and simply not add such blogs. Kept here
     * because dropping the recency window (or ever relaxing it to
     * COALESCE(published_at, fetched_at)) makes undated articles reachable
     * again, and they must sink rather than float.
     */
    private const UNDATED_AGE_DAYS = 30;

    /**
     * FRESH phase — unseen articles, shuffled and weighted to recent.
     *
     * Returns [] once the user has seen everything recent. The caller then
     * falls back to archive().
     */
    public function build(int $userId, int $limit): array
    {
        $candidates = $this->queryCandidates($userId);
        $picked     = $this->weightedShuffle($candidates, $limit);

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
                    a.published_at, b.blog_name, c.name AS category_name
               FROM user_categories uc
         CROSS JOIN LATERAL (
                    SELECT r.*
                      FROM (
                            SELECT ar.id, ar.title, ar.excerpt, ar.article_url,
                                   ar.image_url, ar.author, ar.published_at,
                                   ar.blog_source_id, ar.category_id
                              FROM articles ar
                             WHERE ar.category_id = uc.category_id
                               AND ar.published_at > NOW() - INTERVAL '%d days'
                             ORDER BY ar.published_at DESC
                             LIMIT %d
                           ) r
                     WHERE NOT EXISTS (
                            SELECT 1 FROM user_seen_articles s
                             WHERE s.user_id = uc.user_id AND s.article_id = r.id
                           )
                     LIMIT %d
                  ) a
               JOIN blog_sources    b ON b.id = a.blog_source_id
               JOIN category_master c ON c.id = a.category_id
              WHERE uc.user_id = ?",
            self::WINDOW_DAYS,
            self::PER_CATEGORY_SCAN,
            self::PER_CATEGORY_KEEP
        );

        return Db::all($sql, [$userId]);
    }

    /**
     * Weighted sampling without replacement.
     *
     * Each row gets an exponential random key scaled by its weight, then we
     * sort by that key. A same-day post is roughly 7x more likely to surface
     * than a week-old one — but nothing is guaranteed first, so the feed looks
     * different on every pull and does not feel like a static list.
     *
     * mt_rand() is fine here: this is presentation, not security.
     */
    private function weightedShuffle(array $rows, int $limit): array
    {
        $now = time();

        foreach ($rows as &$r) {
            $ageDays = $r['published_at'] !== null
                ? max(0.0, ($now - strtotime((string) $r['published_at'])) / 86400)
                : self::UNDATED_AGE_DAYS;   // undated posts sink; they do not vanish

            $weight = 1 / (1 + $ageDays);

            // u in (0,1) exclusive — log(0) is -INF and would sort to the top.
            $u = (mt_rand() + 1) / (mt_getrandmax() + 2);
            $r['_key'] = -log($u) / $weight;
        }
        unset($r);

        usort($rows, static fn(array $a, array $b): int => $a['_key'] <=> $b['_key']);

        return array_slice($rows, 0, $limit);
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
