-- ============================================================
--  Blog Feed App - PostgreSQL schema
-- ============================================================

DROP TABLE IF EXISTS user_seen_articles, user_tokens, user_categories,
                     donations, articles, category_blog_map,
                     blog_sources, category_master, users, sync_status CASCADE;

-- ---------- configuration (edited by your team, no app screen) ----------

CREATE TABLE category_master (
    id            SERIAL PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    slug          VARCHAR(100) NOT NULL UNIQUE,
    description   TEXT,
    icon_name     VARCHAR(50),
    display_order INT          NOT NULL DEFAULT 0,
    is_active     BOOLEAN      NOT NULL DEFAULT true,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE TABLE blog_sources (
    id              SERIAL PRIMARY KEY,
    blog_name       VARCHAR(200) NOT NULL,
    blog_url        TEXT         NOT NULL,
    feed_url        TEXT         NOT NULL UNIQUE,
    is_active       BOOLEAN      NOT NULL DEFAULT true,
    failure_count   INT          NOT NULL DEFAULT 0,
    last_fetched_at TIMESTAMPTZ,
    last_error      TEXT,
    added_by        VARCHAR(100),
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE TABLE category_blog_map (
    id                SERIAL PRIMARY KEY,
    category_id       INT NOT NULL REFERENCES category_master(id) ON DELETE CASCADE,
    blog_source_id    INT NOT NULL REFERENCES blog_sources(id)    ON DELETE CASCADE,
    feed_url_override TEXT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (category_id, blog_source_id)
);

-- ---------- content (filled by the fetch job) ----------

CREATE TABLE articles (
    id             BIGSERIAL PRIMARY KEY,
    guid           TEXT NOT NULL,
    title          TEXT NOT NULL,
    excerpt        TEXT,
    article_url    TEXT NOT NULL,
    image_url      TEXT,
    author         VARCHAR(200),
    published_at   TIMESTAMPTZ,
    blog_source_id INT  NOT NULL REFERENCES blog_sources(id)    ON DELETE CASCADE,
    category_id    INT  NOT NULL REFERENCES category_master(id) ON DELETE CASCADE,
    fetched_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX articles_guid_cat_uniq ON articles (guid, category_id);
CREATE INDEX articles_feed_idx  ON articles (category_id, published_at DESC NULLS LAST);
CREATE INDEX articles_blog_idx  ON articles (blog_source_id);

-- ---------- users ----------

CREATE TABLE users (
    id             BIGSERIAL PRIMARY KEY,
    email          VARCHAR(255) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    is_active      BOOLEAN      NOT NULL DEFAULT true,
    email_verified BOOLEAN      NOT NULL DEFAULT false,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    last_login_at  TIMESTAMPTZ
);

CREATE UNIQUE INDEX users_email_lower_uniq ON users (LOWER(email));

CREATE TABLE user_categories (
    user_id     BIGINT NOT NULL REFERENCES users(id)           ON DELETE CASCADE,
    category_id INT    NOT NULL REFERENCES category_master(id) ON DELETE CASCADE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, category_id)
);

CREATE TABLE user_tokens (
    id          BIGSERIAL PRIMARY KEY,
    user_id     BIGINT      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  VARCHAR(64) NOT NULL UNIQUE,
    device_info TEXT,
    expires_at  TIMESTAMPTZ NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX user_tokens_expiry_idx ON user_tokens (expires_at);

CREATE TABLE user_seen_articles (
    user_id    BIGINT NOT NULL REFERENCES users(id)    ON DELETE CASCADE,
    article_id BIGINT NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    seen_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, article_id)
);

CREATE INDEX user_seen_recent_idx ON user_seen_articles (user_id, seen_at DESC);

-- ---------- donations ----------

CREATE TABLE donations (
    id                    BIGSERIAL PRIMARY KEY,
    user_id               BIGINT REFERENCES users(id) ON DELETE SET NULL,
    amount                NUMERIC(10,2) NOT NULL CHECK (amount >= 100 AND amount <= 5000),
    currency              CHAR(3)       NOT NULL DEFAULT 'INR',
    status                VARCHAR(20)   NOT NULL DEFAULT 'pending'
                          CHECK (status IN ('pending', 'success', 'failed')),
    instamojo_request_id  VARCHAR(100),
    instamojo_payment_id  VARCHAR(100) UNIQUE,
    payment_url           TEXT,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at          TIMESTAMPTZ
);

CREATE INDEX donations_status_idx ON donations (status, created_at DESC);

-- ---------- job history ----------

CREATE TABLE sync_status (
    id               BIGSERIAL PRIMARY KEY,
    run_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    feeds_ok         INT NOT NULL DEFAULT 0,
    feeds_failed     INT NOT NULL DEFAULT 0,
    articles_added   INT NOT NULL DEFAULT 0,
    duration_seconds INT
);
