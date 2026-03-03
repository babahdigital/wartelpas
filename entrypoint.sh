#!/bin/bash
set -e

# Pesan Log
echo "FIXING PERMISSIONS FOR MIKHMON..."

# 1. Pastikan folder runtime ada agar chmod tidak gagal saat startup
mkdir -p \
    /var/www/html/session \
    /var/www/html/db_data \
    /var/www/html/img \
    /var/www/html/logs \
    /var/www/html/report \
    /var/www/html/voucher

# 1b. Paksa folder runtime bisa ditulis
chmod -R 777 /var/www/html/session || true
chmod -R 777 /var/www/html/db_data || true
chmod -R 777 /var/www/html/img || true
chmod -R 777 /var/www/html/logs || true
chmod -R 777 /var/www/html/report || true
chmod -R 777 /var/www/html/voucher || true

# 1c. Pastikan .htaccess utama ada (tanpa sinkron template legacy)
HTACCESS="/var/www/html/.htaccess"
if [ ! -f "$HTACCESS" ]; then
    echo "Creating missing .htaccess..."
    touch "$HTACCESS"
fi
chown www-data:www-data "$HTACCESS" || true
chmod 666 "$HTACCESS" || true

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