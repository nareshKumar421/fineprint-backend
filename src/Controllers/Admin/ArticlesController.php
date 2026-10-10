<?php
/**
 * ArticlesController — the 17,000-row table nobody could look at.
 *
 * Browse, search and filter what the fetcher has stored, inspect one row
 * with its engagement counts, and delete — singly or in bulk — with a
 * confirmation page that says exactly what goes with it.
 *
 * Routes (exact-match, detail pages by ?id=):
 *   GET  /articles                 index
 *   GET  /articles/view            view
 *   GET  /articles/delete-confirm  deleteConfirm
 *   POST /articles/delete          delete
 *   POST /articles/bulk-delete     bulkDelete
 *
 * Deleting an article cascades by foreign key into article_events,
 * user_seen_articles and article_stats. Nothing else references it. The
 * confirmation pages count those rows from the database rather than
 * describing them in the abstract, because "3 impressions and 1 tap" is a
 * decision an administrator can make and "may have events" is not.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Admin\Page;
use App\Admin\Paginator;
use App\Db;
use App\Request;
use App\Services\Audit;

final class ArticlesController extends Page
{
    /** ORDER BY comes from here and only here — never from the request. */
    private const SORTS = [
        'published_at' => 'a.published_at',
        'fetched_at'   => 'a.fetched_at',
        'title'        => 'a.title',
    ];

    /** Most ids one bulk delete will accept. A full page at the widest limit. */
    private const BULK_CAP = 200;

    /** Columns selected for a single article, shared by view and the confirmations. */
    private const DETAIL_SQL = "
        SELECT a.id, a.guid, a.title, a.excerpt, a.article_url, a.image_url, a.author,
               a.blog_source_id, a.category_id,
               to_char(a.published_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS published_fmt,
               to_char(a.fetched_at   AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS fetched_fmt,
               a.published_at, a.fetched_at,
               b.blog_name, b.blog_url, b.is_active AS source_active,
               c.name AS category_name, c.slug AS category_slug,
               (SELECT count(*) FROM user_seen_articles s WHERE s.article_id = a.id)      AS seen_readers,
               (SELECT count(*) FROM article_events e
                 WHERE e.article_id = a.id AND e.event_type = 'impression')               AS impressions,
               (SELECT count(*) FROM article_events e
                 WHERE e.article_id = a.id AND e.event_type = 'tap')                      AS taps,
               (SELECT count(*) FROM article_events e
                 WHERE e.article_id = a.id AND e.event_type NOT IN ('impression','tap'))  AS other_events,
               (SELECT count(*) FROM article_events e WHERE e.article_id = a.id)          AS events_total,
               (SELECT count(*) FROM article_stats st WHERE st.article_id = a.id)         AS stats_rows
          FROM articles a
          JOIN blog_sources    b ON b.id = a.blog_source_id
          JOIN category_master c ON c.id = a.category_id
         WHERE a.id = ?";

    /* ---------------------------------------------------------------- */
    /*  list                                                             */
    /* ---------------------------------------------------------------- */

    /** GET /articles */
    public function index(Request $request): void
    {
        $this->admin();

        $filters = $this->filters();
        [$where, $params] = $this->whereClause($filters);

        $sort = isset(self::SORTS[$filters['sort']]) ? $filters['sort'] : 'published_at';
        $dir  = $filters['dir'] === 'asc' ? 'ASC' : 'DESC';
        // NULLS LAST either way: an undated article at the top of a
        // newest-first list looks like a bug, and at the top of an
        // oldest-first list it is one.
        $orderBy = self::SORTS[$sort] . ' ' . $dir . ' NULLS LAST, a.id ' . $dir;

        $pager = new Paginator(50);

        // ONE count, ONE page. The joins are on the page query only — the
        // filters never touch the joined columns, so the count can skip them.
        $total = (int) Db::scalar("SELECT count(*) FROM articles a $where", $params);
        $page  = $pager->describe($total);

        // A page past the end (a stale link after deletions, or a hand-typed
        // number) lands on the last page rather than an empty one that
        // claims "showing 51–12 of 12".
        if ($total > 0 && $pager->page > $page['pages']) {
            $this->redirect('/articles' . Paginator::link($page['pages']));
            return;
        }

        $rows = $total === 0 ? [] : Db::all("
            SELECT a.id, a.title, a.author, a.image_url,
                   to_char(a.published_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS published_fmt,
                   to_char(a.fetched_at   AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS fetched_fmt,
                   b.blog_name, c.name AS category_name
              FROM articles a
              JOIN blog_sources    b ON b.id = a.blog_source_id
              JOIN category_master c ON c.id = a.category_id
              $where
             ORDER BY $orderBy
             LIMIT ? OFFSET ?",
            array_merge($params, [$pager->perPage, $pager->offset]));

        // Both dropdowns in one round trip.
        $sources = [];
        $categories = [];
        foreach (Db::all("
            SELECT 'source' AS kind, id, blog_name AS name, is_active FROM blog_sources
            UNION ALL
            SELECT 'category', id, name, is_active FROM category_master
            ORDER BY kind, name") as $r) {
            if ($r['kind'] === 'source') {
                $sources[] = $r;
            } else {
                $categories[] = $r;
            }
        }

        $this->render('articles/index', [
            'rows'       => $rows,
            'page'       => $page,
            'filters'    => $filters,
            'sort'       => $sort,
            'dir'        => strtolower($dir),
            'sources'    => $sources,
            'categories' => $categories,
            'filtered'   => $this->isFiltered($filters),
            'bulkCap'    => self::BULK_CAP,
        ], 'Articles', 'articles');
    }

    /** Read and normalise every list parameter. Invalid values become "unset". */
    private function filters(): array
    {
        $date = static function (string $key): string {
            $v = $_GET[$key] ?? '';
            if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
                return '';
            }
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : '';
        };
        $flag = static function (string $key): string {
            $v = $_GET[$key] ?? '';
            return is_string($v) && in_array($v, ['0', '1'], true) ? $v : '';
        };

        $q = $_GET['q'] ?? '';
        $q = is_string($q) ? trim(mb_substr($q, 0, 200)) : '';

        $sort = $_GET['sort'] ?? '';
        $dir  = $_GET['dir'] ?? '';

        return [
            'q'           => $q,
            'source_id'   => $this->intParam('source_id'),
            'category_id' => $this->intParam('category_id'),
            'from'        => $date('from'),
            'to'          => $date('to'),
            'has_image'   => $flag('has_image'),
            'undated'     => $flag('undated'),
            'sort'        => is_string($sort) ? $sort : '',
            'dir'         => is_string($dir) && $dir === 'asc' ? 'asc' : 'desc',
        ];
    }

    private function isFiltered(array $f): bool
    {
        return $f['q'] !== '' || $f['source_id'] !== null || $f['category_id'] !== null
            || $f['from'] !== '' || $f['to'] !== '' || $f['has_image'] !== '' || $f['undated'] !== '';
    }

    /** @return array{0: string, 1: array} WHERE clause (may be empty) and its parameters. */
    private function whereClause(array $f): array
    {
        $conds  = [];
        $params = [];

        if ($f['q'] !== '') {
            // A literal % or _ in the search box should match itself, not
            // act as a wildcard. ILIKE's default escape is backslash.
            $like = '%' . addcslashes($f['q'], '\\%_') . '%';
            $conds[]  = '(a.title ILIKE ? OR a.excerpt ILIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($f['source_id'] !== null) {
            $conds[]  = 'a.blog_source_id = ?';
            $params[] = $f['source_id'];
        }
        if ($f['category_id'] !== null) {
            $conds[]  = 'a.category_id = ?';
            $params[] = $f['category_id'];
        }
        if ($f['from'] !== '') {
            $conds[]  = 'a.published_at >= ?::date';
            $params[] = $f['from'];
        }
        if ($f['to'] !== '') {
            // Inclusive of the whole day named.
            $conds[]  = "a.published_at < ?::date + INTERVAL '1 day'";
            $params[] = $f['to'];
        }
        if ($f['has_image'] === '1') {
            $conds[] = "(a.image_url IS NOT NULL AND a.image_url <> '')";
        } elseif ($f['has_image'] === '0') {
            $conds[] = "(a.image_url IS NULL OR a.image_url = '')";
        }
        if ($f['undated'] === '1') {
            $conds[] = 'a.published_at IS NULL';
        } elseif ($f['undated'] === '0') {
            $conds[] = 'a.published_at IS NOT NULL';
        }

        return [$conds === [] ? '' : 'WHERE ' . implode(' AND ', $conds), $params];
    }

    /* ---------------------------------------------------------------- */
    /*  detail                                                           */
    /* ---------------------------------------------------------------- */

    /** GET /articles/view?id= */
    public function view(Request $request): void
    {
        $this->admin();

        $id = $this->intParam('id');
        $a  = $id === null ? null : Db::one(self::DETAIL_SQL, [$id]);
        if ($a === null) {
            $this->redirect('/articles', 'No such article.', 'bad');
            return;
        }

        $this->render('articles/view', ['a' => $a], 'Article', 'articles');
    }

    /** GET /articles/delete-confirm?id= */
    public function deleteConfirm(Request $request): void
    {
        $this->admin();

        $id = $this->intParam('id');
        $a  = $id === null ? null : Db::one(self::DETAIL_SQL, [$id]);
        if ($a === null) {
            $this->redirect('/articles', 'No such article.', 'bad');
            return;
        }

        $this->render('articles/delete_confirm', ['a' => $a], 'Delete article', 'articles');
    }

    /* ---------------------------------------------------------------- */
    /*  mutations                                                        */
    /* ---------------------------------------------------------------- */

    /** POST /articles/delete  {id, confirm=1} */
    public function delete(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id = $this->intParam('id', $_POST);
        if ($id === null) {
            $this->redirect('/articles', 'No such article.', 'bad');
            return;
        }
        if (($_POST['confirm'] ?? '') !== '1') {
            // Not confirmed: back to the page that asks.
            $this->redirect('/articles/delete-confirm?id=' . $id, 'Please confirm the deletion.', 'warn');
            return;
        }

        $before = Db::one(self::DETAIL_SQL, [$id]);
        if ($before === null) {
            $this->redirect('/articles', 'No such article.', 'bad');
            return;
        }

        Db::exec('DELETE FROM articles WHERE id = ?', [$id]);

        Audit::record($admin['id'], $admin['email'], 'article.delete', 'articles', (string) $id,
            $this->snapshot($before), null);

        $this->redirect('/articles', 'Deleted "' . $this->short((string) $before['title']) . '" and '
            . (int) $before['events_total'] . ' event(s), ' . (int) $before['seen_readers'] . ' seen record(s).');
    }

    /** POST /articles/bulk-delete  {ids[], confirm=1} */
    public function bulkDelete(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $ids = $this->postedIds();
        if ($ids === []) {
            $this->redirect('/articles', 'Select at least one article first.', 'warn');
            return;
        }
        if (count($ids) > self::BULK_CAP) {
            $this->redirect('/articles', 'At most ' . self::BULK_CAP . ' articles can be deleted at once ('
                . count($ids) . ' were selected).', 'bad');
            return;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));

        // The BEFORE snapshot, which also tells us which ids really exist.
        // Title and counts only: the full excerpt of 200 rows does not
        // belong in one audit record.
        $rows = Db::all("
            SELECT a.id, a.title, a.blog_source_id, a.category_id, a.article_url,
                   to_char(a.published_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS published_fmt,
                   b.blog_name,
                   (SELECT count(*) FROM article_events e WHERE e.article_id = a.id)     AS events_total,
                   (SELECT count(*) FROM user_seen_articles s WHERE s.article_id = a.id) AS seen_readers
              FROM articles a
              JOIN blog_sources b ON b.id = a.blog_source_id
             WHERE a.id IN ($in)
             ORDER BY a.published_at DESC NULLS LAST, a.id DESC", $ids);

        if ($rows === []) {
            $this->redirect('/articles', 'None of the selected articles exist any more.', 'warn');
            return;
        }

        $found  = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $events = array_sum(array_map(static fn(array $r): int => (int) $r['events_total'], $rows));
        $seen   = array_sum(array_map(static fn(array $r): int => (int) $r['seen_readers'], $rows));

        if (($_POST['confirm'] ?? '') !== '1') {
            $this->render('articles/bulk_confirm', [
                'rows'    => $rows,
                'ids'     => $found,
                'missing' => count($ids) - count($found),
                'events'  => $events,
                'seen'    => $seen,
            ], 'Delete ' . count($found) . ' articles', 'articles');
            return;
        }

        $in = implode(',', array_fill(0, count($found), '?'));
        $deleted = Db::transaction(static fn() => Db::exec("DELETE FROM articles WHERE id IN ($in)", $found));

        Audit::record($admin['id'], $admin['email'], 'article.bulk_delete', 'articles',
            count($found) . ' rows',
            [
                'ids'    => $found,
                'count'  => $deleted,
                'events' => $events,
                'seen'   => $seen,
                'titles' => array_map(static fn(array $r): string => (string) $r['title'], $rows),
            ],
            null);

        $this->redirect('/articles', "Deleted {$deleted} article(s), {$events} event(s) and {$seen} seen record(s).");
    }

    /* ---------------------------------------------------------------- */
    /*  helpers                                                          */
    /* ---------------------------------------------------------------- */

    /** Distinct positive integers from ids[], in the order posted. Junk is dropped. */
    private function postedIds(): array
    {
        $raw = $_POST['ids'] ?? [];
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_int($v) && $v > 0) {
                $out[$v] = $v;
            } elseif (is_string($v) && ctype_digit($v) && (int) $v > 0) {
                $out[(int) $v] = (int) $v;
            }
        }
        return array_values($out);
    }

    /** The article row without the derived display columns, for the audit log. */
    private function snapshot(array $row): array
    {
        $keep = ['id', 'guid', 'title', 'excerpt', 'article_url', 'image_url', 'author',
                 'published_at', 'blog_source_id', 'category_id', 'fetched_at',
                 'blog_name', 'category_name', 'seen_readers', 'impressions', 'taps', 'other_events'];
        return array_intersect_key($row, array_flip($keep));
    }

    private function short(string $s, int $max = 60): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
