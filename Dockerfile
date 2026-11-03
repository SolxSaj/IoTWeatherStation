FROM php:8.2-apache

# Instalar dependencias del sistema y la extensión de MongoDB
RUN apt-get update && apt-get install -y \
    libssl-dev \
    unzip \
    git \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copiar los archivos del proyecto al servidor web de Apache
COPY . /var/www/html/

# Ajustar permisos si es necesario
RUN chown -R www-data:www-data /var/www/html

# Render asigna un puerto dinámico mediante la variable PORT
ENV PORT=8080
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 8080