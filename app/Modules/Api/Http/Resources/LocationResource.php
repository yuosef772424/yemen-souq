<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Shared\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Location
 *
 * محافظة ومديرياتها — بنفس شكل التصنيفات ولنفس السبب.
 *
 * والإحداثيات تُرسَل حيث وُجدت: التطبيق يعرض خريطة، وجلبها بطلب ثانٍ
 * لكل موقع يعني رحلة شبكة لأجل رقمين موجودين في نفس الصف.
 */
class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name_ar,
            'level' => $this->level,
            'parent_id' => $this->parent_id,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
