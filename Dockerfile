FROM php:8.3-fpm-alpine

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install pdo_mysql pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install dependencies first so this layer is cached while only the code changes.
# Dev dependencies are included so the test suite can be run inside the container.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .

# Configuration comes from environment variables (docker-compose.yml). The empty .env only
# exists because some artisan commands (e.g. `artisan test`) expect the file to be there.
RUN composer dump-autoload --optimize \
    && touch .env \
    && chown -R www-data:www-data storage bootstrap/cache

CMD ["php-fpm"]
