FROM php:8.4-cli-alpine

RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install -j2 pdo_pgsql \
    && apk del .build-deps

WORKDIR /app
COPY backend/public backend/public
COPY web web

USER www-data
EXPOSE 8080
CMD ["php", "-d", "expose_php=0", "-S", "0.0.0.0:8080", "-t", "backend/public", "backend/public/index.php"]
