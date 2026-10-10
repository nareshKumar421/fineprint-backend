<?php
/**
 * EventService — validate and store a batch of interaction events.
 *
 * This is the only writer of article_events, and everything the feed learns
 * ultimately comes from here. It is deliberately forgiving: analytics that
 * rejects a whole batch because one row is stale loses the other nineteen.
 */

declare(strict_types=1);

namespace App\Services;

use App\ApiException;
use App\Db;
use Throwable;

final class EventService
{
    /** Most events one request may carry. The app flushes at 20. */
    private const MAX_BATCH = 100;

    /**
     * Longest dwell we will believe: one hour.
     *
     * Beyond this the reader put the phone down with the browser open, and
     * the number says nothing about the article. Capping rather than
     * discarding keeps the tap itself, which is still a real signal.
     */
    private const MAX_DWELL_MS = 3_600_000;

    private const TYPES = ['impression', 'tap', 'hide_source', 'not_interested', 'share'];

    /**
     * @return array{accepted: int, skipped: int}
     */
    public function record(int $userId, mixed $events): array
    {
        if (!is_array($events) || $events === []) {
            throw new ApiException('VALIDATION_ERROR', 'events must be a non-empty list.', 400);
        }
        if (count($events) > self::MAX_BATCH) {
            throw new ApiException('VALIDATION_ERROR',
                'At most ' . self::MAX_BATCH . ' events per request.', 400);
        }

        $clean = [];
        foreach ($events as $raw) {
            $row = $this->validate($raw);
            if ($row !== null) {
                $clean[] = $row;
            }
        }

        if ($clean === []) {
            return ['accepted' => 0, 'skipped' => count($events)];
        }

        /*
         * Drop events for articles that no longer exist.
         *
         * Not a hypothetical: articles are purged at 60 days, and the app
         * keeps an offline cache. A reader opening a cached article after the
         * purge sends a perfectly valid event for a row that is gone, and the
         * foreign key would reject the ENTIRE batch. Filtering first turns a
         * 500 into a silent, correct skip.
         */
        $ids   = array_values(array_unique(array_column($clean, 'article_id')));
        $marks = implode(',', array_fill(0, count($ids), '?'));

        $live = Db::all(
            "SELECT id, blog_source_id FROM articles WHERE id IN ($marks)",
            $ids
        );

        $sourceOf = [];
        foreach ($live as $r) {
            $sourceOf[(int) $r['id']] = (int) $r['blog_source_id'];
        }

        $clean = array_values(array_filter(
            $clean,
            static fn(array $e): bool => isset($sourceOf[$e['article_id']])
        ));

        if ($clean === []) {
            return ['accepted' => 0, 'skipped' => count($events)];
        }

        $inserted = $this->insert($userId, $clean);
        $this->applyHides($userId, $clean, $sourceOf);
        $this->countViews($userId, $inserted);

        $accepted = count($inserted);

        return ['accepted' => $accepted, 'skipped' => count($events) - $accepted];
    }

    /** One event in, a storable row or null out. Never throws on a bad row. */
    private function validate(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $type = $raw['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            return null;
        }

        $articleId = $raw['article_id'] ?? null;
        if (!is_int($articleId) && !(is_string($articleId) && ctype_digit($articleId))) {
            return null;
        }
        $articleId = (int) $articleId;
        if ($articleId <= 0) {
            return null;
        }

        $dwell = null;
        if ($type === 'tap' && isset($raw['dwell_ms'])) {
            $d = $raw['dwell_ms'];
            if (is_int($d) || (is_string($d) && ctype_digit($d))) {
                $dwell = min((int) $d, self::MAX_DWELL_MS);
            }
        }

        $position = null;
        if (isset($raw['position']) && is_int($raw['position']) && $raw['position'] >= 0) {
            $position = min($raw['position'], 32767);
        }

        $session = null;
        if (isset($raw['session_id']) && is_string($raw['session_id'])) {
            $s = trim($raw['session_id']);
            if ($s !== '' && strlen($s) <= 36) {
                $session = $s;
            }
        }

        /*
         * The client's own id for this event, stamped when the event
         * HAPPENED rather than when it was sent. That is what lets a retried
         * flush be recognised as a retry: the id travels with the event, so
         * the same tap keeps the same id however many times it is uploaded,
         * while a genuine second open of the same article gets a new one.
         *
         * Optional. A client that omits it still gets impression-level
         * deduplication from the partial index, just not tap-level.
         */
        $clientId = null;
        if (isset($raw['id']) && is_string($raw['id'])) {
            $candidate = trim($raw['id']);
            if ($candidate !== '' && strlen($candidate) <= 36) {
                $clientId = $candidate;
            }
        }

        return [
            'type'       => $type,
            'article_id' => $articleId,
            'dwell_ms'   => $dwell,
            'position'   => $position,
            'session_id' => $session,
            'client_id'  => $clientId,
        ];
    }

