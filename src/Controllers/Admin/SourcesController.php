<?php
/**
 * SourcesController — blog_sources and their category mapping.
 *
 * Replaces the list/toggle pair that lived in AdminController. Everything a
 * source needs over its life is here: add (with the feed fetched and parsed
 * before a row is written, so a dead URL never reaches the nightly job),
 * edit, re-map categories, disable/reactivate, and delete with the cascade
 * spelled out first.
 *
 * Feed validation reuses jobs/feedlib.php. The job and this form must agree
 * on what a usable feed is, otherwise the dashboard accepts a source that
 * the nightly run treats as broken from its first night.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Admin\Page;
use App\Admin\Paginator;
use App\Db;
use App\Request;
use App\Services\Audit;
use App\Services\Settings;
use PDOException;
use Throwable;

final class SourcesController extends Page
{
    /** Sort keys the list accepts, mapped to the expression actually used. */
    private const SORTS = [
        'name'     => 'lower(s.blog_name)',
        'status'   => 's.status_rank, lower(s.blog_name)',
        'articles' => 's.articles_total',
        'recent'   => 's.articles_30d',
        'failures' => 's.failure_count',
        'fetched'  => 's.last_fetched_at',
    ];

    private const STATUSES = ['healthy', 'no_recent', 'failing', 'deactivated'];

    /**
     * One derived row per source: the counts and the status everything on
     * this module agrees on. Used by the list (both queries) and the detail.
     */
    private const SOURCE_CTE = "
        WITH s AS (
            SELECT b.*,
                   COALESCE(st.total,  0) AS articles_total,
                   COALESCE(st.recent, 0) AS articles_30d,
                   CASE WHEN NOT b.is_active          THEN 'deactivated'
                        WHEN b.failure_count > 0      THEN 'failing'
                        WHEN COALESCE(st.recent,0) = 0 THEN 'no_recent'
                        ELSE 'healthy' END            AS status,
                   CASE WHEN NOT b.is_active          THEN 3
                        WHEN b.failure_count > 0      THEN 2
                        WHEN COALESCE(st.recent,0) = 0 THEN 1
                        ELSE 0 END                    AS status_rank
              FROM blog_sources b
              LEFT JOIN (
                  SELECT blog_source_id,
                         count(*) AS total,
                         count(*) FILTER (WHERE COALESCE(published_at, fetched_at)
                                                > NOW() - INTERVAL '30 days') AS recent
                    FROM articles GROUP BY blog_source_id
              ) st ON st.blog_source_id = b.id
        )";

    /* ---------------------------------------------------------------- */
    /*  list                                                             */
    /* ---------------------------------------------------------------- */

    /** GET /sources */
    public function index(Request $request): void
    {
        $this->admin();

        $q      = trim((string) ($_GET['q'] ?? ''));
        $status = (string) ($_GET['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) {
            $status = '';
        }
        $sort = (string) ($_GET['sort'] ?? 'status');
        if (!isset(self::SORTS[$sort])) {
            $sort = 'status';
        }
        $dir = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        // The filter, once, bound identically in both queries.
        $where  = "(?::text = '' OR s.blog_name ILIKE ? OR s.feed_url ILIKE ?)
                   AND (?::text = '' OR s.status = ?::text)";
        $like   = '%' . addcslashes($q, '%_\\') . '%';
        $params = [$q, $like, $like, $status, $status];

        /*
         * ONE count query. It returns the filtered total for the paginator
         * AND the unfiltered breakdown for the tiles, so the tiles do not
         * cost a third round trip.
         */
        $counts = Db::one(self::SOURCE_CTE . "
            SELECT count(*) FILTER (WHERE $where)                 AS total,
                   count(*)                                       AS all_total,
                   count(*) FILTER (WHERE s.status = 'healthy')     AS healthy,
                   count(*) FILTER (WHERE s.status = 'no_recent')   AS no_recent,
                   count(*) FILTER (WHERE s.status = 'failing')     AS failing,
                   count(*) FILTER (WHERE s.status = 'deactivated') AS deactivated
              FROM s", $params) ?? [];

        $pager = new Paginator(50);
        $page  = $pager->describe((int) ($counts['total'] ?? 0));

        $rows = Db::all(self::SOURCE_CTE . "
            SELECT s.id, s.blog_name, s.blog_url, s.feed_url, s.is_active, s.failure_count,
                   s.last_fetched_at, s.last_error, s.status,
                   s.articles_total, s.articles_30d,
                   (SELECT string_agg(c.name, ', ' ORDER BY c.display_order, c.name)
                      FROM category_blog_map m
                      JOIN category_master c ON c.id = m.category_id
                     WHERE m.blog_source_id = s.id)                 AS categories
              FROM s
             WHERE $where
             ORDER BY " . self::SORTS[$sort] . " $dir NULLS LAST, s.id
             LIMIT ? OFFSET ?",
            [...$params, $pager->perPage, $pager->offset]);

        $this->render('sources/index', [
            'rows'        => $rows,
            'counts'      => $counts,
            'page'        => $page,
            'q'           => $q,
            'status'      => $status,
            'sort'        => $sort,
            'dir'         => strtolower($dir),
            'maxFailures' => Settings::int('feed.max_failures', 5),
        ], 'Sources', 'sources');
    }

    /* ---------------------------------------------------------------- */
    /*  detail                                                           */
    /* ---------------------------------------------------------------- */

    /** GET /sources/view?id= */
    public function view(Request $request): void
    {
        $this->admin();

        $id  = $this->intParam('id');
        $src = $id === null ? null : Db::one(self::SOURCE_CTE . '
            SELECT s.*,
                   (SELECT count(*) FROM user_hidden_sources h WHERE h.source_id = s.id) AS hidden_by,
                   (SELECT count(*) FROM articles a
                     WHERE a.blog_source_id = s.id AND a.fetched_at::date = CURRENT_DATE) AS today
              FROM s WHERE s.id = ?', [$id]);
        if ($src === null) {
            $this->redirect('/sources', 'No such source.', 'bad');
            return;
        }

        $mappings = Db::all('
            SELECT c.id, c.name, c.is_active, m.feed_url_override
              FROM category_blog_map m
              JOIN category_master c ON c.id = m.category_id
             WHERE m.blog_source_id = ?
             ORDER BY c.display_order, c.name', [$id]);

        // Every one of the last 14 days, zero-filled, oldest first so the
        // bars read left to right. A missing day must show as a gap, not
        // vanish — a feed that fetched nothing for three days is the point.
        $ingest = [];
        for ($d = 13; $d >= 0; $d--) {
            $ingest[gmdate('m-d', strtotime("-$d days"))] = 0;
        }
        foreach (Db::all("
            SELECT fetched_at::date AS d, count(*) AS n
              FROM articles
             WHERE blog_source_id = ? AND fetched_at > NOW() - INTERVAL '14 days'
             GROUP BY 1 ORDER BY 1", [$id]) as $r) {
            $key = substr((string) $r['d'], 5);
            if (array_key_exists($key, $ingest)) {
                $ingest[$key] = (int) $r['n'];
            }
        }

        $recent = Db::all('
            SELECT a.id, a.title, a.published_at, a.fetched_at, a.article_url, c.name AS category
              FROM articles a
              JOIN category_master c ON c.id = a.category_id
             WHERE a.blog_source_id = ?
             ORDER BY COALESCE(a.published_at, a.fetched_at) DESC, a.id DESC
             LIMIT 10', [$id]);

        $this->render('sources/view', [
            'src'         => $src,
            'mappings'    => $mappings,
            'ingest'      => $ingest,
            'recent'      => $recent,
            'maxFailures' => Settings::int('feed.max_failures', 5),
        ], $src['blog_name'], 'sources');
    }

    /* ---------------------------------------------------------------- */
    /*  create                                                           */
    /* ---------------------------------------------------------------- */

    /** GET /sources/new */
    public function newForm(Request $request): void
    {
        $this->admin();

        $this->renderForm(null, [
            'blog_name'  => '',
            'blog_url'   => '',
            'feed_url'   => '',
            'categories' => [],
            'override'   => [],
        ], []);
    }

    /** POST /sources/create */
    public function create(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $in     = $this->formInput();
        $errors = $this->validateForm($in);

        $feed = null;
        if ($errors === []) {
            try {
                $feed = $this->checkFeed($in['feed_url']);
                $in['feed_url'] = $feed['final_url'];
            } catch (Throwable $e) {
                $errors['feed_url'] = 'Feed check failed: ' . $e->getMessage();
            }
        }

        if ($errors === []) {
            $dup = Db::one('SELECT id, blog_name FROM blog_sources WHERE feed_url = ?', [$in['feed_url']]);
            if ($dup !== null) {
                $errors['feed_url'] = $this->dupMessage($dup);
            }
        }

        if ($errors !== []) {
            $this->renderForm(null, $in, $errors);
            return;
        }

        try {
            $row = Db::transaction(function () use ($in, $admin): array {
                $row = Db::one('
                    INSERT INTO blog_sources (blog_name, blog_url, feed_url, added_by)
                    VALUES (?, ?, ?, ?)
                    RETURNING *',
                    [$in['blog_name'], $in['blog_url'], $in['feed_url'], $admin['email']]);
                $this->insertMappings((int) $row['id'], $in['categories'], $in['override']);
                return $row;
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {          // lost the race to a duplicate
                $dup = Db::one('SELECT id, blog_name FROM blog_sources WHERE feed_url = ?', [$in['feed_url']]);
                $this->renderForm(null, $in, ['feed_url' => $dup ? $this->dupMessage($dup) : 'That feed URL already exists.']);
                return;
            }
            throw $e;
        }

        $after = $row + ['categories' => $this->mappingSnapshot((int) $row['id'])];
        Audit::record($admin['id'], $admin['email'], 'source.create', 'blog_sources',
            (string) $row['id'], null, $after);

        $title = $feed['title'] !== '' ? $feed['title'] : $in['blog_name'];
        $this->redirect('/sources/view?id=' . (int) $row['id'], sprintf(
            'Added %s. The feed "%s" returned %d item%s; the first sync will pick it up tonight.',
            $in['blog_name'], $title, $feed['items'], $feed['items'] === 1 ? '' : 's'));
    }

    /* ---------------------------------------------------------------- */
    /*  edit                                                             */
    /* ---------------------------------------------------------------- */

    /** GET /sources/edit?id= */
    public function edit(Request $request): void
    {
        $this->admin();

        $id  = $this->intParam('id');
        $src = $id === null ? null : Db::one('SELECT * FROM blog_sources WHERE id = ?', [$id]);
        if ($src === null) {
            $this->redirect('/sources', 'No such source.', 'bad');
            return;
        }

        $categories = [];
        $override   = [];
        foreach (Db::all('SELECT category_id, feed_url_override FROM category_blog_map WHERE blog_source_id = ?', [$id]) as $m) {
            $categories[] = (int) $m['category_id'];
            if ($m['feed_url_override'] !== null && $m['feed_url_override'] !== '') {
                $override[(int) $m['category_id']] = (string) $m['feed_url_override'];
            }
        }

        $this->renderForm($src, [
            'blog_name'  => (string) $src['blog_name'],
            'blog_url'   => (string) $src['blog_url'],
            'feed_url'   => (string) $src['feed_url'],
            'categories' => $categories,
            'override'   => $override,
        ], []);
    }

    /** POST /sources/update */
    public function update(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id     = $this->intParam('id', $_POST);
        $before = $id === null ? null : Db::one('SELECT * FROM blog_sources WHERE id = ?', [$id]);
        if ($before === null) {
            $this->redirect('/sources', 'No such source.', 'bad');
            return;
        }
        $before['categories'] = $this->mappingSnapshot($id);

        $in     = $this->formInput();
        $errors = $this->validateForm($in);

        // Only a CHANGED feed URL is re-fetched. Re-checking an unchanged one
        // would turn "fix a typo in the name" into a 15-second outbound call
        // and would refuse the edit whenever the publisher happens to be down.
        $feed    = null;
        $changed = $in['feed_url'] !== (string) $before['feed_url'];
        if ($errors === [] && $changed) {
            try {
                $feed = $this->checkFeed($in['feed_url']);
                $in['feed_url'] = $feed['final_url'];
            } catch (Throwable $e) {
                $errors['feed_url'] = 'Feed check failed: ' . $e->getMessage();
            }
            if ($errors === [] && $in['feed_url'] !== (string) $before['feed_url']) {
                $dup = Db::one('SELECT id, blog_name FROM blog_sources WHERE feed_url = ? AND id <> ?', [$in['feed_url'], $id]);
                if ($dup !== null) {
                    $errors['feed_url'] = $this->dupMessage($dup);
                }
            }
        }

        if ($errors !== []) {
            $this->renderForm($before, $in, $errors);
            return;
        }

        try {
            Db::transaction(function () use ($id, $in): void {
                Db::exec('UPDATE blog_sources SET blog_name = ?, blog_url = ?, feed_url = ? WHERE id = ?',
                    [$in['blog_name'], $in['blog_url'], $in['feed_url'], $id]);
                // Replace, not merge: what the form shows is what the row
                // has afterwards, with no hidden leftovers.
                Db::exec('DELETE FROM category_blog_map WHERE blog_source_id = ?', [$id]);
                $this->insertMappings($id, $in['categories'], $in['override']);
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                $dup = Db::one('SELECT id, blog_name FROM blog_sources WHERE feed_url = ? AND id <> ?', [$in['feed_url'], $id]);
                $this->renderForm($before, $in, ['feed_url' => $dup ? $this->dupMessage($dup) : 'That feed URL already exists.']);
                return;
            }
            throw $e;
        }

        $after = Db::one('SELECT * FROM blog_sources WHERE id = ?', [$id]) ?? [];
        $after['categories'] = $this->mappingSnapshot($id);
        Audit::record($admin['id'], $admin['email'], 'source.update', 'blog_sources', (string) $id, $before, $after);

        $msg = 'Saved ' . $in['blog_name'] . '.';
        if ($feed !== null) {
            $msg .= sprintf(' New feed "%s" returned %d item%s.',
                $feed['title'] !== '' ? $feed['title'] : $in['feed_url'],
                $feed['items'], $feed['items'] === 1 ? '' : 's');
        }
        $this->redirect('/sources/view?id=' . $id, $msg);
    }

    /* ---------------------------------------------------------------- */
    /*  toggle                                                           */
    /* ---------------------------------------------------------------- */

    /** POST /sources/toggle  {id, back?} */
    public function toggle(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id     = $this->intParam('id', $_POST);
        $before = $id === null ? null : Db::one('SELECT * FROM blog_sources WHERE id = ?', [$id]);
        if ($before === null) {
            $this->redirect('/sources', 'No such source.', 'bad');
            return;
        }

        $wasActive = self::truthy($before['is_active']);
        $enable    = !$wasActive;

        /*
         * Reactivating also clears failure_count and last_error. Leaving them
         * would mean the source is one bad night away from being deactivated
         * again, which is not what "reactivate" means to the person clicking.
         */
        // pdo_pgsql binds a PHP false as '' — not a boolean to Postgres. Send
        // the literal words and cast; the same value is needed three times
        // and a placeholder cannot repeat with emulation off.
        $flag = $enable ? 'true' : 'false';
        Db::exec('
            UPDATE blog_sources
               SET is_active     = ?::boolean,
                   failure_count = CASE WHEN ?::boolean THEN 0    ELSE failure_count END,
                   last_error    = CASE WHEN ?::boolean THEN NULL ELSE last_error    END
             WHERE id = ?',
            [$flag, $flag, $flag, $id]);

        $after = Db::one('SELECT * FROM blog_sources WHERE id = ?', [$id]);
        Audit::record($admin['id'], $admin['email'],
            $enable ? 'source.enable' : 'source.disable',
            'blog_sources', (string) $id, $before, $after);

        $name = (string) $before['blog_name'];
        $back = ($_POST['back'] ?? '') === 'view' ? '/sources/view?id=' . $id : '/sources';
        $this->redirect($back,
            $enable ? "Reactivated {$name} and cleared its failure count." : "Disabled {$name}. It will be skipped by the nightly sync.",
            $enable ? 'ok' : 'warn');
    }

    /* ---------------------------------------------------------------- */
    /*  delete                                                           */
    /* ---------------------------------------------------------------- */

    /** GET /sources/delete-confirm?id= */
    public function deleteConfirm(Request $request): void
    {
        $this->admin();

        $id  = $this->intParam('id');
        $src = $id === null ? null : $this->cascadeCounts($id);
        if ($src === null) {
            $this->redirect('/sources', 'No such source.', 'bad');
            return;
        }

        $this->render('sources/delete', ['src' => $src], 'Delete ' . $src['blog_name'], 'sources');
    }

    /** POST /sources/delete  {id, confirm=1} */
    public function delete(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id = $this->intParam('id', $_POST);
        if ($id === null) {
            $this->redirect('/sources', 'No such source.', 'bad');
            return;
        }
        if (($_POST['confirm'] ?? '') !== '1') {
            $this->redirect('/sources/delete-confirm?id=' . $id, 'Please confirm the deletion.', 'warn');
            return;
        }

        $before = Db::transaction(function () use ($id): ?array {
            $before = $this->cascadeCounts($id);
            if ($before === null) {
                return null;
            }
            // Everything else — articles, their events and seen rows, the
            // category mapping, hidden-source rows, per-reader stats — goes
            // with the row through ON DELETE CASCADE. One statement.
            Db::exec('DELETE FROM blog_sources WHERE id = ?', [$id]);
            return $before;
        });

        if ($before === null) {
            $this->redirect('/sources', 'No such source.', 'bad');
            return;
        }

        Audit::record($admin['id'], $admin['email'], 'source.delete', 'blog_sources', (string) $id, $before, null);

        $this->redirect('/sources', sprintf(
            'Deleted %s with %s article%s and %d category mapping%s.',
            $before['blog_name'],
            number_format((int) $before['articles']), (int) $before['articles'] === 1 ? '' : 's',
            (int) $before['mappings'], (int) $before['mappings'] === 1 ? '' : 's'), 'warn');
    }

    /* ---------------------------------------------------------------- */
    /*  validate                                                         */
    /* ---------------------------------------------------------------- */

    /** GET /sources/validate?feed_url= — fetch + parse, show what came back. */
    public function validate(Request $request): void
    {
        $this->admin();

        $url    = trim((string) ($_GET['feed_url'] ?? ''));
        $result = null;
        $error  = null;

        if ($url === '') {
            $error = 'No feed URL given.';
        } elseif (!self::isHttpUrl($url)) {
            $error = 'The feed URL must start with http:// or https://.';
        } else {
            try {
                $result = $this->checkFeed($url);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $this->render('sources/validate', [
            'url'    => $url,
            'result' => $result,
            'error'  => $error,
            'back'   => $this->safeBack((string) ($_GET['back'] ?? '')),
        ], 'Check feed', 'sources');
    }

    /* ---------------------------------------------------------------- */
    /*  helpers                                                          */
    /* ---------------------------------------------------------------- */

    /**
     * Download and parse a feed exactly as the nightly job would.
     *
     * @return array{final_url:string, title:string, items:int, first:string[]}
     * @throws \RuntimeException from feed_fetch / feed_parse
     */
    private function checkFeed(string $url): array
    {
        require_once __DIR__ . '/../../../jobs/feedlib.php';

        $fetched = feed_fetch($url);
        $parsed  = feed_parse($fetched['body']);

        $first = [];
        foreach (array_slice($parsed['items'], 0, 3) as $item) {
            $first[] = (string) $item['title'];
        }

        return [
            'final_url' => (string) $fetched['final_url'],
            'title'     => (string) $parsed['title'],
            'items'     => count($parsed['items']),
            'first'     => $first,
            'malformed' => (bool) $parsed['malformed'],
        ];
    }

    private static function isHttpUrl(string $url): bool
    {
        if (mb_strlen($url) > 2000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return $scheme === 'http' || $scheme === 'https';
    }

    /** The raw form, normalised: trimmed strings, int category ids, override keyed by id. */
    private function formInput(): array
    {
        $categories = [];
        foreach ((array) ($_POST['categories'] ?? []) as $c) {
            if ((is_string($c) && ctype_digit($c)) || is_int($c)) {
                $categories[(int) $c] = (int) $c;
            }
        }
        $override = [];
        foreach ((array) ($_POST['override'] ?? []) as $k => $v) {
            $k = (string) $k;
            if (ctype_digit($k) && is_string($v) && trim($v) !== '') {
                $override[(int) $k] = trim($v);
            }
        }
        $str = static fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';

        return [
            'blog_name'  => $str('blog_name'),
            'blog_url'   => $str('blog_url'),
            'feed_url'   => $str('feed_url'),
            'categories' => array_values($categories),
            'override'   => $override,
        ];
    }

    /** @return array<string,string> field => message */
    private function validateForm(array $in): array
    {
        $errors = [];
        if ($in['blog_name'] === '' || mb_strlen($in['blog_name']) > 200) {
            $errors['blog_name'] = 'Name is required (max 200 characters).';
        }
        if (!self::isHttpUrl($in['blog_url'])) {
            $errors['blog_url'] = 'Blog URL must be a full http:// or https:// address.';
        }
        if (!self::isHttpUrl($in['feed_url'])) {
            $errors['feed_url'] = 'Feed URL must be a full http:// or https:// address.';
        }
        if ($in['categories'] === []) {
            $errors['categories'] = 'Pick at least one category.';
        } else {
            // Ids come from checkboxes, but a checkbox is only a suggestion
            // to the browser. Verify every one exists — ONE query.
            $known = array_map('intval', array_column(Db::all(
                'SELECT id FROM category_master WHERE id = ANY(?::int[])',
                ['{' . implode(',', $in['categories']) . '}']), 'id'));
            if (count($known) !== count($in['categories'])) {
                $errors['categories'] = 'One of the selected categories no longer exists.';
            }
        }
        foreach ($in['override'] as $cid => $url) {
            if (!in_array($cid, $in['categories'], true)) {
                continue;                  // an override for an unticked category is ignored
            }
            if (!self::isHttpUrl($url)) {
                $errors['override'] = 'A feed override must be a full http:// or https:// address.';
                break;
            }
        }
        return $errors;
    }

    /** Insert the category rows for a source. Runs inside the caller's transaction. */
    private function insertMappings(int $sourceId, array $categories, array $override): void
    {
        if ($categories === []) {
            return;
        }
        $marks  = [];
        $params = [];
        foreach ($categories as $cid) {
            $marks[]  = '(?, ?, ?)';
            $params[] = $cid;
            $params[] = $sourceId;
            $params[] = $override[$cid] ?? null;
        }
        Db::exec('INSERT INTO category_blog_map (category_id, blog_source_id, feed_url_override) VALUES '
                 . implode(', ', $marks), $params);
    }

    /** The mapping as it is now, for an audit snapshot. */
    private function mappingSnapshot(int $sourceId): array
    {
        return Db::all('
            SELECT m.category_id, c.name, m.feed_url_override
              FROM category_blog_map m
              JOIN category_master c ON c.id = m.category_id
             WHERE m.blog_source_id = ?
             ORDER BY m.category_id', [$sourceId]);
    }

    /** The row plus everything that would go with it. Null when it does not exist. */
    private function cascadeCounts(int $id): ?array
    {
        return Db::one('
            SELECT b.*,
                   (SELECT count(*) FROM articles a WHERE a.blog_source_id = b.id)            AS articles,
                   (SELECT count(*) FROM article_events e
                      JOIN articles a ON a.id = e.article_id WHERE a.blog_source_id = b.id)   AS events,
                   (SELECT count(*) FROM user_seen_articles s
                      JOIN articles a ON a.id = s.article_id WHERE a.blog_source_id = b.id)   AS seen,
                   (SELECT count(*) FROM category_blog_map m WHERE m.blog_source_id = b.id)   AS mappings,
                   (SELECT count(*) FROM user_hidden_sources h WHERE h.source_id = b.id)      AS hidden,
                   (SELECT count(*) FROM user_source_stats u WHERE u.source_id = b.id)        AS reader_stats
              FROM blog_sources b WHERE b.id = ?', [$id]);
    }

    private function dupMessage(array $dup): string
    {
        return sprintf('That feed URL already belongs to "%s" (source #%d).', $dup['blog_name'], (int) $dup['id']);
    }

    /** Only a same-site dashboard path is ever used as a return link. */
    private function safeBack(string $back): string
    {
        return $back !== '' && $back[0] === '/' && !str_starts_with($back, '//') ? $back : '/sources/new';
    }

    private function renderForm(?array $src, array $values, array $errors): void
    {
        $this->render('sources/form', [
            'src'        => $src,
            'values'     => $values,
            'errors'     => $errors,
            'categories' => Db::all('SELECT id, name, icon, is_active FROM category_master ORDER BY display_order, name'),
        ], $src === null ? 'Add source' : 'Edit ' . $src['blog_name'], 'sources');
    }
}
