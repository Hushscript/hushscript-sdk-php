ARG PHP_VERSION=8.2

FROM php:8.2-cli-bookworm@sha256:58bd7cb60b690c01106084ce4a0a73eb01094ceb00904ebb4b8514516765292a AS php8.2
FROM php:8.3-cli-bookworm@sha256:cdb77e306d6bb99db40e513fbe036ecc6c53b575cbcb7e581387845fef26d755 AS php8.3
FROM php:8.4-cli-bookworm@sha256:496b5e02fe9fe44fcfb715023b931048111dc4e0bc719789d83c906be9f04b05 AS php8.4
FROM php:8.5-cli-bookworm@sha256:d4f6e10eb393de7e3a7a88e8b88e139072dd3ae297f6a5dada8257e6fe32c585 AS php8.5
FROM composer:2@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee AS composer
FROM busybox:1.38-musl@sha256:ea2b9914a16a4ac1981994af97b318f7c7d4db76b580c56177f08bf76f4a0be8 AS busybox
FROM mlocati/php-extension-installer:2.12.3@sha256:ac4b252c153a9f6de5578ff2c417790aba836bb49855543144606033eceda253 AS ipe

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
