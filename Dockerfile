# One image, three commands (web / queue:work / schedule:work) — see 04-deployment.md.
FROM php:8.4-fpm-alpine AS base
RUN apk add --no-cache nginx $PHPIZE_DEPS \
 && docker-php-ext-install pdo_mysql opcache pcntl \
 && pecl install redis && docker-php-ext-enable redis
WORKDIR /var/www/html
COPY . .
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
 && composer install --no-dev --optimize-autoloader --no-interaction
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
