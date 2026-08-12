<?php

namespace App\Modules\Api\Http\Controllers;

use App\Models\User;
use App\Modules\Api\Http\Requests\PhoneRequest;
use App\Modules\Api\Http\Requests\ResetPasswordByPhoneRequest;
use App\Modules\Api\Services\TokenIssuer;
use App\Modules\Identity\Enums\VerificationResult;
use App\Modules\Identity\Services\PhoneVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * استعادة كلمة المرور بالجوال — خطوتان بلا جلسة.
 *
 * ═══ الاستجابة واحدة للمسجَّل وغيره ═══
 *
 * أخطر ما في أي مسار استعادة أن يقول «هذا الرقم غير مسجَّل»: من يجرّب
 * ألف رقم يحصل على قائمة مستخدمي المنصة — وهي في سوق إعلانات قائمةُ من
 * يملك ما يُباع. فلا رمزَ يُصدَر لرقم غير مسجَّل، والرسالة والحالة
 * متطابقتان في الحالين.
 *
 * ولهذا حدّ المعدّل هنا **فوق** حدّ الخدمة: الخدمة تحدّ الرموز الصادرة،
 * والرقم غير المسجَّل لا يُصدر له رمز فلا يمسّ حدّها إطلاقاً — وهو
 * بالضبط ما يجرّبه من يعدّ المستخدمين.
 *
 * ═══ والنجاح يُصدر رمز وصول ═══
 *
 * الويب يعيد إلى شاشة الدخول لأن فحص الحساب الموقوف يعيش في
 * `LoginController` وحده. وهنا الفحص مكتوب صراحةً قبل الإصدار، فلا حاجة
 * إلى جولةٍ ثانية: من أثبت ملكية الرقم وغيّر كلمته لا يُطلب منه إدخالها
 * فوراً على شاشة تالية.
 *
 * ═══ وكل الرموز القديمة تُبطَل ═══
 *
 * من استعاد كلمته الغالب أنه فقد جهازه أو خشي اختراقاً. كلمةٌ جديدة مع
 * رمزٍ قديم يعمل ليست استعادة — نفس منطق تدوير `remember_token` في
 * الويب، مطبَّقاً على الرموز.
 */
class PasswordResetController
{
    /** طلبات في الساعة لكل رقم — يشمل غير المسجَّل */
    private const RATE_LIMIT = 5;

    public function __construct(
        private readonly PhoneVerificationService $verification,
        private readonly TokenIssuer $tokens,
    ) {}

    public function send(PhoneRequest $request): JsonResponse
    {
        $phone = (string) $request->validated('phone');

        $this->guardRate($phone);

        $user = User::query()->where('phone', $phone)->first();

        $code = null;

        if ($user !== null) {
            try {
                $code = $this->verification->issue($phone, $request->ip());
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['phone' => $e->getMessage()]);
            }
        }

        return response()->json([
            'message' => 'إن كان الرقم مسجَّلاً فقد أُرسل إليه رمز من ستة أرقام.',
            // فارغ دائماً في الإنتاج، وفارغ للرقم غير المسجَّل في التطوير
            'verification_code' => $code,
        ]);
    }

    public function reset(ResetPasswordByPhoneRequest $request): JsonResponse
    {
        $phone = (string) $request->validated('phone');

        $result = $this->verification->verify($phone, (string) $request->validated('code'));

        if ($result !== VerificationResult::Verified) {
            return response()->json([
                'message' => $result->message(),
                'code' => $result->value,
            ], 422);
        }

        $user = User::query()->where('phone', $phone)->first();

        // الرمز صحيح والحساب اختفى بينهما — حذف في نفس الدقيقة. لا رسالة
        // خاصة به: أي تمييز هنا يعيد فتح باب كشف الحسابات من الخلف
        if ($user === null) {
            return response()->json(['message' => 'غُيّرت كلمة المرور.']);
        }

        $user->forceFill([
            'password' => (string) $request->validated('password'),
            'remember_token' => Str::random(60),
            // الدليل هو الدليل نفسه: استلام رمز على الرقم. وطلب إثباته
            // مرة ثانية بعد ثوانٍ احتكاكٌ بلا مقابل أمني
            'phone_verified_at' => $user->phone_verified_at ?? now(),
        ])->save();

        $this->tokens->revokeAll($user);

        RateLimiter::clear($this->rateKey($phone));

        if (! $user->isActive()) {
            return response()->json([
                'message' => 'غُيّرت كلمة المرور، والحساب موقوف حالياً.',
                'code' => 'account_inactive',
            ], 403);
        }

        $issued = $this->tokens->issue($user);

        return response()->json([
            'message' => 'غُيّرت كلمة المرور.',
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['expires_at'],
        ]);
    }

    private function guardRate(string $phone): void
    {
        $key = $this->rateKey($phone);

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            throw ValidationException::withMessages([
                'phone' => 'طلبات كثيرة. انتظر قليلاً ثم أعد المحاولة.',
            ]);
        }

        RateLimiter::hit($key, 3600);
    }

    /**
     * نفس مفتاح الويب — `PasswordResetController::rateKey`.
     *
     * مقصود: الميزانية للرقم لا للقناة، تماماً كـ`LoginThrottle`. ولم
     * يُنقَل إلى صنف مشترك لأنه سطرٌ واحد بلا سياسة حوله؛ لو صار له
     * فرعٌ ثانٍ فمكانه صنف بجانب `LoginThrottle`.
     */
    private function rateKey(string $phone): string
    {
        return 'password-reset:'.$phone;
    }
}
