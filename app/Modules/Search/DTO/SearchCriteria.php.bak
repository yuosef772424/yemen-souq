<?php

namespace App\Modules\Search\DTO;

use App\Modules\Shared\Models\Currency;
use Illuminate\Http\Request;

/**
 * معايير البحث كوحدة واحدة غير قابلة للتغيير.
 *
 * لماذا كائن بدل تمرير عشرة معاملات: الفلاتر تتزايد مع الوقت،
 * وتوقيع دالة بعشرة معاملات يصبح غير قابل للقراءة أو الاختبار.
 * وهذا أيضاً المكان الوحيد الذي يعرف كيف تُقرأ الفلاتر من الطلب —
 * ولذلك هو المكان الصحيح للتحقق منها.
 *
 * كل قيمة قادمة من الرابط تمر بقائمة بيضاء أو تُهمَل. لا استثناء:
 * ?currency=EUR كان يصل إلى ExchangeRateService فيرمي استثناءً
 * ويُسقط صفحة عامة بخطأ 500. رابط يكتبه أي أحد لا يجب أن يُسقط صفحة.
 */
final readonly class SearchCriteria
{
    /** الترتيبات المدعومة — أي قيمة أخرى تُهمَل بصمت */
    public const SORTS = ['newest', 'oldest', 'price_asc', 'price_desc'];

    public const CONDITIONS = ['new', 'like_new', 'used', 'for_parts'];

    public const SALE_TYPES = ['direct', 'auction'];

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
        public ?string $saleType = null,
        public bool $withImageOnly = false,
        public string $sort = 'newest',
        public int $perPage = 24,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $currencies = Currency::activeCodes();

        [$min, $max] = self::readRange($request);

        return new self(
            query: $request->string('q')->trim()->value() ?: null,
            categoryId: $request->integer('category') ?: null,
            locationId: $request->integer('location') ?: null,
            priceMin: $min,
            priceMax: $max,
            priceCurrency: self::pick($request->string('currency')->upper()->value(), $currencies)
                ?? Currency::baseCode(),
            listingCurrency: self::pick($request->string('listing_currency')->upper()->value(), $currencies),
            condition: self::pick($request->string('condition')->value(), self::CONDITIONS),
            saleType: self::pick($request->string('type')->value(), self::SALE_TYPES),
            withImageOnly: $request->boolean('with_image'),
            sort: self::pick($request->string('sort')->value(), self::SORTS) ?? 'newest',
            perPage: min(max($request->integer('per_page', 24), 6), 60),
        );
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
     * السالب يُهمَل (لا سعر سالب)، والحدّان المنقلبان يُعكسان بدل
     * إرجاع صفر نتائج. المستخدم الذي يكتب "من 500 إلى 100" يعني
     * النطاق نفسه — وصفحة فارغة بلا تفسير تُقرأ كعطل في المنصة.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private static function readRange(Request $request): array
    {
        $min = $request->filled('min') ? (float) $request->input('min') : null;
        $max = $request->filled('max') ? (float) $request->input('max') : null;

        if ($min !== null && $min < 0) {
            $min = null;
        }

        if ($max !== null && $max < 0) {
            $max = null;
        }

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [$min, $max];
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
            'sale_type' => $this->saleType !== null,
            'with_image' => $this->withImageOnly,
        ]));
    }
}