FROM composer:2.6 AS composer

# Use the official PHP image as a base image to construct our own image from
FROM php:8.5.12-apache

# Install and enable mysql modules for PHP
RUN docker-php-ext-install mysqli pdo pdo_mysql && \
    docker-php-ext-enable mysqli pdo pdo_mysql

# Set an environment variable which contains the apache document root
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

# Update the apache configuration using the `APACHE_DOCUMENT_ROOT` environment variable
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Send every request for a path that is not a file to the front controller
RUN printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n    FallbackResource /index.php\n</Directory>\n' > /etc/apache2/conf-available/front-controller.conf && \
    a2enconf front-controller

# Log PHP errors instead of showing them in responses
RUN printf 'display_errors = Off\nlog_errors = On\n' > "$PHP_INI_DIR/conf.d/errors.ini"
