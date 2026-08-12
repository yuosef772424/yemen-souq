<?php

namespace App\Modules\Sms\Enums;

/**
 * نتيجة رسالة واردة.
 *
 * بنفس حجّة `VerificationResult`: «فشل» وحدها لا تكفي. والحالات هنا
 * تُقرأ في سجلّ الخادم لا على شاشة مستخدم — فقيمتها تشخيصية:
 *
 *   UnknownSender  رقم المرسِل ليس جوالاً يمنياً — رسالة عابرة، تُهمَل
 *   NoChallenge    لا تحدٍّ قائم لهذا الرقم — إمّا انتهى أو لم يُطلب
 *   Invalid        الرمز لا يطابق — تخمين، أو نسخٌ عن شاشة غيره
 *   Locked         محاولات كثيرة على نفس التحدّي
 *   Verified       طابق، والحساب وُثِّق
 *
 * و`NoChallenge` هي الأكثر توقّعاً في التشغيل العادي: كل رسالة تصل رقمنا
 * ولا تخصّ توثيقاً تنتهي هنا. تكرارها ليس عطلاً — وتكرار `Invalid` هو
 * ما يستحق النظر.
 */
enum ChallengeResult: string
{
    case Verified = 'verified';
    case Invalid = 'invalid';
    case NoChallenge = 'no_challenge';
    case Locked = 'locked';
    case UnknownSender = 'unknown_sender';

    public function succeeded(): bool
    {
        return $this === self::Verified;
    }
}
