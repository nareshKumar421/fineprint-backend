<?php
/**
 * FeedController — the main endpoint. This is what users experience most.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\ApiException;
use App\Request;
use App\Response;
use App\Services\FeedService;
use DateTimeImmutable;
use DateTimeZone;

final class FeedController
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 50;

    /**
     * GET /api/feed[?limit=][&cursor=]
     *
     * Two phases, so the feed never goes blank:
     *
     *   FRESH    no cursor. Unseen articles, shuffled, weighted to recent.
     *   ARCHIVE  a cursor, OR fresh came back empty. Older articles the user
     *            has already seen, newest-first, keyset-paginated.
     *
     * The response says which phase produced it and whether more exists, so
     * the app knows when it has genuinely reached the end.
     */
    public function index(Request $request): void
    {
        $userId = $request->requireUserId();
        $limit  = $this->limit($request);
        $cursor = $this->cursor($request);

        $service = new FeedService();

        // A cursor means the app is already paging the archive.
        if ($cursor !== null) {
            $this->send($service->archive($userId, $limit, $cursor), 'archive');
            return;
        }

        $fresh = $service->build($userId, $limit);

        if ($fresh !== []) {
            // More fresh articles may remain; the app finds out by asking
            // again, since seen-tracking excludes what it has already shown.
            $this->send(
                ['articles' => $fresh, 'next_cursor' => null, 'has_more' => true],
                'fresh'
            );
            return;
        }

        /*
         * Fresh is empty — the user has seen everything recent.
         *
         * Fall straight through to the archive rather than returning an
         * empty list. Returning [] here is what made the feed go blank
         * after a full scroll, which reads as a broken app.
         *
         * An empty ARCHIVE is still valid and still not an error: it means
         * the user's categories genuinely contain no articles at all.
         */
        $this->send($service->archive($userId, $limit, null), 'archive');
    }

    private function send(array $page, string $phase): void
    {
        Response::json([
            'articles'     => $page['articles'],
            'phase'        => $phase,
            'next_cursor'  => $page['next_cursor'],
            'has_more'     => $page['has_more'],
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                                  ->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    private function limit(Request $request): int
    {
        $raw = $request->query('limit');
        if ($raw === null) {
            return self::DEFAULT_LIMIT;
        }
        if (!ctype_digit((string) $raw)) {
            throw new ApiException('VALIDATION_ERROR', 'limit must be a whole number.', 400);
        }

        $limit = (int) $raw;
        if ($limit < 1) {
            throw new ApiException('VALIDATION_ERROR', 'limit must be at least 1.', 400);
        }

        return min($limit, self::MAX_LIMIT);
    }

    private function cursor(Request $request): ?string
    {
        $raw = $request->query('cursor');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || strlen($raw) > 100) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid cursor.', 400);
        }
        return $raw;
    }
}
