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
use Illuminate\Http\Request;
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
        $listings = $this->search->paginate($criteria);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->recordSearch($criteria, $listings->total(), $durationMs);
        $this->recordFilters($criteria, $listings->total());
        $this->recordImpressions($listings);

        return view('search::index', [
            'criteria' => $criteria,
            'listings' => $listings,
            'breakdown' => $this->search->currencyBreakdown($criteria),
            'currencies' => Currency::active()->get(),
            'categories' => Category::active()->roots()->with('children')->get(),
            'governorates' => Location::active()->governorates()->get(),
            'rateMap' => $this->rates->currentRates(),
            'baseCurrency' => Currency::base(),
        ]);
    }

    /**
     * حدث البحث.
     *
     * يُسجَّل فقط عند وجود نية بحث فعلية — تصفّح الصفحة الأولى بلا فلاتر
     * ليس بحثاً، وتسجيله يُفسد كل إحصاءات «الاستعلامات بصفر نتائج».
     */
    private function recordSearch(SearchCriteria $criteria, int $total, int $durationMs): void
    {
        if (! $criteria->hasQuery() && ! $criteria->hasPriceFilter()) {
            return;
        }

        $this->events->record(EventName::SearchPerformed, payload: [
            // النص المطبّع فقط: التجميع يعمل صحيحاً، والنص الأصلي لا يُحفظ
            'q_normalized' => $this->normalizer->normalize($criteria->query),
            'results' => $total,
            'duration_ms' => $durationMs,
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
    private function recordImpressions($listings): void
    {
        if ($listings->isEmpty()) {
            return;
        }

        $offset = ($listings->firstItem() ?? 1) - 1;

        $this->events->recordImpressions($listings->pluck('id')->all(), $offset);
    }
}
