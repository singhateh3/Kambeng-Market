# Dockerfile
FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    nginx \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy application files
COPY . .

# Install dependencies WITHOUT scripts (this prevents the exit code 2 error)
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Manually run post-autoload-dump script
RUN composer run-script post-autoload-dump || true

# Generate application key
RUN php artisan key:generate

# Cache configurations for production
RUN php artisan config:cache || true
RUN php artisan route:cache || true
RUN php artisan view:cache || true

# Create nginx.conf if missing (fallback)
RUN if [ -f nginx.conf ]; then \
    cp nginx.conf /etc/nginx/nginx.conf; \
    else \
    echo 'worker_processes 1; \
    events { worker_connections 1024; } \
    http { \
    include /etc/nginx/mime.types; \
    default_type application/octet-stream; \
    sendfile on; \
    keepalive_timeout 65; \
    server { \
    listen 80; \
    server_name localhost; \
    root /var/www/public; \
    index index.php; \
    location / { \
    try_files $$uri $$uri/ /index.php?$$query_string; \
    } \
    location ~ \.php$ { \
    fastcgi_pass 127.0.0.1:9000; \
    fastcgi_index index.php; \
    fastcgi_param SCRIPT_FILENAME $$document_root$$fastcgi_script_name; \
    include fastcgi_params; \
    } \
    location ~ /\.ht { \
    deny all; \
    } \
    } \
    }' > /etc/nginx/nginx.conf; \
    fi

# Set permissions
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache && \
    chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Expose port 80
EXPOSE 80

# Start PHP-FPM and Nginx
CMD ["sh", "-c", "php-fpm & nginx -g 'daemon off;'"]