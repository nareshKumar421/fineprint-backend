<?php
/**
 * run_job.php — the queue worker behind the dashboard's Jobs page.
 *
 *     php jobs/run_job.php <id>
 *
 * The dashboard INSERTs a row into admin_jobs and spawns this script
 * detached, so the web request returns at once and the row carries the
 * progress. This script claims the row, runs the operation the row names,
 * and writes back the exit code, the captured output and a one-line summary.
 *
 * Two guarantees, because an operator reads this table to decide whether
 * something happened:
 *
 *   1. The row ALWAYS reaches a terminal status. Everything after the claim
 *      sits inside try/catch, and the final write is retried on a fresh
 *      connection — a feed sync runs for minutes, long enough for the first
 *      connection to have gone away.
 *
 *   2. Two workers never run the same type at once. The partial unique index
 *      stops two rows being queued; the flock here stops a queued row being
 *      run beside the nightly cron (which takes the same lock file).
 *
 * Output is capped at the LAST 64 KB. The end of a log is where the result
 * and the error live; the beginning is "starting…".
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/feedlib.php';

use App\Db;
use App\Env;

define('JOB_ROOT', dirname(__DIR__));
const JOB_OUTPUT_CAP   = 65536;      // bytes of output kept, from the end
const JOB_BACKUP_KEEP  = 14;         // days a dump is kept
const JOB_SUMMARY_MAX  = 300;

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "usage: php jobs/run_job.php <job-id>\n");
    exit(2);
}

function job_note(string $msg): void
{
    fwrite(STDOUT, '[run_job] ' . $msg . "\n");
}

/* ------------------------------------------------------------------ */
/*  Claim                                                              */
/* ------------------------------------------------------------------ */

try {
    $job = Db::one('SELECT * FROM admin_jobs WHERE id = ?', [$id]);
} catch (Throwable $e) {
    job_note("cannot load job #{$id}: " . $e->getMessage());
    exit(1);
}

if ($job === null) {
    job_note("no such job #{$id}");
    exit(1);
}
if ($job['status'] !== 'queued') {
    job_note("job #{$id} is '{$job['status']}', not queued — nothing to do");
    exit(0);
}

$claimed = Db::exec(
    "UPDATE admin_jobs SET status = 'running', started_at = NOW()
      WHERE id = ? AND status = 'queued'",
    [$id]
);
if ($claimed === 0) {
    job_note("job #{$id} was claimed by another worker");
    exit(0);
}

$type = (string) $job['type'];
job_note("claimed job #{$id} ({$type})");

/* ------------------------------------------------------------------ */
/*  Finish — the one write that must never be lost                     */
/* ------------------------------------------------------------------ */

