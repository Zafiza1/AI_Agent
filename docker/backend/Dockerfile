# Development image for the Laravel control plane (backend, worker, scheduler).
FROM php:8.4-cli-alpine

RUN apk add --no-cache git unzip icu-dev libzip-dev postgresql-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip bcmath pcntl opcache \
    && apk del $PHPIZE_DEPS linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/backend/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint

# vendor/ exists in the image so the backend_vendor volume is initialised owned by "app".
RUN chmod +x /usr/local/bin/entrypoint \
    && addgroup -g 1000 app && adduser -D -u 1000 -G app app \
    && mkdir -p /var/www/html/vendor && chown -R app:app /var/www/html

WORKDIR /var/www/html
USER app

ENTRYPOINT ["entrypoint"]
# PHP's built-in server with Laravel's router. Not `artisan serve`: it strips every variable that
# is also in .env from the server process, so docker-compose settings would be silently ignored.
CMD ["sh", "-c", "cd public && exec php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"]
