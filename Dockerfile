FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/* \
    && echo "date.timezone=Asia/Tokyo" > /usr/local/etc/php/conf.d/timezone.ini \
    && sed -ri "s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf \
    && printf '%s\n' \
        '#!/bin/sh' \
        'set -eu' \
        'PORT="${PORT:-10000}"' \
        'sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf' \
        'sed -ri "s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf' \
        'exec apache2-foreground' \
        > /usr/local/bin/render-entrypoint.sh \
    && chmod +x /usr/local/bin/render-entrypoint.sh

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html/data /var/www/html/api \
    && chmod -R ug+rwX /var/www/html/data /var/www/html/api

EXPOSE 10000
CMD ["render-entrypoint.sh"]
