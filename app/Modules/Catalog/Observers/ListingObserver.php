<?php

namespace App\Modules\Catalog\Observers;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Models\ListingPriceHistory;
use App\Modules\Shared\Services\ArabicNormalizer;
use App\Modules\Shared\Services\ExchangeRateService;
use Illuminate\Support\Str;

/**
 * كل الأعمدة المحسوبة تُملأ هنا — في مكان واحد.
 *
 * لماذا Observer وليس Controller: أي مسار لحفظ إعلان (واجهة، API،
 * أمر artisan، seeder، اختبار) يمر من هنا. لو وضعنا المنطق في الـController
 * فسينسى أحدهم استدعاءه يوماً ما، وستظهر إعلانات بلا price_in_base
 * فتختفي من نتائج الفلترة بصمت — وهذا أسوأ أنواع الأخطاء.
 */
class ListingObserver
{
    public function __construct(
        private readonly ArabicNormalizer $normalizer,
        private readonly ExchangeRateService $rates,
    ) {}

    public function saving(Listing $listing): void
    {
        $this->fillNormalizedText($listing);
        $this->fillSlug($listing);
        $this->fillBasePrice($listing);
    }

    public function updating(Listing $listing): void
    {
        $this->recordPriceChange($listing);
    }

    // ---- التفاصيل ----

    private function fillNormalizedText(Listing $listing): void
    {
        if ($listing->isDirty('title')) {
            $listing->title_normalized = $this->normalizer->normalize($listing->title);
        }

        if ($listing->isDirty('description')) {
            $listing->description_normalized = $this->normalizer->normalize($listing->description);
        }
    }

    private function fillSlug(Listing $listing): void
    {
        if ($listing->slug !== null && $listing->slug !== '') {
            return;
        }

        // العربية لا تنتج slug مفيداً، فنعتمد على معرّف عشوائي قصير
        $base = Str::slug($listing->title) ?: 'listing';

        $listing->slug = $base.'-'.Str::lower(Str::random(8));
    }

    /**
     * تحويل السعر إلى عملة الأساس.
     * السعر الأصلي وعملته يبقيان كما هما — هذا عمود إضافي للفلترة فقط.
     */
    private function fillBasePrice(Listing $listing): void
    {
        $needsRecalculation = $listing->isDirty(['price_amount', 'currency_code'])
            || $listing->price_in_base === null;

        if (! $needsRecalculation) {
            return;
        }

        if ($listing->price_amount === null || $listing->currency_code === null) {
            $listing->price_in_base = null;
            $listing->rate_used = null;
            $listing->rate_date = null;

            return;
        }

        $converted = $this->rates->toBase(
            (float) $listing->price_amount,
            $listing->currency_code,
            $listing->rate_date?->toDateString(),
        );

        $listing->price_in_base = $converted['amount'];
        $listing->rate_used = $converted['rate'];
        $listing->rate_date = $converted['date'];
    }

    /** سجل تغيّر السعر — يفتح لاحقاً ميزة "انخفض السعر منذ الأسبوع الماضي" */
    private function recordPriceChange(Listing $listing): void
    {
        if (! $listing->isDirty(['price_amount', 'currency_code'])) {
            return;
        }

        ListingPriceHistory::create([
            'listing_id' => $listing->id,
            'old_amount' => $listing->getOriginal('price_amount'),
            'old_currency' => $listing->getOriginal('currency_code'),
            'new_amount' => $listing->price_amount,
            'new_currency' => $listing->currency_code,
            'new_in_base' => $listing->price_in_base,
            'changed_at' => now(),
        ]);
    }
}
