# السكيمة المحذوفة

> **الحالة:** سجلّ حيّ — يحرسه `tests/Unit/Architecture/RemovedSchemaTest`.
> **القاعدة:** كل جدول في المشروع يشير إليه كود التطبيق. ما لا يشير إليه شيء يُحذف،
> ويُحفَظ تعريفه هنا كاملاً ليُعاد يوم تُبنى ميزته. لا ثالث.

## ما تغيّر عن السجلّ السابق

هذا الملف يحلّ محلّ `deferred-schema.md`. ذاك كان يقول: «الجدول باقٍ وتأجيله معلَن». وهذا
يقول: **الجدول محذوف وتعريفه محفوظ.**

سبب التبديل أن التأجيل المعلَن لم يحلّ المشكلة التي وُجد لأجلها. الوعد الكاذب لا يقع في
ملف التوثيق بل في **القاعدة نفسها**: من يفتح المخطط يقرأ `auctions` ولا يقرأ سجلّاً في
`docs/`. ومربّع «يسمح بالمزاد» في لوحة الإدارة كان يُؤشَّر ولا يفعل شيئاً — وأيّ توثيق
لا يمنع ذلك.

والحذف لا يفقد شيئاً: التعريف الكامل أدناه، ونسخُه إلى هجرة جديدة أرخص من إبقاء ستة
جداول فارغة تُقرأ سنواتٍ على أنها تعمل. والأهم أن ما سيُبنى يوم يُبنى **سيختلف** عن هذه
التعاريف — كُتبت كلها قبل أن يمسّها كود، فلم يختبرها استعمال.

---

## ما حُذف

| ما حُذف | أين كان | لماذا حُذف | متى يُعاد |
|---|---|---|---|
| `auctions` | Engagement | مزادات بلا سطر تنفيذ | مع بناء المزاد |
| `bids` | Engagement | العروض على المزاد | مع `auctions` |
| `saved_searches` | Engagement | بحث محفوظ بتنبيه — تنقصه مهمّة المطابقة | حين تُبنى المهمّة |
| `reviews` | Engagement | **لن يُعاد** — قرار صريح، انظر أدناه | — |
| `search_index` | Search | نسخة مسطّحة من `listings`، و`search_blob` يؤدّي الغرض | حين يفرضه الحجم |
| `password_reset_tokens` | لارافل | الاستعادة بالجوال، والجدول لم يُلمس قط | إن أُضيفت استعادة بالبريد |
| `categories.allows_auction` | Shared | مفتاح في لوحة الإدارة لا يفتح شيئاً | مع `auctions` |
| `listings.sale_type` | Catalog | قيمتان إحداهما محذوفة — سؤالٌ بجواب واحد | مع `auctions` |

**الحذف في موضعين:** من الهجرة الأصلية ليخرج التثبيت الجديد نظيفاً، وبهجرة `drop…` لتلحق
القواعد المهاجَرة أصلاً. الاكتفاء بالأولى يترك الجداول قائمةً عند من شغّل المشروع قبل
اليوم؛ والاكتفاء بالثانية يُنشئ الجداول ثم يُسقطها في كل تثبيت جديد.

وهجرات الإسقاط **بلا `down()`**: التراجع يعني إعادة إنشاء ما قُرِّر حذفه. المصدر لإعادة
البناء هو هذا الملف، لا `down()` في هجرة.

---

## `reviews` — مرفوض لا مؤجَّل

القرار ألّا يُبنى شيء عليه، وهو أقدم من هذا الحذف.

**السبب:** أي تقييم يفترض أن المقيِّم اشترى فعلاً. والمنصة لا تعرف ذلك ولا تستطيع: لا دفع
داخلها ولا تسليم ولا طرف ثالث. فكل إثبات ممكن — محادثة، كشف رقم، علامة «مباع» — يصنعه
الطرفان أنفسهما، **وحسابان متواطئان ينتجانه كاملاً**: أحدهما ينشر إعلاناً والآخر يمثّل
المشتري.

والأسوأ من غياب التقييم تقييمٌ يبدو موثوقاً وليس كذلك: النجوم تُقرأ كحقيقة، ومن يثق بها
يخسر مالاً.

وأي بوابة تشترط «مباع» تُسلّم المفتاح لمن يُقيَّم: البائع الذي لا يعلّم إعلانه مباعاً لا
يتلقى تقييماً سيئاً أبداً.

