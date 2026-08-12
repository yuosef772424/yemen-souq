<?php

namespace App\Modules\Notifications\DTO;

use App\Modules\Notifications\Models\AppNotification;

/**
 * الحمولة كما يراها مزوّد الدفع — لا نموذج Eloquent.
 *
 * لماذا DTO لا تمرير AppNotification نفسه: المرسِل حدٌّ خارجي، وتمرير
 * نموذج إليه يفتح له القاعدة كلها عبر العلاقات، ويجعل توقيعه يتغيّر مع
 * كل تعديل في المخطط. والحمولة هنا هي بالضبط ما يُسلَّم للمزوّد: عنوان
 * ونصّ وخريطة نصوص.
 *
 * data نصوص كلها بقصد: FCM لا يقبل في حقل data إلا سلاسل، والأرقام
 * تصل إلى التطبيق نصوصاً على أي حال. تحويلها هنا يجعل ما يراه المطوّر
 * في PHP هو ما يصل الجهاز — بدل أن يكتشف في التطبيق أن id صار "42".
 */
final class PushMessage
{
    /** @param array<string, string> $data */
    public function __construct(
        public readonly int $notificationId,
        public readonly string $title,
        public readonly ?string $body,
        public readonly array $data = [],
    ) {}

    public static function fromNotification(AppNotification $notification): self
    {
        $data = ['notification_id' => (string) $notification->id];

        foreach ($notification->data ?? [] as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $data[(string) $key] = (string) $value;
            }
        }

        $data['type'] = $notification->type->value;

        return new self(
            notificationId: (int) $notification->id,
            title: $notification->title,
            body: $notification->body,
            data: $data,
        );
    }
}
