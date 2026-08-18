<?php
/**
 * EventController — interaction logging, and the user's hidden-source list.
 *
 * Everything the feed learns about a reader enters through here.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\ApiException;
use App\Request;
use App\Response;
use App\Services\EventService;

final class EventController
{
    /**
     * POST /api/events
     *
     * { "events": [ {type, article_id, position, session_id, dwell_ms}, ... ] }
     *
     * Returns 202, not 200. The client must not care whether every row landed:
     * this is analytics, and a dropped impression costs a rounding error while
     * a retry storm costs a database. Malformed individual events are counted
     * in `skipped` rather than failing the batch.
     */
    public function store(Request $request): void
    {
        $userId = $request->requireUserId();
        $result = (new EventService())->record($userId, $request->input('events'));

        Response::json($result, 202);
    }

    /** GET /api/user/hidden-sources */
    public function hidden(Request $request): void
    {
        $userId = $request->requireUserId();

        Response::json(['sources' => (new EventService())->hiddenSources($userId)]);
    }

    /**
     * POST /api/user/hidden-sources
     *
     * { "source_id": 12, "hidden": false }
     *
     * One endpoint for both directions. Hiding also arrives as a `hide_source`
     * event from the feed's long-press menu; this exists so Profile can undo
     * it, which is the half people forget to build.
     */
    public function setHidden(Request $request): void
    {
        $userId = $request->requireUserId();

        $sourceId = $request->input('source_id');
        if (!is_int($sourceId) && !(is_string($sourceId) && ctype_digit($sourceId))) {
            throw new ApiException('VALIDATION_ERROR', 'source_id must be a whole number.', 400);
        }

        $hidden = $request->input('hidden');
        if (!is_bool($hidden)) {
            throw new ApiException('VALIDATION_ERROR', 'hidden must be true or false.', 400);
        }

        (new EventService())->setHidden($userId, (int) $sourceId, $hidden);

        Response::json(['ok' => true]);
    }
}