function job_finish(int $id, string $status, ?int $exit, string $output, string $summary): void
{
    if (strlen($output) > JOB_OUTPUT_CAP) {
        $output = "[… earlier output trimmed …]\n" . substr($output, -JOB_OUTPUT_CAP);
    }
    // Postgres TEXT rejects invalid UTF-8 and NUL bytes; a feed body or a
    // pg_dump message can contain either.
    $output  = str_replace("\0", '', mb_convert_encoding($output, 'UTF-8', 'UTF-8'));
    $summary = mb_substr(trim(str_replace("\0", '', mb_convert_encoding($summary, 'UTF-8', 'UTF-8'))), 0, JOB_SUMMARY_MAX);

    $sql = "UPDATE admin_jobs
               SET status = ?, finished_at = NOW(), exit_code = ?, output = ?, summary = ?
             WHERE id = ?";
    $params = [$status, $exit, $output, $summary, $id];

    try {
        Db::exec($sql, $params);
        return;
    } catch (Throwable $e) {
        job_note('final write failed, retrying on a fresh connection: ' . $e->getMessage());
    }

    // The shared connection may have died during a long run. One fresh PDO,
    // built from the same environment, is the second and last attempt.
    try {
        sleep(2);
        $pdo = new PDO(Env::get('FEED_DB_DSN'), Env::get('FEED_DB_USER'), Env::get('FEED_DB_PASS'), [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        job_note('final write failed twice; job row is stale: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/*  Lock                                                               */
/* ------------------------------------------------------------------ */

$lockPath = '/tmp/fineprint-jobs-' . $type . '.lock';
$lock     = @fopen($lockPath, 'c');
if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
    job_note("another {$type} is running (lock {$lockPath} is held)");
    job_finish($id, 'failed', 1, '', "another {$type} is running");
    exit(1);
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

/**
 * Run a shell command from the project root, capturing stdout+stderr.
 *
 * @return array{exit:int, output:string}
 */
function job_run_command(string $cmd, ?array $env = null): array
{
    $desc  = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $proc  = proc_open($cmd, $desc, $pipes, JOB_ROOT, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('could not start: ' . $cmd);
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $buf = '';
    $open = [$pipes[1], $pipes[2]];
    while ($open !== []) {
        $read = $open;
        $w = $x = null;
        $n = @stream_select($read, $w, $x, 1);
        if ($n === false) {
            usleep(100_000);
            continue;
        }
        foreach ($read as $p) {
            $chunk = fread($p, 65536);
            if ($chunk !== false && $chunk !== '') {
                $buf .= $chunk;
                if (strlen($buf) > JOB_OUTPUT_CAP * 2) {
                    $buf = substr($buf, -JOB_OUTPUT_CAP);   // keep the tail, bound memory
                }
            }
        }
        $open = array_values(array_filter($open, static fn($p) => !feof($p)));
    }
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($proc), 'output' => $buf];
}

/** Strip a "[2026-01-01 03:10:00] INFO " prefix from a log line. */
function job_strip_prefix(string $line): string
{
    return trim(preg_replace('/^\[[^\]]*\]\s+[A-Z]+\s+/', '', $line) ?? $line);
}

/** One line for the list view: the result line when there is one, else the first real line. */
function job_summary_from(string $output, int $exit): string
{
    $lines = array_values(array_filter(array_map('rtrim', explode("\n", $output)),
        static fn($l) => trim($l) !== '' && !preg_match('/^\s*[-=]{3,}/', $l)));
    if ($lines === []) {
        return $exit === 0 ? 'finished with no output' : "exited with code {$exit}";
    }
    if ($exit !== 0) {
        foreach (array_reverse($lines) as $l) {
            if (preg_match('/fatal|error|fail/i', $l)) {
                return job_strip_prefix($l);
            }
        }
        return job_strip_prefix(end($lines));
    }
    foreach (array_reverse($lines) as $l) {
        if (preg_match('/\b(done:|rollups rebuilt|partial run)/i', $l)) {
            return job_strip_prefix($l);
        }
    }
    return job_strip_prefix($lines[0]);
}

function job_bytes(int $n): string
{
    if ($n >= 1048576) {
        return round($n / 1048576, 1) . ' MB';
    }
    if ($n >= 1024) {
        return round($n / 1024, 1) . ' KB';
    }
    return $n . ' B';
}

/** The environment for a child process: ours, plus the libpq URL for psql/pg_dump. */
function job_child_env(): array
{
    $env = getenv();
    $env['FEED_DB_URL'] = Env::get('FEED_DB_URL');
    return $env;
}

/* ------------------------------------------------------------------ */
/*  Run by type                                                        */
/* ------------------------------------------------------------------ */

$php = escapeshellarg(PHP_BINARY);
$result = ['exit' => 1, 'output' => '', 'summary' => ''];

try {
    switch ($type) {
        case 'sync':
        case 'rollup':
        case 'report':
            $script = ['sync' => 'fetch_feeds.php', 'rollup' => 'rollup_stats.php', 'report' => 'pipeline_report.php'][$type];
            $r = job_run_command("{$php} jobs/{$script}", job_child_env());
            $summary = job_summary_from($r['output'], $r['exit']);
            if ($type === 'report' && $r['exit'] === 0) {
                // The report's value is its WARN lines; say how many and quote the first.
                preg_match_all('/^WARN\s+(.*)$/m', $r['output'], $warns);
                $n = count($warns[1]);
                $summary = $n === 0 ? 'no warnings — ' . $summary
                                    : $n . ' warning(s): ' . trim($warns[1][0]);
            }
            $result = ['exit' => $r['exit'], 'output' => $r['output'], 'summary' => $summary];
            break;

        case 'cleanup':
            if (Env::get('FEED_DB_URL') === '') {
                throw new RuntimeException('FEED_DB_URL is not set — psql cannot connect');
            }
            $r = job_run_command('psql "$FEED_DB_URL" -v ON_ERROR_STOP=1 -f db/cleanup.sql', job_child_env());
            $summary = $r['exit'] === 0
                ? 'cleanup.sql applied: ' . implode(', ', array_slice(array_filter(array_map('trim',
                      explode("\n", $r['output'])), static fn($l) => str_starts_with($l, 'DELETE')), 0, 6))
                : job_summary_from($r['output'], $r['exit']);
            $result = ['exit' => $r['exit'], 'output' => $r['output'], 'summary' => $summary];
            break;

        case 'backup':
            if (Env::get('FEED_DB_URL') === '') {
                throw new RuntimeException('FEED_DB_URL is not set — pg_dump cannot connect');
            }
            $dir = JOB_ROOT . '/backups';
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException("cannot create {$dir}");
            }
            $name = 'fineprint-' . gmdate('Ymd-His') . '.dump';
            $r = job_run_command(
                'pg_dump "$FEED_DB_URL" --no-owner --no-privileges -Fc -f ' . escapeshellarg('backups/' . $name),
                job_child_env()
            );
            $path = $dir . '/' . $name;
            if ($r['exit'] !== 0 || !is_file($path)) {
                if (is_file($path)) {
                    @unlink($path);                      // never leave a partial dump
                }
                $result = ['exit' => $r['exit'] ?: 1, 'output' => $r['output'],
                           'summary' => job_summary_from($r['output'], $r['exit'] ?: 1)];
                break;
            }
            $size = (int) filesize($path);

            // Retention. Older dumps go; the new one is never a candidate.
            $pruned = 0;
            $cutoff = time() - JOB_BACKUP_KEEP * 86400;
            foreach (glob($dir . '/fineprint-*.dump') ?: [] as $old) {
                if ($old !== $path && is_file($old) && (int) filemtime($old) < $cutoff && @unlink($old)) {
                    $pruned++;
                }
            }
            $summary = sprintf('%s (%s); %d older dump(s) removed', $name, job_bytes($size), $pruned);
            $result  = ['exit' => 0, 'summary' => $summary,
                        'output' => $r['output'] . "\n" . "wrote backups/{$name} ({$size} bytes)\n"
                                  . "pruned {$pruned} dump(s) older than " . JOB_BACKUP_KEEP . " days\n"];
            break;

        case 'validate_feed':
            $params = json_decode((string) ($job['params_json'] ?? ''), true);
            $url    = is_array($params) ? trim((string) ($params['feed_url'] ?? '')) : '';
            if ($url === '') {
                throw new RuntimeException('params_json.feed_url is missing');
            }
            $out = "GET {$url}\n";
            $t0  = microtime(true);
            $fetched = feed_fetch($url);
            $out .= sprintf("fetched %s bytes in %.1fs (content-type: %s)\n",
                number_format(strlen($fetched['body'])), microtime(true) - $t0, $fetched['content_type']);
            if ($fetched['final_url'] !== $url) {
                $out .= "redirected to {$fetched['final_url']}\n";
            }
            $parsed = feed_parse($fetched['body']);
            $n = count($parsed['items']);
            $out .= "title: {$parsed['title']}\nlink: {$parsed['link']}\nitems: {$n}"
                  . (!empty($parsed['malformed']) ? ' (document had recoverable XML warnings)' : '') . "\n\n";
            foreach (array_slice($parsed['items'], 0, 10) as $i => $item) {
                $out .= sprintf("%2d. %s  [%s]\n    %s\n", $i + 1, $item['title'],
                    $item['published_at'] ?? 'no date', $item['url']);
            }
            $result = ['exit' => 0, 'output' => $out,
                       'summary' => sprintf('%s — %d item(s)', $parsed['title'] !== '' ? $parsed['title'] : $url, $n)];
            break;

        default:
            throw new RuntimeException("unknown job type '{$type}'");
    }
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $result = [
        'exit'    => 1,
        'output'  => ($result['output'] ?? '') . "\n" . get_class($e) . ': ' . $msg . "\n",
        'summary' => $type === 'validate_feed' ? 'invalid feed: ' . $msg : 'error: ' . $msg,
    ];
}

$status = $result['exit'] === 0 ? 'done' : 'failed';
job_finish($id, $status, (int) $result['exit'], (string) $result['output'], (string) $result['summary']);
job_note("job #{$id} {$status} (exit {$result['exit']}): {$result['summary']}");

flock($lock, LOCK_UN);
fclose($lock);
exit($result['exit'] === 0 ? 0 : 1);
