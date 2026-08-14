# نظام الفلاتر والترتيب

> **ما هو:** كل ما يُنقّى به البحث قبل عرضه — فلاتر، نطاقات، وترتيب.
> **ما ليس هو:** محرك المطابقة النصية — ذاك في [نظرة عامة](../overview.md) و`SearchQueryBuilder`.

---

## نظرة عامة

يبني `SearchController` كائن `SearchCriteria` من طلب HTTP واحد، ويمرره إلى
`SearchQueryBuilder::build()`. يُراعم البناء كل شرط في `applyFacets` و
`AttributeFilters::apply()` و`applySort`، ثم يُراعي الترتيب النهائي.

`SearchCriteria` لا يعرف النوع: `attr[mileage][max]=100000` قد تكون نطاقاً
أو قائمة — فالتفريع يجري في `AttributeFilters` حيث `data_type` معروفة.

---

## الفلاتر المتاحة

### الفلاتر على العمود الأساسي (listings)

| الفلتر | المعامل | السلوك |
|---|---|---|
| **النطاق السعري** | `min` + `max` + `currency` | يحوّل الحدود إلى عملة الأساس عبر `ExchangeRateService::convertRange` ثم يقارن على `price_in_base` المفهرس |
| **عملة الإعلان** | `listing_currency` | يطابق `currency_code` — منفصل عن عملية تحويل النطاق السعري |
| **التصنيف** | `category` | يرشح إلى التصنيف وكلّ تحته عبر `Category::inSubtree` |
| **الموقع** | `location` | يرشح إلى المحافظة ومديرياتها عبر `Location::inSubtree` |
| **الحالة** | `condition` | يطابق `condition_type` من قائمة مسموحة (`new`, `like_new`, `used`, `for_parts`) |
| **مع صورة فقط** | `with_image=1` | `whereHas('images')` — يُخفي الإعلانات بلا صور |

### فلاتر الخصائص (attributes)

يقرأها `AttributeFilters` من المعامل `attr` بشكل مباشر على الجدول
(`listing_attribute_values` / `listing_multi_options`) — **بدون JOIN**:

| نوع الخاصية | الشكل في الرابط | الاستراجيك |
|---|---|---|
| `number` | `attr[mileage][min]=10&attr[mileage][max]=100` | نطاق على `value_number` — الفهرس `(attribute_id, value_number)` مبنيّ على هذا الترتيب تحديداً |
| `option` | `attr[transmission]=3` | مطابقة على `option_id` — واحدة لكل إعلان |
| `boolean` | `attr[warranty]=1` | مطابقة على `value_boolean` — الصفر يعني «لا» |
| `multi_option` | `attr[features][]=1&attr[features][]=7` | أيّ منها — شرط `IN` واحد على `listing_multi_options` |

**لماذا `EXISTS` لا `JOIN`؟** فلتران على خاصيتين يعني صفّان في
`listing_attribute_values` لنفس الإعلان. `JOIN` عليهما يضاعف صفوف
النتيجة ويتطلب `DISTINCT` — و`DISTINCT` مع `ORDER BY` على تعبيرات الصلة
يمنع الفهرس ويكسر الترتيب. `EXISTS` يسأل سؤالاً منطقياً بلا أن يمسّ
عدد الصفوف.

**لماذا يُهمل المطابق غير المتطابق؟** نطاق على خاصية خيارات يُهمل (الصفر
للنتائج)، كما مطابقة تامة على خاصية رقمية. دمج الشكل مع النوع يُرجع صفر
نتائج — والفلتر الفارغ يُهدر المنصة. الحلّ: النوع يُحسب من قاعدة البيانات
وليس من الشكل.

**لماذا نطاق `attr[key][min`/`max]` وليس `attr[key]`؟** لأن المفتاح
الواحد لا يحمل إلا قيمة — والنطاق يحتاج اثنتين. والمصفوفة بـ`min`/`max`
(مفاتيح نصية) تُفرّق عن القوائم (مفاتيح عددية) بالمفاتيح لا بأنواع
القيم.

---

## الترتيب

### الترتيب المدمج

| القيمة | الترتيب |
|---|---|
| `newest` (الافتراضي) | `published_at DESC` — مع ترتيب المطابقة على العنوان |
| `oldest` | `published_at ASC` |
| `price_asc` | `price_in_base ASC` — بالقيمة الموحّدة لا الرقم الأصلي |
| `price_desc` | `price_in_base DESC` |

### الترتيب بقيمة خاصية

**القيمة:** `attr:<key>:<asc|desc>` — مثال: `attr:mileage:asc`.

