FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libzip-dev \
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

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Install dependencies first to leverage Docker cache
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

# Copy the rest of the application
COPY . .

# Finish composer installation
RUN composer dump-autoload --no-interaction --optimize \
    && composer_hash=$(sha256sum composer.lock | awk '{ print $1 }') \
    && echo "$composer_hash" > vendor/.composer-lock.false.sha256 \
    && echo "$composer_hash" > vendor/.composer-lock.true.sha256

COPY docker/php/entrypoint.sh /usr/local/bin/poc-entrypoint
RUN chmod +x /usr/local/bin/poc-entrypoint

ENTRYPOINT ["poc-entrypoint"]
CMD ["php-fpm"]
