FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    nginx \
    ffmpeg \
    python3 \
    curl \
    zip \
    unzip \
    git \
    nodejs \
    npm \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    && rm -rf /var/lib/apt/lists/*

RUN curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    -o /usr/local/bin/yt-dlp && chmod a+rx /usr/local/bin/yt-dlp

RUN docker-php-ext-install pdo_mysql pcntl zip
RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --optimize
RUN cp .env.example .env && php artisan key:generate --force

RUN npm ci && npm run build

COPY docker/nginx/default.conf /etc/nginx/sites-available/default

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/horizon-entrypoint.sh /horizon-entrypoint.sh
RUN chmod +x /entrypoint.sh /horizon-entrypoint.sh

EXPOSE 80
CMD ["/entrypoint.sh"]
