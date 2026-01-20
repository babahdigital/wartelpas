FROM php:7.4-apache

# 1. Install Library Pendukung
RUN apt-get update && apt-get install -y git zip unzip sqlite3 libsqlite3-dev \
    && docker-php-ext-install mysqli pdo pdo_sqlite \
    && a2enmod rewrite \
    && apt-get clean

# 2. Copy Source Code
COPY . /var/www/html/

# 3. Setup Folder & Permission (Digabung agar efisien & minim layer)
# - mkdir: Membuat folder penting (termasuk logs) jika belum ada
# - chown: Mengubah owner default ke www-data
# - chmod 777: Memberikan akses tulis penuh ke folder data (logs, session, db, img)
#   ini SOLUSI agar bisa ditulis walau folder host terbaca sebagai root.
RUN mkdir -p /var/www/html/mikhmon_session \
             /var/www/html/db_data \
             /var/www/html/logs \
             /var/www/html/img \
    && chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && chmod -R 777 /var/www/html/mikhmon_session \
    && chmod -R 777 /var/www/html/db_data \
    && chmod -R 777 /var/www/html/img \
    && chmod -R 777 /var/www/html/logs \
    && chmod 444 /var/www/html/include/config.php