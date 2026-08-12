<?php

namespace App\Modules\Sms\Jobs;

use App\Modules\Sms\Contracts\WhatsAppTransport;
use App\Modules\Sms\Enums\DeliveryChannel;
use App\Modules\Sms\Services\SmsOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * دفع رسالة واتساب واحدة — «العامل» الذي لا هاتف له.
 *
 * ═══ يمرّ بنفس السحب الذرّي ═══
 *
 * البديل المرفوض: قراءة الصفّ وإرساله مباشرةً. مرفوض لأن الطابور يعيد
 * المهام: مهمّةٌ انتهت مهلتها وأُعيدت، أو مهمّتان دُفعتا لنفس المعرّف،
 * تعنيان رسالتين. و`claimOne` يحمل نفس الشرط الذي يحمي سحب الهاتف —
 * فالمهمّة الثانية تجد الصفّ `claimed` وتنصرف.
 *
 * ولهذا لا يُمرَّر النموذج بل معرّفه: `SerializesModels` يعيد جلبه، لكنه
 * يعيد جلب **الحالة القديمة المخزَّنة في الحمولة** إن حُذف الصفّ — والسحب
 * يجب أن يقرأ الحالة الآن.
 *
 * ═══ والفشل يُبلَّغ للطابور لا يُرمى ═══
 *
 * `markFailed` هو من يقرّر: تعود إلى الطابور أم استُنفدت المحاولات أم
 * تتراجع إلى قناة أخرى. ورميُ الاستثناء من هنا يجعل الطابور يعيد المهمّة
 * بمنطقه هو — فيصير للإعادة مصدران يتعاركان، وتُحرق المحاولات مرتين.
 */
class DeliverWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** مرة واحدة: الإعادة قرار الطابور الداخلي لا قرار لارافل */
    public int $tries = 1;

    public function __construct(
        private readonly int $messageId,
    ) {}

    public function handle(SmsOutbox $outbox, WhatsAppTransport $transport): void
    {
        $message = $outbox->claimOne($this->messageId, DeliveryChannel::WhatsApp->serverWorker());

        // سحبها غيرُنا، أو فات أجلها، أو لم تعد في الطابور — والثلاثة
        // نتائج صحيحة لا أعطال
        if ($message === null || $message->channel !== DeliveryChannel::WhatsApp) {
            return;
        }

        $token = (string) $message->claim_token;

        try {
            $transport->send(
                to: $message->to,
                text: (string) $message->body,
                code: $this->codeFrom((string) $message->body),
            );
        } catch (Throwable $exception) {
            $outbox->markFailed($message->id, $token, $exception->getMessage());

            return;
        }

        $outbox->markSent($message->id, $token);
    }

    /**
     * انتزاع الرمز من النصّ — لقوالب واتساب المعتمَدة.
     *
     * القالب عند Meta يأخذ الرمز معامِلاً لا نصّاً كاملاً. والنصّ عندنا
     * مبنيٌّ من `sms.templates.phone_verification` الذي يضع `:code` بين
     * كلمات عربية — فأول تسلسل أرقام فيه هو الرمز.
     *
     * ويعود `null` حين لا يوجد: رسالةٌ عامة بلا رمز تُرسَل نصّاً حرّاً
     * عبر الجسر، وتُرفض عند Meta — وهو الصحيح، فالقالب للمصادقة وحدها.
     */
    private function codeFrom(string $body): ?string
    {
        return preg_match('/\d{4,8}/u', $body, $matches) === 1 ? $matches[0] : null;
    }
}
