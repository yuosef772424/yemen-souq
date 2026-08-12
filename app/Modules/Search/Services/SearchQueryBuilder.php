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
 * القرار المحوري: النطاق السعري يُحوَّل مرة واحدة إلى عملة الأساس ثم
 * يُقارن بعمود price_in_base المفهرس. البديل الخاطئ الشائع — تحويل سعر
 * كل إعلان أثناء الاستعلام — يمنع استخدام الفهرس ويجعل البحث يبطؤ خطياً.
 *
 * والمطابقة على درجتين: تامة، ثم أقرب عند فشلها. التفصيل في applyText.
 */
final class SearchQueryBuilder
{
    public const MODE_STRICT = 'strict';

    public const MODE_LOOSE = 'loose';

    public function __construct(
        private readonly SearchTokenizer $tokenizer,
        private readonly QueryExpander $expander,
        private readonly ExchangeRateService $rates,
        private readonly AttributeFilters $attributes,
    ) {}

    /**
     * @return array{listings: LengthAwarePaginator, mode: string}
     *                                                             mode ∈ browse | exact | near | none
     */
    public function search(SearchCriteria $criteria): array
    {
        if (! $criteria->hasQuery()) {
            return [
                'listings' => $this->paginate($criteria, self::MODE_STRICT),
                'mode' => 'browse',
            ];
        }

        $strict = $this->paginate($criteria, self::MODE_STRICT);

        if ($strict->total() > 0) {
            return ['listings' => $strict, 'mode' => 'exact'];
        }

        $loose = $this->paginate($criteria, self::MODE_LOOSE);

        return [
            'listings' => $loose,
            'mode' => $loose->total() > 0 ? 'near' : 'none',
        ];
    }

    public function paginate(SearchCriteria $criteria, string $mode = self::MODE_STRICT): LengthAwarePaginator
    {
        return $this->build($criteria, $mode)->paginate($criteria->perPage)->withQueryString();
    }

    public function count(SearchCriteria $criteria, string $mode = self::MODE_STRICT): int
    {
        return $this->build($criteria, $mode)->count();
    }

    public function build(SearchCriteria $criteria, string $mode = self::MODE_STRICT): Builder
    {
        $query = Listing::query()
            ->published()
            ->with(['currency', 'category', 'location', 'images']);

        $this->applyText($query, $criteria, $mode);
        $this->applyPrice($query, $criteria);
        $this->applyFacets($query, $criteria);

        // بعد بقية الفلاتر بقصد: كل شرط قبله يقصي صفوفاً، والاستعلام
        // الفرعي يعمل على ما بقي لا على الجدول كاملاً
        $this->attributes->apply($query, $criteria->attributes);

        $this->applySort($query, $criteria, $mode);

        return $query;
    }

    // ---- النص ----

    /**
     * المطابقة تجري على search_blob وحده — وهو يضم العنوان والوصف
     * والتصنيف والموقع والمحافظة وصور أكواد الموديلات.
     *
     * LIKE لا MATCH: محلل MySQL الافتراضي لا يفهم سوابق العربية ولواحقها،
     * فيفوّت «بالسيارة» عند البحث عن «سيارة».
     *
     * والدرجتان: الصارمة تشترط كل مجموعة، والمتراجعة تكفيها واحدة —
     * والثانية لا تعمل إلا عند فشل الأولى، وإلا غرقت النتائج الصحيحة في
     * ضجيج مطابقة كلمة من أربع.
     */
    private function applyText(Builder $query, SearchCriteria $criteria, string $mode): void
    {
        $groups = $this->groups($criteria);

        if ($groups === []) {
            return;
        }

        if ($this->hasFulltext()) {
            $this->applyFulltext($query, $groups, $mode);

            return;
        }

        if ($mode === self::MODE_STRICT) {
            $query->where(function (Builder $outer) use ($groups): void {
                foreach ($groups as $group) {
                    $outer->where(function (Builder $inner) use ($group): void {
                        foreach ($group as $variant) {
                            $inner->orWhere('search_blob', 'like', "%{$variant}%");
                        }
                    });
                }
            });

            return;
        }

        $query->where(function (Builder $outer) use ($groups): void {
            foreach ($groups as $group) {
                foreach ($group as $variant) {
                    $outer->orWhere('search_blob', 'like', "%{$variant}%");
                }
            }
        });
    }

    /**
     * هل يوجد فهرس FULLTEXT؟
     *
     * sqlite لا يعرفه، فالاختبارات تسلك مسار LIKE — وهو مسار حقيقي لا
     * محاكاة: يبقى مستعمَلاً في أي تركيب بلا الفهرس، ونتائجه يجب أن
     * تطابق نتائج MATCH. اختبار الاسترجاع يقارن الاثنين على نفس النصوص.
     */
    private function hasFulltext(): bool
    {
        return Listing::query()->getConnection()->getDriverName() === 'mysql';
    }

