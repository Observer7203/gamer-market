FROM php:8.3-fpm-alpine

RUN apk add --no-cache postgresql-dev libzip-dev git unzip \
    && docker-php-ext-install pdo pdo_pgsql pcntl \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Статический пул: параллельная обработка конкурентных запросов
RUN sed -i 's/^pm.max_children = .*/pm.max_children = 24/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^pm = .*/pm = static/' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /app
