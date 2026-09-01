<?php

namespace App\Modules\Identity\Senders;

use App\Modules\Identity\Contracts\PhoneVerificationSender;
use App\Modules\Identity\Exceptions\PhoneVerificationUnavailableException;

/**
 * لا يُرسل شيئاً — يُعرَض الرمز على الشاشة.
 *
 * للتطوير والعرض التجريبي. الرمز مولَّد عشوائياً كما في الإنتاج تماماً،
 * فيمرّ بالتجزئة والصلاحية وعدّ المحاولات كلها. الفرق الوحيد قناة التوصيل.
 *
 * ولماذا لا رمز ثابت (123456): يتخطّى كل ما سبق، فيبقى المسار الحقيقي
 * غير مُجرَّب حتى يوم النشر — وإن نجا إلى الإنتاج فهو باب مفتوح.
 */
final class DisplaySender implements PhoneVerificationSender
{
    public function send(string $phone, string $code): void
    {
        if (app()->environment('production')) {
            throw new PhoneVerificationUnavailableException(
                'DisplaySender لا يعمل في الإنتاج. اضبط PHONE_VERIFICATION_DRIVER=sms.'
            );
        }

        // السجل يكفي كأثر؛ العرض يتم في الواجهة
        logger()->info('رمز تحقق (بيئة تطوير)', ['phone' => $phone]);
    }

    public function displaysCode(): bool
    {
        return true;
    }
}