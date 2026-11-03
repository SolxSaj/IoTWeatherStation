FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libssl-dev \
    unzip \
    git \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

ENV PORT=8080
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

# Habilitar mod_rewrite si tu proyecto lo usa
RUN a2enmod rewrite

EXPOSE 8080
