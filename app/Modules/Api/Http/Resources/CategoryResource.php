<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Shared\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 *
 * الشجرة تُرسَل متداخلة لا مسطّحة.
 *
 * التصنيفات تتغيّر مرات في العمر، والتطبيق يخزّنها مرة ويبني منها كل
 * قوائم الاختيار. وقائمةٌ مسطّحة بـ`parent_id` تجبر كل عميل على إعادة
 * بناء الشجرة بنفسه — نفس الحلقة مكتوبة في أندرويد وiOS والويب، وثلاث
 * فرص للاختلاف.
 *
 * و`children` تظهر فقط حين تُحمَّل: نفس التصنيف يُرسَل داخل بطاقة إعلان
 * بلا أبنائه، وهناك الحقل زائدٌ لا ناقص.
 */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name_ar,
            'icon' => $this->icon,
            'parent_id' => $this->parent_id,
            'depth' => (int) $this->depth,
            'allows_auction' => (bool) $this->allows_auction,
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
