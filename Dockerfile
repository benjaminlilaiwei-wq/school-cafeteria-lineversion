FROM php:8.3-apache-trixie

# mbstring と curl は公式イメージに最初から入っている。mbstring を作り直すと oniguruma が無くて失敗する。
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libsharpyuv-dev \
        libwebp-dev \
        pkg-config \
        zlib1g-dev \
    ; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j1 gd; \
    php -r 'exit(function_exists("imagewebp") ? 0 : 1);'; \
    rm -rf /var/lib/apt/lists/*; \
    echo "date.timezone=Asia/Tokyo" > /usr/local/etc/php/conf.d/timezone.ini; \
    sed -ri "s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf; \
    printf '%s\n' \
        '#!/bin/sh' \
        'set -eu' \
        'PORT="${PORT:-10000}"' \
        'sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf' \
        'sed -ri "s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf' \
        'exec apache2-foreground' \
        > /usr/local/bin/render-entrypoint.sh; \
    chmod +x /usr/local/bin/render-entrypoint.sh

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html/data /var/www/html/api \
    && chmod -R ug+rwX /var/www/html/data /var/www/html/api

EXPOSE 10000
CMD ["render-entrypoint.sh"]
