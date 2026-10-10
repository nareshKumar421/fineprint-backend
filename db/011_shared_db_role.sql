-- ============================================================
--  Migration 011 — the fineprint role and database
--
--  FinePrint shares a PostgreSQL SERVER with an unrelated application, but
--  not a database and not a role. This file records how that separation was
--  made so it can be rebuilt or audited.
--
--  Run as the server superuser, ONCE, against the shared instance:
--
--    docker exec -i portfolio-db-1 psql -U postgres \
--      -v pw="$(openssl rand -base64 24)" -f 011_shared_db_role.sql
--
--  Sharing a server is a deliberate trade. The host has ~950MB of RAM and
--  is already running Postgres; a second instance would halve the page
--  cache available to each and double the backup and upgrade burden. What
--  matters is that neither application can read or damage the other's data,
--  which is what the grants below establish.
-- ============================================================

\if :{?pw}
\else
  \echo 'ERROR: pass a password, e.g. -v pw="$(openssl rand -base64 24)"'
  \quit 1
\endif

-- Not a superuser, and no CREATEDB or CREATEROLE. It owns one database and
-- can do nothing outside it.
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'fineprint') THEN
    EXECUTE format('CREATE ROLE fineprint WITH LOGIN PASSWORD %L', :'pw');
  ELSE
    EXECUTE format('ALTER ROLE fineprint WITH LOGIN PASSWORD %L', :'pw');
  END IF;
END $$;

-- The database itself is created with createdb -O fineprint, because
-- CREATE DATABASE cannot run inside a transaction block and psql -f wraps
-- this file in one:
--
--   docker exec portfolio-db-1 createdb -U postgres -O fineprint fineprint

-- ---------- the fence ----------
--
-- PUBLIC holds CONNECT on every database by default, so without the first
-- line the FinePrint credential could open the neighbouring database and
-- read anything granted to PUBLIC. Revoking it for THIS ROLE BY NAME leaves
-- PUBLIC and the other application's own role exactly as they were — this
-- file must never widen or narrow anything that is not ours.
REVOKE CONNECT ON DATABASE portfolio  FROM fineprint;
REVOKE CONNECT ON DATABASE fineprint  FROM PUBLIC;
GRANT  CONNECT ON DATABASE fineprint  TO   fineprint;

-- Verified after running:
--   fineprint -> portfolio  = FATAL: permission denied for database
--   fineprint -> fineprint  = connects
--   rolsuper                = false
