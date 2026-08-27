<?php

namespace App\Modules\Notifications\Contracts;

/**
 * عقد إرسال إشعار برسالة نصية.
 *
 * نفس نمط PushSender: الواجهة هنا في وحدة الإشعارات، والتنفيذ في وحدة Sms
 * القابلة للحذف. حاوية لارافل تحقن التنفيذ حين يُربط، وتقع إلى المعامل
 * الافتراضي `null` في DeliverSms حين لا يُربط — فحذف مجلد Sms يُسكت هذه
 * القناة وحدها بلا أن يكسر شيئاً هنا.
 */
interface SmsSender
{
    public function send(string $phone, string $body): void;
}
