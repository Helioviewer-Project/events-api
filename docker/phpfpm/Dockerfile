FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql

# Copy composer from official composer image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /u/apps/site