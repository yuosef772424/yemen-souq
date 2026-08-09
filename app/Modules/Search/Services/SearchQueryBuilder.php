<?php

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\ArabicNormalizer;
use App\Modules\Shared\Services\ExchangeRateService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * محرك البحث.
 *
 * القرار المحوري: النطاق السعري الذي يكتبه المستخدم يُحوَّل مرة واحدة
 * إلى عملة الأساس، ثم يُقارن بعمود price_in_base المفهرس.
 *
 * البديل الخاطئ الشائع: تحويل سعر كل إعلان أثناء الاستعلام. ذلك يمنع
 * استخدام الفهرس ويجعل البحث يبطؤ خطياً مع عدد الإعلانات.
 */
final class SearchQueryBuilder
{
    public function __construct(
        private readonly ArabicNormalizer $normalizer,
        private readonly ExchangeRateService $rates,
    ) {
    }

    public function paginate(SearchCriteria $criteria): LengthAwarePaginator
    {
        return $this->build($criteria)
            ->paginate($criteria->perPage)
            ->withQueryString();
    }

    public function count(SearchCriteria $criteria): int
    {
        return $this->build($criteria)->count();
    }

    public function build(SearchCriteria $criteria): Builder
    {
        $query = Listing::query()
            ->published()
            ->with(['currency', 'category', 'location', 'images']);

        $this->applyText($query, $criteria);
        $this->applyPrice($query, $criteria);
        $this->applyFacets($query, $criteria);
        $this->applySort($query, $criteria);

        return $query;
    }

    // ---- النص ----

    private function applyText(Builder $query, SearchCriteria $criteria): void
    {
        if (! $criteria->hasQuery()) {
            return;
        }

        $tokens = $this->normalizer->tokenize($criteria->query);

        if ($tokens === []) {
            return;
        }

        // كل كلمة يجب أن تظهر في العنوان أو الوصف المطبّعين.
        // نستخدم LIKE لا MATCH لأن محلل MySQL الافتراضي لا يفهم
        // سوابق ولواحق العربية، فيفوّت "بالسيارة" عند البحث عن "سيارة".
        $query->where(function (Builder $outer) use ($tokens): void {
            foreach ($tokens as $token) {
                $outer->where(function (Builder $inner) use ($token): void {
                    $inner->where('title_normalized', 'like', "%{$token}%")
                        ->orWhere('description_normalized', 'like', "%{$token}%");
                });
            }
        });
    }

    // ---- السعر: جوهر المشروع ----

    private function applyPrice(Builder $query, SearchCriteria $criteria): void
    {
        if (! $criteria->hasPriceFilter()) {
            return;
        }

        // تحويل واحد لحدود النطاق — لا تحويل لكل إعلان
        $range = $this->rates->convertRange(
            $criteria->priceMin,
            $criteria->priceMax,
            $criteria->priceCurrency,
        );

        $query->priceBetween($range['min'], $range['max']);

        // الإعلانات بلا سعر لا يصح ظهورها عند الفلترة بالسعر
        $query->whereNotNull('price_in_base');
    }

    // ---- بقية الفلاتر ----

    private function applyFacets(Builder $query, SearchCriteria $criteria): void
    {
        if ($criteria->categoryId !== null) {
            $category = Category::find($criteria->categoryId);

            if ($category !== null) {
                $query->inCategory($category);
            }
        }

        if ($criteria->locationId !== null) {
            $location = Location::find($criteria->locationId);

            if ($location !== null) {
                $query->inLocation($location);
            }
        }

        // فلتر عملة الإعلان — منفصل تماماً عن عملة النطاق السعري
        if ($criteria->listingCurrency !== null) {
            $query->where('currency_code', $criteria->listingCurrency);
        }

        if ($criteria->condition !== null) {
            $query->where('condition_type', $criteria->condition);
        }

        if ($criteria->saleType !== null) {
            $query->where('sale_type', $criteria->saleType);
        }

        if ($criteria->withImageOnly) {
            $query->whereHas('images');
        }
    }

    private function applySort(Builder $query, SearchCriteria $criteria): void
    {
        // المميّزة أولاً — والنطاق يتحقق من سريان الترقية لا من العلم وحده
        $query->featuredFirst();

        match ($criteria->sort) {
            // الترتيب بالسعر يجري على القيمة الموحّدة —
            // وإلا لظهر إعلان بـ100 ريال يمني قبل إعلان بـ90 دولاراً
            'price_asc' => $query->orderByRaw('price_in_base IS NULL')->orderBy('price_in_base'),
            'price_desc' => $query->orderByRaw('price_in_base IS NULL')->orderByDesc('price_in_base'),
            'oldest' => $query->orderBy('published_at'),
            default => $query->orderByDesc('published_at'),
        };
    }

    // ---- بيانات مساعدة للواجهة ----

    /**
     * توزيع النتائج على العملات — يُعرض بجانب فلتر العملة.
     * يُظهر للمستخدم أن النتائج فعلاً مختلطة العملات، بدل أن يفاجأ بذلك.
     */
    public function currencyBreakdown(SearchCriteria $criteria): array
    {
        return $this->build($criteria)
            ->reorder()
            ->select('currency_code', DB::raw('count(*) as total'))
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code')
            ->all();
    }
}