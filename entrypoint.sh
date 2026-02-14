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

# 1b. Pastikan .htaccess ada dan tidak kosong
HTACCESS="/var/www/html/.htaccess"
HTACCESS_TEMPLATE="/var/www/html/htaccess-templated"

# Jika dua-duanya kosong/tidak ada, buat placeholder agar Apache tidak error
if { [ ! -f "$HTACCESS" ] || [ ! -s "$HTACCESS" ]; } && { [ ! -f "$HTACCESS_TEMPLATE" ] || [ ! -s "$HTACCESS_TEMPLATE" ]; }; then
    echo "Both .htaccess and template are missing/empty. Creating safe placeholders..."
    touch "$HTACCESS" "$HTACCESS_TEMPLATE"
fi

# Sinkron awal dua arah: pilih file yang berisi sebagai sumber
if [ -s "$HTACCESS_TEMPLATE" ] && { [ ! -f "$HTACCESS" ] || [ ! -s "$HTACCESS" ]; }; then
    echo "Restoring .htaccess from template..."
    cp "$HTACCESS_TEMPLATE" "$HTACCESS"
elif [ -s "$HTACCESS" ] && { [ ! -f "$HTACCESS_TEMPLATE" ] || [ ! -s "$HTACCESS_TEMPLATE" ]; }; then
    echo "Restoring htaccess-templated from .htaccess..."
    cp "$HTACCESS" "$HTACCESS_TEMPLATE"
fi

echo "Updating .htaccess and template ownership/permissions..."
chown www-data:www-data "$HTACCESS" "$HTACCESS_TEMPLATE" || true
chmod 666 "$HTACCESS" "$HTACCESS_TEMPLATE" || true

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

# 4. Sinkronisasi VIP dari env/db ke .htaccess + htaccess-templated
if [ -f "/var/www/html/tools/htaccess_vip_sync.php" ]; then
    echo "Syncing VIP whitelist into .htaccess and template..."
    if php /var/www/html/tools/htaccess_vip_sync.php; then
        echo "VIP sync completed."
    else
        echo "WARNING: VIP sync failed. Continuing startup with existing .htaccess." >&2
    fi
fi

echo "PERMISSIONS FIXED. STARTING APACHE..."

# 3. Jalankan command default Docker (Apache)
exec docker-php-entrypoint apache2-foreground