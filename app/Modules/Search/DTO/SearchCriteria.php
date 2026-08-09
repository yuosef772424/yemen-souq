<?php

namespace App\Modules\Search\DTO;

use Illuminate\Http\Request;

/**
 * معايير البحث كوحدة واحدة غير قابلة للتغيير.
 *
 * لماذا كائن بدل تمرير عشرة معاملات: الفلاتر تتزايد مع الوقت،
 * وتوقيع دالة بعشرة معاملات يصبح غير قابل للقراءة أو الاختبار.
 * وهذا أيضاً المكان الوحيد الذي يعرف كيف تُقرأ الفلاتر من الطلب.
 */
final readonly class SearchCriteria
{
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
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            query: $request->string('q')->trim()->value() ?: null,
            categoryId: $request->integer('category') ?: null,
            locationId: $request->integer('location') ?: null,
            priceMin: $request->filled('min') ? (float) $request->input('min') : null,
            priceMax: $request->filled('max') ? (float) $request->input('max') : null,
            priceCurrency: $request->string('currency')->upper()->value() ?: 'YER',
            listingCurrency: $request->string('listing_currency')->upper()->value() ?: null,
            condition: $request->string('condition')->value() ?: null,
            saleType: $request->string('type')->value() ?: null,
            withImageOnly: $request->boolean('with_image'),
            sort: $request->string('sort')->value() ?: 'newest',
            perPage: min(max($request->integer('per_page', 24), 6), 60),
        );
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
            // 'query' => $this->hasQuery(),
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
