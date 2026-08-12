<?php

namespace App\Modules\Sms\Senders;

use App\Modules\Identity\Contracts\PhoneVerificationSender;
use App\Modules\Sms\Services\SmsOutbox;

/**
 * الوصلة الوحيدة بين هذه الوحدة و`Identity` — وهي في اتجاه واحد.
 *
 * ═══ صفر تعديل في وحدة الهوية ═══
 *
 * `PhoneVerificationService` بانيها `?PhoneVerificationSender $sender = null`.
 * وحاوية لارافل تحقن الواجهة متى كانت مربوطة، وتقع إلى القيمة
 * الافتراضية `null` متى لم تكن — فيعود القرار إلى `config` كما كان.
 *
 * فربطُ الواجهة في `SmsServiceProvider` يستولي على القناة، **وحذف مجلد
 * الوحدة يعيد كل شيء كما كان بلا لمس حرف في `Identity`**. وهذا هو
 * الشرط الذي طُلبت الوحدة لأجله: قابلة للحذف كاملةً.
 *
 * والاتجاه سليم: `Sms` تعرف `Identity`، و`Identity` لا تعرفها.
 *
 * ═══ ولماذا لا تُرسل شيئاً ═══
 *
 * هذا المُرسل **يكتب في طابور**، والإرسال يقع على هاتف في مكان آخر.
 * والفارق يظهر في `displaysCode`: القناة لا تعرض الرمز، لكنها أيضاً لا
 * تضمن وصوله لحظتها. المستخدم يرى «أُرسل رمز» ثم ينتظر ثوانيَ حتى
 * ينادي الهاتف — وهو سلوك أي مزوّد رسائل حقيقي، لا خاصية لهذا الحلّ.
 */
final class OutboxSender implements PhoneVerificationSender
{
    public function __construct(
        private readonly SmsOutbox $outbox,
    ) {}

    public function send(string $phone, string $code): void
    {
        /**
         * أجل الرسالة = عمر الرمز نفسه.
         *
         * ولا يُقرأ من `config('sms.default_ttl_minutes')`: إعدادان
         * لأجلٍ واحد يتفرّقان عند أول تعديل — فيخرج من الطابور رمزٌ
         * انتهت صلاحيته قبل أن يصل، ويقرأ صاحبه الرفضَ عطلاً في المنصة
         * لا انتهاءً للصلاحية. مصدر الحقيقة هو من يملك الرمز.
         */
        $ttl = (int) config('identity.phone_verification.ttl_minutes', 10);

        /**
         * القناة تُترك لـ`queue` تقرّرها.
         *
         * `preferredChannelFor` يقرأ تاريخ هذا الرقم: إن كانت آخر
         * محاولاته على القناة الافتراضية كلها فاشلة، بدأ من البديلة
         * مباشرةً بدل أن يحرق ثلاث محاولات وينتظر التراجع في كل مرة.
         *
         * وتمرير قناةٍ صريحة هنا كان سيُلغي ذلك التعلّم — فيبقى الرقم
         * المحظور يُجرَّب على SMS إلى الأبد.
         */
        $this->outbox->queue(
            to: $phone,
            body: $this->body($code),
            purpose: 'phone_verification',
            expiresAt: now()->addMinutes($ttl),
        );
    }

    public function displaysCode(): bool
    {
        return false;
    }

    private function body(string $code): string
    {
        return str_replace(
            ':code',
            $code,
            (string) config('sms.templates.phone_verification', 'رمز التحقق: :code'),
        );
    }
}
