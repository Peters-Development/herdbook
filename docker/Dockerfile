# One Dockerfile for every environment, by target:
#   base  PHP-FPM with the extensions the app needs
#   dev   base + a user with your own uid (HOST_UID); the code is bind-mounted (docker-compose.yml)
#   app   base + production config + the code (docker/docker-compose.prod.yml: app, queue, queue-checks, scheduler)
#   web   nginx with public/ copied from `app`; serves both `web` (tailscale) and `web-lan` (lan)
#
# Pinned to the patch release, not the floating 8.5-fpm: a build reuses the
# locally cached base image, so a floating tag silently keeps an old PHP.
# A PHP security release = bump this one tag.
FROM php:8.5.12-fpm AS base

RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libicu-dev \
    libonig-dev \
    libzip-dev \
    default-mysql-client \
    iputils-ping \
    dnsutils \
    && docker-php-ext-install pdo_mysql mbstring bcmath intl pcntl zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www


FROM base AS dev

ARG user=user
ARG uid=1000

COPY docker/php/overwrites.ini /usr/local/etc/php/conf.d/overwrites.ini

RUN useradd -G www-data,root -u $uid -d /home/$user $user \
    && mkdir -p /home/$user/.composer \
    && chown -R $user:$user /home/$user

USER $user


FROM base AS app

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/production.ini $PHP_INI_DIR/conf.d/99-production.ini

# Dependencies first: this layer is reused as long as composer.lock is unchanged.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .

# Runs post-autoload-dump: package discovery and `filament:upgrade`, which
# publishes the Filament assets into public/ (the `web` stage copies them).
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php/entrypoint.sh /usr/local/bin/herdbook-entrypoint
RUN chmod +x /usr/local/bin/herdbook-entrypoint

# www-data's home is /var/www (root-owned): tinker/psysh then cannot write its
# config and refuses to run. Give it a writable home of its own.
RUN install -d -o www-data -g www-data /home/www-data \
    && usermod -d /home/www-data www-data

USER www-data

ENTRYPOINT ["herdbook-entrypoint"]
CMD ["php-fpm"]


FROM nginx:stable-alpine AS web

# Rendered to /etc/nginx/conf.d/default.conf at every start, with only the
# NGINX_* variables filled in. The default listen suits `web` (tailscale);
# `web-lan` sets its own in docker/docker-compose.prod.yml.
COPY docker/nginx/production.conf.template /etc/nginx/templates/default.conf.template
ENV NGINX_LISTEN=127.0.0.1:80 \
    NGINX_ENVSUBST_FILTER=^NGINX_
COPY --from=app /var/www/public /var/www/public
