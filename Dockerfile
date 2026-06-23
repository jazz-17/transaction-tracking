# ============================================
# Base stage: PHP 8.4 FPM with extensions
# ============================================
FROM composer:2@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267 AS composer

FROM php:8.4-fpm-bookworm@sha256:66cf4b823e8dcde762ffa705b8589d592d709e0705ce6fdcd832d9a7ea4ed0f3 AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        zip \
        intl \
        pcntl \
        bcmath \
        opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN groupadd -g 1000 www && useradd -u 1000 -g www -m www

# ============================================
# Vendor stage: install PHP dependencies
# ============================================
FROM base AS vendor

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
ENV COMPOSER_MAX_PARALLEL_HTTP=4
RUN set -eux; \
    for attempt in 1 2 3 4 5; do \
        composer install --no-dev --no-scripts --no-interaction --no-progress --optimize-autoloader && exit 0; \
        echo "Composer install failed (attempt ${attempt}/5), retrying..."; \
        sleep $((attempt * 5)); \
    done; \
    exit 1

# ============================================
# Wayfinder stage: generate TypeScript types
# ============================================
FROM vendor AS wayfinder

COPY . .
RUN mkdir -p \
        storage/app \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache
RUN composer dump-autoload --optimize \
    && php artisan wayfinder:generate --with-form

# ============================================
# Node build stage (frontend assets)
# ============================================
FROM node:22-slim@sha256:d9f850096136edbc402debdd8729579a288aac64574ada0ff4db26b6ae58b0b2 AS node-build

WORKDIR /build

COPY package.json package-lock.json ./
ENV npm_config_audit=false \
    npm_config_fund=false \
    npm_config_fetch_retries=5 \
    npm_config_fetch_retry_factor=2 \
    npm_config_fetch_retry_mintimeout=10000 \
    npm_config_fetch_retry_maxtimeout=120000
RUN set -eux; \
    for attempt in 1 2 3 4 5; do \
        npm ci && exit 0; \
        echo "npm ci failed (attempt ${attempt}/5), retrying..."; \
        sleep $((attempt * 5)); \
    done; \
    exit 1

COPY . .
COPY --from=wayfinder /var/www/html/resources/js/actions resources/js/actions
COPY --from=wayfinder /var/www/html/resources/js/routes resources/js/routes
COPY --from=wayfinder /var/www/html/resources/js/wayfinder resources/js/wayfinder

ENV VITE_SKIP_WAYFINDER=1
RUN npm run build

# ============================================
# Production
# ============================================
FROM base AS prod

COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

COPY --from=vendor /var/www/html/vendor /var/www/html/vendor

COPY . .

COPY --from=node-build /build/public/build public/build

RUN mkdir -p \
        storage/app \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache
RUN composer dump-autoload --optimize \
    && php artisan view:cache

RUN chown -R www:www storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# ============================================
# Nginx stage (serves static files + proxies PHP)
# ============================================
FROM nginx:alpine@sha256:20316569d8f81a160065d7d2a5eeffc7ca97d79022462ee255fd23fa103a6b5c AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /var/www/html/public /var/www/html/public
