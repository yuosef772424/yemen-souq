<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AppNotification
 *
 * الشكل يأتي من الموديل لا يُعاد بناؤه هنا.
 *
 * `AppNotification::toApiArray()` كُتبت وفي تعليقها: «الشكل عقدٌ يخصّ
 * الإشعار نفسه… أول مرة يُنسخ هذا الشكل في موضعين يبدأ افتراقهما».
 * ووحدة الدفع تستعمل نفس الحقول، فنسخُها في مورد API يعني أن إضافة حقل
 * تظهر في قناة وتغيب عن أخرى.
 *
 * ولهذا هذا الصنف سطرٌ واحد: وجوده لأجل `NotificationResource::collection`
 * والترقيم، لا لأجل شكلٍ ثانٍ.
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->toApiArray();
    }
}
