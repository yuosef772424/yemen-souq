<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Engagement\Models\ListingQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ListingQuestion
 *
 * السؤال العام وجوابه في كائن واحد.
 *
 * ═══ لماذا لا كائنان ═══
 *
 * الجواب لا يوجد بلا سؤاله، ولا يُعرض إلا تحته. صنفٌ مستقل له يعني
 * أن العميل يربط بينهما بمعرّف — عملٌ يقوم به الخادم أرخص، والشكل
 * يطابق ما تعرضه الشاشة.
 *
 * ═══ اسم السائل يُعرض والهوية لا ═══
 *
 * `asker.id` موجود لأن التطبيق يحتاجه لسؤال واحد: أهذا سؤالي فأعرض زرّ
 * الحذف؟ (الحذف للسائل وحده — انظر `ListingQuestionPolicy`.) وما عدا
 * ذلك اسمٌ معروض لا أكثر: الأسئلة صفحة عامة يقرأها كل زائر.
 */
class QuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'listing_id' => $this->listing_id,
            'body' => $this->body,
            'answer' => $this->answer,
            'is_answered' => $this->isAnswered(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'asker' => $this->whenLoaded('asker', fn (): ?array => $this->asker === null ? null : [
                'id' => $this->asker->id,
                'name' => $this->asker->displayName(),
            ]),

            'listing' => new ListingResource($this->whenLoaded('listing')),

            // القرار من الخادم: زرٌّ يظهر ثم يُرفض بعد الضغط أسوأ من
            // زرٍّ لا يظهر — نفس قاعدة صفحة الإعلان في الويب
            'can_delete' => $request->user()?->id === $this->asker_id,
        ];
    }
}
