<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Catalog\Models\ListingImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ListingImage
 *
 * الرابط المطلق لا المسار المخزَّن.
 *
 * التطبيق على جهاز لا يعرف أصل الموقع، وتركيبه من المسار في العميل
 * يعني أن نقل الصور إلى نطاق ثانٍ (CDN) يتطلب إصداراً جديداً من
 * التطبيق على أجهزة الناس. `Storage::url` يقرأ القرص من الإعدادات،
 * فالنقل تغييرٌ في الخادم وحده.
 */
class ListingImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // نفس مُنشئ الرابط الذي تستعمله القوالب: عقدُ التطبيق والويب
            // واحد، وقرصان مختلفان بينهما يعنيان تطبيقاً يرى صوراً
            // ومتصفّحاً لا يراها
            'url' => $this->url(),
            'thumb_url' => $this->thumb_path === null ? null : $this->thumbUrl(),
            'width' => $this->width,
            'height' => $this->height,
            'is_primary' => (bool) $this->is_primary,
        ];
    }
}
