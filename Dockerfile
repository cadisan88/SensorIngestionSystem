FROM php:8.4.27-fpm-alpine

# Use your own user id, so files created in the container belong to you on the host.
ARG HOST_UID=1000
ARG HOST_GID=1000

# Tools: the PHP extension installer and Composer.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# pdo_mysql : MariaDB connection
# redis     : phpredis client
# sockets   : used by php-amqplib for RabbitMQ
# pcntl     : lets the workers stop cleanly on SIGTERM
# bcmath    : used by php-amqplib for some number types
# opcache   : faster PHP
RUN apk add --no-cache git unzip shadow \
    && install-php-extensions pdo_mysql redis sockets pcntl bcmath opcache \
    && usermod -o -u ${HOST_UID} www-data \
    && groupmod -o -g ${HOST_GID} www-data

COPY docker/php/custom.ini ${PHP_INI_DIR}/conf.d/99-custom.ini

ENV COMPOSER_HOME=/tmp/composer

WORKDIR /var/www/html

USER www-data

# php-fpm listens on port 9000 (default of the official image).