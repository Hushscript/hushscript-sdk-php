ARG PHP_VERSION=8.2

FROM php:8.2-cli-bookworm@sha256:8bfff0e5249f2073504c335b9f7ae54bbe84c3bc9338bfc8d2a04e77d112360b AS php8.2
FROM php:8.3-cli-bookworm@sha256:6e25fbb304ba6641eb4d2a53e92bea8eeba8c0d0eabf6ca41d0f1ac3b21a9ef0 AS php8.3
FROM php:8.4-cli-bookworm@sha256:54b467ede1db96c66675e7b78457dd43729d0e7be31978d4a1e5c069ceabcf76 AS php8.4
FROM php:8.5-cli-bookworm@sha256:104890ada56869090c9cd0ec709fe205834d3e383b46d9995dc16cd6388a9302 AS php8.5
FROM composer:2@sha256:4ed71c38c6d788eb4e4e3747b5332f5b40d5b5a6fcadd5062a1a70986a118e0a AS composer
FROM busybox:1.38-musl@sha256:80e38f9a1b39188b8bdb28879178edb322a4402b081a9aedda1924609decd6f6 AS busybox
FROM mlocati/php-extension-installer:2.13.1@sha256:35821db0661308f64aa18483bb3af56bedc3b40090ff441ed961327d1ccbceb8 AS ipe

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