**البديل المُنفَّذ:** زرّ «تم البيع» يُخفي الإعلان من السوق ويُبقيه في لوحة مالكه — حالة
يصرّح بها البائع عن سلعته، بلا ادعاء ثقة عن شخصه.

**ما يُعاد النظر فيه:** لو دخل دفع داخل المنصة، صار للصفقة أثر لا يصنعه الطرفان — وعندها
يستحق القرار مراجعة، ويُعاد الجدول من التعريف أدناه.

---

## التعاريف — للنسخ عند الإعادة

> **تحذير قبل النسخ:** لم يُكتب على أيٍّ من هذه الجداول سطرٌ قط، فلم يُختبر تصميمها
> بالاستعمال. الأرجح أن الأعمدة تحتاج مراجعة عند الإعادة — لا أن تُؤخذ كما هي.

### `auctions` و`bids`

كانتا في `app/Modules/Engagement/Database/Migrations/2026_01_01_000400_create_engagement_tables.php`.

```php
Schema::create('auctions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('listing_id')->unique()->constrained('listings')->cascadeOnDelete();
    $table->char('currency_code', 3)->comment('ثابتة طوال المزاد — لا تتغير بعد أول مزايدة');
    $table->decimal('start_price', 15, 2);
    $table->decimal('reserve_price', 15, 2)->nullable()->comment('مخفي عن المزايدين');
    $table->decimal('bid_increment', 15, 2)->default(1);
    $table->decimal('current_price', 15, 2);
    $table->decimal('current_in_base', 18, 2);
    $table->unsignedInteger('bids_count')->default(0);
    $table->unsignedBigInteger('winning_bid_id')->nullable()
        ->comment('بلا مفتاح أجنبي — تجنباً لاعتماد دائري مع bids');
    $table->dateTime('starts_at');
    $table->dateTime('ends_at');
    $table->boolean('extends_on_late_bid')->default(true)->comment('تمديد تلقائي لمنع القنص');
    $table->enum('status', ['scheduled', 'running', 'ended', 'cancelled'])->default('scheduled');
    $table->timestamps();

    $table->index(['status', 'ends_at']);
    $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
});

Schema::create('bids', function (Blueprint $table) {
    $table->id();
    $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->decimal('amount', 15, 2);
    $table->decimal('amount_in_base', 18, 2);
    $table->boolean('is_auto')->default(false)->comment('مزايدة تلقائية بحد أقصى');
    $table->decimal('max_amount', 15, 2)->nullable();
    $table->enum('status', ['active', 'outbid', 'won', 'retracted'])->default('active');
    $table->dateTime('created_at');

    $table->index(['auction_id', 'amount']);
    $table->index(['user_id', 'created_at']);
});
```

**وما يُعاد معهما:**

```php
// Shared — categories
$table->boolean('allows_auction')->default(true);

// Catalog — listings
$table->enum('sale_type', ['direct', 'auction'])->default('direct');
```

ومعهما في الكود: `sale_type` في `Listing::$fillable` وفي `ListingResource`، و`allows_auction`
في `Category::$fillable` وقوالبه، وفلتر `?type=` في `SearchCriteria` و`SearchQueryBuilder`.

**ولماذا `sale_type` عمود على `listings` لا حالةٌ تُشتقّ من وجود صفّ في `auctions`:**
لأن كل استعلام بحثٍ عام يحتاج التفريق، وربطُ جدولٍ في كل استعلام أغلى من عمود مفهرس. لكن
القرار يستحق مراجعة يوم البناء — فهو مبنيّ على تصوّرٍ لم يُختبر.

### `saved_searches`

```php
Schema::create('saved_searches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->string('label', 120)->nullable();
    $table->json('filters')->comment('التصنيف، الموقع، نطاق السعر، العملة');
    $table->boolean('notify')->default(true);
    $table->timestamp('last_run_at')->nullable();
    $table->timestamp('created_at')->nullable();
});
```

**ما ينقصه ليعمل:** مهمّة مجدولة تطابق كل إعلان جديد بمعايير كل بحث محفوظ. وقناة الإشعار
جاهزة منذ بناء وحدة `Notifications` — الناقص المطابقة وحدها. و`filters` مخزَّنة JSON
بينما `SearchCriteria` صار له شكل محدَّد: الأولى بإعادة البناء أن تُخزَّن سلسلة الاستعلام
كما يكتبها المستخدم، فتُقرأ بـ`SearchCriteria::fromRequest` نفسها بلا مُحوِّل ثانٍ يتفرّق
عن الأصل.

