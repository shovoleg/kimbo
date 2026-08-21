FROM php:8.4-fpm-alpine

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        intl \
        zip \
        opcache \
    && apk del .build-deps \
    && apk add --no-cache postgresql-libs icu-libs libzip git

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.interned_strings_buffer=16'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

RUN { \
        echo 'memory_limit=256M'; \
        echo 'upload_max_filesize=8M'; \
        echo 'post_max_size=8M'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/app.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
