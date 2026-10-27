FROM php:8.3-apache-bookworm
RUN docker-php-ext-install mysqli && a2enmod rewrite
RUN printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/esp-test.conf && a2enconf esp-test
