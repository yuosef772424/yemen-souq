<?php

namespace App\Modules\Search\Http\Controllers;

use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Search\Services\SearchQueryBuilder;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\ArabicNormalizer;
use App\Modules\Shared\Services\ExchangeRateService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class SearchController
{
    public function __construct(
        private readonly SearchQueryBuilder $search,
        private readonly ExchangeRateService $rates,
        private readonly ArabicNormalizer $normalizer,
        private readonly EventRecorder $events,
    ) {}

    public function __invoke(Request $request): View
    {
        $criteria = SearchCriteria::fromRequest($request);

        // القياس حول الاستعلام نفسه فقط — لا حول تجهيز القوالب
        $startedAt = microtime(true);
        $result = $this->search->search($criteria);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $listings = $result['listings'];
        $mode = $result['mode'];

        $this->recordSearch($criteria, $listings->total(), $durationMs, $mode);
        $this->recordFilters($criteria, $listings->total());
        $this->recordImpressions($listings);

        return view('search::index', [
            'criteria' => $criteria,
            'listings' => $listings,
            'matchMode' => $mode,
            'breakdown' => $this->breakdown($criteria, $listings, $mode),
            'currencies' => Currency::active()->get(),
            'categories' => Category::active()->roots()->with('children')->get(),
            'governorates' => Location::active()->governorates()->get(),
            'rateMap' => $this->rates->currentRates(),
            'baseCurrency' => Currency::base(),
            'categoryAttributes' => $this->categoryAttributes($criteria),
        ]);
    }

    /**
     * خصائص التصنيف المختار — لبناء فلاتره.
     *
     * القابلة للفلترة وحدها: is_filterable في محور category_attributes
     * موجود لهذا الغرض بالضبط، وعرض خاصية لا تُفلتر يَعِد بما لا يقع.
     */
    private function categoryAttributes(SearchCriteria $criteria): Collection
    {
        if ($criteria->categoryId === null) {
            return collect();
        }

        $category = Category::with(['attributes' => fn ($query) => $query->wherePivot('is_filterable', true),
            'attributes.options'])->find($criteria->categoryId);

        return $category?->attributes ?? collect();
    }

    /**
     * توزيع العملات يُحسب بنفس درجة المطابقة التي أنتجت النتائج، ويُتخطّى
     * كلياً عند صفر نتائج.
     *
     * كان يُحسب دائماً، أي مسح كامل ثالث للجدول في كل بحث — واحد للنتائج،
     * وواحد لعدّاد الترقيم، وواحد للتوزيع. وعند صفر نتائج كانت الثلاثة
     * تُنفَّذ لتعرض شريطاً فارغاً لا يقرأه أحد.
     */
    private function breakdown(SearchCriteria $criteria, LengthAwarePaginator $listings, string $mode): array
    {
        if ($listings->total() === 0) {
            return [];
        }

        return $this->search->currencyBreakdown(
            $criteria,
            $mode === 'near' ? SearchQueryBuilder::MODE_LOOSE : SearchQueryBuilder::MODE_STRICT
        );
    }

    /**
     * حدث البحث.
     *
     * يُسجَّل فقط عند وجود نية بحث فعلية — تصفّح الصفحة الأولى بلا فلاتر
     * ليس بحثاً، وتسجيله يُفسد كل إحصاءات «الاستعلامات بصفر نتائج».
     *
     * وmatch_mode أُضيف إلى الحمولة لأنه أهم رقم تشخيصي في المحرك:
     * نسبة الاستعلامات التي فشلت مطابقتها التامة هي المقياس المباشر
     * لجودة النص المجمّع والمكافئات. بلا تسجيله، التراجع إلى «الأقرب»
     * يعمل بصمت ولا نعرف كم مرة احتاجه الناس.
     */
    private function recordSearch(SearchCriteria $criteria, int $total, int $durationMs, string $mode): void
    {
        if (! $criteria->hasQuery() && ! $criteria->hasPriceFilter()) {
            return;
        }

        $this->events->record(EventName::SearchPerformed, payload: [
            // النص المطبّع فقط: التجميع يعمل صحيحاً، والنص الأصلي لا يُحفظ
            'q_normalized' => $this->normalizer->normalize($criteria->query),
            'results' => $total,
            'duration_ms' => $durationMs,
            'match_mode' => $mode,
            'filters' => $criteria->activeFilterKeys(),
            'price_currency' => $criteria->hasPriceFilter() ? $criteria->priceCurrency : null,
            'sort' => $criteria->sort,
        ]);
    }

    /** حدث لكل فلتر مُطبَّق — منه تُحسب الفلاتر المُربكة */
    private function recordFilters(SearchCriteria $criteria, int $total): void
    {
        foreach ($criteria->activeFilterKeys() as $key) {
            $this->events->record(EventName::FilterApplied, payload: [
                'key' => $key,
                'results_after' => $total,
            ]);
        }
    }

    /** ظهور كل نتيجة مع موضعها — البسط في حساب نسبة النقر */
    private function recordImpressions(LengthAwarePaginator $listings): void
    {
        if ($listings->isEmpty()) {
            return;
        }

        $offset = ($listings->firstItem() ?? 1) - 1;

        $this->events->recordImpressions($listings->pluck('id')->all(), $offset);
    }
}