يتم إضافتها إلى `SORTS` كقيمة ديناميكية — المفتاح لا يُقيّم في قائمة
مسموحة بل يُتحقّق منه عند التطبيق.

**كيف يعمل؟**
1. `SearchCriteria` يفكك القيمة إلى `sortAttribute` (المفتاح) و
   `sortDirection` (asc/desc).
2. في `applySort`، إذا كانت القيمة النوع `number` يُضاف
   `LEFT JOIN listing_attribute_values AS lav ON lav.listing_id = listings.id AND lav.attribute_id = ?`
   ثم `ORDER BY lav.value_number`.
3. للخيارات يُرتّب على `option_id`، للبوليان على `value_boolean`.
4. القيم الثامثة (`value_date`, `value_string`) تدعمان نفس الآلية.

**لماذا `LEFT JOIN` وليس `EXISTS`؟** لأن الترتيب يتطلب جلب القيمة لكل
صف، وليس اختباراً منطقياً. والمفتاح الثلاثي `(listing_id, attribute_id)`
يضمن صفّاً واحداً لكل إعلان — لا يضاعف الصفوف.

**ماذا يحدث للإعلانات بلا القيمة؟** تُرتّب في الأسفل دائماً —
`LEFT JOIN` يُعطي `NULL`، و`NULL` الأولوية الأولى (NULLS LAST) أو الأخيرة
حسب الاتجاه.

---

## كيف يُقرأ المعامل من الرابط

كل القراءة في `SearchCriteria::fromRequest` عبر دوال خاصة:

| الدالة | تُرجع | سلوك غير المطابق |
|---|---|---|
| `text()` | `?string` | مقطوع بـ`MAX_QUERY_LENGTH` (120) |
| `id()` | `?int` | يُهمل السالب والصفر — لا يسقط الصفحة |
| `pick()` | `?string` | بلا قيمة في القائمة البيضاء → `null` |
| `flag()` | `bool` | `1`/`true`/`on`/`yes` فقط |
| `amount()` | `?float` | يُهمل السالب والغير-رقمي — لا يُصبح «من صفر» |

**مبدأ التصميم:** الرابط يكتبها أي أحد، ومدخلاً غير متوقع يجب أن يُوسّع
البحث لا يُسقط الصفحة. وهذا يشمل `attr[]=` المصفوفي المكسور، والقيم
غير الرقمية، والمفاتيح غير الصالحة.

---

## الحدود الأمنية

| الحد | الغرض |
|---|---|
| `MAX_QUERY_LENGTH = 120` | منع نمط `LIKE` ب 50 كيلوبايت على كل صف |
| `MAX_ATTRIBUTE_FILTERS = 12` | كل فلتر = استعلام فرعي `EXISTS` — أغزر تصنيف 12 خاصية |
| `MAX_OPTIONS_PER_FILTER = 30` | منع `IN` بألف رابطة في استعلام واحد |
| `perPage` بين 6 و 60 | منع طلب `per_page=99999` يُفرغ الذاكرة |

---

## الترتيب في الرابط

```
/?category=3&attr[mileage][max]=150000&attr[transmission]=2
 &min=100&max=500&currency=USD&sort=attr:mileage:asc&with_image=1
```

| المعامل | يقرأه |
|---|---|
| `q` | `text()` |
| `category` | `id()` |
| `location` | `id()` |
| `min` / `max` | `amount()` — نطاق السعر |
| `currency` | `upper()` + `pick()` |
| `listing_currency` | `upper()` + `pick()` |
| `condition` | `pick()` من `CONDITIONS` |
| `with_image` | `flag()` |
| `sort` | `pick()` من `SORTS` + تحليل `attr:key:dir` |
| `per_page` | `id()` محصور بين 6 و 60 |
| `attr` | `readAttributes()` — يبني `AttributeFilter` كل فلتر |

---

## المراقبة والتحليلات

يُسجّل `SearchController` حدثين لكل بحث:

1. **`SearchPerformed`** — عند وجود نية بحث فعلية (استعلام أو نطاق سعري).
   يحمل النص المطبّع، عدد النتائج، مدة التنفيذ بالملي ثواني، درجة
   المطابقة (exact/near/none)، والفلاتر النشطة.

2. **`FilterApplied`** — لكل فلتر منفرد. يحمل المفتاح والنتائج بعد
   التطبيق. منه تُحسب `filter_usage_stats` — وعمود
   `times_removed_fast` فيها يكتشف الفلاتر المُربكة.

**ملحوظة خصوصية:** لا يُخزن النص الأصلي، ولا يُرسل، ولا يُسجّل معرّف
المشترك مباشرةً — فقط `anon_id` كوكي مستقل عن الحساب.
