<?php

namespace App\Modules\Sms\Services;

use App\Modules\Sms\Enums\SmsStatus;
use App\Modules\Sms\Models\SmsMessage;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * طابور الرسائل الصادرة — المدخل الوحيد للكتابة فيه.
 *
 * نفس حجّة `Notifier` و`AuditLogger`: القواعد التي تُطبَّق هنا — الأجل،
 * والسحب الذرّي، وعدّ المحاولات، ومحو النصّ بعد التسليم — لو وُزّعت على
 * كل موضعٍ يُنشئ رسالة أو يؤكّدها لسقطت واحدة منها في أول موضع جديد.
 * وسقوطها لا يُنتج خطأً بل سلوكاً خاطئاً: رسالة تُرسَل مرتين، أو رمزٌ
 * صريح يبقى في الجدول إلى الأبد.
 *
 * ═══ السحب ذرّي، وهذا أهم ما في الملف ═══
 *
 * الطريقة الساذجة: `SELECT … WHERE status = 'queued'` ثم إرسال. وهي
 * تعمل حتى يوجد قارئان — هاتفان، أو **نفس الهاتف بعد أن انقطعت شبكته
 * وأعاد النداء**. حينها يقرأ الاثنان نفس الصف ويُرسل الرمز مرتين:
 * الرسالة بكلفة حقيقية، وصاحب الرقم يتلقى رمزين لا يعرف أيّهما يكتب.
 *
 * والحلّ ليس قفلاً على الجدول بل **تحديثاً مشروطاً**:
 *
 *   1. اقرأ معرّفات المرشّحين
 *   2. حدّثها بشرط `status = queued` وبرمز سحبةٍ فريد
 *   3. أعِد ما يحمل رمزك أنت
 *
 * القارئ الثاني يجد الصفوف `claimed` فلا يحدّثها، فلا يحمل رمزه شيئاً،
 * فيعود بلا رسائل. لا قفل ولا `SELECT … FOR UPDATE` — ويعمل على MySQL
 * وSQLite بلا فرق.
 *
 * ═══ ولماذا at-least-once لا at-most-once ═══
 *
 * الهاتف قد يُرسل ثم يموت قبل التأكيد، فتُستعاد الرسالة وتُرسَل ثانيةً.
 * الاختيار مقصود: رمزٌ يصل مرتين إزعاج، ورمزٌ لا يصل **يقفل الحساب**.
 * وتكرار التسليم غير ضارّ هنا تحديداً لأن `PhoneVerificationService`
 * يُبطل الرمز السابق عند إصدار جديد — فالرسالتان تحملان الرمز نفسه.
 */
final class SmsOutbox
{
    /** حدّ عمود error في المخطط — القصّ هنا لا في قاعدة البيانات */
    private const ERROR_LIMIT = 250;

    /**
     * إضافة رسالة إلى الطابور.
     *
     * `expires_at` مطلوبٌ عملياً وإن كان اختيارياً في التوقيع: رسالةٌ بلا
     * أجل تبقى قابلة للسحب إلى الأبد، فتخرج بعد يومين بمحتوى لا معنى له.
     * القيمة الافتراضية تأتي من `config('sms.default_ttl_minutes')`،
     * ومُرسل رموز التحقق يمرّر عمر الرمز نفسه.
     */
    public function queue(
        string $to,
        string $body,
        string $purpose = 'general',
        ?CarbonInterface $expiresAt = null,
    ): SmsMessage {
        $message = new SmsMessage([
            'to' => $to,
            'body' => $body,
            'purpose' => $purpose,
            'expires_at' => $expiresAt ?? now()->addMinutes(
                (int) config('sms.default_ttl_minutes', 10)
            ),
        ]);

        // خارج fillable بقصد — انظر الموديل
        $message->forceFill([
            'status' => SmsStatus::Queued,
            'attempts' => 0,
        ])->save();

        return $message;
    }

