<?php
/**
 * JobsController — the queue and the Sync pages it replaces.
 *
 * Some operations outlast a web request: a full feed sync runs ~100 s.
 * Every button here INSERTs a row into admin_jobs and spawns
 * jobs/run_job.php detached, so the request returns at once and the
 * row carries the progress. The detail page refreshes itself while the job
 * is live, which gives an operator visible progress with no JavaScript.
 *
 * Overlap is refused at the database: the partial unique index on
 * admin_jobs allows ONE queued/running row per type, and the controller
 * turns that violation into a flash that names the live job.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Admin\Page;
use App\Admin\Paginator;
use App\Db;
use App\Request;
use App\Services\Audit;
use DateTimeImmutable;
use PDOException;
use Throwable;

final class JobsController extends Page
{
    public const TYPES    = ['sync', 'rollup', 'cleanup', 'report', 'validate_feed'];
    public const STATUSES = ['queued', 'running', 'done', 'failed', 'cancelled'];

    /** Button labels, in the order they appear at the top of the list. */
    public const LABELS = [
        'sync'          => 'Run sync',
        'rollup'        => 'Rebuild stats',
        'cleanup'       => 'Run cleanup',
        'report'        => 'Report',
        'validate_feed' => 'Validate feed',
    ];

    /** What a button must say before it is clicked. */
    public const CONFIRMS = [
        'sync'          => 'Run a full feed sync now? It fetches every active source and takes a few minutes.',
        'rollup'        => 'Rebuild the ranking statistics now? Readers see the new weights on their next refresh.',
        'cleanup'       => 'Run cleanup.sql now? It permanently deletes articles, events and sessions past retention.',
        'report'        => 'Produce a pipeline health report?',
        'validate_feed' => 'Fetch and parse this feed URL?',
    ];

    /** The queued/running/done/failed/cancelled pill tones. */
    public const TONES = ['queued' => 'info', 'running' => 'warn', 'done' => 'ok', 'failed' => 'bad', 'cancelled' => 'muted'];

    /* ---------------------------------------------------------------- */
    /*  paths                                                            */
    /* ---------------------------------------------------------------- */

    /** backend/ — the directory the worker and the job scripts run from. */
    private static function root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }

    /* ---------------------------------------------------------------- */
    /*  GET /jobs                                                        */
    /* ---------------------------------------------------------------- */

    public function index(Request $request): void
    {
        $this->admin();

        $type   = (string) ($_GET['type'] ?? '');
        $status = (string) ($_GET['status'] ?? '');
        $q      = trim((string) ($_GET['q'] ?? ''));
        $type   = in_array($type, self::TYPES, true) ? $type : '';
        $status = in_array($status, self::STATUSES, true) ? $status : '';

        // ORDER BY is a whitelist lookup, never the request value itself.
        $sorts = ['requested_at' => 'j.requested_at', 'type' => 'j.type',
                  'status' => 'j.status', 'finished_at' => 'j.finished_at'];
        $sort  = (string) ($_GET['sort'] ?? 'requested_at');
        $sort  = isset($sorts[$sort]) ? $sort : 'requested_at';
        $dir   = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

        $where  = [];
        $params = [];
        if ($type !== '') {
            $where[]  = 'j.type = ?';
            $params[] = $type;
        }
        if ($status !== '') {
            $where[]  = 'j.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[]  = '(j.summary ILIKE ? OR u.email ILIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $from     = ' FROM admin_jobs j LEFT JOIN users u ON u.id = j.requested_by' . $sqlWhere;

        $pager = new Paginator(50);
        $total = (int) Db::scalar('SELECT count(*)' . $from, $params);
        $rows  = Db::all(
            'SELECT j.id, j.type, j.status, j.requested_at, j.started_at, j.finished_at,
                    j.exit_code, j.summary, j.retry_of, u.email AS requested_by_email,
                    EXTRACT(EPOCH FROM (COALESCE(j.finished_at, NOW()) - j.started_at)) AS duration_s'
            . $from . ' ORDER BY ' . $sorts[$sort] . ' ' . $dir . ' NULLS LAST, j.id DESC LIMIT ? OFFSET ?',
            [...$params, $pager->perPage, $pager->offset]
        );
        foreach ($rows as &$r) {
            $r['duration'] = self::duration($r['duration_s']);
        }
        unset($r);

        $k = Db::one("
            SELECT count(*) FILTER (WHERE status = 'queued')                                        AS queued,
                   count(*) FILTER (WHERE status = 'running')                                       AS running,
                   count(*) FILTER (WHERE status = 'done'   AND requested_at > NOW() - INTERVAL '7 days') AS done_7d,
                   count(*) FILTER (WHERE status = 'failed' AND requested_at > NOW() - INTERVAL '7 days') AS failed_7d
              FROM admin_jobs") ?? [];

        $this->render('jobs/index', [
            'rows'    => $rows,
            'total'   => $total,
            'p'       => $pager->describe($total),
            'k'       => $k,
            'filters' => ['type' => $type, 'status' => $status, 'q' => $q, 'sort' => $sort, 'dir' => strtolower($dir)],
            'live'    => $this->liveJobs(),
        ], 'Jobs', 'jobs');
    }

    /* ---------------------------------------------------------------- */
    /*  POST /jobs/run                                                   */
    /* ---------------------------------------------------------------- */

    public function run(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $type = (string) ($_POST['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            $this->redirect('/jobs', 'Unknown job type.', 'bad');
            return;
        }

        $params = null;
        if ($type === 'validate_feed') {
            $url = $this->requireString('feed_url', 2000);
            if (!preg_match('~^https?://~i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                $this->redirect('/jobs', 'The feed URL must start with http:// or https://.', 'bad');
                return;
            }
            $params = ['feed_url' => $url];
        }

        $id = $this->enqueue($type, $params, $admin, null);
        if ($id === null) {
            return;                                   // enqueue already redirected
        }

        $row = Db::one('SELECT * FROM admin_jobs WHERE id = ?', [$id]);
        Audit::record($admin['id'], $admin['email'], 'job.run', 'admin_jobs', (string) $id, null, $row);

        $spawned = self::spawn($id);
        $this->redirect('/jobs/view?id=' . $id,
            $spawned ? self::LABELS[$type] . ': job #' . $id . ' queued.'
                     : 'Job #' . $id . ' queued, but the worker could not be started on this server.',
            $spawned ? 'ok' : 'warn');
    }

    /* ---------------------------------------------------------------- */
    /*  GET /jobs/view?id=                                               */
    /* ---------------------------------------------------------------- */

    public function view(Request $request): void
    {
        $this->admin();

        $id = $this->intParam('id');
        if ($id === null) {
            $this->redirect('/jobs', 'No such job.', 'bad');
            return;
        }

        $job = Db::one(
            'SELECT j.*, u.email AS requested_by_email,
                    EXTRACT(EPOCH FROM (COALESCE(j.finished_at, NOW()) - j.started_at)) AS duration_s,
                    EXTRACT(EPOCH FROM (NOW() - j.requested_at))                         AS waiting_s,
                    (SELECT max(r.id) FROM admin_jobs r WHERE r.retry_of = j.id)         AS retried_by
               FROM admin_jobs j
               LEFT JOIN users u ON u.id = j.requested_by
              WHERE j.id = ?',
            [$id]
        );
        if ($job === null) {
            $this->redirect('/jobs', 'No such job.', 'bad');
            return;
        }
        $job['duration'] = self::duration($job['duration_s']);

        $this->render('jobs/view', [
            'job'    => $job,
            'params' => json_decode((string) ($job['params_json'] ?? ''), true) ?: [],
            'liveFor' => in_array($job['status'], ['queued', 'running'], true),
            // Queued for two minutes with no worker: exec/setsid is probably
            // unavailable, and the operator should know rather than wait.
            'stuck'  => $job['status'] === 'queued' && (float) $job['waiting_s'] > 120,
        ], 'Job #' . $id, 'jobs');
    }

    /* ---------------------------------------------------------------- */
    /*  POST /jobs/retry                                                 */
    /* ---------------------------------------------------------------- */

    public function retry(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id  = $this->intParam('id', $_POST);
        $old = $id === null ? null : Db::one('SELECT * FROM admin_jobs WHERE id = ?', [$id]);
        if ($old === null) {
            $this->redirect('/jobs', 'No such job.', 'bad');
            return;
        }
        if (!in_array($old['status'], ['failed', 'cancelled'], true)) {
            $this->redirect('/jobs/view?id=' . $id, 'Only a failed or cancelled job can be retried.', 'warn');
            return;
        }

        $params = json_decode((string) ($old['params_json'] ?? ''), true);
        $newId  = $this->enqueue((string) $old['type'], is_array($params) ? $params : null, $admin, $id);
        if ($newId === null) {
            return;
        }

        $row = Db::one('SELECT * FROM admin_jobs WHERE id = ?', [$newId]);
        Audit::record($admin['id'], $admin['email'], 'job.retry', 'admin_jobs', (string) $newId, $old, $row);

        $spawned = self::spawn($newId);
        $this->redirect('/jobs/view?id=' . $newId,
            $spawned ? "Retrying job #{$id} as job #{$newId}."
                     : "Job #{$newId} queued, but the worker could not be started on this server.",
            $spawned ? 'ok' : 'warn');
    }

    /* ---------------------------------------------------------------- */
    /*  POST /jobs/cancel                                                */
    /* ---------------------------------------------------------------- */

    public function cancel(Request $request): void
    {
        $admin = $this->admin();
        $this->csrf();

        $id     = $this->intParam('id', $_POST);
        $before = $id === null ? null : Db::one('SELECT * FROM admin_jobs WHERE id = ?', [$id]);
        if ($before === null) {
            $this->redirect('/jobs', 'No such job.', 'bad');
            return;
        }

        // Only a queued job can be cancelled: a running one has a process
        // behind it that this request cannot reach, and a finished one is
        // history. The status guard in the WHERE closes the race with the
        // worker's claim.
        $n = Db::exec(
            "UPDATE admin_jobs
                SET status = 'cancelled', finished_at = NOW(), summary = ?
              WHERE id = ? AND status = 'queued'",
            ['Cancelled by ' . $admin['email'], $id]
        );
        if ($n === 0) {
            $this->redirect('/jobs/view?id=' . $id, 'That job is no longer queued, so it cannot be cancelled.', 'warn');
            return;
        }

        $after = Db::one('SELECT * FROM admin_jobs WHERE id = ?', [$id]);
        Audit::record($admin['id'], $admin['email'], 'job.cancel', 'admin_jobs', (string) $id, $before, $after);
        $this->redirect('/jobs/view?id=' . $id, "Job #{$id} cancelled.");
    }

    /* ---------------------------------------------------------------- */
    /*  GET /sync                                                        */
    /* ---------------------------------------------------------------- */

    public function sync(Request $request): void
    {
        $this->admin();

        // One statement for the tiles.
        $m = Db::one("
            SELECT (SELECT max(run_at) FROM sync_status)                                         AS last_run,
                   (SELECT count(*) FROM sync_status WHERE run_at > NOW() - INTERVAL '7 days')    AS runs_7d,
                   (SELECT COALESCE(sum(articles_added), 0) FROM sync_status
                     WHERE run_at > NOW() - INTERVAL '7 days')                                   AS added_7d,
                   (SELECT COALESCE(sum(feeds_failed), 0) FROM sync_status
                     WHERE run_at > NOW() - INTERVAL '7 days')                                   AS failed_7d,
                   (SELECT count(*) FROM blog_sources WHERE failure_count > 0)                    AS failing_now,
                   (SELECT count(*) FROM blog_sources WHERE NOT is_active)                        AS dead_now
        ") ?? [];

        $live = Db::one(
            "SELECT j.*, u.email AS requested_by_email,
                    EXTRACT(EPOCH FROM (NOW() - COALESCE(j.started_at, j.requested_at))) AS elapsed_s
               FROM admin_jobs j LEFT JOIN users u ON u.id = j.requested_by
              WHERE j.type = 'sync' AND j.status IN ('queued', 'running')
              ORDER BY j.id DESC LIMIT 1"
        );

        $pager = new Paginator(30);
        $total = (int) Db::scalar('SELECT count(*) FROM sync_status');
        $runs  = Db::all('SELECT * FROM sync_status ORDER BY run_at DESC, id DESC LIMIT ? OFFSET ?',
                         [$pager->perPage, $pager->offset]);
        foreach ($runs as &$r) {
            $r['key'] = self::isoMicro((string) $r['run_at']);
        }
        unset($r);

        $failing = Db::all(
            'SELECT id, blog_name, failure_count, last_error, is_active
               FROM blog_sources
              WHERE failure_count > 0
              ORDER BY failure_count DESC, blog_name LIMIT 50'
        );

        $lastRun = $m['last_run'] ?? null;
        $this->render('jobs/sync', [
            'm'        => $m,
            'lastRun'  => $lastRun === null ? null : substr((string) $lastRun, 0, 16),
            'lastAge'  => $lastRun === null ? null : self::ago((string) $lastRun),
            'stale'    => $lastRun === null || strtotime((string) $lastRun) < time() - 36 * 3600,
            'nextRun'  => self::nextScheduled(),
            'live'     => $live,
            'runs'     => $runs,
            'total'    => $total,
            'p'        => $pager->describe($total),
            'failing'  => $failing,
        ], 'Sync', 'sync');
    }

    /* ---------------------------------------------------------------- */
    /*  GET /sync/view?run_at=                                           */
    /* ---------------------------------------------------------------- */

    public function syncView(Request $request): void
    {
        $this->admin();

        $raw = trim((string) ($_GET['run_at'] ?? ''));
        if ($raw === '' || strlen($raw) > 40 || strtotime($raw) === false) {
            $this->redirect('/sync', 'No such sync run.', 'bad');
            return;
        }

        // Matched with a one-second tolerance: the key in the link carries
        // microseconds, but a hand-edited URL will not.
        $run = Db::one(
            "SELECT *
               FROM sync_status
              WHERE run_at BETWEEN ?::timestamptz - INTERVAL '1 second'
                                AND ?::timestamptz + INTERVAL '1 second'
              ORDER BY abs(EXTRACT(EPOCH FROM (run_at - ?::timestamptz))) LIMIT 1",
            [$raw, $raw, $raw]
        );
        if ($run === null) {
            $this->redirect('/sync', 'No such sync run.', 'bad');
            return;
        }
        $runAt = (string) $run['run_at'];

        // The twelve runs up to and including this one, oldest first.
        $series = [];
        foreach (array_reverse(Db::all(
            'SELECT run_at, articles_added FROM sync_status WHERE run_at <= ? ORDER BY run_at DESC LIMIT 12',
            [$runAt])) as $r) {
            $series[substr((string) $r['run_at'], 5, 11)] = (int) $r['articles_added'];
        }

        // What this run stored, per category. fetched_at is stamped at
        // insert and run_at at the end of the run, so the window is the
        // run's duration (plus slack) ending at run_at. Articles the purge
        // has since removed are not counted, hence "still stored".
        $byCategory = [];
        foreach (Db::all(
            "SELECT c.name, count(*) AS n
               FROM articles a JOIN category_master c ON c.id = a.category_id
              WHERE a.fetched_at BETWEEN ?::timestamptz - (? || ' seconds')::interval
                                     AND ?::timestamptz + INTERVAL '5 seconds'
              GROUP BY c.name ORDER BY n DESC LIMIT 10",
            [$runAt, (int) ($run['duration_seconds'] ?? 0) + 60, $runAt]) as $r) {
            $byCategory[(string) $r['name']] = (int) $r['n'];
        }

        $failing = Db::all(
            'SELECT id, blog_name, feed_url, failure_count, last_error, last_fetched_at, is_active
               FROM blog_sources
              WHERE failure_count > 0 OR last_error IS NOT NULL
              ORDER BY failure_count DESC, blog_name LIMIT 100'
        );

        $job = Db::one(
            "SELECT j.*, u.email AS requested_by_email,
                    EXTRACT(EPOCH FROM (COALESCE(j.finished_at, NOW()) - j.started_at)) AS duration_s
               FROM admin_jobs j LEFT JOIN users u ON u.id = j.requested_by
              WHERE j.type = 'sync'
                AND j.started_at BETWEEN ?::timestamptz - INTERVAL '5 minutes'
                                     AND ?::timestamptz + INTERVAL '5 minutes'
              ORDER BY abs(EXTRACT(EPOCH FROM (j.started_at - ?::timestamptz))) LIMIT 1",
            [$runAt, $runAt, $runAt]
        );
        if ($job !== null) {
            $job['duration'] = self::duration($job['duration_s']);
        }

        $this->render('jobs/sync_view', [
            'run'        => $run,
            'runKey'     => self::isoMicro($runAt),
            'series'     => $series,
            'byCategory' => $byCategory,
            'failing'    => $failing,
            'job'        => $job,
        ], 'Sync run ' . substr($runAt, 0, 16), 'sync');
    }

    /* ---------------------------------------------------------------- */
    /*  internals                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * INSERT the job row, or redirect with the id of the live one.
     *
     * The partial unique index is the arbiter: two admins clicking at once
     * both reach the INSERT, exactly one succeeds, and the other is told
     * which job to watch.
     */
    private function enqueue(string $type, ?array $params, array $admin, ?int $retryOf): ?int
    {
        try {
            return (int) Db::scalar(
                'INSERT INTO admin_jobs (type, params_json, requested_by, retry_of)
                 VALUES (?, ?::jsonb, ?, ?) RETURNING id',
                [$type, $params === null ? null : json_encode($params, JSON_UNESCAPED_SLASHES), $admin['id'], $retryOf]
            );
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23505') {
                throw $e;
            }
        }

        $live = Db::one(
            "SELECT id FROM admin_jobs WHERE type = ? AND status IN ('queued', 'running') ORDER BY id DESC LIMIT 1",
            [$type]
        );
        $liveId = $live === null ? null : (int) $live['id'];
        $this->redirect(
            $liveId === null ? '/jobs' : '/jobs/view?id=' . $liveId,
            "A {$type} job is already queued or running — see job #" . ($liveId ?? '?'),
            'bad'
        );
        return null;
    }

    /**
     * Start the worker detached from this request.
     *
     * setsid puts it in its own session so Apache reaping the request's
     * process group does not take it down; nohup and the redirects cut the
     * last ties to the request's terminal and pipes.
     */
    private static function spawn(int $id): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $php = (PHP_SAPI === 'cli' && PHP_BINARY !== '') ? PHP_BINARY : 'php';
        try {
            exec(sprintf('cd %s && setsid nohup %s jobs/run_job.php %d > /dev/null 2>&1 &',
                escapeshellarg(self::root()), escapeshellarg($php), $id));
            return true;
        } catch (Throwable $e) {
            error_log('JobsController::spawn failed: ' . $e->getMessage());
            return false;
        }
    }

    /** @return array<int, array{id:int, type:string, status:string}> */
    private function liveJobs(): array
    {
        return Db::all("SELECT id, type, status FROM admin_jobs WHERE status IN ('queued', 'running') ORDER BY id");
    }

    /** The next 03:10 UTC, as 'Y-m-d H:i UTC'. */
    private static function nextScheduled(): string
    {
        $today = gmmktime(3, 10, 0);
        $next  = $today > time() ? $today : $today + 86400;
        return gmdate('Y-m-d H:i', $next) . ' UTC';
    }

    /** A timestamptz from the database → an ISO key that round-trips exactly. */
    public static function isoMicro(string $dbTs): string
    {
        try {
            return (new DateTimeImmutable($dbTs))->format('Y-m-d\TH:i:s.uP');
        } catch (Throwable $e) {
            return $dbTs;
        }
    }

    public static function duration(mixed $seconds): string
    {
        if ($seconds === null || $seconds === '') {
            return '—';
        }
        $s = (int) round((float) $seconds);
        if ($s < 60) {
            return $s . 's';
        }
        if ($s < 3600) {
            return intdiv($s, 60) . 'm ' . ($s % 60) . 's';
        }
        return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm';
    }

    public static function ago(string $ts): string
    {
        $t = strtotime($ts);
        if ($t === false) {
            return '';
        }
        $d = max(0, time() - $t);
        if ($d < 3600) {
            return intdiv($d, 60) . ' min ago';
        }
        if ($d < 86400) {
            return intdiv($d, 3600) . ' h ago';
        }
        return intdiv($d, 86400) . ' d ago';
    }

    public static function bytes(int $n): string
    {
        if ($n >= 1073741824) {
            return number_format($n / 1073741824, 2) . ' GB';
        }
        if ($n >= 1048576) {
            return number_format($n / 1048576, 1) . ' MB';
        }
        if ($n >= 1024) {
            return number_format($n / 1024, 1) . ' KB';
        }
        return $n . ' B';
    }
}