    /**
     * One multi-row INSERT.
     *
     * ON CONFLICT DO NOTHING pairs with the two partial unique indexes from
     * migration 007. A retried flush after a network timeout re-sends events
     * already stored, and double-counting them would silently distort every
     * rate the scoring depends on — the feed would still work, just on wrong
     * numbers. No target is named on the conflict clause because either index
     * may be the one that fires.
     */
    private function insert(int $userId, array $rows): array
    {
        $values = [];
        $params = [];

        foreach ($rows as $r) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?)';
            $params[] = $userId;
            $params[] = $r['article_id'];
            $params[] = $r['type'];
            $params[] = $r['dwell_ms'];
            $params[] = $r['position'];
            $params[] = $r['session_id'];
            $params[] = $r['client_id'];
        }

        /*
         * RETURNING, not rowCount(). The caller needs to know WHICH rows
         * landed, not just how many — a retried flush is dropped by ON
         * CONFLICT, and counting a view for an event that was already stored
         * would inflate the view count and retire articles early.
         */
        $sql = 'INSERT INTO article_events
                    (user_id, article_id, event_type, dwell_ms, position,
                     session_id, client_event_id)
                VALUES ' . implode(',', $values) . '
                ON CONFLICT DO NOTHING
                RETURNING article_id, event_type';

        return Db::all($sql, $params);
    }

    /**
     * Record that the reader actually had these articles on screen.
     *
     * This is the counter the archive's cap prefers. It is kept separate from
     * times_shown because the two answer different questions: the server
     * sending a 20-card page is not evidence that anybody saw twenty
     * articles, and capping on serves alone retires articles the reader never
     * laid eyes on.
     *
     * Only genuinely new impression events reach here, so a retried flush
     * cannot double-count.
     *
     * Errors are swallowed. A missed view count costs one extra showing of
     * one article; a 500 on the events endpoint costs every event in the
     * batch and teaches the client to retry into a wall.
     */
    private function countViews(int $userId, array $inserted): void
    {
        $articleIds = [];
        foreach ($inserted as $row) {
            if (($row['event_type'] ?? '') === 'impression') {
                $articleIds[(int) $row['article_id']] = true;
            }
        }

        if ($articleIds === []) {
            return;
        }

        try {
            // Four columns, so four values per row. The two literals are the
            // INSERT path only — on conflict the UPDATE below takes over.
            $values = implode(',', array_fill(0, count($articleIds), '(?, ?, 1, 1)'));
            $params = [];
            foreach (array_keys($articleIds) as $id) {
                $params[] = $userId;
                $params[] = $id;
            }

            /*
             * times_shown defaults to 1 on insert. An impression for an
             * article with no seen row means the server served it and the
             * bookkeeping is only arriving now — one showing is the honest
             * figure, not zero.
             */
            Db::exec(
                "INSERT INTO user_seen_articles (user_id, article_id, times_shown, times_viewed)
                 VALUES $values
                 ON CONFLICT (user_id, article_id) DO UPDATE
                     SET times_viewed  = user_seen_articles.times_viewed + 1,
                         last_shown_at = NOW()",
                $params
            );
        } catch (Throwable $e) {
            error_log('EventService::countViews failed: ' . $e->getMessage());
        }
    }

    /**
     * A hide_source event is also a state change.
     *
     * The event log records that it happened; user_hidden_sources records
     * that it is still true. The feed reads the state table, so a hide takes
     * effect on the next pull rather than waiting for the nightly rollup.
     */
    private function applyHides(int $userId, array $rows, array $sourceOf): void
    {
        $sources = [];
        foreach ($rows as $r) {
            if ($r['type'] === 'hide_source' && isset($sourceOf[$r['article_id']])) {
                $sources[$sourceOf[$r['article_id']]] = true;
            }
        }

        if ($sources === []) {
            return;
        }

        $values = implode(',', array_fill(0, count($sources), '(?, ?)'));
        $params = [];
        foreach (array_keys($sources) as $sourceId) {
            $params[] = $userId;
            $params[] = $sourceId;
        }

        Db::exec(
            "INSERT INTO user_hidden_sources (user_id, source_id)
             VALUES $values
             ON CONFLICT DO NOTHING",
            $params
        );
    }

    /** Sources this user has hidden, for the Profile list. */
    public function hiddenSources(int $userId): array
    {
        $rows = Db::all(
            'SELECT h.source_id, b.blog_name, h.created_at
               FROM user_hidden_sources h
               JOIN blog_sources b ON b.id = h.source_id
              WHERE h.user_id = ?
              ORDER BY b.blog_name ASC',
            [$userId]
        );

        return array_map(static fn(array $r): array => [
            'source_id' => (int) $r['source_id'],
            'name'      => $r['blog_name'],
        ], $rows);
    }

    /**
     * Hide or unhide one source.
     *
     * Unhiding matters more than it looks: a hide is invisible once applied,
     * so without a way back a single mis-tap silently removes a blog forever
     * and the user has no way to diagnose it (docs §5.6).
     */
    public function setHidden(int $userId, int $sourceId, bool $hidden): void
    {
        $exists = Db::scalar('SELECT 1 FROM blog_sources WHERE id = ?', [$sourceId]);
        if ($exists === null) {
            throw new ApiException('VALIDATION_ERROR', 'Unknown source.', 400);
        }

        if ($hidden) {
            Db::exec(
                'INSERT INTO user_hidden_sources (user_id, source_id)
                 VALUES (?, ?) ON CONFLICT DO NOTHING',
                [$userId, $sourceId]
            );
        } else {
            Db::exec(
                'DELETE FROM user_hidden_sources WHERE user_id = ? AND source_id = ?',
                [$userId, $sourceId]
            );
        }
    }
}
