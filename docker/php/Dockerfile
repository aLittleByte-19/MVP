FROM php:8.4-fpm-bookworm

ARG COMPOSER_INSTALL_DEV=true

RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libzip-dev \
        qpdf \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

ARG COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Install dependencies first to leverage Docker cache
COPY composer.json composer.lock ./
RUN if [ "$COMPOSER_INSTALL_DEV" = "true" ]; then \
        composer install --no-interaction --no-scripts --no-autoloader --prefer-dist; \
    else \
        composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist; \
    fi

# Copy the rest of the application
COPY . .

# Finish composer installation and apply runtime security settings.
# security.ini is copied here (after all Composer downloads) because it disables
# allow_url_fopen, which Composer needs to fetch packages via HTTPS.
RUN composer dump-autoload --no-interaction --optimize \
    && composer_hash=$(sha256sum composer.lock | awk '{ print $1 }') \
    && echo "$composer_hash" > vendor/.composer-lock.false.sha256 \
    && echo "$composer_hash" > vendor/.composer-lock.true.sha256 \
    && mkdir -p \
        storage/app/public \
        storage/app/private \
        storage/app/private/observability \
        storage/app/tmp/poc-processing \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
        /tmp/laravel-views \
    && if [ -d vendor/pestphp/pest-plugin-mutate ]; then \
        mkdir -p vendor/pestphp/pest-plugin-mutate/.temp/pest-mutate-cache; \
    fi \
    && if [ -d vendor/pestphp/pest ]; then \
        mkdir -p vendor/pestphp/pest/.temp; \
    fi \
    && chown -R www-data:www-data storage bootstrap/cache /tmp/laravel-views \
    && if [ -d vendor/pestphp/pest-plugin-mutate/.temp ]; then \
        chown -R www-data:www-data vendor/pestphp/pest-plugin-mutate/.temp; \
    fi \
    && if [ -d vendor/pestphp/pest/.temp ]; then \
        chown -R www-data:www-data vendor/pestphp/pest/.temp; \
    fi

COPY docker/php/security.ini /usr/local/etc/php/conf.d/zz-security.ini

USER www-data

CMD ["php-fpm"]
