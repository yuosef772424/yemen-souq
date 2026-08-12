<?php

namespace App\Modules\Notifications\Senders;

use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\DTO\PushMessage;
use RuntimeException;

/**
 * مزوّد دفع حقيقي — الموضع الذي ينتظر التكامل.
 *
 * موجود كعقد لا كوهم، بنفس حجّة SmsGatewaySender: وجوده يجعل قيمة
 * PUSH_DRIVER=fcm صالحة ويحدّد بدقة ما يحتاج التنفيذ، ورميه استثناءً
 * صريحاً أفضل من صنف يبدو عاملاً ويصمت.
 *
 * ═══ ما ينقص لتنفيذه ═══
 *
 * 1. طلب HTTP إلى FCM بدفعات ≤ 500 رمزاً — الحدّ من المزوّد، وتجاوزه
 *    يُرفَض كلّه لا جزؤه.
 * 2. قراءة نتيجة كل رمز على حدة من الاستجابة: FCM يردّ 200 على الدفعة
 *    كاملةً حتى لو فشل نصفها، والتفصيل داخل مصفوفة results.
 * 3. إرجاع رموز NotRegistered وInvalidRegistration — وهي وحدها التي
 *    تُحذف. أما Unavailable وInternalServerError فأعطال مؤقتة، وحذف
 *    رموزها يُفقد المستخدم إشعاراته إلى الأبد بسبب انقطاع دقيقة.
 *
 * البند الثالث هو ما يجعل هذا الصنف يستحق كتابةً حقيقية لا استدعاء
 * مكتبة: الفرق بين «فشل مؤقت» و«رمز ميت» هو الفرق بين نظام يتعافى
 * وآخر يتآكل.
 */
final class FcmPushSender implements PushSender
{
    public function send(array $tokens, PushMessage $message): array
    {
        throw new RuntimeException(
            'لم يُربط مزوّد دفع بعد. نفّذ هذا الصنف أو أعد PUSH_DRIVER=log.'
        );
    }
}
