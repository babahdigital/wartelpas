FROM php:7.4-apache

# Install ekstensi yang dibutuhkan Mikhmon
RUN apt-get update && apt-get install -y git zip unzip \
    && docker-php-ext-install mysqli \
    && a2enmod rewrite

# Download Mikhmon V3 langsung dari Official Repo Laksa19
WORKDIR /var/www/html
RUN rm -rf * \
    && git clone https://github.com/laksa19/mikhmonv3.git . \
    && chown -R www-data:www-data /var/www/html

# Setting Permission agar data bisa disimpan
RUN chmod -R 777 /var/www/html