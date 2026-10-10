<?php
/**
 * CategoriesController — category_master and everything hanging off it.
 *
 * A category is the unit readers subscribe to, the unit blogs are mapped
 * into, and the unit the feed candidate query balances by. Deactivating one
 * therefore reaches three places at once — the topic picker, the feed and
 * the article counts — which is why the toggle states the subscriber count
 * before it goes through, and why delete refuses anything that still has
 * rows hanging off it even though the foreign keys would cascade happily.
 *
 * Routes (all exact-match, detail pages via ?id=):
 *   GET  /categories                 index
 *   GET  /categories/new             newForm
 *   GET  /categories/edit?id=        editForm
 *   POST /categories/create          create
 *   POST /categories/update          update
 *   POST /categories/toggle          toggle
 *   POST /categories/reorder         reorder
 *   GET  /categories/delete-confirm  deleteConfirm
 *   POST /categories/delete          delete
 *   GET  /categories/view?id=        view
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Admin\Page;
use App\Admin\Paginator;
use App\Db;
use App\Request;
use App\Services\Audit;
use PDO;

final class CategoriesController extends Page
{
    private const NAME_MAX = 80;
    private const SLUG_MAX = 100;   // column is VARCHAR(100)
    private const ICON_MAX = 8;     // column is VARCHAR(8); emoji can be 2-7 chars
    private const DESC_MAX = 500;

    /**
     * Sort keys the list accepts. The request supplies a KEY; the SQL comes
     * from here and only here.
     */
    private const SORTS = [
        'order'    => 'c.display_order ASC, c.id ASC',
        'name'     => 'c.name ASC',
        'sources'  => 'sources DESC, c.display_order ASC',
        'articles' => 'articles DESC, c.display_order ASC',
        'readers'  => 'readers DESC, c.display_order ASC',
    ];

    /* ---------------------------------------------------------------- */
    /*  list                                                             */
    /* ---------------------------------------------------------------- */

    /** GET /categories */
    public function index(Request $request): void
    {
        $this->admin();

        $q      = trim((string) ($_GET['q'] ?? ''));
        $status = (string) ($_GET['status'] ?? '');
        $sort   = (string) ($_GET['sort'] ?? 'order');
        if (!array_key_exists($sort, self::SORTS)) {
            $sort = 'order';
        }
        if (!in_array($status, ['', 'active', 'inactive'], true)) {
            $status = '';
        }

        $where  = [];
        $params = [];
        if ($q !== '') {
            $where[]  = '(c.name ILIKE ? OR c.slug ILIKE ?)';
            $like     = '%' . self::escapeLike($q) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($status === 'active') {
            $where[] = 'c.is_active';
        } elseif ($status === 'inactive') {
            $where[] = 'NOT c.is_active';
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $pager = new Paginator(50);

        // ONE count query. The KPI tiles ride along as scalar sub-selects so
        // they do not cost a round trip each.
        $counts = Db::one("
            SELECT (SELECT count(*) FROM category_master c{$whereSql})                    AS total,
                   (SELECT count(*) FROM category_master)                               AS all_n,
                   (SELECT count(*) FROM category_master WHERE is_active)               AS active_n,
                   (SELECT count(*) FROM user_categories)                               AS subs,
                   (SELECT count(*) FROM category_master c
                     WHERE NOT EXISTS (SELECT 1 FROM category_blog_map m
                                        WHERE m.category_id = c.id))                     AS unsourced
        ", $params) ?? ['total' => 0, 'all_n' => 0, 'active_n' => 0, 'subs' => 0, 'unsourced' => 0];

        // ONE page query.
        $rows = Db::all("
            SELECT c.id, c.name, c.slug, c.icon, c.description, c.display_order, c.is_active,
                   (SELECT count(*) FROM category_blog_map m WHERE m.category_id = c.id) AS sources,
                   (SELECT count(*) FROM articles a          WHERE a.category_id = c.id) AS articles,
                   (SELECT count(*) FROM user_categories u   WHERE u.category_id = c.id) AS readers
              FROM category_master c{$whereSql}
             ORDER BY " . self::SORTS[$sort] . '
             LIMIT ? OFFSET ?',
            array_merge($params, [$pager->perPage, $pager->offset]));

        $this->render('categories/index', [
            'rows'   => $rows,
            'pg'     => $pager->describe((int) $counts['total']),
            'kpi'    => $counts,
            'q'      => $q,
            'status' => $status,
            'sort'   => $sort,
            'sorts'  => array_keys(self::SORTS),
        ], 'Categories', 'categories');
    }

    /* ---------------------------------------------------------------- */
    /*  create / edit                                                    */
    /* ---------------------------------------------------------------- */

    /** GET /categories/new */
    public function newForm(Request $request): void
    {
        $this->admin();

        $next = (int) (Db::scalar('SELECT COALESCE(max(display_order), 0) + 1 FROM category_master') ?? 1);

        $this->render('categories/form', [
            'mode'   => 'new',
            'cat'    => ['id' => null, 'name' => '', 'slug' => '', 'description' => '',
                         'icon' => '', 'display_order' => $next, 'is_active' => true],
            'errors' => [],
        ], 'New category', 'categories');
    }

    /** GET /categories/edit?id= */
    public function editForm(Request $request): void
    {
        $this->admin();

        $cat = $this->find($this->intParam('id'));
        if ($cat === null) {
            $this->redirect('/categories', 'No such category.', 'bad');
            return;
        }
        $cat['is_active'] = self::truthy($cat['is_active']);

        $this->render('categories/form', [
            'mode'   => 'edit',
            'cat'    => $cat,
            'errors' => [],
        ], 'Edit ' . $cat['name'], 'categories');
    }

    /** POST /categories/create */
    public function create(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        [$clean, $errors] = $this->validate(null);
        if ($errors !== []) {
            $this->render('categories/form', [
                'mode' => 'new', 'cat' => $clean + ['id' => null], 'errors' => $errors,
            ], 'New category', 'categories');
            return;
        }

        $id = (int) Db::scalar(
            'INSERT INTO category_master (name, slug, description, icon, display_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            [$clean['name'], $clean['slug'], $clean['description'], $clean['icon'],
             $clean['display_order'], self::pgBool($clean['is_active'])]
        );

        Audit::record($admin['id'], $admin['email'], 'category.create', 'category_master',
            (string) $id, null, $this->find($id));

        $this->redirect('/categories/view?id=' . $id, "Created {$clean['name']}.", 'ok');
    }

    /** POST /categories/update */
    public function update(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id     = $this->intParam('id', $_POST);
        $before = $this->find($id);
        if ($before === null) {
            $this->redirect('/categories', 'No such category.', 'bad');
            return;
        }

        [$clean, $errors] = $this->validate($id);
        if ($errors !== []) {
            $this->render('categories/form', [
                'mode' => 'edit', 'cat' => $clean + ['id' => $id], 'errors' => $errors,
            ], 'Edit ' . $before['name'], 'categories');
            return;
        }

        Db::exec(
            'UPDATE category_master
                SET name = ?, slug = ?, description = ?, icon = ?, display_order = ?, is_active = ?
              WHERE id = ?',
            [$clean['name'], $clean['slug'], $clean['description'], $clean['icon'],
             $clean['display_order'], self::pgBool($clean['is_active']), $id]
        );

        Audit::record($admin['id'], $admin['email'], 'category.update', 'category_master',
            (string) $id, $before, $this->find($id));

        $this->redirect('/categories/view?id=' . $id, "Saved {$clean['name']}.", 'ok');
    }

    /**
     * Read and validate the form fields from POST.
     *
     * Returns the cleaned values (always, so the form can be re-rendered
     * with what was typed) and a list of human-readable errors. $selfId
     * excludes the row being edited from the slug-uniqueness check.
     *
     * @return array{0: array, 1: string[]}
     */
    private function validate(?int $selfId): array
    {
        $errors = [];
        $str = static fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';

        $name = $str('name');
        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors[] = 'Name is too long (max ' . self::NAME_MAX . ' characters).';
        }

        $slug = $str('slug');
        if ($slug === '') {
            $slug = self::slugify($name);
        } else {
            $slug = strtolower($slug);
        }
        if ($slug === '' && $name !== '') {
            $errors[] = 'Could not derive a slug from that name — please type one.';
        } elseif ($slug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $errors[] = 'Slug may only contain lowercase letters, digits and single dashes.';
        } elseif (mb_strlen($slug) > self::SLUG_MAX) {
            $errors[] = 'Slug is too long (max ' . self::SLUG_MAX . ' characters).';
        } elseif ($slug !== '') {
            $clash = Db::one('SELECT id, name FROM category_master WHERE slug = ? AND id <> ?',
                             [$slug, $selfId ?? 0]);
            if ($clash !== null) {
                $errors[] = "Slug \"{$slug}\" is already used by {$clash['name']} (#{$clash['id']}).";
            }
        }

        $description = $str('description');
        if (mb_strlen($description) > self::DESC_MAX) {
            $errors[] = 'Description is too long (max ' . self::DESC_MAX . ' characters).';
        }

        $icon = $str('icon');
        if (mb_strlen($icon) > self::ICON_MAX) {
            $errors[] = 'Icon is too long (max ' . self::ICON_MAX . ' characters — one emoji).';
        }

        $orderRaw = $str('display_order');
        if ($orderRaw === '') {
            $order = (int) (Db::scalar('SELECT COALESCE(max(display_order), 0) + 1 FROM category_master') ?? 1);
        } elseif (ctype_digit($orderRaw) && (int) $orderRaw <= 100000) {
            $order = (int) $orderRaw;
        } else {
            $errors[] = 'Display order must be a whole number from 0 to 100000.';
            $order    = 0;
        }

        $isActive = isset($_POST['is_active']) && (string) $_POST['is_active'] !== '' && (string) $_POST['is_active'] !== '0';

        return [[
            'name'          => $name,
            'slug'          => $slug,
            'description'   => $description === '' ? null : $description,
            'icon'          => $icon === '' ? null : $icon,
            'display_order' => $order,
            'is_active'     => $isActive,
        ], $errors];
    }

    /** "Health & Fitness" → "health-fitness". */
    public static function slugify(string $name): string
    {
        $s = $name;
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false) {
                $s = $t;
            }
        }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    /* ---------------------------------------------------------------- */
    /*  toggle / reorder                                                 */
    /* ---------------------------------------------------------------- */

    /** POST /categories/toggle {id} */
    public function toggle(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id     = $this->intParam('id', $_POST);
        $before = $this->find($id);
        if ($before === null) {
            $this->redirect('/categories', 'No such category.', 'bad');
            return;
        }

        $wasActive = self::truthy($before['is_active']);
        Db::exec('UPDATE category_master SET is_active = ? WHERE id = ?', [self::pgBool(!$wasActive), $id]);

        Audit::record($admin['id'], $admin['email'],
            $wasActive ? 'category.deactivate' : 'category.activate',
            'category_master', (string) $id, $before, $this->find($id));

        $name = (string) $before['name'];
        if ($wasActive) {
            $readers = (int) Db::scalar('SELECT count(*) FROM user_categories WHERE category_id = ?', [$id]);
            $this->redirect('/categories',
                "Deactivated {$name}. It is now hidden from {$readers} subscribed reader(s) and from the feed; "
                . 'their subscriptions are kept and come back if you reactivate it.', 'warn');
        } else {
            $this->redirect('/categories', "Activated {$name}.", 'ok');
        }
    }

    /**
     * POST /categories/reorder {id, dir=up|down}
     *
     * Swaps display_order with the neighbour in one transaction.
     *
     * No audit entry, deliberately: reordering is cosmetic, happens in
     * bursts of five clicks, and would drown the real changes in the log.
     * Edits to the field itself go through update(), which does audit.
     */
    public function reorder(Request $request): void
    {
        $this->admin();
        $this->csrf();

        $id  = $this->intParam('id', $_POST);
        $dir = (string) ($_POST['dir'] ?? '');
        if ($id === null || !in_array($dir, ['up', 'down'], true)) {
            $this->redirect('/categories', 'Bad reorder request.', 'bad');
            return;
        }

        $result = Db::transaction(static function (PDO $db) use ($id, $dir): array {
            /*
             * Renumber 1..N first, touching only rows that are out of
             * sequence. Fresh rows default to display_order 0 and hand-edited
             * values leave gaps and ties; swapping two equal values would be
             * a no-op the operator cannot see. After this, "neighbour" is
             * simply display_order ± 1.
             */
            $db->prepare('
                UPDATE category_master c
                   SET display_order = t.rn
                  FROM (SELECT id, row_number() OVER (ORDER BY display_order, id) AS rn
                          FROM category_master) t
                 WHERE c.id = t.id AND c.display_order <> t.rn')->execute();

            $st = $db->prepare('SELECT id, name, display_order FROM category_master WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $me = $st->fetch();
            if ($me === false) {
                return ['ok' => false, 'msg' => 'No such category.'];
            }

            $target = (int) $me['display_order'] + ($dir === 'up' ? -1 : 1);
            $st = $db->prepare('SELECT id, display_order FROM category_master WHERE display_order = ? FOR UPDATE');
            $st->execute([$target]);
            $other = $st->fetch();
            if ($other === false) {
                return ['ok' => false, 'msg' => $me['name'] . ' is already at the ' . ($dir === 'up' ? 'top' : 'bottom') . '.'];
            }

            $db->prepare('
                UPDATE category_master
                   SET display_order = CASE id WHEN ? THEN ?::int WHEN ? THEN ?::int END
                 WHERE id IN (?, ?)')
               ->execute([$me['id'], $other['display_order'], $other['id'], $me['display_order'],
                          $me['id'], $other['id']]);

            return ['ok' => true, 'msg' => 'Moved ' . $me['name'] . ' ' . $dir . '.'];
        });

        $this->redirect('/categories', $result['msg'], $result['ok'] ? 'ok' : 'warn');
    }

    /* ---------------------------------------------------------------- */
    /*  delete                                                           */
    /* ---------------------------------------------------------------- */

    /** GET /categories/delete-confirm?id= */
    public function deleteConfirm(Request $request): void
    {
        $this->admin();

        $cat = $this->findWithCounts($this->intParam('id'));
        if ($cat === null) {
            $this->redirect('/categories', 'No such category.', 'bad');
            return;
        }

        $this->render('categories/delete-confirm', [
            'cat'      => $cat,
            'blockers' => self::blockers($cat),
        ], 'Delete ' . $cat['name'], 'categories');
    }

    /** POST /categories/delete {id, confirm=1} */
    public function delete(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id  = $this->intParam('id', $_POST);
        $cat = $this->findWithCounts($id);
        if ($cat === null) {
            $this->redirect('/categories', 'No such category.', 'bad');
            return;
        }
        if ((string) ($_POST['confirm'] ?? '') !== '1') {
            $this->redirect('/categories/delete-confirm?id=' . $id, 'Please confirm the deletion.', 'warn');
            return;
        }

        /*
         * Re-checked here, not only on the confirmation page: a sync may have
         * landed articles in the meantime. The foreign keys would cascade,
         * which is exactly why this refuses — a cascade here silently
         * removes articles and readers' subscriptions.
         */
        $blockers = self::blockers($cat);
        if ($blockers !== []) {
            $this->redirect('/categories/delete-confirm?id=' . $id,
                "Cannot delete {$cat['name']}: it still has " . implode(', ', $blockers)
                . '. Move or remove those first.', 'bad');
            return;
        }

        $before = $this->find($id);
        Db::exec('DELETE FROM category_master WHERE id = ?', [$id]);

        Audit::record($admin['id'], $admin['email'], 'category.delete', 'category_master',
            (string) $id, $before, null);

        $this->redirect('/categories', "Deleted {$cat['name']}.", 'ok');
    }

    /** Human-readable list of what still references the category. */
    private static function blockers(array $cat): array
    {
        $out = [];
        if ((int) $cat['articles'] > 0) {
            $out[] = number_format((int) $cat['articles']) . ' article(s)';
        }
        if ((int) $cat['sources'] > 0) {
            $out[] = (int) $cat['sources'] . ' mapped source(s)';
        }
        if ((int) $cat['readers'] > 0) {
            $out[] = (int) $cat['readers'] . ' subscribed reader(s)';
        }
        return $out;
    }

    /* ---------------------------------------------------------------- */
    /*  detail                                                           */
    /* ---------------------------------------------------------------- */

    /** GET /categories/view?id= */
    public function view(Request $request): void
    {
        $this->admin();

        $cat = $this->findWithCounts($this->intParam('id'));
        if ($cat === null) {
            $this->redirect('/categories', 'No such category.', 'bad');
            return;
        }

        $sources = Db::all('
            SELECT b.id, b.blog_name, b.feed_url, b.is_active, b.failure_count, m.feed_url_override,
                   (SELECT count(*) FROM articles a
                     WHERE a.category_id = m.category_id AND a.blog_source_id = b.id) AS articles
              FROM category_blog_map m
              JOIN blog_sources b ON b.id = m.blog_source_id
             WHERE m.category_id = ?
             ORDER BY b.is_active DESC, b.blog_name', [$cat['id']]);

        $articles = Db::all('
            SELECT a.id, a.title, a.article_url, a.author, a.published_at, a.fetched_at, b.blog_name
              FROM articles a
              JOIN blog_sources b ON b.id = a.blog_source_id
             WHERE a.category_id = ?
             ORDER BY COALESCE(a.published_at, a.fetched_at) DESC, a.id DESC
             LIMIT 10', [$cat['id']]);

        $this->render('categories/view', [
            'cat'      => $cat,
            'sources'  => $sources,
            'articles' => $articles,
        ], $cat['name'], 'categories');
    }

    /* ---------------------------------------------------------------- */
    /*  helpers                                                          */
    /* ---------------------------------------------------------------- */

    private function find(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        return Db::one('SELECT id, name, slug, description, icon, display_order, is_active, created_at
                          FROM category_master WHERE id = ?', [$id]);
    }

    private function findWithCounts(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        return Db::one('
            SELECT c.id, c.name, c.slug, c.description, c.icon, c.display_order, c.is_active, c.created_at,
                   (SELECT count(*) FROM category_blog_map m WHERE m.category_id = c.id) AS sources,
                   (SELECT count(*) FROM articles a          WHERE a.category_id = c.id) AS articles,
                   (SELECT count(*) FROM articles a          WHERE a.category_id = c.id
                       AND a.published_at > NOW() - INTERVAL \'30 days\')                AS in_window,
                   (SELECT count(*) FROM user_categories u   WHERE u.category_id = c.id) AS readers
              FROM category_master c WHERE c.id = ?', [$id]);
    }

    /**
     * A PHP bool as a value Postgres accepts for a boolean column.
     *
     * pdo_pgsql sends `false` in an execute() array as the empty string,
     * which Postgres rejects ("invalid input syntax for type boolean"),
     * while `true` arrives as '1' and works. So a plain bool parameter is a
     * bug that only shows up on the false path.
     */
    private static function pgBool(bool $v): string
    {
        return $v ? 'true' : 'false';
    }

    /** Escape %, _ and \ so a search for "100%" does not match everything. */
    private static function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}
