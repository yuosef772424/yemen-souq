<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Resources\AttributeResource;
use App\Modules\Api\Http\Resources\CategoryResource;
use App\Modules\Api\Http\Resources\CurrencyResource;
use App\Modules\Api\Http\Resources\LocationResource;
use App\Modules\Catalog\Services\ListingAttributes;
use App\Modules\Catalog\Services\ListingImageService;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * بنية السوق: التصنيفات، المواقع، العملات، الخصائص.
 *
 * ═══ متحكّم واحد لأربع قوائم ═══
 *
 * كلها ثابتة، عامة، وتُجلب مرة واحدة عند أول تشغيل للتطبيق ثم تُخزَّن
 * محلياً. وأربعة ملفات لأربع دوال بلا حالة ولا تبعيات مشتركة تفرّق ما
 * يُقرأ معاً — والقارئ الذي يسأل «ماذا يجلب التطبيق عند الإقلاع؟» يجد
 * الجواب في ملف واحد.
 *
 * ═══ /bootstrap نقطة واحدة تجمعها ═══
 *
 * التطبيق على شبكة يمنية يدفع ثمن كل رحلة. أربع نقاط نهاية تعني أربع
 * جولات قبل أن تُرسَم أول شاشة، ونقطةٌ جامعة تجعلها واحدة. والأربع
 * تبقى مستقلة لمن يريد تحديث واحدة منها وحدها.
 *
 * ═══ الخصائص تحتاج تصنيفاً ═══
 *
 * `groupedByCategory` يُرجع خريطة (تصنيف ← خصائص) في استعلام واحد —
 * وهو ما يبني به نموذجُ النشر كلَّ حقوله دفعةً. أما `/categories/{id}/
 * attributes` فلمن يريد تصنيفاً واحداً بلا حمل الخريطة كلها.
 */
class TaxonomyController
{
    public function __construct(
        private readonly ListingAttributes $attributes,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * كل ما يحتاجه التطبيق ليرسم أول شاشة — في رحلة واحدة.
     */
    public function bootstrap(): JsonResponse
    {
        return response()->json([
            'version' => config('api.version'),
            'categories' => CategoryResource::collection($this->categoryTree()),
            'locations' => LocationResource::collection($this->locationTree()),
            'currencies' => CurrencyResource::collection(Currency::active()->get()),
            'base_currency' => Currency::baseCode(),
            // أسعار الصرف الحالية: التطبيق يعرض المكافئ بلا رحلة لكل إعلان
            'exchange_rates' => $this->rates->currentRates(),
            'attributes_by_category' => $this->attributes->groupedByCategory()
                ->map(fn ($attributes) => AttributeResource::collection($attributes)->resolve()),
            'limits' => [
                'max_images_per_listing' => ListingImageService::MAX_PER_LISTING,
                'max_per_page' => (int) config('api.pagination.max'),
            ],
        ]);
    }

    public function categories(): AnonymousResourceCollection
    {
        return CategoryResource::collection($this->categoryTree());
    }

    public function locations(): AnonymousResourceCollection
    {
        return LocationResource::collection($this->locationTree());
    }

    public function currencies(): AnonymousResourceCollection
    {
        return CurrencyResource::collection(Currency::active()->get());
    }

    /**
     * خصائص تصنيف واحد — مع خياراتها وقواعد التحقق.
     *
     * الربط بـid لا بـslug هنا خلافاً لبقية المسارات: `attributes` تُقرأ
     * فوراً بعد اختيار التصنيف من قائمة يحمل كل عنصر فيها معرّفه، والعميل
     * يملك الرقم لا الاسم اللطيف.
     */
    public function attributes(int $categoryId): AnonymousResourceCollection
    {
        // 404 على تصنيف غير موجود بدل قائمة فارغة: القائمة الفارغة
        // مشروعة (تصنيف بلا خصائص)، فخلطها بالخطأ يخفي معرّفاً خاطئاً
        abort_unless(Category::whereKey($categoryId)->exists(), 404);

        return AttributeResource::collection($this->attributes->forCategory($categoryId));
    }

    /**
     * الشجرة بمستويين — وهو كل ما تحمله البذرة.
     *
     * `with('children')` لا تحميل عودي غير محدود: عمقٌ مفتوح يعني
     * استعلاماً لكل مستوى، وشجرةً قد تكبر بلا أن يلاحظ أحد. المستويان
     * هما ما تعرضه كل شاشة اختيار في التطبيق.
     */
    private function categoryTree()
    {
        return Category::active()
            ->roots()
            ->with(['children' => fn ($query) => $query->where('is_active', true)])
            ->get();
    }

    private function locationTree()
    {
        return Location::active()
            ->governorates()
            ->with(['children' => fn ($query) => $query->where('is_active', true)])
            ->get();
    }
}
