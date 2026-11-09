# استخدام صورة PHP رسمية مع الحزم المطلوبة
FROM php:8.2-fpm

# تثبيت الحزم ونظام التشغيل اللازم لعمل لارافيل
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# تثبيت Composer (مدير حزم PHP)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# تحديد مجلد العمل داخل السيرفر
WORKDIR /var/www/html

# نسخ ملفات المشروع إلى السيرفر
COPY . .

# تثبيت الحزم عبر Composer
RUN composer install --no-dev --optimize-autoloader

# إعطاء صلاحيات التخزين والكاش
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# فتح البورت المناسب لتشغيل السيرفر
EXPOSE 8080

# أمر تشغيل السيرفر
CMD php artisan serve --host=0.0.0.0 --port=8080
