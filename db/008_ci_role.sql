-- ============================================================
--  Migration 008 — the fineprint_ci role
--
--  The role the nightly GitHub Actions workflow logs in as
--  (.github/workflows/nightly-jobs.yml).
--
--  WHY A SEPARATE ROLE
--
--  That workflow's credential lives in the Actions secrets of a PUBLIC
--  repository. Using neondb_owner there would put a credential that can
--  read every password hash, read every donation, drop any table and
--  delete every user into a place with a much larger attack surface than
--  the application server has.
--
--  So this role gets exactly what the two cron jobs and cleanup.sql need
--  and nothing else. It cannot:
--    - read users.password_hash or users.email (column-level grant)
--    - read or write donations
--    - delete users
--    - create, alter or drop anything
--
--  If it leaks, revoking it is one statement and the application keeps
--  running untouched.
--
--  RUN THIS BY HAND, with a password you generate:
--
--    psql "$FEED_DB_URL" -v pw="$(openssl rand -base64 24)" -f db/008_ci_role.sql
--
--  Then store the four repository secrets (FEED_DB_DSN, FEED_DB_USER,
--  FEED_DB_PASS, FEED_DB_URL) built from it. The password is deliberately
--  not in this file and must never be committed.
-- ============================================================

\if :{?pw}
\else
  \echo 'ERROR: pass a password, e.g. -v pw="$(openssl rand -base64 24)"'
  \quit 1
\endif

DROP ROLE IF EXISTS fineprint_ci;
CREATE ROLE fineprint_ci WITH LOGIN PASSWORD :'pw';

GRANT CONNECT ON DATABASE neondb TO fineprint_ci;
GRANT USAGE ON SCHEMA public TO fineprint_ci;

-- ---------- fetch_feeds.php ----------
GRANT SELECT, INSERT, DELETE           ON articles            TO fineprint_ci;
GRANT SELECT, UPDATE                   ON blog_sources        TO fineprint_ci;
GRANT SELECT                           ON category_blog_map   TO fineprint_ci;
GRANT SELECT                           ON category_master     TO fineprint_ci;
GRANT SELECT, INSERT, DELETE           ON sync_status         TO fineprint_ci;

-- ---------- rollup_stats.php ----------
GRANT SELECT, DELETE                   ON article_events      TO fineprint_ci;
GRANT SELECT, INSERT, TRUNCATE, DELETE ON user_category_stats TO fineprint_ci;
GRANT SELECT, INSERT, TRUNCATE, DELETE ON user_source_stats   TO fineprint_ci;
GRANT SELECT, INSERT, TRUNCATE, DELETE ON source_stats        TO fineprint_ci;
GRANT SELECT, INSERT, TRUNCATE, DELETE ON article_stats       TO fineprint_ci;
GRANT SELECT, INSERT, TRUNCATE, DELETE ON user_feed_stats     TO fineprint_ci;
GRANT SELECT, UPDATE                   ON feed_globals        TO fineprint_ci;

-- ---------- cleanup.sql ----------
--
-- SELECT is required alongside DELETE. A `DELETE ... WHERE seen_at < ...`
-- reads the column it filters on, so DELETE alone fails with "permission
-- denied" — and it fails at the SECOND statement, so the first delete
-- succeeds and the retention rules stop applying without any obvious sign.
GRANT SELECT, DELETE                   ON user_seen_articles  TO fineprint_ci;
GRANT SELECT, DELETE                   ON user_tokens         TO fineprint_ci;

-- ---------- pipeline_report.php ----------
--
-- Column-level. The report only counts active readers, so it needs these
-- two columns; table-level SELECT would also expose password_hash and email.
GRANT SELECT (id, is_active)           ON users               TO fineprint_ci;

-- ---------- sequences behind the INSERTs above ----------
GRANT USAGE, SELECT ON SEQUENCE articles_id_seq       TO fineprint_ci;
GRANT USAGE, SELECT ON SEQUENCE sync_status_id_seq    TO fineprint_ci;
GRANT USAGE, SELECT ON SEQUENCE article_events_id_seq TO fineprint_ci;

-- ---------- VACUUM at the end of cleanup.sql ----------
-- MAINTAIN exists from PostgreSQL 16 and is what lets a non-owner vacuum.
GRANT MAINTAIN ON articles, user_seen_articles, article_events TO fineprint_ci;

-- To revoke everything later:
--   DROP OWNED BY fineprint_ci; DROP ROLE fineprint_ci;
