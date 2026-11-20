FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize

FROM php:8.4-cli-alpine

RUN apk add --no-cache postgresql-dev oniguruma-dev curl \
    && docker-php-ext-install pdo_pgsql mbstring pcntl

WORKDIR /app
COPY --from=vendor /app /app
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && touch .env

EXPOSE 8000
ENTRYPOINT ["entrypoint"]
