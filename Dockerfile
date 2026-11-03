FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libssl-dev \
    unzip \
    git \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

ENV PORT=8080
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

# Permitir que Apache pase las variables de entorno de Render a PHP
RUN echo "PassEnv MONGODB_URI" >> /etc/apache2/apache2.conf
RUN echo "PassEnv TOKEN" >> /etc/apache2/apache2.conf

EXPOSE 8080
