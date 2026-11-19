FROM php:8.4-cli-alpine@sha256:af068e577aaaf473344a53254078f6daa8e3d482cd6d6050e6da5786763cc5b4 AS runtime

RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install -j2 pdo_pgsql \
    && apk del .build-deps

FROM runtime AS dependencies
COPY --from=composer:2@sha256:8c47c745534c6717c94a307e6f7b73e5b93f37c7679fe9faf50ff54eb12be32f /usr/bin/composer /usr/local/bin/composer
WORKDIR /dependencies
COPY backend/composer.json backend/composer.lock ./
RUN apk add --no-cache unzip \
    && composer install --no-dev --no-plugins --no-scripts --no-interaction --prefer-dist --classmap-authoritative

FROM runtime

WORKDIR /app
COPY backend/public backend/public
COPY backend/bootstrap.php backend/
COPY backend/src backend/src
COPY --from=dependencies /dependencies/vendor backend/vendor
COPY scripts/LocalClient.php scripts/osiris-cli.php scripts/context-consumer.php scripts/probe-github-context.php scripts/provision-user.php scripts/
COPY web web

USER www-data
EXPOSE 8080
CMD ["php", "-d", "expose_php=0", "-S", "0.0.0.0:8080", "-t", "backend/public", "backend/public/index.php"]
