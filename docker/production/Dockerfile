# Obraz produkcyjny Udzio. Kontekst budowania: katalog główny repozytorium.
#   docker build -f docker/production/Dockerfile --target app .   (PHP-FPM + kod)
#   docker build -f docker/production/Dockerfile --target web .   (nginx + public/)

FROM php:8.3-fpm-alpine AS base
RUN apk add --no-cache icu-libs libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev \
    && docker-php-ext-install pdo_mysql bcmath intl zip opcache pcntl \
    && apk del .build-deps
COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-udzio.ini

FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

FROM base AS app
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build
COPY docker/production/entrypoint.sh /usr/local/bin/udzio-entrypoint
RUN chmod +x /usr/local/bin/udzio-entrypoint
USER www-data
ENTRYPOINT ["udzio-entrypoint"]
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS web
COPY docker/production/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
