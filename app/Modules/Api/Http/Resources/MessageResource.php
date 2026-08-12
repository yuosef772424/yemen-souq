<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Engagement\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 *
 * `is_mine` محسوب في الخادم لا مقارَنٌ في العميل.
 *
 * كل عميل يرسم فقاعة الرسالة يميناً أو يساراً بناءً على هذا السؤال،
 * ومقارنة `sender_id` بالمستخدم الحالي مكتوبةٌ في ثلاثة تطبيقات هي
 * ثلاث فرص لخطأٍ يقلب المحادثة كلها.
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'sender_id' => $this->sender_id,
            'is_mine' => $request->user()?->id === $this->sender_id,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
