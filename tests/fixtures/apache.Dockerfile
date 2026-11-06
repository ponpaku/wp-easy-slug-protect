FROM php:8.3-apache-bookworm
# Only this disposable CI image enables PDF rasterization to test the actual
# wp_generate_attachment_metadata() path, including wp_unique_filename().
RUN apt-get update \
    && apt-get install -y --no-install-recommends libmagickwand-dev ghostscript \
    && pecl install imagick-3.8.0 \
    && docker-php-ext-enable imagick \
    && sed -i 's/rights="none" pattern="PDF"/rights="read" pattern="PDF"/' /etc/ImageMagick-6/policy.xml \
    && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install mysqli && a2enmod rewrite
RUN printf '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/esp-test.conf && a2enconf esp-test
