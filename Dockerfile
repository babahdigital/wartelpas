FROM php:7.4-apache

# Install Library Pendukung & SQLite Driver
RUN apt-get update && apt-get install -y git zip unzip sqlite3 libsqlite3-dev \
    && docker-php-ext-install mysqli pdo pdo_sqlite \
    && a2enmod rewrite \
    && apt-get clean

# Copy Source Code dari Folder Lokal (Lebih cepat & aman daripada git clone ulang)
COPY . /var/www/html/

# Buat Folder Data jika belum ada
RUN mkdir -p /var/www/html/mikhmon_session /var/www/html/db_data

# SET PERMISSION (KEAMANAN)
# 1. Set Owner ke www-data (Apache User)
RUN chown -R www-data:www-data /var/www/html

# 2. Set Permission Standar (Folder 755, File 644)
RUN find /var/www/html -type d -exec chmod 755 {} \;
RUN find /var/www/html -type f -exec chmod 644 {} \;

# 3. Kunci Config & File Sensitif (Hanya bisa dibaca, tidak bisa diedit via script/shell)
RUN chmod 444 /var/www/html/include/config.php

# 4. Beri akses tulis HANYA ke folder yang butuh (Session, Database, IMG untuk upload logo)
RUN chmod -R 777 /var/www/html/mikhmon_session
RUN chmod -R 777 /var/www/html/db_data
RUN chmod -R 777 /var/www/html/img