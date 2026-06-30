FROM php:8.4-fpm

# ----------------------------
# Environment Variables
# ----------------------------
ENV COMPOSER_MEMORY_LIMIT=-1
ENV PHP_OPCACHE_ENABLE=1
ENV PHP_OPCACHE_ENABLE_CLI=1
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
ENV PHP_OPCACHE_REVALIDATE_FREQ=60
ENV PHP_OPCACHE_MAX_ACCELERATED_FILES=20000
ENV PHP_OPCACHE_MEMORY_CONSUMPTION=192
ENV PHP_OPCACHE_MAX_WASTED_PERCENTAGE=10

# ----------------------------
# System dependencies
# ----------------------------
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    zip \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    nginx \
    supervisor \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# ----------------------------
# PHP extensions
# ----------------------------
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    opcache

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
RUN composer install \
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
# ----------------------------
RUN composer dump-autoload \
    --optimize \
    --no-dev \
    --ignore-platform-reqs \
    --no-scripts

# ----------------------------
# Optimize Laravel
# ----------------------------
# RUN php artisan config:cache && \
#     php artisan route:cache && \
#     php artisan view:cache && \
#     php artisan event:cache

# ----------------------------
# Permissions
# ----------------------------
RUN mkdir -p storage bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache && \
    chown -R www-data:www-data /var/www && \
    chmod -R 755 /var/www

# ----------------------------
# OPcache Configuration
# ----------------------------
RUN echo '[opcache]' > /usr/local/etc/php/conf.d/opcache.ini && \
    echo 'opcache.enable=1' >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo 'opcache.enable_cli=1' >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo 'opcache.validate_timestamps=0' >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo 'opcache.revalidate_freq=60' >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo 'opcache.max_accelerated_files=20000' >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo 'opcache.memory_consumption=192' >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo 'opcache.max_wasted_percentage=10' >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo 'opcache.interned_strings_buffer=16' >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo 'opcache.fast_shutdown=1' >> /usr/local/etc/php/conf.d/opcache.ini

# ----------------------------
# PHP-FPM Configuration
# ----------------------------
RUN echo 'pm.max_children = 20' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    echo 'pm.start_servers = 3' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    echo 'pm.min_spare_servers = 2' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    echo 'pm.max_spare_servers = 5' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    echo 'pm.max_requests = 500' >> /usr/local/etc/php-fpm.d/zz-docker.conf

