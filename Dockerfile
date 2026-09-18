FROM php:8.3-cli

# Install system deps + Postgres client libs
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl libonig-dev libxml2-dev libsqlite3-dev libpq-dev zip unzip \
    && docker-php-ext-install pdo_sqlite pdo_mysql pdo_pgsql opcache \
    && docker-php-ext-enable opcache \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy source
COPY . .

# Storage writable + config from template if absent
RUN mkdir -p storage/logs storage/sessions storage/backups \
    && chmod -R 777 storage \
    && if [ ! -f config.php ]; then cp config.sample.php config.php; fi

EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t public public/index.php"]
