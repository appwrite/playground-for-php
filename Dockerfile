FROM composer AS composer

COPY composer.json composer.json

RUN composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader

FROM php:8.6-cli-alpine@sha256:7cadc021ea2464b01da1e546c51e3d5ffe924a07ce75d169c1608457ad56879f

COPY --from=composer /app/vendor /app/vendor
COPY global.inc.php /app/global.inc.php
COPY playground.php /app/playground.php
COPY test.txt /app/test.txt
COPY resources /app/resources

WORKDIR /app
CMD ["php", "playground.php"]
