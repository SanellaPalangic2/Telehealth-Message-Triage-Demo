# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# 1. PHP dependencies (production only: no Pest, no dev tools)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app

COPY composer.json composer.lock ./
# The composer image's PHP version can differ from the runtime's; the lock
# file was resolved for PHP 8.4, which the runtime stage below uses.
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---------------------------------------------------------------------------
# 2. Runtime: PHP 8.4 + Apache
# ---------------------------------------------------------------------------
FROM php:8.4-apache

# pdo_sqlite is built into the official image; add opcache for speed.
RUN docker-php-ext-install opcache \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-triage.ini"
COPY docker/apache.conf /etc/apache2/conf-available/triage.conf

# Serve /public, honor Laravel's .htaccess, and listen on $PORT (Render sets it).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    PORT=10000
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's!Listen 80!Listen ${PORT}!' /etc/apache2/ports.conf \
    && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!' /etc/apache2/sites-available/000-default.conf \
    && a2enmod rewrite headers \
    && a2enconf triage

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache database

COPY docker/entrypoint.sh /usr/local/bin/triage-entrypoint
RUN chmod +x /usr/local/bin/triage-entrypoint

EXPOSE 10000
ENTRYPOINT ["triage-entrypoint"]
CMD ["apache2-foreground"]
