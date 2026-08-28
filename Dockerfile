# صورة واحدة تُشغّل ثلاث خدمات — الويب والعامل والمجدول.
#
# ═══ لماذا Docker لا بناء المنصة التلقائي ═══
#
# البناء التلقائي (Nixpacks/Railpack) يخمّن الامتدادات من composer.json،
# و`gd` ليس فيه: `intervention/image` يطلبه في وقت التشغيل لا في الاعتماديات.
# فينجح البناء، ثم يسقط أول رفع صورة على خادم يبدو سليماً. والملف هنا يجعل
# القائمة صريحة ومُلتزَمة في Git.
#
# وهو أيضاً ما يجعل الانتقال من مستضيف إلى آخر تغييرَ إعداد لا إعادة بناء:
# نفس الصورة تعمل على Railway وRender وأي خادم فيه Docker.
#
# ═══ ثلاث خدمات من صورة واحدة ═══
#
#   web       — الافتراضي (FrankenPHP يخدم public/)
#   worker    — php artisan queue:work
#   scheduler — php artisan schedule:work
#
# الثلاثة نفس الكود بأمر تشغيل مختلف. وفصلها ضرورة لا ترف: عاملُ طابور
# داخل عملية الويب يموت مع كل إعادة نشر، ومجدولٌ لا يعمل يعني أن
# `sms:dispatch` و`sms:reap` لا يجريان — فتبقى الرسائل في الطابور بلا
# أن يشير شيء إلى السبب.

# ─────────────────────────────────────────────────────────────────────
# ١. أصول الواجهة
# ─────────────────────────────────────────────────────────────────────
#
# المشروع كاملاً لا `resources/` وحدها: Tailwind v4 يكتشف الأصناف
# بمسح ملفات المشروع، والقوالب موزّعة على `app/Modules/*/Resources/Views`.
# نسخُ `resources/` وحدها يُنتج CSS ينقصه كل صنف في تلك القوالب.

FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

# ─────────────────────────────────────────────────────────────────────
# ٢. اعتماديات PHP
# ─────────────────────────────────────────────────────────────────────

FROM composer:2 AS vendor

WORKDIR /app

# الملفان وحدهما أولاً: طبقة `composer install` تُعاد فقط حين يتغيّر
# القفل — لا مع كل تعديل في متحكّم
COPY composer.json composer.lock ./

# --no-scripts هنا بقصد: السكربتات تستدعي artisan، وartisan يحتاج
# الكود كاملاً وهو لم يُنسخ بعد
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction

COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ─────────────────────────────────────────────────────────────────────
# ٣. صورة التشغيل
# ─────────────────────────────────────────────────────────────────────

FROM dunglas/frankenphp:php8.4-alpine

WORKDIR /app

# gd    — intervention/image: كل رفع صورة إعلان
# pcntl — إشارات الإيقاف الرشيق: بلاه `queue:work` يُقتل وسط مهمة
#
# opcache ليس هنا: الصورة الأساس تحمله أصلاً، وإدراجه يُخرج
# «Module already installed» في كل بناء — تحذيرٌ يعتاده القارئ فيتوقف عن
# قراءة التحذيرات كلها.
RUN install-php-extensions \
    gd \
    pdo_mysql \
    pcntl \
    bcmath \
    zip \
    intl \
    exif

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

RUN chown -R www-data:www-data storage bootstrap/cache

# ═══ SERVER_NAME=:80 لا اسم نطاق ═══
#
# قالب Caddy الافتراضي يقرأ `{$SERVER_NAME:localhost}`، و«localhost» يدفع
# Caddy إلى إصدار شهادة TLS لنفسه. وخلف وسيط المنصة — وهو من ينهي TLS
# أصلاً — يعني ذلك خادماً يستمع على منفذ لا يناديه أحد، فتظهر الخدمة
# «سليمة» ولا تصل إليها طلبات.
#
# النقطتان بلا اسم: استمع على 80 لكل اسم وارد.
ENV SERVER_NAME=:80
ENV SERVER_ROOT=/app/public

ENTRYPOINT ["entrypoint"]

# صريح لا موروث: تعريف ENTRYPOINT يمسح CMD الموروثة من الصورة الأساس.
# والوسائط هنا نفس وسائطها.
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
