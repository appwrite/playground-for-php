FROM composer AS composer

COPY composer.json composer.json
COPY composer.loc[k] composer.lock

RUN composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader

FROM php:8.4-cli-alpine

COPY --from=composer /app/vendor /app/vendor
COPY global.inc.php /app/global.inc.php
COPY playground.php /app/playground.php
COPY test.txt /app/test.txt
COPY resources /app/resources

WORKDIR /app
CMD php playground.php
