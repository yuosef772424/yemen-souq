# توافق داتاسيت Amazon Reviews 2023 مع جدول `listings`

> **الحالة:** فحص أولي — نتيجة مثبّتة قبل أي تنفيذ.
> **النطاق:** `item metadata` من داتاسيت [Amazon Reviews 2023 (McAuley Lab)](https://amazon-reviews-2023.github.io/) مقابل السكيمة الفعلية للمشروع.
> **الخلاصة مسبقاً:** الإدخال المباشر مستحيل. لا يمكن إدخال صف واحد دون كود وسيط.

---

## 1. لماذا هذا التوثيق

قبل بناء كود الوسيط (importer) لزم التأكد من أن السكيمة الفعلية تقبل البيانات أصلاً،
بدل افتراض أن الاستيراد سيعمل ثم اكتشاف الحواجز أثناء التنفيذ. هذا الملف يثبّت نتيجة
الفحص: أي عمود سيفشل، ولماذا بالضبط بالرجوع لتعريفه في الـ migration.

## 2. المصادر المفحوصة

| الجدول | ملف الـ migration |
|---|---|
| `listings`, `listing_images` | [`2026_01_01_000300_create_catalog_tables.php`](../../app/Modules/Catalog/Database/Migrations/2026_01_01_000300_create_catalog_tables.php) |
| `categories`, `locations`, `currencies` | [`2026_01_01_000100_create_shared_tables.php`](../../app/Modules/Shared/Database/Migrations/2026_01_01_000100_create_shared_tables.php) |
| `users` | [`0001_01_01_000000_create_users_table.php`](../../database/migrations/0001_01_01_000000_create_users_table.php) |

## 3. الحقول المتاحة من المصدر

```
main_category, title, average_rating, rating_number, features, description,
price, images, videos, store, categories, details, parent_asin, bought_together
```

---

## 4. نقاط الفشل

### 4.1 فشل مؤكد — الإدخال يُرفض على مستوى قاعدة البيانات

| # | العمود | تعريفه في الـ migration | السبب | نوع الفشل |
|---|---|---|---|---|
| 1 | `listings.user_id` | `foreignId('user_id')->constrained('users')->cascadeOnDelete()` — السطر 13 | أمازون تُعطي `store` كنص (مثل `"Sony"`) ولا تُعطي أي معرّف رقمي يقابل `users.id`. العمود بلا `nullable()` وبلا `default()`، ولا يوجد مسار تحويل من `store` إلى مستخدم. | `SQLSTATE[23000]` — Integrity constraint violation |
| 2 | `listings.category_id` | `foreignId('category_id')->constrained('categories')->restrictOnDelete()` — السطر 14 | أمازون تُعطي `main_category` (نص) و`categories` (مصفوفة نصوص إنجليزية). جدول `categories` يخزّن `id` عددي + `name_ar` + `slug` + `path`، ولا يحتوي عمود `name_en` أصلاً — فلا مفتاح مطابقة. | `SQLSTATE[23000]` — FK violation أو NOT NULL |
| 3 | `listings.location_id` | `foreignId('location_id')->constrained('locations')->restrictOnDelete()` — السطر 15 | حقول أمازون الأربعة عشر لا تحتوي أي حقل جغرافي من أي نوع. جدول `locations` مبني على هرم يمني (`country`/`governorate`/`district`/`area`)، ولا قيمة قابلة للاستنتاج من المصدر. | `SQLSTATE[23000]` — Integrity constraint violation |
| 4 | `listings.title_normalized` | `string('title_normalized', 180)` بلا `nullable()` وبلا `default()` — السطر 18 | العمود يتطلب ناتج تطبيع عربي محدد (بلا تشكيل، `أ/إ/آ→ا`، `ة→ه`، `ى→ي`) حسب الـ `comment` نفسه. عناوين أمازون لاتينية ولا تمرّ بهذا التطبيع. | `Field 'title_normalized' doesn't have a default value` |
| 5 | `listings.slug` | `string('slug', 220)->unique()` — السطر 20 | أمازون لا تُعطي slug إطلاقاً — يجب توليده. ومع التوليد يظهر خطر ثانٍ: العناوين المتشابهة في الداتاسيت تُنتج slugs متطابقة تصطدم بالـ unique index. | `NOT NULL` عند الإهمال، أو `Duplicate entry` عند التوليد الساذج |

### 4.2 فشل ثانوي — الإدخال قد يمر لكن البيانات تُقتطع أو تُعطب

| # | العمود | تعريفه في الـ migration | السبب | نوع الفشل |
|---|---|---|---|---|
| 6 | `listings.title` | `string('title', 180)` — السطر 17 | عناوين أمازون تتجاوز 300 حرف بانتظام (حشو كلمات مفتاحية). مع `STRICT_TRANS_TABLES` — وهو افتراضي Laravel — أي عنوان أطول من 180 يوقف الإدخال بدل أن يُقتطع بصمت. | `Data too long for column 'title'` |
| 7 | `listings.currency_code` | `char('currency_code', 3)->nullable()` + `foreign('currency_code')->references('code')->on('currencies')` — السطران 26 و61 | العمود `nullable()` فتمرير `NULL` آمن، لكن `price` في المصدر نص خام (`"$29.99"` أو `None`) يحتاج parsing. وأي قيمة مستخرجة مثل `USD` تفشل ما لم يكن الصف موجوداً مسبقاً في `currencies`. | `SQLSTATE[23000]` عند تمرير عملة غير مزروعة |
| 8 | `listing_images.path` | `string('path', 255)` بلا `nullable()` — السطر 67 | أمازون تُعطي `images` كمصفوفة URLs خارجية (`thumb`/`large`/`hi_res`)، بينما العمود يتوقع مساراً محلياً. تمرير URL يمر في SQL لكنه يكسر أي منطق عرض يبني المسار محلياً. | خطأ منطقي لا SQL — بيانات معطوبة صامتة |

---

## 5. الخلاصة

الحواجز الثلاث الأولى (`user_id`، `category_id`، `location_id`) وحدها كافية لرفض **كل**
INSERT قبل أن يصل التنفيذ إلى باقي الأعمدة — فهي `NOT NULL` ومفاتيح أجنبية إلزامية بلا
أي حقل مقابل في المصدر. أي محاولة استيراد مباشر (`INSERT ... SELECT` أو `insert()` جماعي)
تفشل من الصف الأول.

الاستيراد يتطلب كود وسيط يحلّ — كحد أدنى — الخمس نقاط في القسم 4.1 قبل أي إدخال.

## 6. الخطوة التالية المقترحة

1. **جدول تحويل التصنيفات** — mapping صريح من `main_category`/`categories` الإنجليزية إلى
   `categories.id` في المشروع، بدل الاعتماد على مطابقة نصية غير موجودة.
2. **منطق توليد قيم افتراضية** — للأعمدة الإلزامية بلا مقابل: مستخدم استيراد مخصّص لـ
   `user_id`، وموقع افتراضي لـ `location_id`، وتوليد `title_normalized` و`slug` مع ضمان التفرد.
3. **قص/رفض العناوين** الأطول من 180 حرف بقرار صريح، لا بصمت.
4. **زرع `currencies`** قبل أي استيراد يمرّر عملة.

> لا يُبنى كود الوسيط قبل إقرار هذه النقاط — هذا الملف توثيقي فقط.
