<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Services\ExchangeRateService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListingController
{
    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly EventRecorder $events,
    ) {}

    public function show(Request $request, Listing $listing): View
    {
        abort_unless($listing->status === 'active', 404);

        $listing->load(['currency', 'category.parent', 'location.parent', 'images', 'user']);

        // زيادة العدّاد بلا لمس updated_at — وإلا بدا كل إعلان مُعدَّلاً للتو
        $listing->newQuery()->whereKey($listing->getKey())->increment('views_count');

        $this->recordVisit($request, $listing);

        return view('catalog::show', [
            'listing' => $listing,
            'baseCurrency' => Currency::base(),
            'equivalents' => $this->equivalents($listing),
            'similar' => $this->similar($listing),
        ]);
    }

    /**
     * حدث المشاهدة، وحدث النقر إن جاء من نتائج البحث.
     *
     * لماذا نتتبّع النقرة هنا لا بـJavaScript في صفحة النتائج:
     * الرابط يحمل from وpos وq، فالنقرة تُسجَّل بمجرد وصول الزائر.
     * أبسط، ولا يفقد أحداثاً إن كان JS معطّلاً أو الشبكة انقطعت.
     */
    private function recordVisit(Request $request, Listing $listing): void
    {
        $source = $request->string('from')->value() ?: 'direct';

        $this->events->record(EventName::ListingViewed, 'listing', $listing->id, [
            'source' => $source,
            'category_id' => $listing->category_id,
            'currency' => $listing->currency_code,
        ]);

        if ($source !== 'search') {
            return;
        }

        $this->events->record(EventName::SearchResultClicked, 'listing', $listing->id, [
            'q_normalized' => $request->string('q')->value() ?: null,
            'position' => $request->integer('pos') ?: null,
        ]);
    }

    /**
     * السعر مُعبَّراً عنه بكل العملات النشطة.
     *
     * هذه هي المشكلة الأصلية معكوسة: بدل أن يخمّن المشتري قيمة رقم
     * بعملة لا يفكر بها، نعرضها له بعملته صريحةً.
     */
    private function equivalents(Listing $listing): array
    {
        if ($listing->price_amount === null || $listing->currency_code === null) {
            return [];
        }

        $result = [];

        foreach (Currency::active()->get() as $currency) {
            if ($currency->code === $listing->currency_code) {
                continue;
            }

            $result[] = [
                'currency' => $currency,
                'amount' => $this->rates->convert(
                    (float) $listing->price_amount,
                    $listing->currency_code,
                    $currency->code,
                    $listing->rate_date?->toDateString(),
                ),
            ];
        }

        return $result;
    }

    /** إعلانات قريبة في القيمة الموحّدة — لا في الرقم المكتوب */
    private function similar(Listing $listing): iterable
    {
        $query = Listing::query()
            ->published()
            ->whereKeyNot($listing->getKey())
            ->where('category_id', $listing->category_id)
            ->with(['currency', 'category', 'location', 'images'])
            ->limit(6);

        if ($listing->price_in_base !== null) {
            $query->orderByRaw('ABS(price_in_base - ?)', [(float) $listing->price_in_base]);
        } else {
            $query->latest('published_at');
        }

        return $query->get();
    }
}
