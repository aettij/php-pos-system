FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx postgresql-dev bash

RUN docker-php-ext-install pdo_pgsql

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

COPY deploy/nginx-docker.conf /etc/nginx/http.d/default.conf
COPY deploy/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8080

CMD ["/entrypoint.sh"]
