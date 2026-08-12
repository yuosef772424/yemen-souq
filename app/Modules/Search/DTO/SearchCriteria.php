<?php

namespace App\Modules\Search\DTO;

use App\Modules\Shared\Models\Currency;
use Illuminate\Http\Request;

/**
 * معايير البحث كوحدة واحدة غير قابلة للتغيير.
 *
 * الثغرة المُسدَّدة هنا: كل القراءات كانت تمر بـ$request->string()، وهي
 * تُمرِّر القيمة إلى Stringable الذي يحوّلها بـ(string). والرابط
 * ?q[]=x يعطي مصفوفة، فـ(string)array يُطلق تحذير PHP، ولارافل يحوّل
 * التحذيرات إلى ErrorException — فيسقط أي أحد الصفحة الرئيسية بخطأ 500
 * برابط واحد بلا حساب ولا أدوات. وهذا بالضبط ما تعِد به تعليقات هذا
 * الملف نفسه: «رابط يكتبه أي أحد لا يجب أن يُسقط صفحة».
 *
 * الحلّ: قراءة قياسية واحدة تُهمل كل ما ليس قيمة مفردة. القوائم البيضاء
 * الموجودة تحمي القيم، وهذه تحمي *النوع* — وهما حاجتان مختلفتان.
 */
