# appwrite/appwrite 32.x needs PHP 8.5, so both stages run on it. The composer
# image is not used as a base for the same reason: it ships its own PHP.
FROM php:8.5-cli-alpine AS vendor

COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer

WORKDIR /app

COPY composer.json composer.json

RUN composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader

FROM php:8.5-cli-alpine

COPY --from=vendor /app/vendor /app/vendor
COPY global.inc.php /app/global.inc.php
COPY playground.php /app/playground.php
COPY test.txt /app/test.txt
COPY resources /app/resources

WORKDIR /app
CMD ["php", "playground.php"]
