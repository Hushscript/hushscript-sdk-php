ARG PHP_VERSION=8.2

FROM php:8.2-cli-bookworm@sha256:0d6ec82fde85307fd9c672527017fccf9bac55f254756f035e542f8c566b63c9 AS php8.2
FROM php:8.3-cli-bookworm@sha256:c24b55afdf860c874b9ec6267ced132ff52de97d308ab08e6cf41e8596e509f7 AS php8.3
FROM php:8.4-cli-bookworm@sha256:836ac6c672d1372a47c8fd61b625015bd07760f493fb2eaab2087636498a2b4b AS php8.4
FROM php:8.5-cli-bookworm@sha256:819080ed413631f6ec564184dd9acd08d9425bd863b9f8b5876b5ea2147d55e7 AS php8.5
FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM busybox:1.36-musl@sha256:3c6ae8008e2c2eedd141725c30b20d9c36b026eb796688f88205845ef17aa213 AS busybox
FROM mlocati/php-extension-installer:2.12.1@sha256:486d090fba21c04170262152c0ae63e7434d75e84fdd13450201c7fd54721b22 AS ipe

# PHP 8.2 through 8.5 above are the digest-pinned images; the build arg only selects one.
# hadolint ignore=DL3006
FROM php${PHP_VERSION} AS xdebug-build
# Xdebug is a compiled extension and the official image carries no compiler: the installer fetches the
# build tools, compiles it, and only the .so leaves this stage.
COPY --from=ipe /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions xdebug-3.5.3 \
  && mkdir -p /out \
  && cp "$(php-config --extension-dir)/xdebug.so" /out/xdebug.so

# hadolint ignore=DL3006
FROM php${PHP_VERSION} AS base
RUN groupadd --gid 10001 dev && useradd --uid 10001 --gid 10001 --no-create-home --shell /usr/sbin/nologin dev
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
# Composer unpacks dist archives with unzip when the zip extension is missing; the static musl busybox supplies it.
COPY --from=busybox /bin/busybox /usr/local/bin/busybox
COPY --from=xdebug-build /out/xdebug.so /opt/xdebug/xdebug.so
RUN ln -s busybox /usr/local/bin/unzip \
  && printf 'zend_extension=/opt/xdebug/xdebug.so\nxdebug.mode=coverage\n' > /usr/local/etc/php/conf.d/xdebug.ini \
  && printf 'memory_limit=1G\n' > /usr/local/etc/php/conf.d/memory.ini
ENV HOME=/tmp \
    COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_ALLOW_SUPERUSER=0
WORKDIR /work

FROM base AS deps
COPY composer.json composer.lock ./
RUN composer install --no-plugins --no-scripts --no-autoloader --prefer-dist

# vendor holds the dev tools; the autoloader is dumped over the real source, so src is read in place.
FROM base AS dev
COPY --from=deps /work/vendor vendor
COPY --chown=10001:10001 . .
ENV PATH=/work/vendor/bin:$PATH
RUN composer dump-autoload --no-plugins --no-scripts --optimize
USER 10001:10001

# Runtime dependencies only: `just build` installs the archive next to these with no network.
FROM base AS proddeps
COPY composer.json composer.lock ./
RUN composer install --no-plugins --no-scripts --no-dev --no-autoloader --prefer-dist \
  && composer dump-autoload --no-plugins --no-scripts --no-dev --optimize
