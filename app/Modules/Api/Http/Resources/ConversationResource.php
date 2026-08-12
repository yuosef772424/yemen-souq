<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Engagement\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 *
 * ═══ «الطرف الآخر» لا «المشتري والبائع» ═══
 *
 * دور المستخدم يتبدّل بين خيط وخيط: هو بائعٌ في محادثة عن إعلانه،
 * ومشترٍ في محادثة عن إعلان غيره. وقائمةٌ تعرض العمودين تجبر العميل
 * على أن يقرّر أيّهما يعرض في كل صف — بنفس الشرط، مكتوباً في كل عميل.
 * `counterpart` يجيب السؤال مرة واحدة هنا.
 *
 * ═══ عدد غير المقروء يُحسب عند الطلب ═══
 *
 * `unreadCountFor` استعلامٌ لكل محادثة، وهو ما يجعله محصوراً بقائمة
 * مرقَّمة بخمسة عشر صفاً. عمود مخزَّن أدقّ أداءً، لكنه عدّاد ثانٍ يجب
 * أن يبقى متسقاً مع `messages.read_at` — ومصدرا حقيقةٍ لعددٍ واحد
 * ينحرفان. الحدّ الحالي يجعل الكلفة مقبولة والصحة مضمونة.
 */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $counterpart = $user === null ? null : $this->counterpart($user);

        return [
            'id' => $this->id,
            'listing_id' => $this->listing_id,

            'counterpart' => $counterpart === null ? null : [
                'id' => $counterpart->id,
                'name' => $counterpart->displayName(),
            ],

            // withTrashed على العلاقة: الخيط يبقى بعد حذف الإعلان،
            // وإخفاؤه يقطع محادثةً قد تكون في منتصف اتفاق
            'listing' => new ListingResource($this->whenLoaded('listing')),

            'unread_count' => $user === null ? 0 : $this->unreadCountFor($user),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),

            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
