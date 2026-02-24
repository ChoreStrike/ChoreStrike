# syntax=docker/dockerfile:1

# Use official Nextcloud image as base for OCP classes
FROM nextcloud:29-apache

# Install additional PHP extensions and tools needed for testing
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libxml2-dev \
    libicu-dev \
    libonig-dev \
    && docker-php-ext-install \
    zip \
    intl \
    mbstring \
    xml \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set up PHP configuration for testing
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini
RUN echo "error_reporting=E_ALL" > /usr/local/etc/php/conf.d/error_reporting.ini

# Create app directory structure
RUN mkdir -p /var/www/html/custom_apps/chorestrike

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html

# Default command
CMD ["apache2-foreground"]
