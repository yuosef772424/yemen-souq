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
 * إلى عملة الأساس، ثم يُقارن بعمود price_in_base المفهرس. البديل الخاطئ
 * الشائع — تحويل سعر كل إعلان أثناء الاستعلام — يمنع استخدام الفهرس
 * ويجعل البحث يبطؤ خطياً مع عدد الإعلانات.
 *
 * ═══ الثغرة المُسدَّدة في هذه النسخة ═══
 *
 * كانت المطابقة اشتراطاً صارماً لكل كلمة: كلمة واحدة غائبة تعني صفر
 * نتائج. و«تويوتا كورولا 2018 اوتوماتيك صنعاء» استعلام واقعي تماماً،
 * وكفيل بأن يُرجع صفراً حتى مع وجود عشرين كورولا في صنعاء — لأن
 * «اوتوماتيك» لم يكتبها المعلن.
 *
 * البنية الآن ثلاث درجات، بنفس الترتيب المطلوب:
 *
 *   1. تام    كل كلمة موجودة (بذاتها أو بمكافئها)
 *   2. قريب   إن أعطت الدرجة الأولى صفراً: كلمة واحدة تكفي، والترتيب
 *             بعدد الكلمات المطابقة تنازلياً
 *   3. رواج   ليس بعد — يحتاج بيانات سلوك حقيقية، وهو المرحلة التالية
 *
 * والدرجة الثانية لا تعمل إلا عند فشل الأولى. البديل المرفوض: تشغيلها
 * دائماً والاكتفاء بالترتيب. مرفوض لأن مطابقة كلمة واحدة من أربع تعني
 * صفحات من ضجيج فوق النتائج الصحيحة، وثقة المستخدم بالنتيجة أغلى من
 * تغطية إضافية لا يريدها.
 *
 * الكلفة: بحث بصفر نتائج يمسح الجدول مرتين بدل مرة. مقبول لأنه الحالة
 * التي يخرج فيها المستخدم من المنصة إن لم نُصلحها.
 */
final class SearchQueryBuilder
{
    public const MODE_STRICT = 'strict';

    public const MODE_LOOSE = 'loose';

    public function __construct(
        private readonly SearchTokenizer $tokenizer,
        private readonly SynonymExpander $synonyms,
        private readonly ExchangeRateService $rates,
    ) {
    }

    /**
     * البحث بدرجاته.
     *
     * @return array{listings: LengthAwarePaginator, mode: string}
     *         mode ∈ browse | exact | near | none
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
        return $this->build($criteria, $mode)
            ->paginate($criteria->perPage)
            ->withQueryString();
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
        $this->applySort($query, $criteria, $mode);

        return $query;
    }

    // ---- النص ----

    /**
     * المطابقة تجري على search_blob وحده.
     *
     * كانت على title_normalized وdescription_normalized، فكان «شقة في
     * حدة» يفشل لأن اسم المديرية مفتاح أجنبي لا نص. والعمود المجمّع يضم
     * العنوان والوصف والتصنيف والموقع والمحافظة.
     *
     * نستخدم LIKE لا MATCH لأن محلل MySQL الافتراضي لا يفهم سوابق
     * ولواحق العربية، فيفوّت «بالسيارة» عند البحث عن «سيارة».
     */
    private function applyText(Builder $query, SearchCriteria $criteria, string $mode): void
    {
        $groups = $this->groups($criteria);

        if ($groups === []) {
            return;
        }

        if ($mode === self::MODE_STRICT) {
            // كل مجموعة شرط لازم، وداخل المجموعة الكلمة أو أحد مكافئاتها
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

        // الدرجة الثانية: مجموعة واحدة تكفي — والترتيب هو ما يفصل
        $query->where(function (Builder $outer) use ($groups): void {
            foreach ($groups as $group) {
                foreach ($group as $variant) {
                    $outer->orWhere('search_blob', 'like', "%{$variant}%");
                }
            }
        });
    }

    /** @return array<int, array<int, string>> */
    private function groups(SearchCriteria $criteria): array
    {
        if (! $criteria->hasQuery()) {
            return [];
        }

        $tokens = $this->tokenizer->tokens($criteria->query);

        return $tokens === [] ? [] : $this->synonyms->expand($tokens);
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

    private function applySort(Builder $query, SearchCriteria $criteria, string $mode): void
    {
        // المميّزة أولاً — والنطاق يتحقق من سريان الترقية لا من العلم وحده
        $query->featuredFirst();

        // في الدرجة الثانية يتقدّم عدد الكلمات المطابقة على كل ما بعده:
        // النتيجة التي طابقت ثلاث كلمات أقرب حتماً من التي طابقت واحدة
        if ($mode === self::MODE_LOOSE) {
            $this->applyMatchCount($query, $criteria);
        }

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
                $bindings[] = '%' . $variant . '%';
            }

            $cases[] = 'case when (' . implode(' or ', $conditions) . ') then 1 else 0 end';
        }

        $query->orderByRaw('(' . implode(' + ', $cases) . ') desc', $bindings);
    }

    /**
     * إشارتا الصلة، بهذا الترتيب:
     *
     *   1. العبارة الكاملة كما كتبها المستخدم موجودة في العنوان
     *   2. عدد كلمات الاستعلام الموجودة في العنوان
     *
     * ثم يكسر التعادل الترتيب المطلوب (الأحدث افتراضاً).
     *
     * لماذا عدّ لا اشتراط كل الكلمات: اسم المدينة لا يكون في العنوان
     * أبداً — هو في النص المجمّع. فأي استعلام من شكل «سلعة + مدينة»،
     * وهو أشيع أشكال البحث في اليمن، كان يُسقط كل النتائج إلى مستوى
     * واحد فيعود الترتيب إلى التاريخ وحده.
     *
     * ولماذا يبقى هذا مقبولاً بعد رفض المجموع المرجّح (0.6×صلة +
     * 0.4×رواج): العدّ ليس وزناً. «عنوانه طابق كلمتين من كلماتك» جملة
     * يفهمها البائع؛ «درجته 0.73» ليست كذلك.
     *
     * وهنا العنوان وحده لا النص المجمّع، عن قصد: العنوان ما اختاره
     * المعلن ليصف السلعة، والنص المجمّع يضم اسم المحافظة والتصنيف —
     * فلو عُدَّ عليه لتساوت كل إعلانات صنعاء في الصلة.
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
     *
     * reorder() ضرورية: تُزيل ترتيب الصلة وارتباطاته، وGROUP BY لا يقبل
     * ترتيباً بتعبير على عمود غير مجمَّع.
     *
     * والدرجة تُمرَّر من المتحكّم: لو حُسب التوزيع دائماً بالدرجة الأولى
     * لعرض «0 نتيجة بالريال» فوق صفحة مليئة بنتائج الدرجة الثانية —
     * رقمان متناقضان في شاشة واحدة.
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