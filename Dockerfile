# Utilise l'image officielle PHP avec Apache
FROM php:8.2-apache

# Installe les extensions PHP requises
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    zip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-install pdo_mysql zip

# Active le module de réécriture Apache
RUN a2enmod rewrite

# Définit le répertoire de travail
WORKDIR /var/www/html

# Copie tout le projet dans le conteneur
COPY . /var/www/html

# Installe Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Installe les dépendances Laravel
RUN composer install --no-dev --optimize-autoloader

# Donne les bons droits à Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Expose le port 80 pour le web
EXPOSE 80
