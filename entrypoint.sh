#!/bin/bash
set -e

# Pesan Log
echo "FIXING PERMISSIONS FOR MIKHMON..."

set_dir_permissions() {
    local target_dir="$1"
    if [ -d "$target_dir" ]; then
        chown -R www-data:www-data "$target_dir" || true
        find "$target_dir" -type d -exec chmod 775 {} \; || true
        find "$target_dir" -type f -exec chmod 664 {} \; || true
    fi
}

# 1. Pastikan folder runtime ada agar chmod tidak gagal saat startup
mkdir -p \
    /var/www/html/session \
    /var/www/html/db_data \
    /var/www/html/img \
    /var/www/html/logs \
    /var/www/html/report \
    /var/www/html/voucher

# 1b. Terapkan permission minimum yang tetap writable untuk runtime
set_dir_permissions /var/www/html/session
set_dir_permissions /var/www/html/db_data
set_dir_permissions /var/www/html/img
set_dir_permissions /var/www/html/logs
set_dir_permissions /var/www/html/report
set_dir_permissions /var/www/html/voucher

# 1c. Pastikan .htaccess utama ada (tanpa sinkron template legacy)
HTACCESS="/var/www/html/.htaccess"
if [ ! -f "$HTACCESS" ]; then
    echo "Creating missing .htaccess..."
    touch "$HTACCESS"
fi
chown www-data:www-data "$HTACCESS" || true
chmod 664 "$HTACCESS" || true

# 2. Pastikan file konfigurasi bisa ditulis oleh web server
if [ -f "/var/www/html/include/config.php" ]; then
    chown www-data:www-data /var/www/html/include/config.php || true
    chmod 664 /var/www/html/include/config.php || true
fi

# 2c. Bersihkan session lama agar tidak numpuk (lebih dari 7 hari)
if [ -d "/var/www/html/session" ]; then
    find /var/www/html/session -type f -name 'sess_*' -mtime +7 -delete || true
fi

# 3. Khusus folder settings agar bisa simpan config
if [ -d "/var/www/html/settings" ]; then
    set_dir_permissions /var/www/html/settings
fi

echo "PERMISSIONS FIXED. STARTING APACHE..."

# 3. Jalankan command default Docker (Apache)
exec docker-php-entrypoint apache2-foreground