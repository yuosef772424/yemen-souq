<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\DTO\PushMessage;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Models\AppNotification;
use App\Modules\Notifications\Services\DeviceRegistrar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * تسليم الإشعار إلى مزوّد الدفع.
 *
 * ═══ afterCommit ضروري لا تحسيني ═══
 *
 * ConversationService يكتب الرسالة والإشعار داخل ترانزاكشن. وعامل
 * الطابور عملية أخرى بوصلة أخرى — فلو التُقطت المهمة قبل الإيداع لبحثت
 * عن صفٍّ لا يراه، وفشلت بـModelNotFound على إشعار موجود. عطلٌ عشوائي
 * يعتمد على سباق توقيت، ولا يظهر إلا تحت الحمل.
 *
 * ═══ المعرّف لا النموذج ═══
 *
 * تمرير id لا AppNotification: الإشعار قد يُقرأ أو يُطوى بين الجدولة
 * والتنفيذ، والنموذج المسلسَل يحمل لقطةً قديمة — فيُدفع عنوانٌ عفا عليه
 * الزمن. القراءة الطازجة داخل handle هي ما يجعل الطيّ صحيحاً.
 *
 * ═══ الفشل لا يتصاعد ═══
 *
 * مزوّد الدفع طرف خارجي، وسقوطه لا يجوز أن يُفشل شيئاً: الإشعار موجود
 * في القائمة، وغاية الدفع تنبيهٌ فوقه. نفس مقاربة PersistEvents.
 */
class DeliverPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @param list<string> $tokens */
    public function __construct(
        private readonly int $notificationId,
        private readonly array $tokens,
    ) {
        // بالاستدعاء لا بخاصية ‎public $afterCommit = true;‎: سمة Queueable
        // تعلن الخاصية بلا قيمة ابتدائية، وإعادة إعلانها بقيمة تجعل تركيب
        // السمة «غير متوافق» عند PHP — خطأ قاتل وقت التحميل لا وقت التشغيل
        $this->afterCommit();
    }

    public function handle(PushSender $sender, DeviceRegistrar $devices): void
    {
        if ($this->tokens === []) {
            return;
        }

        $notification = AppNotification::find($this->notificationId);

        if ($notification === null) {
            return;
        }

        try {
            $dead = $sender->send($this->tokens, PushMessage::fromNotification($notification));
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        if ($dead !== []) {
            $devices->pruneTokens($dead);
        }

        // sent_at ذاكرة التهدئة في Notifier، وchannel أبعد قناة بلغها —
        // markDelivered يحمي الثاني من التراجع لو سبقته sms أو email
        $notification->markDelivered(Channel::Push);
    }
}
