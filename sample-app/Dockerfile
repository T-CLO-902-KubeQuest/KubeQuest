# Image Docker officielle PHP 8.3 avec Apache.
# Tag mineur (non figé sur un patch) pour récupérer les correctifs de sécurité.
FROM php:8.3-apache

# Installez les extensions PHP nécessaires
RUN docker-php-ext-install pdo_mysql

# Patche les paquets OS de l'image de base, puis installe les dépendances.
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends git unzip p7zip-full \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Installez Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copiez les fichiers de l'application dans le conteneur
COPY . /var/www/html/

# Installez les dépendances de l'application
RUN composer install

RUN chown -R www-data:www-data /var/www/html/vendor /var/www/html/storage /var/www/html/bootstrap /var/www/html/public /var/www/html/app /var/www/html/config /var/www/html/routes /var/www/html/resources
RUN chmod -R 755 /var/www/html/vendor /var/www/html/storage /var/www/html/bootstrap /var/www/html/public /var/www/html/app /var/www/html/config /var/www/html/routes /var/www/html/resources

# Modifiez la configuration d'Apache pour pointer vers le répertoire public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Activez le module Apache Rewrite
RUN a2enmod rewrite

# Apache écoute sur un port non privilégié (8080) afin de tourner en non-root
# et satisfaire les policies Kyverno en mode Enforce (runAsNonRoot, drop ALL caps).
RUN sed -ri -e 's!^Listen 80$!Listen 8080!' /etc/apache2/ports.conf \
    && sed -ri -e 's!<VirtualHost \*:80>!<VirtualHost *:8080>!' /etc/apache2/sites-available/*.conf \
    && chown -R www-data:www-data /var/run/apache2 /var/log/apache2 /var/lib/apache2

# Tournez en tant qu'utilisateur non privilégié www-data.
USER www-data

# Exposez le port non privilégié
EXPOSE 8080