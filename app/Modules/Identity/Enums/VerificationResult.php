<?php

namespace App\Modules\Identity\Enums;

/**
 * نتيجة محاولة تحقق.
 *
 * لماذا enum لا bool: "فشل" وحدها لا تكفي. الرمز المنتهي يحتاج زر
 * إعادة إرسال، والمقفل يحتاج انتظاراً، والخاطئ يحتاج محاولة أخرى —
 * ثلاث رسائل مختلفة للمستخدم من نفس القيمة false.
 */
enum VerificationResult: string
{
    case Verified = 'verified';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case Locked = 'locked';

    public function message(): string
    {
        return match ($this) {
            self::Verified => 'تم توثيق رقمك.',
            self::Invalid => 'الرمز غير صحيح.',
            self::Expired => 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.',
            self::Locked => 'تجاوزت عدد المحاولات المسموح. اطلب رمزاً جديداً.',
        };
    }
}