# ----------------------------
# NGINX CONFIG
# ----------------------------
RUN echo 'worker_processes auto;' > /etc/nginx/nginx.conf && \
    echo 'worker_rlimit_nofile 4096;' >> /etc/nginx/nginx.conf && \
    echo 'events {' >> /etc/nginx/nginx.conf && \
    echo '    worker_connections 1024;' >> /etc/nginx/nginx.conf && \
    echo '    multi_accept on;' >> /etc/nginx/nginx.conf && \
    echo '    use epoll;' >> /etc/nginx/nginx.conf && \
    echo '}' >> /etc/nginx/nginx.conf && \
    echo 'http {' >> /etc/nginx/nginx.conf && \
    echo '    include /etc/nginx/mime.types;' >> /etc/nginx/nginx.conf && \
    echo '    default_type application/octet-stream;' >> /etc/nginx/nginx.conf && \
    echo '    sendfile on;' >> /etc/nginx/nginx.conf && \
    echo '    tcp_nopush on;' >> /etc/nginx/nginx.conf && \
    echo '    tcp_nodelay on;' >> /etc/nginx/nginx.conf && \
    echo '    keepalive_timeout 65;' >> /etc/nginx/nginx.conf && \
    echo '    keepalive_requests 100;' >> /etc/nginx/nginx.conf && \
    echo '    types_hash_max_size 2048;' >> /etc/nginx/nginx.conf && \
    echo '    client_max_body_size 100M;' >> /etc/nginx/nginx.conf && \
    echo '    gzip on;' >> /etc/nginx/nginx.conf && \
    echo '    gzip_vary on;' >> /etc/nginx/nginx.conf && \
    echo '    gzip_proxied any;' >> /etc/nginx/nginx.conf && \
    echo '    gzip_comp_level 6;' >> /etc/nginx/nginx.conf && \
    echo '    gzip_types text/plain text/css text/xml text/javascript application/json application/javascript application/xml+rss application/rss+xml;' >> /etc/nginx/nginx.conf && \
    echo '    server {' >> /etc/nginx/nginx.conf && \
    echo '        listen 80;' >> /etc/nginx/nginx.conf && \
    echo '        server_name localhost;' >> /etc/nginx/nginx.conf && \
    echo '        root /var/www/public;' >> /etc/nginx/nginx.conf && \
    echo '        index index.php;' >> /etc/nginx/nginx.conf && \
    echo '        charset utf-8;' >> /etc/nginx/nginx.conf && \
    echo '        client_max_body_size 100M;' >> /etc/nginx/nginx.conf && \
    echo '        location / {' >> /etc/nginx/nginx.conf && \
    echo '            try_files $uri $uri/ /index.php?$query_string;' >> /etc/nginx/nginx.conf && \
    echo '        }' >> /etc/nginx/nginx.conf && \
    echo '        location ~ \.php$ {' >> /etc/nginx/nginx.conf && \
    echo '            fastcgi_pass 127.0.0.1:9000;' >> /etc/nginx/nginx.conf && \
    echo '            fastcgi_index index.php;' >> /etc/nginx/nginx.conf && \
    echo '            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;' >> /etc/nginx/nginx.conf && \
    echo '            fastcgi_buffers 16 16k;' >> /etc/nginx/nginx.conf && \
    echo '            fastcgi_buffer_size 32k;' >> /etc/nginx/nginx.conf && \
    echo '            fastcgi_read_timeout 300;' >> /etc/nginx/nginx.conf && \
    echo '            include fastcgi_params;' >> /etc/nginx/nginx.conf && \
    echo '        }' >> /etc/nginx/nginx.conf && \
    echo '        location ~ /\.ht {' >> /etc/nginx/nginx.conf && \
    echo '            deny all;' >> /etc/nginx/nginx.conf && \
    echo '        }' >> /etc/nginx/nginx.conf && \
    echo '        location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|webp)$ {' >> /etc/nginx/nginx.conf && \
    echo '            expires 1y;' >> /etc/nginx/nginx.conf && \
    echo '            add_header Cache-Control "public, immutable";' >> /etc/nginx/nginx.conf && \
    echo '        }' >> /etc/nginx/nginx.conf && \
    echo '    }' >> /etc/nginx/nginx.conf && \
    echo '}' >> /etc/nginx/nginx.conf

# ----------------------------
# Supervisor Configuration (for process management)
# ----------------------------
RUN mkdir -p /etc/supervisor/conf.d
RUN echo '[supervisord]' > /etc/supervisor/conf.d/supervisord.conf && \
    echo 'nodaemon=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'user=root' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'logfile=/var/log/supervisord.log' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'pidfile=/var/run/supervisord.pid' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '[program:php-fpm]' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'command=/usr/local/sbin/php-fpm -F' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autostart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autorestart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile=/dev/stdout' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile=/dev/stderr' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '[program:nginx]' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'command=/usr/sbin/nginx -g "daemon off;"' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autostart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autorestart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile=/dev/stdout' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile=/dev/stderr' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf

# ----------------------------
# Expose ports
# ----------------------------
EXPOSE 80

# ----------------------------
# Health Check
# ----------------------------
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/api/health || exit 1

# ----------------------------
# Start: migrate then boot with Supervisor
# Images are served from Cloudinary, no storage:link needed
# ----------------------------
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache && php artisan migrate --force && php artisan package:discover --ansi || true && supervisord -c /etc/supervisor/conf.d/supervisord.conf"]