    /**
     * سحب دفعة للهاتف.
     *
     * `reap()` أولاً لا بعده: السحبات الميتة يجب أن تعود إلى الطابور
     * **قبل** أن نقرأ المرشّحين، وإلا انتظرت رسالةٌ عالقة تشغيلَ مجدولٍ
     * قد لا يعمل أصلاً. نفس مبدأ `TokenIssuer::pruneExpired` — التنظيف
     * عند الاستعمال لا يعتمد على مجدول.
     *
     * @return array{claim_token: ?string, messages: Collection<int, SmsMessage>}
     */
    public function claim(string $worker, ?int $limit = null): array
    {
        $this->reap();

        $limit = max(1, min($limit ?? (int) config('sms.worker.claim_batch', 5), 25));
        $token = (string) Str::uuid();
        $ttl = (int) config('sms.worker.claim_ttl_seconds', 120);

        $claimed = DB::transaction(function () use ($worker, $limit, $token, $ttl): Collection {
            $ids = SmsMessage::query()
                ->claimable()
                // الأقدم أولاً: رمز التحقق يتقادم، وترتيبٌ غيره يجعل
                // من طلب أولاً ينتظر أطول
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return collect();
            }

            /**
             * الشرط `status = queued` هنا هو الحارس كله.
             *
             * قارئٌ آخر سبقنا إلى هذه الصفوف تركها `claimed`، فلا يطابقها
             * التحديث — ولا يحمل رمزُنا شيئاً. حذفُ هذا الشرط يحوّل
             * السحب إلى قراءة، ويعيد الإرسال المزدوج.
             */
            SmsMessage::query()
                ->whereIn('id', $ids)
                ->where('status', SmsStatus::Queued)
                ->update([
                    'status' => SmsStatus::Claimed,
                    'claim_token' => $token,
                    'claimed_by' => Str::limit($worker, 60, ''),
                    'claimed_at' => now(),
                    'claim_expires_at' => now()->addSeconds($ttl),
                    // العدّاد على السحب لا على الفشل — انظر الهجرة
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);

            return SmsMessage::query()->where('claim_token', $token)->orderBy('id')->get();
        });

        return [
            'claim_token' => $claimed->isEmpty() ? null : $token,
            'messages' => $claimed,
        ];
    }

    /**
     * تأكيد الإرسال.
     *
     * الشرط على `claim_token` لا على المعرّف وحده: هاتفٌ ماتت سحبته
     * واستُعيدت رسالته يستطيع — بلا هذا الشرط — أن يؤكّد إرسالها بعد أن
     * سحبها هاتفٌ آخر. فيُكتب `sent` على سحبةٍ ما زالت جارية، وتُحسب
     * رسالةٌ مرسَلةً مرتين مرةً واحدة.
     *
     * @return bool هل طابق التأكيد سحبةً قائمة
     */
    public function markSent(int $id, string $claimToken): bool
    {
        $values = [
            'status' => SmsStatus::Sent,
            'sent_at' => now(),
            'claim_token' => null,
            'claim_expires_at' => null,
            'error' => null,
            'updated_at' => now(),
        ];

        // النصّ يُمحى فور وصوله: رمزٌ صريح باقٍ في جدول يجعل قراءة
        // الجدول كافيةً لتوثيق أي رقم — وهو ما تمنعه code_hash أصلاً
        if ((bool) config('sms.redact_body_on_send', true)) {
            $values['body'] = null;
        }

        return $this->confirm($id, $claimToken, $values);
    }

