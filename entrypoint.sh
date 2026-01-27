#!/bin/bash
set -e

# Pesan Log
echo "FIXING PERMISSIONS FOR MIKHMON..."

# 1. Paksa folder data agar bisa ditulis oleh siapa saja (777)
# Ini wajib karena folder ini dimount dari Host (User 1000) tapi dipakai oleh Container (www-data)
chmod -R 777 /var/www/html/mikhmon_session
chmod -R 777 /var/www/html/db_data
chmod -R 777 /var/www/html/img
chmod -R 777 /var/www/html/logs
chmod -R 777 /var/www/html/report
chmod -R 777 /var/www/html/voucher
chmod -R 755 /var/www/html/include/config.php

# 2. Khusus folder settings agar bisa simpan config
if [ -d "/var/www/html/settings" ]; then
    chmod -R 777 /var/www/html/settings
fi

echo "PERMISSIONS FIXED. STARTING APACHE..."

# 3. Jalankan command default Docker (Apache)
exec docker-php-entrypoint apache2-foreground