#!/bin/bash
set -e

echo "FIXING PERMISSIONS FOR MIKHMON..."

# 1. Folder DATA (Wajib www-data dan 777/775 agar tidak error)
# Session, db_data, img, logs, report, voucher adalah data dinamis
chmod -R 777 /var/www/html/session
chmod -R 777 /var/www/html/db_data
chmod -R 777 /var/www/html/img
chmod -R 777 /var/www/html/logs
chmod -R 777 /var/www/html/report
chmod -R 777 /var/www/html/voucher

# 2. File Config Spesifik (Hanya file tertentu, jangan satu folder)
if [ -f "/var/www/html/include/config.php" ]; then
    # Cukup beri akses tulis ke semua (development) atau pastikan group benar
    chmod 666 /var/www/html/include/config.php || true
fi

# BAGIAN INI DIHAPUS ATAU DIKOMENTARI
# Karena folder include adalah Source Code yang Anda edit via SFTP
# if [ -d "/var/www/html/include" ]; then
#    chown -R www-data:www-data /var/www/html/include || true
#    chmod -R 775 /var/www/html/include || true
# fi

# 3. Settings folder
if [ -d "/var/www/html/settings" ]; then
    chmod -R 777 /var/www/html/settings
fi

echo "PERMISSIONS FIXED. STARTING APACHE..."
exec docker-php-entrypoint apache2-foreground