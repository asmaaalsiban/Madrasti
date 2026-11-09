# نشر مدرستي على سيرفر حقيقي (Hetzner + Ploi)

دليل خطوة بخطوة لأول نشر. الهدف: سيرفر واحد يشغّل الباك اند والفرونت اند وقاعدة البيانات، ويكفي لعدة مدارس في البداية.

> **كيف تعمل المنصة**: الفرونت اند ملفات ثابتة (بناء واحد لكل المدارس). المدرسة تُحدَّد برمز المدرسة (`X-School`) يكتبه المستخدم عند الدخول، وليس بالنطاق الفرعي. لذلك يكفي **نطاق واحد** (مثلا `app.example.com`) لكل المدارس.

## 1) ما الذي تحتاجه
| الشيء | الاقتراح |
|---|---|
| سيرفر | Hetzner Cloud `CPX21` (3 vCPU, 4GB) أو `CPX31`، نظام Ubuntu 24.04 |
| لوحة إدارة | **Ploi** (تثبّت Nginx وPHP وMySQL وSSL وتدير النشر) |
| نطاق | واحد، مع سجل `A` يشير لعنوان السيرفر |
| مكان نسخ احتياطي | **جهاز آخر** (Hetzner Storage Box أو S3). ليس نفس السيرفر |
| بريد | حساب SMTP (لإعادة كلمة المرور) |

## 2) إنشاء السيرفر وربطه بـ Ploi
1. في Hetzner: Create Server، اختاري الموقع الأقرب (Falkenstein/Helsinki)، Ubuntu 24.04، وفعّلي **Backups** (نسخة للجهاز كله، بالإضافة لنسخ المنصة).
2. في Ploi: Servers ← Create server ← Custom VPS، وانسخي أمر التثبيت وشغّليه على السيرفر كـ root. اختاري: **Web server**، **PHP 8.3**، **MySQL 8**، و**Redis**.
3. فعّلي الجدار الناري: المنافذ 22 و80 و443 فقط.

## 3) إنشاء الموقع
في Ploi: Sites ← Create site:
- Domain: `app.example.com`
- Web directory: `/public`
- Project type: **Laravel**
- Repository: `asmaaalsiban/Madrasti`، branch `main`
- **Project root**: `backend` (مهم: المشروع فيه مجلدان)

