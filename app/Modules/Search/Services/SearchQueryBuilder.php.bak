<?php

namespace App\Modules\Search\Services;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Location;
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
        private readonly SearchTokenizer $tokenizer,
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

    /**
     * المطابقة تجري على search_blob وحده.
     *
     * كانت على title_normalized و description_normalized، فكان
     * «شقة في حدة» يفشل لأن اسم المديرية مفتاح أجنبي لا نص.
     * والعمود المجمّع يضم العنوان والوصف والتصنيف والموقع والمحافظة.
     *
     * نستخدم LIKE لا MATCH لأن محلل MySQL الافتراضي لا يفهم
     * سوابق ولواحق العربية، فيفوّت "بالسيارة" عند البحث عن "سيارة".
     */
    private function applyText(Builder $query, SearchCriteria $criteria): void
    {
        if (! $criteria->hasQuery()) {
            return;
        }

        $tokens = $this->tokenizer->tokens($criteria->query);

        if ($tokens === []) {
            return;
        }

        $query->where(function (Builder $outer) use ($tokens): void {
            foreach ($tokens as $token) {
                $outer->where('search_blob', 'like', "%{$token}%");
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

    // ---- الترتيب ----

    private function applySort(Builder $query, SearchCriteria $criteria): void
    {
        // المميّزة أولاً — والنطاق يتحقق من سريان الترقية لا من العلم وحده
        $query->featuredFirst();

        $this->applyRelevance($query, $criteria);

        match ($criteria->sort) {
            // الترتيب بالسعر يجري على القيمة الموحّدة —
            // وإلا لظهر إعلان بـ100 ريال يمني قبل إعلان بـ90 دولاراً
            'price_asc' => $query->orderByRaw('price_in_base IS NULL')->orderBy('price_in_base'),
            'price_desc' => $query->orderByRaw('price_in_base IS NULL')->orderByDesc('price_in_base'),
            'oldest' => $query->orderBy('published_at'),
            default => $query->orderByDesc('published_at'),
        };
    }

    /**
     * إشارتا الصلة، بهذا الترتيب:
     *
     *   1. العبارة الكاملة كما كتبها المستخدم موجودة في العنوان
     *   2. عدد كلمات الاستعلام الموجودة في العنوان
     *
     * ثم يكسر التعادل الترتيب المطلوب (الأحدث افتراضاً).
     *
     * لماذا عدّ لا اشتراط كل الكلمات: كان الشرط «كل الكلمات في العنوان»،
     * واسم المدينة لا يكون في العنوان أبداً — هو في النص المجمّع. فأي
     * استعلام من شكل «سلعة + مدينة»، وهو أشيع أشكال البحث في اليمن،
     * كان يُسقط كل النتائج إلى مستوى واحد فيعود الترتيب إلى التاريخ وحده.
     * الصلة تتعطّل صامتةً — وميزة تبدو عاملة وهي معطّلة أسوأ من غيابها.
     *
     * ولماذا يبقى هذا مقبولاً بعد رفض المجموع المرجّح (0.6×صلة + 0.4×رواج):
     * العدّ ليس وزناً. «عنوانه طابق كلمتين من كلماتك وعنوانك واحدة» جملة
     * يفهمها البائع؛ «درجته 0.73» ليست كذلك. الرفض كان للأوزان المخترعة
     * التي لا تُضبط بلا حركة مرور، لا لكل حساب.
     *
     * ولماذا لا يُطبَّق عند ترتيب صريح بالسعر: من طلب «الأرخص أولاً»
     * يريد عمود سعر مرتّباً. الاختيار الصريح يتقدّم على الصلة.
     */
    private function applyRelevance(Builder $query, SearchCriteria $criteria): void
    {
        if (! $criteria->hasQuery() || $criteria->sort !== 'newest') {
            return;
        }

        $tokens = $this->tokenizer->tokens($criteria->query);

        if ($tokens === []) {
            return;
        }

        $phrase = $this->tokenizer->phrase($criteria->query);

        if ($phrase !== '') {
            $query->orderByRaw(
                'case when title_normalized like ? then 1 else 0 end desc',
                ['%' . $phrase . '%']
            );
        }

        $hits = implode(
            ' + ',
            array_fill(0, count($tokens), 'case when title_normalized like ? then 1 else 0 end')
        );

        $query->orderByRaw(
            "({$hits}) desc",
            array_map(fn (string $token): string => '%' . $token . '%', $tokens)
        );
    }

    // ---- بيانات مساعدة للواجهة ----

    /**
     * توزيع النتائج على العملات — يُعرض بجانب فلتر العملة.
     * يُظهر للمستخدم أن النتائج فعلاً مختلطة العملات، بدل أن يفاجأ بذلك.
     *
     * reorder() ضرورية: تُزيل ترتيب الصلة وارتباطاته، وGROUP BY لا يقبل
     * ترتيباً بتعبير على عمود غير مجمَّع.
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