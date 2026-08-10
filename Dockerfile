FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod headers rewrite expires

ENV APACHE_DOCUMENT_ROOT=/var/www/html

COPY deploy/apache/petlio.conf /etc/apache2/conf-available/petlio.conf
RUN a2enconf petlio

COPY . /var/www/html
COPY --from=vendor /app/vendor /var/www/html/vendor

RUN mkdir -p /var/lib/petlio/order-photos \
    && chown -R www-data:www-data /var/lib/petlio

EXPOSE 80

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=5 \
    CMD php -r '$$status = @get_headers("http://127.0.0.1/"); exit($$status && str_contains($$status[0], "200") ? 0 : 1);'
