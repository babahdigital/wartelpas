#!/bin/bash
set -e

# Pesan Log
echo "FIXING PERMISSIONS FOR MIKHMON..."

# 1. Paksa folder data agar bisa ditulis (gunakan 775 bila memungkinkan)
chmod -R 777 /var/www/html/session
chmod -R 777 /var/www/html/db_data
chmod -R 777 /var/www/html/img
chmod -R 777 /var/www/html/logs
chmod -R 777 /var/www/html/report
chmod -R 777 /var/www/html/voucher

# 1b. Pastikan .htaccess bisa ditulis dan dimiliki oleh www-data
if [ -f "/var/www/html/.htaccess" ]; then
    echo "Updating .htaccess ownership and permissions..."
    chown www-data:www-data /var/www/html/.htaccess
    chmod 666 /var/www/html/.htaccess || true
else
    # Jika file tidak ada (misal tertinggal di host), buat baru agar apache tidak error
    touch /var/www/html/.htaccess
    chown www-data:www-data /var/www/html/.htaccess
    chmod 666 /var/www/html/.htaccess
fi

# 2. Pastikan file konfigurasi bisa ditulis oleh web server
if [ -f "/var/www/html/include/config.php" ]; then
    chmod 666 /var/www/html/include/config.php || true
fi

# 2c. Bersihkan session lama agar tidak numpuk (lebih dari 7 hari)
if [ -d "/var/www/html/session" ]; then
    find /var/www/html/session -type f -name 'sess_*' -mtime +7 -delete || true
fi

# 3. Khusus folder settings agar bisa simpan config
if [ -d "/var/www/html/settings" ]; then
    chmod -R 777 /var/www/html/settings
fi

echo "PERMISSIONS FIXED. STARTING APACHE..."

# 3. Jalankan command default Docker (Apache)
exec docker-php-entrypoint apache2-foreground