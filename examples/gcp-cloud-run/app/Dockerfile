# Laravel Octane on Swoole, listening directly on $PORT.
#
# Build context is the *repository root*, not this directory, so the package
# source can be installed through a Composer path repository. Once the package
# is published on Packagist this collapses to a plain `composer require` and the
# context can be this directory alone.
#
#   docker build -f examples/gcp-cloud-run/app/Dockerfile -t IMAGE .

FROM php:8.4-cli

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        git unzip libzip-dev zlib1g-dev gcc make autoconf libc-dev pkg-config libbrotli-dev libssl-dev && \
    docker-php-ext-install zip pcntl sockets && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Swoole is what Octane runs on. Built from a pinned tag rather than `pecl
# install`, which prompts for a dozen build options — answering those blind is
# how you end up enabling c-ares against a library that is not installed.
#
# openssl is enabled because the app makes outbound HTTPS calls: publishing to
# Pub/Sub, and fetching Google's public keys to verify the inbound OIDC token.
ARG SWOOLE_VERSION=v6.0.2
RUN set -eux; \
    git clone --depth 1 --branch "${SWOOLE_VERSION}" https://github.com/swoole/swoole-src.git /tmp/swoole; \
    cd /tmp/swoole; \
    phpize; \
    ./configure --enable-openssl --enable-sockets; \
    make -j"$(nproc)"; \
    make install; \
    docker-php-ext-enable swoole; \
    rm -rf /tmp/swoole; \
    php -m | grep -q '^swoole$'

# Opcache tuned for a container that is rebuilt rather than edited in place.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# The package source, copied before the app so an edit to the example does not
# invalidate this layer.
COPY composer.json /package/composer.json
COPY src /package/src
COPY config /package/config
COPY routes /package/routes

# Dependency install on its own layer, keyed to the manifest only.
COPY examples/gcp-cloud-run/app/composer.json /var/www/html/composer.json
RUN composer config repositories.stateless-queue --json '{"type":"path","url":"/package","options":{"symlink":false}}' \
    && composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY examples/gcp-cloud-run/app/ /var/www/html/

# Laravel writes its package manifest and caches here at boot, so both trees
# must exist and be writable regardless of what the build context contained.
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/views storage/framework/sessions storage/logs \
    && chmod -R 775 bootstrap/cache storage \
    && composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && chmod +x /var/www/html/startup.sh

# This example has no database, cache server, or queue backend. Laravel's
# defaults for these reach for one — the session driver alone is enough to make
# every web route 500 on a missing sqlite file — so they are pinned to in-memory
# equivalents here rather than left to be discovered at runtime.
ENV SESSION_DRIVER=array \
    CACHE_STORE=array \
    QUEUE_CONNECTION=sync

# Cloud Run overrides this; the default keeps `docker run` usable as-is.
ENV PORT=8080
EXPOSE 8080

CMD ["/var/www/html/startup.sh"]