    /**
     * المطابقة على الفهرس بدل مسح الجدول.
     *
     * BOOLEAN MODE لا الطبيعي: الطبيعي يتجاهل الكلمات الشائعة (عتبة 50%
     * في MyISAM) ويفرض ترتيبه بالصلة، والترتيب هنا مبنيّ صراحةً في
     * applySort على إشارات هذا المشروع لا على TF-IDF عام.
     *
     * والصارم يشترط كل مجموعة بقوس منفصل، والمتراجع يكفيه واحدة —
     * بنفس منطق مسار LIKE تماماً، فالنتائج تتطابق.
     *
     * والتنظيف ضروري: + و- و* و" و( ) معاملات في BOOLEAN MODE، فكلمة
     * بحث تحمل أحدها تُغيّر معنى الاستعلام أو تُفشله.
     *
     * @param  array<int, array<int, string>>  $groups
     */
    private function applyFulltext(Builder $query, array $groups, string $mode): void
    {
        $clauses = [];

        foreach ($groups as $group) {
            $words = array_filter(array_map(
                fn (string $w): string => $this->sanitize($w),
                $group
            ), fn (string $w): bool => $w !== '');

            if ($words === []) {
                continue;
            }

            // كل مجموعة قوس بدلالة «أيّ من مكافئاتها»
            $clauses[] = '('.implode(' ', array_map(
                fn (string $w): string => $w,
                $words
            )).')';
        }

        if ($clauses === []) {
            return;
        }

        // الصارم: كل مجموعة لازمة — يسبقها + على القوس
        $expression = $mode === self::MODE_STRICT
            ? implode(' ', array_map(fn (string $c): string => '+'.$c, $clauses))
            : implode(' ', $clauses);

        $query->whereRaw('MATCH(search_blob) AGAINST (? IN BOOLEAN MODE)', [$expression]);
    }

    /** يُبقي الحروف والأرقام والمسافة — كل ما عداها معامل أو ضجيج */
    private function sanitize(string $word): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', '', $word) ?? '');
    }

    /** @return array<int, array<int, string>> */
    private function groups(SearchCriteria $criteria): array
    {
        if (! $criteria->hasQuery()) {
            return [];
        }

        $tokens = $this->tokenizer->tokens($criteria->query);

        return $tokens === [] ? [] : $this->expander->expand($tokens);
    }

    // ---- السعر: جوهر المشروع ----

    private function applyPrice(Builder $query, SearchCriteria $criteria): void
    {
        if (! $criteria->hasPriceFilter()) {
            return;
        }

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

        if ($criteria->withImageOnly) {
            $query->whereHas('images');
        }
    }

    // ---- الترتيب ----

    private function applySort(Builder $query, SearchCriteria $criteria, string $mode): void
    {
        // المميّزة أولاً — والنطاق يتحقق من سريان الترقية لا من العلم وحده
        $query->featuredFirst();

        // في الدرجة الثانية يتقدّم عدد الكلمات المطابقة على كل ما بعده
        if ($mode === self::MODE_LOOSE) {
            $this->applyMatchCount($query, $criteria);
        }

        $this->applyRelevance($query, $criteria);

        match ($criteria->sort) {
            // الترتيب بالسعر على القيمة الموحّدة — وإلا ظهر إعلان بـ100
            // ريال يمني قبل إعلان بـ90 دولاراً
            'price_asc' => $query->orderByRaw('price_in_base IS NULL')->orderBy('price_in_base'),
            'price_desc' => $query->orderByRaw('price_in_base IS NULL')->orderByDesc('price_in_base'),
            'oldest' => $query->orderBy('published_at'),
            default => $query->orderByDesc('published_at'),
        };
    }

    /** عدد مجموعات الكلمات التي طابقت النص المجمّع */
    private function applyMatchCount(Builder $query, SearchCriteria $criteria): void
    {
        $groups = $this->groups($criteria);

        if ($groups === []) {
            return;
        }

        $cases = [];
        $bindings = [];

        foreach ($groups as $group) {
            $conditions = [];

            foreach ($group as $variant) {
                $conditions[] = 'search_blob like ?';
                $bindings[] = '%'.$variant.'%';
            }

            $cases[] = 'case when ('.implode(' or ', $conditions).') then 1 else 0 end';
        }

        $query->orderByRaw('('.implode(' + ', $cases).') desc', $bindings);
    }

    /**
     * إشارتا الصلة: العبارة الكاملة في العنوان، ثم عدد كلمات الاستعلام
     * الموجودة في العنوان. ثم يكسر التعادل الترتيب المطلوب.
     *
     * لماذا عدّ لا اشتراط: اسم المدينة لا يكون في العنوان أبداً — هو في
     * النص المجمّع. فأي استعلام «سلعة + مدينة»، وهو أشيع أشكال البحث في
     * اليمن، كان يُسقط النتائج كلها إلى مستوى واحد فيعود الترتيب إلى
     * التاريخ وحده.
     *
     * وهنا العنوان وحده لا النص المجمّع بقصد: العنوان ما اختاره المعلن
     * ليصف السلعة، والمجمّع يضم المحافظة والتصنيف — فلو عُدَّ عليه
     * لتساوت كل إعلانات صنعاء في الصلة.
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
                ['%'.$phrase.'%']
            );
        }

        $hits = implode(
            ' + ',
            array_fill(0, count($tokens), 'case when title_normalized like ? then 1 else 0 end')
        );

        $query->orderByRaw(
            "({$hits}) desc",
            array_map(fn (string $token): string => '%'.$token.'%', $tokens)
        );
    }

    // ---- بيانات مساعدة للواجهة ----

    /**
     * توزيع النتائج على العملات — يُعرض بجانب فلتر العملة.
     *
     * reorder() ضرورية: تُزيل ترتيب الصلة وارتباطاته، وGROUP BY لا يقبل
     * ترتيباً بتعبير على عمود غير مجمَّع.
     */
    public function currencyBreakdown(SearchCriteria $criteria, string $mode = self::MODE_STRICT): array
    {
        return $this->build($criteria, $mode)
            ->reorder()
            ->select('currency_code', DB::raw('count(*) as total'))
            ->groupBy('currency_code')
            ->pluck('total', 'currency_code')
            ->all();
    }
}
