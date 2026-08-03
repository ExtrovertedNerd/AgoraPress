# AgoraPress local development image.
# PHP 8.3 + Apache with extensions required by SPEC.md §1.
FROM php:8.3-apache-bookworm

LABEL org.opencontainers.image.title="AgoraPress"
LABEL org.opencontainers.image.description="Lightweight free-forever CMS + forums (dev image)"
LABEL org.opencontainers.image.source="https://github.com/ExtrovertedNerd/AgoraPress"

# System libraries for PHP extensions + curl for healthchecks.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Pretty permalinks need .htaccess (AllowOverride All).
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# Sensible PHP defaults for local dev (uploads, memory).
COPY docker/php-agorapress.ini /usr/local/etc/php/conf.d/agorapress.ini

WORKDIR /var/www/html

# Bind-mounted project code is the document root in docker-compose.
EXPOSE 80

# 503 is expected before install (index.php "not installed" placeholder).
HEALTHCHECK --interval=10s --timeout=5s --start-period=20s --retries=5 \
    CMD code=$(curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1/) \
        && echo "$code" | grep -Eq '^(200|301|302|503)$'

