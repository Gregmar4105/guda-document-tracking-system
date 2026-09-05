FROM php:8.3-apache

# Install required extensions and utilities
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    && docker-php-ext-install mysqli pdo_mysql \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Configure Apache to listen on port 8000
RUN sed -i 's/80/8000/g' /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf

# Configure custom virtual host
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Copy and prepare entrypoint script (safely stripping any CRLF from Windows git checkout)
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh && chmod +x /usr/local/bin/docker-entrypoint.sh

# Ensure proper permissions for web server
RUN chown -R www-data:www-data /var/www/html

# Expose port 8000 as requested
EXPOSE 8000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
