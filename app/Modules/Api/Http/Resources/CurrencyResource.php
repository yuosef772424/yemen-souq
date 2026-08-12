<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Shared\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Currency
 *
 * `decimals` ليس تفصيلاً تجميلياً: الريال اليمني بلا كسور والدولار
 * بخانتين، وتطبيقٌ يعرض «١٥٠٠٠٠٫٠٠ ر.ي» يبدو مكسوراً. العدد يُرسَل
 * ليقرأه العميل لا ليخمّنه.
 *
 * و`is_base` يقول أيّ عملة تُحسب إليها المقارنات — وهو ما يفسّر
 * `price.in_base` في بطاقة الإعلان.
 */
class CurrencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name_ar,
            'symbol' => $this->symbol,
            'decimals' => (int) $this->decimals,
            'is_base' => (bool) $this->is_base,
        ];
    }
}
