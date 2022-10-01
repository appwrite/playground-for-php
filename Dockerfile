FROM composer AS composer

COPY composer.json composer.json
COPY composer.lock composer.lock

RUN composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader

FROM php:8.1.11-alpine3.16

COPY --from=composer /app/vendor /app/vendor
COPY global.inc.php /app/global.inc.php
COPY playground.php /app/playground.php
COPY test.txt /app/test.txt

WORKDIR /app
CMD php playground.php
