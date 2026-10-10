<?php

namespace App\Modules\Engagement\Services;

use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Models\Message;

/**
 * نتيجة الإرسال: الرسالة والمحادثة، وهل أُنشئت الآن أم أُعيدت.
 *
 * الفرق يحدّد رمز الاستجابة (201 للإنشاء و200 للتكرار) — فالعميل الذي
 * أعاد المحاولة بعد انقطاع يعرف أن رسالته وصلت أول مرة، لا أنها أُرسلت
 * ثانية.
 */
final class SentMessage
{
    public function __construct(
        public readonly Conversation $conversation,
        public readonly Message $message,
        public readonly bool $created,
    ) {}
}