ثم فعّلي **SSL** (Let's Encrypt) من تبويب SSL.

### Nginx: توجيه الفرونت اند و`/api`
الفرونت اند يُبنى لملفات ثابتة، والـ API على `/api`. في Ploi ← Site ← **Nginx configuration** اجعلي الجذر مجلد الفرونت اند، ووجّهي `/api` و`/sanctum` لـ Laravel:

```nginx
root /home/ploi/app.example.com/frontend/dist;
index index.html;

location / {
    try_files $uri /index.html;          # React Router
}

location ~ ^/(api|storage)/ {
    root /home/ploi/app.example.com/backend/public;
    try_files $uri /index.php?$query_string;
}

location ~ \.php$ {
    root /home/ploi/app.example.com/backend/public;
    fastcgi_pass unix:/run/php/php8.3-fpm-ploi.sock;   # كما يولّدها Ploi لموقعك
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
}

client_max_body_size 20M;               # صور الواجبات والملفات
```
> اترك أسطر SSL التي يضيفها Ploi كما هي. إذا اختلف اسم مقبس PHP عندك فانسخيه من الإعداد الأصلي.

## 4) قاعدة البيانات
في Ploi ← Databases: أنشئي قاعدة `madrasati_central` ومستخدما خاصا بها.
**مهم**: المنصة تنشئ قاعدة لكل مدرسة، لذلك يحتاج مستخدم MySQL صلاحية `CREATE` على قواعد جديدة. أسهل حل: امنحي المستخدم صلاحيات على `madrasati_%`:
```sql
CREATE USER 'madrasati'@'localhost' IDENTIFIED BY '<كلمة-مرور-قوية>';
GRANT ALL PRIVILEGES ON `madrasati\_%`.* TO 'madrasati'@'localhost';
CREATE DATABASE madrasati_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
(قاعدة كل مدرسة تُسمّى `madrasati_` + رمز المدرسة، مثلا `madrasati_school1`، حسب `config/tenancy.php`. لذلك نمط الصلاحيات أعلاه يغطيها كلها.)

## 5) ملف `.env` على السيرفر
في Ploi ← Site ← Environment. **لا تضعي أي سر في Git.** القيم المهمة:

```env
APP_NAME=Madrasati
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.example.com
APP_LOCALE=ar
# APP_KEY: يولَّد بالأمر php artisan key:generate (الخطوة 6)

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=madrasati_central
DB_USERNAME=madrasati
DB_PASSWORD=...

CENTRAL_DOMAINS=app.example.com
REGISTRATION_ENABLED=false          # أوقفي التسجيل العام إن لم تحتاجيه
TRUSTED_PROXIES=                    # ضعي IP الـ load balancer فقط إن وُجد

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=redis              # انظر ملاحظة الـ queue أدناه
BROADCAST_CONNECTION=log

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@example.com

BACKUP_DISK=backups                 # قرص على جهاز آخر (الخطوة 9)
BACKUP_PASSWORD=...                 # احفظيها في مكان آمن. بدونها لا تُفتح النسخ
BACKUP_ALERT_EMAIL=you@example.com

DEFAULT_ACCOUNT_PASSWORD=...        # غيّريها عن القيمة الافتراضية
PAYMENT_GATEWAY=demo                # اتركيها demo حتى تجربي PayTabs
```
> **ملاحظة الـ queue**: جدول `jobs` موجود داخل قاعدة كل مدرسة، لذلك `QUEUE_CONNECTION=database` لا يناسب الإنتاج. استعملي **Redis** (مثبّت في الخطوة 2). المنصة تعيد المدرسة الصحيحة لكل job تلقائيا. وإن أردتِ البدء بأبسط شكل فـ `sync` يعمل (الإشعارات تُرسل أثناء الطلب نفسه، أبطأ قليلا).

مفاتيح الإشعارات على الهاتف (اختياري): `php artisan push:generate-keys` ثم ضعي سطور `VAPID_*` في `.env`. **لا تغيّريها بعد ذلك**.
بيانات الشركة للصفحات القانونية (`VITE_COMPANY_*`) تُضبط في `frontend/.env` **قبل بناء الفرونت اند** (الخطوة 6).

## 6) النشر (Deploy script)
في Ploi ← Site ← Deployment ← Deploy script:

```bash
cd /home/ploi/app.example.com

git pull origin main

# Backend
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force                # قاعدة المنصة المركزية
php artisan tenants:migrate --force        # كل المدارس
php artisan storage:link
php artisan optimize                       # config + route cache

# Frontend
cd ../frontend
npm ci
npm run build

# أعيدي تشغيل العمال ليأخذوا الكود الجديد
cd ../backend
php artisan queue:restart
# Ploi يعيد تحميل PHP-FPM تلقائيا بعد النشر. إن لم يفعل فأضيفي {RELOAD_PHP_FPM} من متغيرات Ploi
```
أول مرة فقط، على السيرفر داخل `backend`:
```bash
php artisan key:generate                  # ثم انسخي APP_KEY لمكان آمن
php artisan super-admin:create you@example.com --name="Your Name"
```
> احتفظي بنسخة من `APP_KEY`. فقدانه يجعل البيانات المشفّرة غير قابلة للقراءة.

## 7) العمليات الخلفية (بدونها لا تعمل التذكيرات)
**أ) Scheduler** (التذكيرات اليومية، النسخ الاحتياطي، الإنذارات). في Ploi ← Cron jobs أضيفي كل دقيقة:
```
* * * * * cd /home/ploi/app.example.com/backend && php artisan schedule:run >> /dev/null 2>&1
```
**ب) Queue worker**. في Ploi ← Daemons:
- Command: `php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600`
- Directory: `/home/ploi/app.example.com/backend`
- Processes: 1

## 8) إنشاء أول مدرسة
```bash
php artisan school:create school1 "اسم المدرسة" \
  --admin-name="..." --admin-email=admin@school1.example \
  --admin-password='<قوية>' --color="#006ddb" --locale=ar
```
ثم أكملي بدليل `docs/guide/school-start.md` (إعداد المدرسة وقائمة ما قبل الإطلاق). رمز المدرسة (`school1`) هو ما يكتبه المستخدمون عند الدخول.

## 9) النسخ الاحتياطي (مهم جدا)
المنصة تنسخ كل ليلة 02:30 قاعدة كل مدرسة وملفاتها (ملف مضغوط مشفّر بكلمة `BACKUP_PASSWORD`). النسخة على **نفس السيرفر** لا تحميكِ إن خسرتِ السيرفر. لذلك:
1. أنشئي Hetzner Storage Box (أو S3).
2. القرص `backups` الحالي في `backend/config/filesystems.php` محلي (`storage/app/backups`). غيّريه ليشير إليه (SFTP أو S3) واضبطي `BACKUP_DISK`.
3. جرّبي: `php artisan backup:run` ثم `php artisan backup:list school1` و`php artisan backup:status`.
4. **جرّبي الاستعادة مرة** على قاعدة تجريبية قبل الإطلاق: `php artisan backup:restore school1`. نسخة لم تُجرَّب استعادتها ليست نسخة.

## 10) الأداء
- فعّلي OPcache في PHP-FPM: `opcache.enable=1` و`opcache.validate_timestamps=0` (وأعيدي تحميل FPM عند كل نشر، موجود في السكربت).
- `php artisan optimize` عند كل نشر (موجود).
- `pm.max_children` ≈ ضعف إلى أربعة أضعاف عدد الأنوية.
- القياسات في `loadtest/README.md`.

## 11) أمان
- `APP_DEBUG=false` دائما.
- SSH بمفتاح فقط (عطّلي كلمة المرور) وجدار ناري 22/80/443.
- حسابات Super Admin بكلمات قوية، وأوقفي `REGISTRATION_ENABLED` إن لم تحتاجي التسجيل العام.
- لا تضعي `.env` أو مفاتيح PayTabs في Git أبدا. تُضاف لكل مدرسة من إعداداتها.
- فعّلي التحديثات الأمنية التلقائية: `apt install unattended-upgrades`.

## 12) قائمة التحقق بعد النشر
- [ ] `https://app.example.com` يفتح، ودخول مدير مدرسة يعمل.
- [ ] تبديل العربية/الإنجليزية والـ RTL سليم.
- [ ] رفع صورة (شعار أو واجب) يعمل (حجم `client_max_body_size`).
- [ ] إعادة كلمة المرور تصل بالبريد.
- [ ] `php artisan schedule:list` يعرض المهام، والـ daemon شغّال (Ploi ← Daemons).
- [ ] النسخ الاحتياطي ظهر على الجهاز الآخر، واستعادة تجريبية نجحت.
- [ ] `php artisan tenants:migrate --force` ينجح على كل المدارس.
- [ ] مراجعة قائمة "قبل الإطلاق" في `docs/guide/school-start.md`.

## تحديث لاحقا
ادفعي إلى `main` ← Ploi ← **Deploy** (أو فعّلي Quick deploy). السكربت يرحّل قواعد كل المدارس تلقائيا.
