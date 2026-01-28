# PHP 8.2 versiyasini yuklaymiz
FROM php:8.2-cli

# Kerakli tizim kutubxonalarini o'rnatamiz (zip va sqlite uchun)
RUN apt-get update && apt-get install -y \
    zlib1g-dev \
    libzip-dev \
    unzip \
    libsqlite3-dev

# PHP kengaytmalarini (extensions) o'rnatamiz: SQLite ishlashi uchun
RUN docker-php-ext-install zip pdo pdo_sqlite

# Loyiha fayllarini konteyner ichiga nusxalaymiz
COPY . /var/www/html

# Ishchi papkani belgilaymiz
WORKDIR /var/www/html

# Render odatda 10000-portni ishlatadi, shuni ochamiz
EXPOSE 10000

# Serverni ishga tushirish buyrug'i
CMD ["php", "-S", "0.0.0.0:10000", "index.php"]
