<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Resources\UserResource;
use App\Modules\Identity\Enums\VerificationResult;
use App\Modules\Identity\Services\PhoneVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * توثيق رقم صاحب الحساب.
 *
 * ═══ الرقم من الحساب لا من الطلب ═══
 *
 * المسارات كلها خلف `auth:sanctum`، والرقم يُقرأ من `$request->user()`.
 * وحقلٌ للرقم في الجسد يعني أن حاملَ رمزٍ لحسابٍ يستطيع طلب رمز توثيق
 * لرقم غيره — إزعاجٌ مجاني على حساب المنصة، وطريقٌ لتوثيق رقم لا يملكه.
 *
 * ═══ ولماذا لا يُعاد استعمال متحكّم الويب ═══
 *
 * ذاك يُرجع `View` و`RedirectResponse` ويقرأ `intended` من الجلسة.
 * والسياسة كلها — طول الرمز، الصلاحية، سقف المحاولات، حدّ الإرسال —
 * في `PhoneVerificationService`، وهي المُعاد استعمالها هنا. الفرق نقلٌ
 * لا قرار.
 */
class PhoneVerificationController
{
    public function __construct(
        private readonly PhoneVerificationService $verification,
    ) {}

    /** حالة التوثيق وكم بقي على الرمز الحالي */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'verified' => $user->hasVerifiedPhone(),
            'seconds_remaining' => $user->phone === null
                ? 0
                : $this->verification->secondsRemaining($user->phone),
        ]);
    }

    /**
     * إرسال رمز جديد.
     *
     * `verification_code` يعود **فقط** مع سائق العرض (التطوير) — انظر
     * `PhoneVerificationService::issue`: المُرسل هو من يقرّر أيُعرَض
     * الرمز. في الإنتاج يوصّله المزوّد ويعود الحقل فارغاً، فلا حاجة إلى
     * شرطٍ على البيئة هنا.
     */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedPhone()) {
            return response()->json([
                'message' => 'رقمك موثَّق بالفعل.',
                'verified' => true,
            ]);
        }

        if ($user->phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'لا يوجد رقم جوال على هذا الحساب.',
            ]);
        }

        try {
            $code = $this->verification->issue($user->phone, $this->ipPrefix($request->ip()));
        } catch (RuntimeException $e) {
            // حدّ الإرسال — 422 لا 500: الرسالة موجَّهة للمستخدم ويقرأها
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'أُرسل رمز جديد.',
            'verification_code' => $code,
            'seconds_remaining' => $this->verification->secondsRemaining($user->phone),
        ]);
    }

    /**
     * التحقق من الرمز.
     *
     * النتائج الأربع تُميَّز بـ`code` لا بالرسالة: التطبيق يعرض زرّ
     * «أعد الإرسال» للمنتهي والمقفل، وحقلاً فارغاً للخاطئ — وثلاثة
     * سلوكات لا يجوز أن تُشتقّ من مطابقة نصّ عربي.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']], [], ['code' => 'الرمز']);

        $user = $request->user();

        if ($user->hasVerifiedPhone()) {
            return response()->json([
                'message' => 'رقمك موثَّق بالفعل.',
                'data' => new UserResource($user),
            ]);
        }

        $result = $this->verification->verify($user->phone, (string) $request->input('code'));

        if ($result !== VerificationResult::Verified) {
            return response()->json([
                'message' => $result->message(),
                'code' => $result->value,
            ], 422);
        }

        $user->markPhoneAsVerified();

        return response()->json([
            'message' => $result->message(),
            'data' => new UserResource($user->fresh()),
        ]);
    }

    private function ipPrefix(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $parts = explode('.', $ip);

        return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.0.0" : null;
    }
}
