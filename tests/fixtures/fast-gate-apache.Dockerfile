FROM php:8.3-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install mysqli sqlite3 \
    && a2enmod rewrite \
    && printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' \
         > /etc/apache2/conf-available/esp-fg-test.conf \
    && a2enconf esp-fg-test \
    && rm -rf /var/lib/apt/lists/*
