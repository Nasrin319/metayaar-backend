# Laravel API (PHP-FPM on :9000). Used by compose.yaml for the api, scheduler and queue services.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

FROM php:8.4-fpm-alpine
# Servers in Iran often can't reach dl-cdn.alpinelinux.org; ALPINE_MIRROR (from .env) replaces it.
ARG ALPINE_MIRROR
RUN if [ -n "$ALPINE_MIRROR" ]; then sed -i "s#https\?://dl-cdn.alpinelinux.org/alpine#${ALPINE_MIRROR%/}#g" /etc/apk/repositories; fi
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql intl zip bcmath gd opcache pcntl
COPY docker/php.ini /usr/local/etc/php/conf.d/99-metayaar.ini

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app ./
RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

USER www-data
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php-fpm"]