### `search_index`

```php
Schema::create('search_index', function (Blueprint $table) {
    $table->foreignId('listing_id')->primary()->constrained('listings')->cascadeOnDelete();
    $table->string('title_normalized', 180);
    $table->text('search_blob')->comment('عنوان + وصف + تصنيف + موقع، مطبّع');
    $table->unsignedBigInteger('category_id');
    $table->string('category_path', 255);
    $table->unsignedBigInteger('location_id');
    $table->string('location_path', 255);
    $table->decimal('price_in_base', 18, 2)->nullable();
    $table->char('currency_code', 3)->nullable();
    $table->enum('sale_type', ['direct', 'auction']);
    $table->enum('condition_type', ['new', 'like_new', 'used', 'for_parts'])->nullable();
    $table->boolean('has_image')->default(false);
    $table->boolean('is_featured')->default(false);
    $table->decimal('quality_score', 6, 3)->default(0)->comment('اكتمال الإعلان: صور، وصف، صفات');
    $table->decimal('popularity_score', 6, 3)->default(0)->comment('يُحدَّث من وحدة التحليلات');
    $table->timestamp('published_at')->nullable();
    $table->dateTime('indexed_at');

    $table->index(['category_path', 'price_in_base'], 'idx_si_category');
    $table->index(['location_path', 'published_at'], 'idx_si_location');
    $table->index('price_in_base', 'idx_si_price');
    $table->index(['is_featured', 'popularity_score', 'published_at'], 'idx_si_ranking');
});

// SQLite لا يدعم FULLTEXT — الفهرس في MySQL وحده
if (DB::getDriverName() === 'mysql') {
    Schema::table('search_index', function (Blueprint $table) {
        $table->fullText('search_blob', 'ft_si_blob');
    });
}
```

**متى يُعاد:** حين يصير مسح `listings` بفهرس FULLTEXT عليه أبطأ من المقبول — وذلك يقاس
لا يُتوقَّع. وحينها لن يكفي إنشاء الجدول: يحتاج مستمعاً على `ListingSaved` يعيد بناء
الصفّ، وأمراً يعيد بناء الجدول كله عند تغيّر سعر الصرف (لأن `price_in_base` منسوخ فيه)،
وقراراً فيما يحدث حين يتخلّف الفهرس عن الأصل.

### `reviews`

```php
Schema::create('reviews', function (Blueprint $table) {
    $table->id();
    $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
    $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('reviewed_id')->constrained('users')->cascadeOnDelete();
    $table->unsignedTinyInteger('rating')->comment('1..5');
    $table->string('comment', 500)->nullable();
    $table->timestamps();

    $table->unique(['listing_id', 'reviewer_id']);
    $table->index(['reviewed_id', 'created_at']);
});
```

محفوظٌ للاكتمال لا للإعادة — انظر القرار أعلاه.

### `password_reset_tokens`

```php
Schema::create('password_reset_tokens', function (Blueprint $table) {
    $table->string('email')->primary();
    $table->string('token');
    $table->timestamp('created_at')->nullable();
});
```

**ويُعاد معه في `config/auth.php`:**

```php
'defaults' => [
    'guard' => env('AUTH_GUARD', 'web'),
    'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
],

'passwords' => [
    'users' => [
        'provider' => 'users',
        'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
        'expire' => 60,
        'throttle' => 60,
    ],
],
```

---

## ما يحرسه الاختبار

`RemovedSchemaTest` يقارن الواقع بهذا الملف في اتجاهين:

1. **جدول تُنشئه هجرة ولا يشير إليه كود التطبيق** → فشل. لا تُضاف سقالة جديدة بصمت،
   ولا يبقى جدولٌ بعد حذف ما كان يكتب فيه.
2. **جدول مذكور هنا كمحذوف وقد عادت هجرةٌ تُنشئه** → فشل. من يعيد الجدول يشطبه من هذا
   السجلّ في نفس التغيير.

الاتجاه الثاني هو ما يمنع تقادم الملف: سجلٌّ لا يحرسه شيء يصير خطأً موثَّقاً بعد أول
ميزة.

**«موصول» في هذا الاختبار = اسم الجدول يرد في ملف php خارج الهجرات داخل `app/`.**
والاختبارات مستثناة عمداً: جدول لا يمسّه غير اختباره ليس منفَّذاً.
