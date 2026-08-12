<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Shared\Models\Attribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attribute
 *
 * تعريف الخاصية كما يحتاجه العميل ليبني حقلاً ويتحقّق منه محلياً.
 *
 * ═══ القواعد تُرسَل لأن الخادم يشتقّها ═══
 *
 * `ListingAttributes::rules` تبني التحقق من `data_type` و`min_value`
 * و`max_value` و`is_required` في قاعدة البيانات. فإرسال نفس الحقول
 * يجعل العميل يتحقّق بنفس القاعدة بلا أن يعرفها مسبقاً — وإضافة خاصية
 * بالبذرة تظهر في التطبيق محقَّقةً بلا إصدار جديد.
 *
 * وهذا هو الفرق بين إرسال «التعريف» وإرسال «قائمة حقول»: الثاني يجبر
 * كل عميل على نسخ منطق التحقق، والنسخة تتقادم بصمت.
 *
 * ═══ is_required وis_filterable من المحور ═══
 *
 * الخاصية نفسها قد تكون مطلوبة في تصنيف واختيارية في آخر —
 * `category_attributes` هو ما يحمل الفرق. ولذلك يُقرآن من `pivot`،
 * ويغيبان حين تُقرأ الخاصية خارج سياق تصنيف.
 */
class AttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->attr_key,
            'name' => $this->name_ar,
            'data_type' => $this->data_type,
            'unit' => $this->unit,
            'min' => $this->min_value === null ? null : (float) $this->min_value,
            'max' => $this->max_value === null ? null : (float) $this->max_value,

            'is_required' => $this->when(
                $this->pivot !== null,
                fn (): bool => (bool) $this->pivot->is_required
            ),
            'is_filterable' => $this->when(
                $this->pivot !== null,
                fn (): bool => (bool) $this->pivot->is_filterable
            ),

            'options' => $this->whenLoaded(
                'options',
                fn (): array => $this->options
                    ->map(fn ($option): array => [
                        'id' => $option->id,
                        'value' => $option->value_ar,
                    ])
                    ->values()
                    ->all()
            ),
        ];
    }
}
