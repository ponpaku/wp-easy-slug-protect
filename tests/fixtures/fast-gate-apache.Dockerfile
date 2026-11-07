FROM php:8.3-apache-bookworm
RUN docker-php-ext-install mysqli \
    && php -r 'if (!class_exists("SQLite3")) exit(1);' \
    && a2enmod rewrite \
    && printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' \
         > /etc/apache2/conf-available/esp-fg-test.conf \
    && a2enconf esp-fg-test
