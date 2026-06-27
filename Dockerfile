FROM php:8.3-fpm

# ----------------------------
# System dependencies
# ----------------------------
RUN apt-get update && apt-get install -y \
    git curl unzip zip \
    libpng-dev libonig-dev libxml2-dev \
    nginx \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ----------------------------
# PHP extensions
# ----------------------------
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd

# ----------------------------
# Install Composer
# ----------------------------
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# ----------------------------
# Copy composer files FIRST (better layer caching)
# ----------------------------
COPY composer.json composer.lock ./

# ----------------------------
# Install dependencies
# ----------------------------
RUN COMPOSER_MEMORY_LIMIT=-1 composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs

# ----------------------------
# Copy the rest of the project
# ----------------------------
COPY . .

# ----------------------------
# Generate optimized autoloader
# --no-scripts prevents package:discover from running at build time
# (artisan can't boot without .env)
# ----------------------------
RUN COMPOSER_MEMORY_LIMIT=-1 composer dump-autoload \
    --optimize \
    --no-dev \
    --ignore-platform-reqs \
    --no-scripts

# ----------------------------
# Permissions
# ----------------------------
RUN mkdir -p storage bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

# ----------------------------
# NGINX CONFIG
# ----------------------------
RUN echo 'worker_processes 1;' > /etc/nginx/nginx.conf && \
    echo 'events { worker_connections 1024; }' >> /etc/nginx/nginx.conf && \
    echo 'http {' >> /etc/nginx/nginx.conf && \
    echo '    include /etc/nginx/mime.types;' >> /etc/nginx/nginx.conf && \
    echo '    default_type application/octet-stream;' >> /etc/nginx/nginx.conf && \
    echo '    sendfile on;' >> /etc/nginx/nginx.conf && \
    echo '    keepalive_timeout 65;' >> /etc/nginx/nginx.conf && \
    echo '    server {' >> /etc/nginx/nginx.conf && \
    echo '        listen 80;' >> /etc/nginx/nginx.conf && \
    echo '        server_name localhost;' >> /etc/nginx/nginx.conf && \
    echo '        root /var/www/public;' >> /etc/nginx/nginx.conf && \
    echo '        index index.php;' >> /etc/nginx/nginx.conf && \
    echo '        location / {' >> /etc/nginx/nginx.conf && \
    echo '            try_files $uri $uri/ /index.php?$query_string;' >> /etc/nginx/nginx.conf && \
    echo '        }' >> /etc/nginx/nginx.conf && \
    echo '        location ~ \.php$ {' >> /etc/nginx/nginx.conf && \
    echo '            fastcgi_pass 127.0.0.1:9000;' >> /etc/nginx/nginx.conf && \
    echo '            fastcgi_index index.php;' >> /etc/nginx/nginx.conf && \
    echo '            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;' >> /etc/nginx/nginx.conf && \
    echo '            include fastcgi_params;' >> /etc/nginx/nginx.conf && \
    echo '        }' >> /etc/nginx/nginx.conf && \
    echo '        location ~ /\.ht {' >> /etc/nginx/nginx.conf && \
    echo '            deny all;' >> /etc/nginx/nginx.conf && \
    echo '        }' >> /etc/nginx/nginx.conf && \
    echo '    }' >> /etc/nginx/nginx.conf && \
    echo '}' >> /etc/nginx/nginx.conf

# ----------------------------
# Expose port
# ----------------------------
EXPOSE 80

# ----------------------------
# Start: discover packages, then boot PHP-FPM + Nginx
# package:discover runs at startup when .env is available
# ----------------------------
CMD ["sh", "-c", "php artisan package:discover --ansi || true && php-fpm -D && nginx -g 'daemon off;'"]