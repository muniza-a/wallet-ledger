FROM php:8.4-fpm-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends nginx unzip git \
 && docker-php-ext-install pdo_mysql opcache \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first, so code changes don't re-download packages.
# Dev dependencies stay installed so PHPUnit can run inside the container.
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
# Config comes from docker compose env vars; .env is excluded by .dockerignore.
# An empty .env stops `php artisan test` from warning that it cannot read the file.
RUN touch .env \
 && composer dump-autoload --optimize --classmap-authoritative \
 && php artisan package:discover --ansi \
 && mkdir -p storage/framework/cache storage/framework/views storage/framework/sessions storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

COPY docker/app/nginx.conf /etc/nginx/nginx.conf
COPY docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/zz-zapp.conf
COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
# Strip Windows line endings in case the repo was checked out with CRLF.
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint && chmod +x /usr/local/bin/entrypoint

EXPOSE 8080
# PHP-FPM and Nginx both treat SIGQUIT as "finish in-flight requests, then exit".
# Docker's default SIGTERM makes PHP-FPM kill workers mid-request.
STOPSIGNAL SIGQUIT
ENTRYPOINT ["entrypoint"]
