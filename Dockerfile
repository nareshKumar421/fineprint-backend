# ============================================================
#  FinePrint API — container image
#
#  WHY THIS FILE EXISTS
#  --------------------
#  Railway's autodetection (nixpacks) builds a PHP image that does not
#  ship pdo_pgsql, and composer.json requires it. `composer install`
#  fails its platform check, the build dies, and the domain answers an
#  empty 404 from the edge because nothing is running behind it.
#
#  A Dockerfile removes the guesswork: the PHP version, the extensions,
#  the document root and the port are all stated here rather than
#  inferred. Railway prefers this over nixpacks whenever it is present.
# ============================================================

FROM php:8.3-apache

# ---- PHP extensions ------------------------------------------------
# composer.json requires: pdo, pdo_pgsql, curl, simplexml, mbstring,
# dom, json. curl, simplexml, dom and json are already compiled into the
# official image. pdo_pgsql and mbstring are NOT — they are the whole
# reason the nixpacks build failed.
#
# The -dev packages are kept, not purged: apt --auto-remove would take
# libpq5 and libonig5 with them, which are the runtime libraries the
# extensions just linked against.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev libonig-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring; \
    rm -rf /var/lib/apt/lists/*

# ---- Document root -------------------------------------------------
# Only public/ is web-reachable. src/, db/, jobs/ and any .env sit one
# level above it and can never be fetched over HTTP — the property the
# README promises, enforced here rather than hoped for.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN set -eux; \
    sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf; \
    sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Every route is handled by public/index.php; no other file exists on
# disk. FallbackResource sends anything without a matching file there,
# which is what the PHP built-in server does implicitly in development.
# AllowOverride None because there is no .htaccess and reading for one
# on every request is a wasted stat.
RUN set -eux; \
    { \
      echo '<Directory /var/www/html/public>'; \
      echo '    AllowOverride None'; \
      echo '    Require all granted'; \
      echo '    FallbackResource /index.php'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/fineprint.conf; \
    a2enconf fineprint

# ---- Application ---------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .

# vendor/ is gitignored, so autoload.php does not exist in the repo and
# bootstrap.php requires it on the first line of every request. This is
# what creates it. There are no third-party packages, so this only
# generates the PSR-4 autoloader for src/.
RUN composer install --no-dev --optimize-autoloader --no-interaction

# ---- Runtime -------------------------------------------------------
# Railway assigns the port at runtime, so Apache cannot be baked to 80.
COPY docker-start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh
CMD ["/usr/local/bin/start.sh"]
