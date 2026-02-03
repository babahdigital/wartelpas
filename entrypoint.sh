#!/bin/bash
set -e

# Pesan Log
echo "FIXING PERMISSIONS FOR MIKHMON..."

# 1. Paksa folder data agar bisa ditulis (gunakan 775 bila memungkinkan)
# Ini wajib karena folder ini dimount dari Host (User 1000) tapi dipakai oleh Container (www-data)
chmod -R 777 /var/www/html/session
chmod -R 777 /var/www/html/db_data
chmod -R 777 /var/www/html/img
chmod -R 777 /var/www/html/logs
chmod -R 777 /var/www/html/report
chmod -R 777 /var/www/html/voucher

# 2. Pastikan file konfigurasi bisa ditulis oleh web server
if [ -f "/var/www/html/include/config.php" ]; then
    chown www-data:www-data /var/www/html/include/config.php || true
    chmod 664 /var/www/html/include/config.php || true
fi
if [ -f "/var/www/html/include/quickbt.php" ]; then
    chown www-data:www-data /var/www/html/include/quickbt.php || true
    chmod 664 /var/www/html/include/quickbt.php || true
fi

# 2b. Pastikan folder include writable saat dimount
if [ -d "/var/www/html/include" ]; then
    chown -R www-data:www-data /var/www/html/include || true
    chmod -R 775 /var/www/html/include || true
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