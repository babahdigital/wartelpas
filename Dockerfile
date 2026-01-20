FROM php:7.4-apache

# 1. Install Library Pendukung
RUN apt-get update && apt-get install -y git zip unzip sqlite3 libsqlite3-dev \
    && docker-php-ext-install mysqli pdo pdo_sqlite \
    && a2enmod rewrite \
    && apt-get clean

# 2. Copy Source Code & Entrypoint
COPY . /var/www/html/
COPY entrypoint.sh /usr/local/bin/

# 3. Setup Permission Awal & Entrypoint
# Kita buat entrypoint.sh bisa dieksekusi
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/www/html/mikhmon_session \
    /var/www/html/db_data \
    /var/www/html/logs \
    /var/www/html/img \
    && chown -R www-data:www-data /var/www/html

# 4. Set Entrypoint
ENTRYPOINT ["entrypoint.sh"]