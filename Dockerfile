FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx postgresql-dev bash

RUN docker-php-ext-install pdo_pgsql

# Opcache config
RUN docker-php-ext-enable opcache
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=10000'; \
    echo 'opcache.revalidate_freq=60'; \
    echo 'opcache.fast_shutdown=1'; \
    echo 'realpath_cache_size=4096K'; \
    echo 'realpath_cache_ttl=600'; \
} > /usr/local/etc/php/conf.d/opcache.ini

COPY . /var/www/superma
WORKDIR /var/www/superma

RUN mkdir -p /var/www/superma/logs /var/www/superma/public/icons && \
    chown -R www-data:www-data /var/www/superma/logs

# Minimal nginx config
RUN { \
    echo 'worker_processes auto;'; \
    echo 'events { multi_accept on; worker_connections 1024; }'; \
    echo 'http {'; \
    echo '  access_log off;'; \
    echo '  include mime.types; default_type application/octet-stream; sendfile on;'; \
    echo '  server {'; \
    echo '    listen '$PORT' default_server;'; \
    echo '    root /var/www/superma;'; \
    echo '    index index.php;'; \
    echo '    add_header X-Frame-Options "DENY" always;'; \
    echo '    gzip on; gzip_types text/css application/javascript application/json;'; \
    echo '    location /api/sse { proxy_buffering off; proxy_cache off; fastcgi_pass 127.0.0.1:9000; include fastcgi_params; fastcgi_param SCRIPT_FILENAME \$document_root/index.php; fastcgi_read_timeout 120; }'; \
    echo '    location / { try_files \$uri /index.php\$is_args\$args; }'; \
    echo '    location ~ \.php$ { fastcgi_pass 127.0.0.1:9000; fastcgi_index index.php; include fastcgi_params; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; fastcgi_read_timeout 120; }'; \
    echo '    location ~ /(config|includes|logs|deploy) { deny all; }'; \
    echo '  }'; \
    echo '}'; \
} > /etc/nginx/http.d/default.conf

EXPOSE 8080

CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"
