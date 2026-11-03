# Back-office NOCTILUZ para Render (Web Service → Docker). PHP 8.3 + Apache + extensión de PostgreSQL.
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

# Solo la carpeta public/ queda expuesta a Internet; app/ y database/ quedan fuera del DocumentRoot.
ENV APACHE_DOCUMENT_ROOT=/var/www/backoffice/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
 && sed -ri 's!^Listen 80$!Listen ${PORT}!' /etc/apache2/ports.conf \
 && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!' /etc/apache2/sites-available/000-default.conf \
 && echo 'ServerTokens Prod\nServerSignature Off' >> /etc/apache2/conf-available/security.conf \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'expose_php = Off\ndisplay_errors = Off\nlog_errors = On\n' > /usr/local/etc/php/conf.d/seguridad.ini

# Render indica el puerto en $PORT (10000 por defecto)
ENV PORT=10000
COPY app/ /var/www/backoffice/app/
COPY database/ /var/www/backoffice/database/
COPY public/ /var/www/backoffice/public/
RUN chown -R www-data:www-data /var/www/backoffice

EXPOSE 10000
