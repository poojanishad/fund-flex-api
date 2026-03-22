FROM php:8.3-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    git curl unzip \
    libzip-dev oniguruma-dev icu-dev \
    && docker-php-ext-install pdo_mysql zip intl bcmath opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files first for layer caching
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --optimize-autoloader

# Copy application
COPY . .

RUN composer run-script auto-scripts --no-interaction 2>/dev/null || true \
    && php bin/console cache:warmup --env=prod 2>/dev/null || true

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public/"]
