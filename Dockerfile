FROM php:8.3-apache

# 1. Install Library Pendukung & Rclone
# Tambahkan 'curl' dan 'gnupg' untuk download rclone, lalu install rclone via script resmi
RUN apt-get update && apt-get install -y \
    git zip unzip sqlite3 libsqlite3-dev curl gnupg \
    && curl https://rclone.org/install.sh | bash \
    && docker-php-ext-install mysqli pdo pdo_sqlite opcache \
    && a2enmod rewrite headers expires deflate \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# PHP production + OPcache
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "opcache.interned_strings_buffer=8" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "opcache.max_accelerated_files=4000" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "opcache.revalidate_freq=2" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "opcache.revalidate_freq=0" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "opcache.fast_shutdown=1" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini \
    && echo "expose_php = Off" >> "$PHP_INI_DIR/php.ini"

# 2. Copy Source Code & Entrypoint
COPY . /var/www/html/
COPY entrypoint.sh /usr/local/bin/

# 3. Setup Permission Awal & Entrypoint
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/www/html/mikhmon_session \
    /var/www/html/db_data \
    /var/www/html/logs \
    /var/www/html/img \
    && chown -R www-data:www-data /var/www/html

# 4. Set Entrypoint
ENTRYPOINT ["entrypoint.sh"]