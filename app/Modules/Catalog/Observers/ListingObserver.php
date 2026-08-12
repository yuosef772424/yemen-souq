<?php

namespace App\Modules\Catalog\Observers;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Models\ListingPriceHistory;
use App\Modules\Catalog\Services\ListingBlobBuilder;
use App\Modules\Catalog\Services\ListingFingerprint;
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
        private readonly ListingBlobBuilder $blob,
        private readonly ListingFingerprint $fingerprint,
    ) {
    }

    public function saving(Listing $listing): void
    {
        $this->fillNormalizedText($listing);
        $this->fillSlug($listing);
        $this->fillBasePrice($listing);
        $this->fillSearchBlob($listing);
        $this->fillFingerprint($listing);
    }

    /**
     * سجل تغيّر السعر يُكتب بعد نجاح التحديث لا قبله.
     *
     * كان في updating، فلو فشل استعلام التحديث (قيد، انقطاع اتصال)
     * يبقى صف في listing_price_history يشهد بتغيير لم يحدث.
     */
    public function updated(Listing $listing): void
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

    /**
     * النص المجمّع يُعاد بناؤه عند تغيّر أي من مصادره — ومنها التصنيف
     * والموقع. نقل إعلان من مديرية إلى أخرى دون تحديث العمود يجعله
     * قابلاً للعثور عليه بالمدينة الخطأ، وهذا خطأ صامت لا يُلاحَظ.
     */
    private function fillSearchBlob(Listing $listing): void
    {
        $sources = ['title', 'description', 'category_id', 'location_id'];

        if (! $listing->isDirty($sources) && $listing->search_blob !== null) {
            return;
        }

        $listing->search_blob = $this->blob->for($listing);
    }

    /**
     * بصمة المحتوى — تُعاد دائماً لا عند تغيّر مصادرها.
     *
     * الحساب تجزئةٌ على ثمانية حقول في الذاكرة، وشرطُ `isDirty` عليها
     * يوفّر لا شيء ويفتح باب خطأ: مصدرٌ يُنسى من القائمة يعني بصمةً
     * تصف إعلاناً لم يعد قائماً — وحارس التكرار يقرؤها فيرفض السليم
     * أو يقبل النسخة.
     */
    private function fillFingerprint(Listing $listing): void
    {
        $listing->fingerprint = $this->fingerprint->for($listing);
    }

    private function fillSlug(Listing $listing): void
    {
        if ($listing->slug !== null && $listing->slug !== '') {
            return;
        }

        // Str::slug($title) بلا معامل لغة يحذف كل المحارف العربية ويرجع '' —
        // فتصبح كل الروابط listing-x8f3k2m1 ويُفقد أي أثر في محركات البحث.
        $base = Str::slug($listing->title, '-', null) ?: 'listing';

        $listing->slug = Str::limit($base, 120, '') . '-' . Str::lower(Str::random(6));
    }

    /**
     * تحويل السعر إلى عملة الأساس.
     * السعر الأصلي وعملته يبقيان كما هما — هذا عمود إضافي للفلترة فقط.
     */
    private function fillBasePrice(Listing $listing): void
    {
        $priceChanged = $listing->isDirty(['price_amount', 'currency_code']);

        if (! $priceChanged && $listing->price_in_base !== null) {
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
            $this->conversionDate($listing, $priceChanged),
        );

        $listing->price_in_base = $converted['amount'];
        $listing->rate_used = $converted['rate'];
        $listing->rate_date = $converted['date'];
    }

    /**
     * تغيّر السعر حدث جديد → سعر صرف يومه (null تعني اليوم).
     * وإعادة الحساب لإعلان لم يتغيّر سعره تحترم تاريخه الأصلي.
     */
    private function conversionDate(Listing $listing, bool $priceChanged): ?string
    {
        if ($priceChanged) {
            return null;
        }

        if ($listing->rate_date !== null) {
            return $listing->rate_date->toDateString();
        }

        return ($listing->published_at ?? $listing->created_at)?->toDateString();
    }

    /** سجل تغيّر السعر — يفتح لاحقاً ميزة "انخفض السعر منذ الأسبوع الماضي" */
    private function recordPriceChange(Listing $listing): void
    {
        if (! $listing->wasChanged(['price_amount', 'currency_code'])) {
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