    /**
     * تأكيد الفشل.
     *
     * تعود إلى الطابور ما لم تستنفد محاولاتها. والسبب يُحفظ: «لا خدمة»
     * و«الرقم محظور» و«بطاقة SIM غير موجودة» ثلاثة أعطال بثلاثة حلول،
     * وبلا نصّ الخطأ يبدو الثلاثة عطلاً واحداً غامضاً.
     */
    public function markFailed(int $id, string $claimToken, ?string $error = null): bool
    {
        $message = SmsMessage::query()
            ->where('id', $id)
            ->where('claim_token', $claimToken)
            ->first();

        if ($message === null) {
            return false;
        }

        $exhausted = $message->attempts >= (int) config('sms.max_attempts', 3);

        return $this->confirm($id, $claimToken, [
            'status' => $exhausted ? SmsStatus::Failed : SmsStatus::Queued,
            'failed_at' => $exhausted ? now() : null,
            'error' => $error === null ? null : Str::limit($error, self::ERROR_LIMIT, ''),
            'claim_token' => null,
            'claimed_at' => null,
            'claim_expires_at' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * استعادة السحبات الميتة، ووسم ما فات أجله.
     *
     * ═══ الحالة التي وُجدت لأجلها ═══
     *
     * أندرويد يقتل عمليات الخلفية بلا إنذار — وهي الحالة **الغالبة** لا
     * الاستثناء على هاتف يعمل بلا `termux-wake-lock`. وبلا استعادة تبقى
     * الرسالة `claimed` إلى الأبد: لا تُرسَل، ولا تظهر في الطابور،
     * ولا يشتكي أحد إلا صاحب الرقم الذي لم يصله شيء.
     *
     * والمستنفَدة تُوسَم `failed` لا تعود: هاتفٌ يموت عند نفس الرسالة
     * ثلاث مرات لن ينجح في الرابعة، ودورانها يحجب الطابور عن غيرها.
     *
     * @return array{reclaimed: int, exhausted: int, expired: int}
     */
    public function reap(): array
    {
        $max = (int) config('sms.max_attempts', 3);

        // المستنفَدة أولاً: بعد الاستعادة تصير `queued` فلا يميّزها شيء
        $exhausted = SmsMessage::query()
            ->staleClaim()
            ->where('attempts', '>=', $max)
            ->update([
                'status' => SmsStatus::Failed,
                'failed_at' => now(),
                'error' => 'استُنفدت المحاولات — لم يؤكّد الهاتف الإرسال.',
                'claim_token' => null,
                'claim_expires_at' => null,
                'updated_at' => now(),
            ]);

        $reclaimed = SmsMessage::query()
            ->staleClaim()
            ->update([
                'status' => SmsStatus::Queued,
                'claim_token' => null,
                'claimed_by' => null,
                'claimed_at' => null,
                'claim_expires_at' => null,
                'updated_at' => now(),
            ]);

        // بعد الاستعادة لا قبلها: سحبةٌ ماتت ورسالتها فات أجلها يجب أن
        // تُوسَم `expired` لا أن تعود إلى طابور لن تخرج منه
        $expired = SmsMessage::query()
            ->stale()
            ->update([
                'status' => SmsStatus::Expired,
                'claim_token' => null,
                'claim_expires_at' => null,
                'updated_at' => now(),
            ]);

        return [
            'reclaimed' => $reclaimed,
            'exhausted' => $exhausted,
            'expired' => $expired,
        ];
    }

    /**
     * حالة الطابور — لمراقبة العامل لا للعرض.
     *
     * `oldest_queued_seconds` هو الرقم الذي يكشف العطل الحقيقي: طابورٌ
     * فيه ثلاث رسائل أمرٌ عادي، وثلاثٌ أقدمها عمرها ساعة يعني أن الهاتف
     * لم ينادِ منذ ساعة — وهو ما لا يقوله العدد وحده.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $counts = SmsMessage::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $oldest = SmsMessage::query()
            ->where('status', SmsStatus::Queued)
            ->min('created_at');

        return [
            'queued' => (int) ($counts[SmsStatus::Queued->value] ?? 0),
            'claimed' => (int) ($counts[SmsStatus::Claimed->value] ?? 0),
            'sent' => (int) ($counts[SmsStatus::Sent->value] ?? 0),
            'failed' => (int) ($counts[SmsStatus::Failed->value] ?? 0),
            'expired' => (int) ($counts[SmsStatus::Expired->value] ?? 0),
            'oldest_queued_seconds' => $oldest === null
                ? null
                : (int) Carbon::parse($oldest)->diffInSeconds(now()),
            'last_claim_at' => SmsMessage::query()->max('claimed_at'),
        ];
    }

    /** حذف ما انتهى دوره — يُنادى من `sms:prune` */
    public function prune(?int $days = null): int
    {
        $days = $days ?? (int) config('sms.retention_days', 7);

        return SmsMessage::query()
            ->whereIn('status', [SmsStatus::Sent, SmsStatus::Failed, SmsStatus::Expired])
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function confirm(int $id, string $claimToken, array $values): bool
    {
        return SmsMessage::query()
            ->where('id', $id)
            ->where('claim_token', $claimToken)
            ->update($values) === 1;
    }
}