final readonly class SearchCriteria
{
    public const SORTS = ['newest', 'oldest', 'price_asc', 'price_desc'];

    public const CONDITIONS = ['new', 'like_new', 'used', 'for_parts'];

    /**
     * سقف طول الاستعلام.
     *
     * سقف الكلمات (8 في SearchTokenizer) لا يكفي: العبارة الكاملة تُستخدم
     * كنمط LIKE واحد في ترتيب الصلة، فاستعلام بطول 50 كيلوبايت يصبح نمط
     * LIKE بطول 50 كيلوبايت على كل صف في الجدول. 120 محرفاً أطول من أي
     * بحث حقيقي بمراحل.
     */
    public const MAX_QUERY_LENGTH = 120;

    /**
     * سقف عدد فلاتر الخصائص في الطلب الواحد.
     *
     * كل فلتر يصير استعلاماً فرعياً بـEXISTS. وأغزر تصنيف في البذرة له
     * خمس خصائص، فاثنا عشر أوسع من أي استعمال حقيقي — والسقف موجود لأن
     * الرابط يكتبه أي أحد: attr بمئة مفتاح يعني مئة استعلام فرعي.
     */
    public const MAX_ATTRIBUTE_FILTERS = 12;

    /** سقف الخيارات داخل فلتر «أيّ منها» الواحد */
    public const MAX_OPTIONS_PER_FILTER = 30;

    /** attr_key كما في عمود attributes.attr_key */
    private const ATTRIBUTE_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,59}$/';

    public function __construct(
        public ?string $query = null,
        public ?int $categoryId = null,
        public ?int $locationId = null,
        public ?float $priceMin = null,
        public ?float $priceMax = null,
        /** العملة التي كتب المستخدم النطاق السعري بها — ليست عملة الإعلان */
        public string $priceCurrency = 'YER',
        public ?string $listingCurrency = null,
        public ?string $condition = null,
        public bool $withImageOnly = false,
        public string $sort = 'newest',
        public int $perPage = 24,
        /** @var list<AttributeFilter> */
        public array $attributes = [],
    ) {}

    public static function fromRequest(Request $request): self
    {
        $currencies = Currency::activeCodes();

        [$min, $max] = self::readRange($request);

        return new self(
            query: self::text($request, 'q'),
            categoryId: self::id($request, 'category'),
            locationId: self::id($request, 'location'),
            priceMin: $min,
            priceMax: $max,
            priceCurrency: self::pick(self::upper($request, 'currency'), $currencies)
                ?? Currency::baseCode(),
            listingCurrency: self::pick(self::upper($request, 'listing_currency'), $currencies),
            condition: self::pick(self::scalar($request, 'condition'), self::CONDITIONS),
            withImageOnly: self::flag($request, 'with_image'),
            sort: self::pick(self::scalar($request, 'sort'), self::SORTS) ?? 'newest',
            perPage: min(max(self::id($request, 'per_page') ?? 24, 6), 60),
            attributes: self::readAttributes($request),
        );
    }

    /**
     * فلاتر الخصائص من المعامل attr.
     *
     * شكلان مقبولان:
     *   attr[mileage][max]=100000   نطاق رقمي
     *   attr[transmission]=3        مطابقة تامة (خيار أو نعم/لا)
     *
     * وكل ما عداهما يُهمَل بلا خطأ — بنفس مبدأ بقية هذا الملف: الرابط
     * يكتبه أي أحد، ومدخل غريب يجب أن يُنتج بحثاً أوسع لا صفحة 500.
     * والنوع الفعلي للخاصية يُحسم لاحقاً من قاعدة البيانات: نطاق على
     * خاصية خيارات يُهمَل هناك، لا هنا.
     *
     * @return list<AttributeFilter>
     */
    private static function readAttributes(Request $request): array
    {
        $raw = $request->input('attr');

        if (! is_array($raw)) {
            return [];
        }

        $filters = [];

        foreach ($raw as $key => $value) {
            if (count($filters) >= self::MAX_ATTRIBUTE_FILTERS) {
                break;
            }

            if (! is_string($key) || preg_match(self::ATTRIBUTE_KEY_PATTERN, $key) !== 1) {
                continue;
            }

            $filter = is_array($value)
                ? self::arrayFilter($key, $value)
                : self::equalityFilter($key, $value);

            if ($filter !== null) {
                $filters[] = $filter;
            }
        }

        return $filters;
    }

    /**
     * الشكل المصفوفي يحمل معنيين، ويُفرَّق بينهما بالمفاتيح لا بالتخمين:
     *
     *   attr[mileage][min]=10&attr[mileage][max]=90   نطاق  — مفاتيح نصية
     *   attr[features][]=3&attr[features][]=7         قائمة — مفاتيح رقمية
     *
     * والنطاق يُفحص أولاً: مصفوفة فيها min أو max نطاقٌ حتى لو حملت
     * مفاتيح أخرى، فلا تنقلب إلى قائمة خيارات بمفتاح زائد في الرابط.
     *
     * @param  array<mixed>  $value
     */
    private static function arrayFilter(string $key, array $value): ?AttributeFilter
    {
        $min = self::number($value['min'] ?? null);
        $max = self::number($value['max'] ?? null);

        if ($min === null && $max === null) {
            return self::anyOfFilter($key, $value);
        }

        // الحدّان المنقلبان يُعكسان لا يُرجعان صفر نتائج — نفس معالجة
        // النطاق السعري في readRange
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return new AttributeFilter($key, min: $min, max: $max);
    }

    /**
     * قائمة معرّفات خيارات، مقصوصة بنفس منطق سقف الفلاتر.
     *
     * السقف هنا على عدد الخيارات في الفلتر الواحد: كلها تدخل في شرط
     * IN واحد، لكن رابطاً بألف معرّف يُنتج استعلاماً بألف رابطة.
     *
     * @param  array<mixed>  $value
     */
    private static function anyOfFilter(string $key, array $value): ?AttributeFilter
    {
        $ids = [];

        foreach ($value as $item) {
            if (count($ids) >= self::MAX_OPTIONS_PER_FILTER) {
                break;
            }

            if (is_scalar($item) && is_numeric($item) && (int) $item > 0) {
                $ids[] = (int) $item;
            }
        }

        $ids = array_values(array_unique($ids));

        return $ids === [] ? null : new AttributeFilter($key, anyOf: $ids);
    }

    private static function equalityFilter(string $key, mixed $value): ?AttributeFilter
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        // صفر مقبول: قيمة «لا» في خاصية نعم/لا. والسالب لا معنى له
        // لا كمعرّف خيار ولا كقيمة منطقية
        return $id < 0 ? null : new AttributeFilter($key, equals: $id);
    }

    private static function number(mixed $value): ?float
    {
        return is_scalar($value) && is_numeric($value) ? (float) $value : null;
    }

    // ---- قراءة المدخلات: النوع أولاً، ثم القيمة ----

    /** قيمة مفردة أو null — مصفوفة أو كائن يُهمَل بلا خطأ */
    private static function scalar(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function upper(Request $request, string $key): ?string
    {
        $value = self::scalar($request, $key);

        return $value === null ? null : mb_strtoupper($value);
    }

    /** نص البحث: مقصوص بحدّ الطول قبل أن يصل إلى أي استعلام */
    private static function text(Request $request, string $key): ?string
    {
        $value = self::scalar($request, $key);

        return $value === null ? null : mb_substr($value, 0, self::MAX_QUERY_LENGTH);
    }

    private static function id(Request $request, string $key): ?int
    {
        $value = self::scalar($request, $key);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private static function flag(Request $request, string $key): bool
    {
        return in_array(self::scalar($request, $key), ['1', 'true', 'on', 'yes'], true);
    }

    /** القيمة إن كانت في القائمة البيضاء، وإلا null */
    private static function pick(?string $value, array $allowed): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * حدود النطاق السعري.
     *
     * السالب يُهمَل، والحدّان المنقلبان يُعكسان بدل إرجاع صفر نتائج.
     * وغير الرقمي يُهمَل: (float)'abc' كان يعطي 0.0 فيتحوّل «من abc»
     * إلى «من صفر» — حدّ لم يطلبه أحد.
     */
    private static function readRange(Request $request): array
    {
        $min = self::amount($request, 'min');
        $max = self::amount($request, 'max');

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [$min, $max];
    }

    private static function amount(Request $request, string $key): ?float
    {
        $value = self::scalar($request, $key);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        return $amount < 0 ? null : $amount;
    }

    public function hasQuery(): bool
    {
        return $this->query !== null && $this->query !== '';
    }

    public function hasPriceFilter(): bool
    {
        return $this->priceMin !== null || $this->priceMax !== null;
    }

    /** يُستخدم كمفتاح تسجيل في وحدة التحليلات */
    public function activeFilterKeys(): array
    {
        return array_keys(array_filter([
            'category' => $this->categoryId !== null,
            'location' => $this->locationId !== null,
            'price' => $this->hasPriceFilter(),
            'currency' => $this->listingCurrency !== null,
            'condition' => $this->condition !== null,
            'with_image' => $this->withImageOnly,
            'attributes' => $this->attributes !== [],
        ]));
    }
}
