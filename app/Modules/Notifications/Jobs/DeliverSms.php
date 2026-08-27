<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * تسليم الإشعار برسالة نصية.
 *
 * نفس بنية DeliverPush: المعرّف لا النموذج (قراءة طازجة تتجنّب عنواناً
 * عفا عليه الزمن)، وafterCommit لأن الكاتب يعمل داخل ترانزاكشن أحياناً،
 * والفشل لا يتصاعد — القناة الصادرة طرف خارجي، وغايتها تنبيهٌ فوق الصفّ
 * الموجود أصلاً.
 *
 * ═══ السائق قد يكون غير مربوط، وهذا ليس عطلاً ═══
 *
 * `?SmsSender $sender = null`: وحدة Sms قد تكون محذوفة كاملةً، أو
 * موجودة ومُطفأة (`SMS_ENABLED=false`) — وفي الحالتين لا رابط للواجهة
 * في الحاوية. فتخرج المهمّة بهدوء بدل أن تفشل ببحثٍ عن تنفيذ لا يوجد.
 * نفس الاعتماد الذي يقوم عليه `PhoneVerificationService` مع
 * `PhoneVerificationSender`.
 */
class DeliverSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** حدّ عملي للرسالة الواحدة — نصّان أطول من هذا مكلفان بلا داعٍ */
    private const BODY_LIMIT = 300;

    public function __construct(
        private readonly int $notificationId,
        private readonly string $phone,
    ) {
        $this->afterCommit();
    }

    public function handle(?SmsSender $sender = null): void
    {
        if ($sender === null) {
            return;
        }

        $notification = AppNotification::find($this->notificationId);

        if ($notification === null) {
            return;
        }

        try {
            $sender->send($this->phone, $this->body($notification));
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $notification->markDelivered(Channel::Sms);
    }

    private function body(AppNotification $notification): string
    {
        $text = $notification->body === null
            ? $notification->title
            : $notification->title.' — '.$notification->body;

        return Str::limit($text, self::BODY_LIMIT, '…');
    }
}
