<?php

namespace App\Modules\Sms\Http\Controllers;

use App\Modules\Sms\Http\Requests\ConfirmDeliveryRequest;
use App\Modules\Sms\Models\SmsMessage;
use App\Modules\Sms\Services\SmsOutbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * الوجه الذي يراه هاتف تيرمكس — ثلاث نقاط لا غير.
 *
 * ═══ العقد مقصود البساطة ═══
 *
 * المستهلك سكربت `bash` بـ`curl` و`jq`، لا مكتبة عميل. فكل ما يزيد على
 * ثلاثة حقول في الاستجابة سطرُ `jq` إضافي يُكتب على هاتف بلا محرّر
 * حقيقي — وأول خطأ فيه يوقف التسليم كله بلا أن يظهر في أي سجلّ خادم.
 *
 * ═══ اسحب ثم أكّد، ولا شيء بينهما ═══
 *
 *   POST /claim    →  { claim_token, messages: [{id, to, body}] }
 *   POST /confirm  →  { claim_token, results: [{id, status, error?}] }
 *   GET  /health   →  حالة الطابور
 *
 * لا نقطة «احذف» ولا «أعد المحاولة»: الأولى تمحو سجلّ التسليم، والثانية
 * قرار خادم لا قرار هاتف — `SmsOutbox::markFailed` هو من يقرّر أتعود
 * الرسالة إلى الطابور أم استُنفدت محاولاتها.
 */
class SmsWorkerController
{
    public function __construct(
        private readonly SmsOutbox $outbox,
    ) {}

    /**
     * سحب دفعة.
     *
     * ═══ POST لا GET رغم أنه «قراءة» ═══
     *
     * السحب يكتب: يغيّر الحالة ويزيد العدّاد ويحجز الصفوف. وGET يَعِد
     * بألّا يفعل ذلك — فأي إعادة محاولة تلقائية من `curl`، أو وسيطُ
     * تخزينٍ على الشبكة، يسحب دفعةً ثانية بلا أن يطلبها أحد.
     */
    public function claim(Request $request): JsonResponse
    {
        $worker = $this->workerName($request);

        $result = $this->outbox->claim($worker, $request->integer('limit') ?: null);

        return response()->json([
            'claim_token' => $result['claim_token'],
            'claim_expires_in' => (int) config('sms.worker.claim_ttl_seconds', 120),
            'messages' => $result['messages']
                ->map(fn (SmsMessage $message): array => $message->toWorkerArray())
                ->values(),
        ]);
    }

    /**
     * تأكيد ما أُرسل وما فشل.
     *
     * `ignored` ليس حشواً: تأكيدٌ لا يطابق سحبةً قائمة يعني أن السحبة
     * ماتت واستُعيدت رسالتها — وهو ما يُقرأ في سجلّ الهاتف كإشارة إلى
     * أن `claim_ttl_seconds` أقصر من زمن الإرسال الفعلي. رقمٌ يكبر هنا
     * هو التشخيص الوحيد لتلك الحالة.
     */
    public function confirm(ConfirmDeliveryRequest $request): JsonResponse
    {
        $token = (string) $request->validated('claim_token');

        $sent = 0;
        $failed = 0;
        $ignored = 0;

        foreach ($request->validated('results') as $row) {
            $id = (int) $row['id'];

            $ok = $row['status'] === 'sent'
                ? $this->outbox->markSent($id, $token)
                : $this->outbox->markFailed($id, $token, $row['error'] ?? null);

            if (! $ok) {
                $ignored++;

                continue;
            }

            $row['status'] === 'sent' ? $sent++ : $failed++;
        }

        return response()->json([
            'sent' => $sent,
            'failed' => $failed,
            'ignored' => $ignored,
        ]);
    }

    /**
     * حالة الطابور.
     *
     * بلا رموز ولا أرقام: الصحة عددٌ وعمر، والنصّ يخصّ من سيستقبله.
     */
    public function health(): JsonResponse
    {
        return response()->json(['data' => $this->outbox->stats()]);
    }

    /**
     * اسم العامل من الطلب — للتشخيص لا للتوثيق.
     *
     * التوثيق يقع في `AuthenticateSmsWorker` بالسرّ الثابت. وهذا الاسم
     * يُكتب في `claimed_by` ليُعرف أيّ هاتف سحب ماذا حين يكون هناك
     * هاتفان — ولأنه يأتي من الطلب فهو غير موثوق ولا يُبنى عليه قرار.
     */
    private function workerName(Request $request): string
    {
        $name = $request->input('worker');

        return is_string($name) && trim($name) !== ''
            ? Str::limit(trim($name), 60, '')
            : 'unknown';
    }
}
