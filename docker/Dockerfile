ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        zip \
        rsync \
        subversion \
        default-mysql-client \
        libzip-dev \
    && docker-php-ext-install mysqli pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer
COPY php-cli.ini /usr/local/etc/php/conf.d/zz-universal-support-chat.ini

RUN curl -o /usr/local/bin/wp -sL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp \
    && wp --info --allow-root

WORKDIR /app
