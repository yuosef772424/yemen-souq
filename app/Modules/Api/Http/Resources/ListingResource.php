<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Catalog\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Listing
 *
 * بطاقة الإعلان — الشكل الذي تعرضه قوائم البحث والمفضلة ولوحة المعلن.
 *
 * ═══ السعر كائن لا رقم ═══
 *
 * `price_amount` وحده لا يُعرض: المنصة بثلاث عملات، ورقمٌ بلا عملته
 * يُقرأ خطأً بمقدار مئات الأضعاف. والكائن يحمل الأصل (ما أدخله المعلن)
 * والمحوَّل (ما يُفلتَر ويُرتَّب به) معاً — وهو التمييز الذي يقوم عليه
 * البحث الواعي بالعملة كله.
 *
 * و`formatted` يأتي من `Listing::formattedPrice()` لا من العميل: «مجاناً»
 * و«السعر عند الطلب» حالتان لا رقم فيهما، واشتقاقهما في التطبيق يعني
 * نسخةً ثانيةً من القاعدة تتفرّق عند أول تعديل.
 *
 * ═══ العلاقات بـwhenLoaded ═══
 *
 * بلاها يُنتج كل صفٍّ في صفحة نتائج استعلاماً للتصنيف وآخر للموقع وآخر
 * للصور. `whenLoaded` يجعل الحقل يغيب حين لا تُحمَّل العلاقة بدل أن
 * يُحمَّلها كسولاً — فالغياب خطأٌ في المتحكّم يظهر فوراً، لا بطءٌ صامت.
 */
class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,

            'price' => [
                'amount' => $this->price_amount === null ? null : (float) $this->price_amount,
                'currency' => $this->currency_code,
                'type' => $this->price_type,
                'is_negotiable' => (bool) $this->is_negotiable,
                'formatted' => $this->formattedPrice(),
                // القيمة الموحّدة للمقارنة والفرز — لا للعرض
                'in_base' => $this->price_in_base === null ? null : (float) $this->price_in_base,
            ],

            'condition' => $this->condition_type,
            'status' => $this->status,

            'category' => new CategoryResource($this->whenLoaded('category')),
            'location' => new LocationResource($this->whenLoaded('location')),

            'primary_image' => $this->whenLoaded(
                'images',
                fn () => $this->images->isEmpty()
                    ? null
                    : new ListingImageResource($this->images->firstWhere('is_primary', true) ?? $this->images->first())
            ),

            'counters' => [
                'views' => (int) $this->views_count,
                'favorites' => (int) $this->favorites_count,
                'contacts' => (int) $this->contacts_count,
            ],

            'is_featured' => $this->isFeaturedNow(),
            'is_expired' => $this->isExpired(),

            'published_at' => $this->published_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'sold_at' => $this->sold_at?->toIso8601String(),
        ];
    }
}
