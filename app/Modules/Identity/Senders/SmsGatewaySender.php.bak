<?php

namespace App\Modules\Identity\Senders;

use App\Modules\Identity\Contracts\PhoneVerificationSender;
use RuntimeException;

/**
 * مزوّد رسائل حقيقي — الموضع الذي ينتظر التكامل.
 *
 * موجود كعقد لا كوهم: وجوده يجعل قيمة config صالحة ويحدّد بدقة ما
 * يحتاج التنفيذ، ورميه استثناءً صريحاً أفضل من صنف يبدو عاملاً ويصمت.
 */
final class SmsGatewaySender implements PhoneVerificationSender
{
    public function send(string $phone, string $code): void
    {
        throw new RuntimeException(
            'لم يُربط مزوّد رسائل بعد. نفّذ هذا الصنف أو أعد PHONE_VERIFICATION_DRIVER=display.'
        );
    }

    public function displaysCode(): bool
    {
        return false;
    }
}