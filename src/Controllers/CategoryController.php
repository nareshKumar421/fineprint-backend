<?php
/**
 * CategoryController — the topic list, and the user's chosen topics.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\ApiException;
use App\Db;
use App\Request;
use App\Response;
use PDO;

final class CategoryController
{
    private const MAX_SELECTED = 20;

    /**
     * GET /api/categories — no auth.
     *
     * Unauthenticated on purpose: the app may want to show the topic list
     * before someone signs up.
     */
    public function list(Request $request): void
    {
        $rows = Db::all(
            'SELECT id, name, slug, description, icon_name
               FROM category_master
              WHERE is_active = true
              ORDER BY display_order ASC, name ASC'
        );

        Response::json([
            'categories' => array_map(static fn(array $r): array => [
                'id'          => (int) $r['id'],
                'name'        => $r['name'],
                'slug'        => $r['slug'],
                'description' => $r['description'],
                'icon_name'   => $r['icon_name'],
            ], $rows),
        ]);
    }

    /** GET /api/user/categories */
    public function mine(Request $request): void
    {
        $userId = $request->requireUserId();

        $rows = Db::all(
            'SELECT uc.category_id
               FROM user_categories uc
               JOIN category_master c ON c.id = uc.category_id
              WHERE uc.user_id = ? AND c.is_active = true
              ORDER BY c.display_order ASC',
            [$userId]
        );

        // An empty array is valid and means "not chosen yet" — the app sends
        // the user to the interests screen. It is not an error.
        Response::json(['category_ids' => array_map(
            static fn(array $r): int => (int) $r['category_id'],
            $rows
        )]);
    }

    /**
     * POST /api/user/categories
     *
     * REPLACES the whole selection. It is not additive — sending [1,2] after
     * [3] leaves the user with exactly [1,2].
     */
    public function save(Request $request): void
    {
        $userId = $request->requireUserId();
        $ids    = $request->input('category_ids');

        if (!is_array($ids)) {
            throw new ApiException('VALIDATION_ERROR', 'category_ids must be a list.', 400);
        }

        // Normalise: accept "3" as well as 3, drop duplicates.
        $clean = [];
        foreach ($ids as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                throw new ApiException('VALIDATION_ERROR', 'category_ids must be whole numbers.', 400);
            }
            $clean[(int) $id] = true;
        }
        $clean = array_keys($clean);

        if ($clean === []) {
            throw new ApiException('VALIDATION_ERROR', 'Choose at least one topic.', 400);
        }
        if (count($clean) > self::MAX_SELECTED) {
            throw new ApiException('VALIDATION_ERROR',
                'Choose at most ' . self::MAX_SELECTED . ' topics.', 400);
        }

        // Every id must exist AND be active. Checked in one query rather than
        // one per id.
        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $valid = Db::all(
            "SELECT id FROM category_master WHERE is_active = true AND id IN ($placeholders)",
            $clean
        );
        if (count($valid) !== count($clean)) {
            throw new ApiException('VALIDATION_ERROR', 'One or more topics are not available.', 400);
        }

        // Delete-then-insert in ONE transaction. A failure halfway through must
        // not leave the user with no categories at all.
        Db::transaction(static function (PDO $db) use ($userId, $clean): void {
            $del = $db->prepare('DELETE FROM user_categories WHERE user_id = ?');
            $del->execute([$userId]);

            $ins = $db->prepare(
                'INSERT INTO user_categories (user_id, category_id) VALUES (?, ?)
                 ON CONFLICT DO NOTHING'
            );
            foreach ($clean as $id) {
                $ins->execute([$userId, $id]);
            }
        });

        sort($clean);
        Response::json(['success' => true, 'category_ids' => $clean]);
    }
}
