FROM php:8.2-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    libpng-dev \
    curl \
    unzip \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite zip gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --no-interaction --optimize-autoloader

COPY . /var/www/html

RUN mkdir -p storage/uploads storage/exports database \
    && chown -R www-data:www-data /var/www/html \
    && sed -i 's/\r$//' /var/www/html/docker/entrypoint.sh \
    && sed -i 's/\r$//' /var/www/html/docker/healthcheck.sh \
    && chmod +x /var/www/html/docker/entrypoint.sh \
    && chmod +x /var/www/html/docker/healthcheck.sh

ENV PORT=80
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

EXPOSE 80 3000

HEALTHCHECK --interval=15s --timeout=10s --start-period=90s --retries=6 \
    CMD /var/www/html/docker/healthcheck.sh

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
