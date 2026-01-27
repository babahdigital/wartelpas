#!/bin/bash
set -e

# Pesan Log
echo "FIXING PERMISSIONS FOR MIKHMON..."

# 1. Paksa folder data agar bisa ditulis (gunakan 775 bila memungkinkan)
# Ini wajib karena folder ini dimount dari Host (User 1000) tapi dipakai oleh Container (www-data)
chmod -R 777 /var/www/html/mikhmon_session
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

# 3. Khusus folder settings agar bisa simpan config
if [ -d "/var/www/html/settings" ]; then
    chmod -R 777 /var/www/html/settings
fi

echo "PERMISSIONS FIXED. STARTING APACHE..."

# 3. Jalankan command default Docker (Apache)
exec docker-php-entrypoint apache2-foreground