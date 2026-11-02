# syntax=docker/dockerfile:1

# 1) Telas: compila CSS e JS
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# 2) Dependências PHP (sem as de desenvolvimento)
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# 3) Imagem final: PHP-FPM + Nginx
FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx supervisor postgresql16-client icu-libs libzip libpng tzdata \
    && apk add --no-cache --virtual .build $PHPIZE_DEPS icu-dev libzip-dev libpng-dev postgresql16-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip gd bcmath opcache pcntl \
    && apk del .build \
    && cp /usr/share/zoneinfo/America/Boa_Vista /etc/localtime && echo "America/Boa_Vista" > /etc/timezone

COPY docker/php.ini /usr/local/etc/php/conf.d/nexplangov.ini
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

RUN rm -rf node_modules tests .git .env \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/backups bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/entrypoint

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s CMD wget -qO- http://127.0.0.1:8080/up >/dev/null || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
