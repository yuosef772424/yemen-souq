<?php

namespace App\Modules\Sms\Senders;

use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Sms\Services\SmsOutbox;

/**
 * إشعارات النظام إلى نفس الطابور — بجانب رموز التحقق.
 *
 * نفس نمط OutboxSender: يكتب في الطابور ولا يرسل، والإرسال يقع على هاتف
 * في مكان آخر. والفرق غرض الرسالة `notification` لا `phone_verification`
 * — يميّزها `sms:status` عند التشخيص، ولا يمنحها أولوية السحب التي
 * يملكها رمز التحقق.
 *
 * والأجل هنا مصدره وحدة الإشعارات لا `sms.default_ttl_minutes`: ذاك
 * الإعداد صُنع لعمر رمزٍ يعيش دقائق، وإشعارٌ عادي يستحق يوماً كاملاً قبل
 * أن يُعتبر عفا عليه الزمن.
 */
final class NotificationSmsSender implements SmsSender
{
    public function __construct(
        private readonly SmsOutbox $outbox,
    ) {}

    public function send(string $phone, string $body): void
    {
        $this->outbox->queue(
            to: $phone,
            body: $body,
            purpose: 'notification',
            expiresAt: now()->addHours((int) config('notifications.sms.ttl_hours', 24)),
        );
    }
}
