# عقد الوحدات

> **محروس بـ** `tests/Unit/Architecture/ModuleContractTest`.

## القاعدة الواحدة

**مجلد تحت `app/Modules` يعمل بمجرد وجوده.** إضافة وحدة جديدة لا تتطلب تعديل أي ملف
خارجها — لا `bootstrap/providers.php` ولا `routes/web.php` ولا `ModuleServiceProvider` نفسه.

يُكتشف تلقائياً أربعة أشياء:

| ما يُكتشف | من أين |
|---|---|
| موفّر الوحدة | `Modules/<Name>/Providers/*ServiceProvider.php` |
| الهجرات | `Modules/<Name>/Database/Migrations/` |
| القوالب | `Modules/<Name>/Resources/Views/` → `view('<name>::…')` |
| المسارات | `Modules/<Name>/Routes/*.php` |

## لماذا هذا العقد

كان المشروع يحمل **فلسفتين متناقضتين**: `ModuleServiceProvider` يكتشف الهجرات والقوالب
(«أضف مجلداً ولن تحتاج تعديل شيء»)، بينما وحدتان تعيدان تسجيل مساراتهما بنفسيهما («الوحدة
تعمل بمجرد نسخها»).

والثانية لا تصمد: وحدتان من تسع كانتا تطبّقانها، فالاكتفاء الذاتي الذي تَعِد به لم يكن
متحققاً أصلاً — وبقي التكرار مصدرَ حقيقةٍ ثانياً لموضع ملفات الوحدة.

## الموفّر يوجد حين يعمل

موفّر الوحدة يُكتب **فقط** إذا احتاج شيئاً لا يغطّيه الاكتشاف:

- ربط في الحاوية (`AnalyticsServiceProvider`)
- مراقب موديل (`CatalogServiceProvider`)
- صلاحيات (`EngagementServiceProvider`)
- أوامر artisan (`AdminServiceProvider`, `SearchServiceProvider`)

ووحدات `Identity` و`Merchant` و`Shared` بلا موفّر — لا نقصاً بل لأنها لا تحتاجه.

## فخّ وسيط `web`

ملفات مسارات الوحدات كانت ترث مجموعة `web` **ضمنياً**، لكونها تُحمَّل بـ`require` من داخل
`routes/web.php`. وتسجيلها من موفّر يُخرجها من ذلك السياق.

لذلك `ModuleServiceProvider` يلفّها صراحةً:

```php
Route::middleware('web')->group($file);
```

**بلا هذا السطر تسقط الجلسة وحماية CSRF بصمت** — بلا خطأ وبلا اختبار فاشل.

## اتجاه الاعتماد

```
Admin · Merchant · Search · Analytics
                ↓
           Engagement
                ↓
             Catalog
                ↓
        Identity · Shared
```

**`Shared` لا تعتمد على أحد.** استثناء واحد موثَّق: `HealthCheck` يقرأ `EventName` من
`Analytics` ليتحقق أن كل حدث معلَن مغطّى. موضعه الصحيح داخل `Analytics`، ويُنقل حين تُمسّ
الوحدة.

**`Search` تقرأ `Catalog` ولا تكتب فيها.** الكاتب الوحيد هو `ListingObserver` داخل
`Catalog`.

## ما يحرسه الاختبار

1. `Shared` لا تعتمد على أي وحدة أخرى
2. `Search` لا تكتب في جداول `Catalog`
3. لا موفّر وحدة يُسجَّل يدوياً، ولا مسار وحدة يُستدعى بـ`require`
4. لا موفّر يعيد تسجيل ما يكتشفه `ModuleServiceProvider`

القاعدة الثالثة هي ما يمنع الارتداد: أول وحدة تُضاف بـ`require` تُفشل الاختبار.

## ثغرة معروفة في الحراسة

الاختبار يحلّل **الاستيرادات** (`App\Modules\X`). ووحدة `Merchant` تقرأ `listings`
و`messages` و`favorites` و`listing_questions` بـ`DB::table()` مباشرة — اقتران حقيقي بجداول
وحدات أخرى **لا يظهر في تحليل الاستيرادات**، فلا تحرسه القاعدة.
