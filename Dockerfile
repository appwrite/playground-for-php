FROM php:8.5-cli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader

COPY global.inc.php playground.php test.txt ./
COPY resources ./resources

CMD ["php", "playground.php"]
