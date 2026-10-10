-- ============================================================
--  Migration 012 — the job queue
--
--  Some operations outlast a web request: a full feed sync runs ~100s, a
--  database dump longer. A button that runs them inline times out and
--  leaves the operator guessing whether anything happened.
--
--  So the dashboard INSERTS a row here and spawns a detached worker
--  (jobs/run_job.php <id>), which runs the job and writes its output and
--  exit code back. The page then shows real progress and a real result,
--  and a failed job can be retried from its row.
--
--  Overlap is prevented in two layers: the partial unique index below stops
--  two QUEUED/RUNNING rows of the same type, and the worker takes the same
--  flock as the nightly cron, so a dashboard-triggered sync and the
--  scheduled one cannot run together either.
--
--  Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS admin_jobs (
    id            BIGSERIAL PRIMARY KEY,
    type          VARCHAR(32)  NOT NULL
                  CHECK (type IN ('sync', 'rollup', 'backup', 'cleanup', 'report', 'validate_feed')),
    status        VARCHAR(16)  NOT NULL DEFAULT 'queued'
                  CHECK (status IN ('queued', 'running', 'done', 'failed', 'cancelled')),
    params_json   JSONB,                         -- e.g. {"source_id": 12}
    requested_by  BIGINT REFERENCES users(id) ON DELETE SET NULL,
    requested_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at    TIMESTAMPTZ,
    finished_at   TIMESTAMPTZ,
    exit_code     INT,
    output        TEXT,                          -- captured stdout+stderr, capped by the worker
    summary       TEXT,                          -- one line for the list view
    retry_of      BIGINT REFERENCES admin_jobs(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS admin_jobs_recent_idx ON admin_jobs (requested_at DESC);
CREATE INDEX IF NOT EXISTS admin_jobs_type_idx   ON admin_jobs (type, requested_at DESC);

-- One live job per type. The second click on "Sync now" gets told the first
-- is still running, rather than starting a second fetch.
CREATE UNIQUE INDEX IF NOT EXISTS admin_jobs_one_live_per_type
    ON admin_jobs (type) WHERE status IN ('queued', 'running');
