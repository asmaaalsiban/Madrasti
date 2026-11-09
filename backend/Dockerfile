# استخدام صورة PHP رسمية
FROM php:8.2-fpm

# تثبيت حزم النظام المطلوبة ومكتبات قاعدة بيانات PostgreSQL
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql bcmath

# تثبيت Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# تحديد مجلد العمل
WORKDIR /var/www/html

# نسخ ملفات المشروع
COPY . .

# تثبيت حزم لارافيل
RUN composer install --no-dev --optimize-autoloader

# إعطاء الصلاحيات اللازمة لمجلدات التخزين والكاش
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# فتح البورت المطلوب
EXPOSE 8080

# أمر التشغيل الصحيح لإنتاج لارافيل عبر البورت المخصص
CMD php artisan config:clear && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8080
