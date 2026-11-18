# syntax=docker/dockerfile:1

# The architecture the image is built for, set by BuildKit
ARG TARGETARCH

FROM composer:2.6 AS composer

# The Tailwind CSS standalone command line tool, pinned by version and by the checksums published with the release
FROM scratch AS tailwind-amd64
ADD --checksum=sha256:e47695cee3f0d90623a91f3939b08bdee610eb5ad99340adff9d4145b284d6cc --chmod=755 \
    https://github.com/tailwindlabs/tailwindcss/releases/download/v4.3.5/tailwindcss-linux-x64 /tailwindcss

FROM scratch AS tailwind-arm64
ADD --checksum=sha256:ffcd0d21dbd7c605dc12743ff0c0b47debc963c86b399674084c1c8f2c96ecad --chmod=755 \
    https://github.com/tailwindlabs/tailwindcss/releases/download/v4.3.5/tailwindcss-linux-arm64 /tailwindcss

FROM tailwind-${TARGETARCH} AS tailwind

# Builds the stylesheet; runs as the one-shot `assets` service
FROM php:8.5.12-apache AS assets
COPY --from=tailwind /tailwindcss /usr/local/bin/tailwindcss

# Use the official PHP image as a base image to construct our own image from
FROM php:8.5.12-apache AS app

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
