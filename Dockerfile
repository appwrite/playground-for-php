FROM composer AS composer

COPY composer.json composer.json

RUN composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader

FROM php:8.5-cli-alpine@sha256:b9d7db51a2b83c41420ba04de0b90e6078d4b6440e5cc480fc4dc51b18e0b6db

COPY --from=composer /app/vendor /app/vendor
COPY global.inc.php /app/global.inc.php
COPY playground.php /app/playground.php
COPY test.txt /app/test.txt
COPY resources /app/resources

WORKDIR /app
CMD ["php", "playground.php"]
