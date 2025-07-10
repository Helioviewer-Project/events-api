FROM php:8.4-fpm

# Copy composer from official composer image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /u/apps/site