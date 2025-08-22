# ---------- Stage 1: build coordinate transformation binaries ----------
FROM python:3.11-bookworm AS coord_builder

# Install libs needed by NumPy/Astropy/SunPy and by the final binary
RUN apt-get update && apt-get install -y --no-install-recommends \
    libopenblas0 liblapack3 gcc gfortran build-essential \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy coordinate transformation scripts from sunpy directory
COPY sunpy/ /app/sunpy/

# Install latest SunPy with all dependencies + PyInstaller
# SunPy 7.0.1 requires Python >=3.11 (we're using 3.11)
RUN pip install --no-cache-dir \
    "sunpy>=7.0" \
    "pyinstaller>=6"

# Build single-file executables
RUN pyinstaller --onefile --name heeq_to_stonyhurst /app/sunpy/heeq_to_stonyhurst.py

# ---------- Stage 2: PHP-FPM with Python binaries ----------
FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql pcntl

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Copy composer from official composer image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install runtime libs for the bundled binaries
RUN apt-get update && apt-get install -y --no-install-recommends \
    libopenblas0 liblapack3 ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# Copy the binaries built in stage 1
COPY --from=coord_builder /app/dist/heeq_to_stonyhurst /usr/local/bin/heeq_to_stonyhurst
RUN chmod +x /usr/local/bin/heeq_to_stonyhurst

WORKDIR /u/apps/site