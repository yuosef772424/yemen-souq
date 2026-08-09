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
    ) {
    }

    public function saving(Listing $listing): void
    {
        $this->fillNormalizedText($listing);
        $this->fillSlug($listing);
        $this->fillBasePrice($listing);
    }

    /**
     * سجل تغيّر السعر يُكتب بعد نجاح التحديث لا قبله.
     *
     * كان في updating، فلو فشل استعلام التحديث (قيد، انقطاع اتصال)
     * يبقى صف في listing_price_history يشهد بتغيير لم يحدث.
     * في updated الأصول ما زالت متاحة (syncOriginal يجري بعده)،
     * فلا نفقد شيئاً مقابل هذه الضمانة.
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

    private function fillSlug(Listing $listing): void
    {
        if ($listing->slug !== null && $listing->slug !== '') {
            return;
        }

        // Str::slug($title) بلا معامل لغة يحذف كل المحارف العربية ويرجع '' —
        // فتصبح كل الروابط listing-x8f3k2m1 ويُفقد أي أثر في محركات البحث.
        // تمرير null كلغة يُبقي العربية كما هي.
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
     * أي تاريخ نُحوّل به؟ هذا كان العيب.
     *
     * الخطأ السابق: تمرير rate_date المحفوظ دائماً. وrate_date لا يُصفَّر،
     * فمعلن نشر قبل ثلاثة أشهر وخفّض سعره اليوم كان سعره الجديد يُحوَّل
     * بسعر صرف قبل ثلاثة أشهر. في سوق ريالُه متقلب هذا خطأ في جوهر المشروع.
     *
     * القاعدة: تغيّر السعر حدث جديد → سعر صرف يومه (null تعني اليوم).
     * وإعادة الحساب لإعلان لم يتغيّر سعره (تعبئة رجعية لعمود فارغ)
     * تحترم تاريخه الأصلي: rate_date إن وُجد، وإلا تاريخ النشر، وإلا الإنشاء.
     *
     * البديل المرفوض: استعمال اليوم دائماً. أبسط، لكنه يعيد تسعير كل
     * الإعلانات القديمة بسعر اليوم عند أي تعبئة رجعية — ويهدم تاريخية الأسعار
     * التي بُني عليها ExchangeRateService.
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