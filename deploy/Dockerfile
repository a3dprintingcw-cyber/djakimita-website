FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libjpeg-dev libpng-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install pdo_mysql gd exif \
 && rm -rf /var/lib/apt/lists/*
# Pass the Basic-Auth header through to PHP (preview lock)
RUN printf 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1\n' > /etc/apache2/conf-enabled/auth.conf
ENV APACHE_DOCUMENT_ROOT=/var/www/site/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf
COPY . /var/www/site
# Photo uploads for the one-time card check
RUN printf 'upload_max_filesize = 16M\npost_max_size = 64M\nmax_file_uploads = 12\nexpose_php = Off\n' > /usr/local/etc/php/conf.d/uploads.ini
RUN mkdir -p /var/www/site/app/cache /var/www/site/app/storage/verify \
 && chown -R www-data /var/www/site/app/cache /var/www/site/app/storage && chmod 700 /var/www/site/app/storage
