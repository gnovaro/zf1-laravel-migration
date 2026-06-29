FROM php:8.4-cli

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libsqlite3-dev \
        unzip \
        git \
        curl \
    && docker-php-ext-install \
        bcmath \
        pdo_mysql \
        zip \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

ENTRYPOINT ["/app/docker-entrypoint.sh"]
