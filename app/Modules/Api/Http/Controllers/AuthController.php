<?php

namespace App\Modules\Api\Http\Controllers;

use App\Models\User;
use App\Modules\Api\Http\Requests\LoginRequest;
use App\Modules\Api\Http\Resources\UserResource;
use App\Modules\Api\Services\TokenIssuer;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Models\UserProfile;
use App\Modules\Identity\Services\PhoneVerificationService;
use App\Modules\Identity\Support\LoginThrottle;
use App\Modules\Identity\Support\YemeniPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * التسجيل والدخول والخروج — بالرمز لا بالجلسة.
 *
 * ═══ لماذا لا يُعاد استعمال LoginController ═══
 *
 * ما يفعله ذلك المتحكّم بعد نجاح البيانات كلّه خاصٌّ بالمتصفح:
 * `Auth::attempt` يكتب في الجلسة، و`session()->regenerate()` يمنع تثبيت
 * الجلسة، والعودة تحويلٌ إلى صفحة. ونقطة نهاية بلا حالة لا تحتاج شيئاً
 * من ذلك — تحتاج رمزاً.
 *
 * وما **يُعاد استعماله** هو ما يحمل قراراً: `RegisterRequest` بقواعده،
 * و`PhoneVerificationService` بسياسته، و`LoginThrottle` بميزانيته،
 * و`YemeniPhone` بتطبيعه. القاعدة: يُنسخ النقل، ولا يُنسخ القرار.
 *
 * ═══ الميزانية مشتركة مع الويب ═══
 *
 * `LoginThrottle` مفتاحه الهوية لا القناة. لو حمل هذا المتحكّم مفتاحاً
 * خاصاً به لضاعف المهاجمُ محاولاته بالتنقّل بين المتصفح والتطبيق —
 * وهي الحالة التي لا يكشفها أي اختبار لأن كلّ قناة تبدو محدودة وحدها.
 *
 * ═══ والحساب الموقوف يُرفَض برسالة صريحة ═══
 *
 * نفس قرار الويب: «البيانات غير صحيحة» هنا كذبٌ يدفع صاحب الحساب إلى
 * إعادة تعيين كلمة مروره مرة بعد مرة بلا فائدة.
 */
class AuthController
{
    public function __construct(
        private readonly TokenIssuer $tokens,
        private readonly PhoneVerificationService $verification,
    ) {}

    /**
     * حساب جديد + رمز وصول + رمز توثيق للجوال.
     *
     * الرمز يُصدَر فوراً ولا يُنتظر التوثيق: بوابة الهاتف تخصّ **النشر**
     * وحده — انظر `EnsurePhoneIsVerified`. ومنعُ التطبيق من العمل حتى
     * يوثّق صاحبه يحرم المنصة من المشتري، وهو الطرف الذي تحتاجه أكثر.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        // الحساب والملف الشخصي في معاملة — بلاها يبقى مستخدم بلا صفّ
        // عرض، فتنكسر كل قراءة لـdisplayName بلا خطأ واضح
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            UserProfile::create([
                'user_id' => $user->id,
                'display_name' => $data['name'],
            ]);

            return $user;
        });

        // فشل الإرسال لا يُفشل التسجيل: الحساب أُنشئ، والرمز يُطلب ثانيةً
        // من /auth/phone/resend. والعكس يعني حساباً ضائعاً لأن مزوّد
        // الرسائل تعثّر لحظتها
        $code = null;

        try {
            $code = $this->verification->issue($user->phone, $this->ipPrefix($request->ip()));
        } catch (RuntimeException) {
            $code = null;
        }

        return $this->tokenResponse($user, $request->input('device_name'), 201, [
            'verification_code' => $code,
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $identifier = (string) $request->input('identifier');

        if (LoginThrottle::tooManyAttempts($identifier)) {
            throw ValidationException::withMessages([
                'identifier' => 'محاولات كثيرة. انتظر دقيقة ثم أعد المحاولة.',
            ]);
        }

        $user = $this->findByIdentifier($identifier);

        /**
         * التجزئة تُفحص حتى حين لا يوجد مستخدم.
         *
         * `Hash::check` على تجزئة المستخدم إن وُجد، وعلى تجزئة وهمية إن
         * لم يوجد. بلا ذلك يعود الرقم غير المسجَّل **أسرع** من المسجَّل
         * بكلمة خاطئة، والفرق يُقاس — فيصير المسار عدّاداً لمن يملك
         * حساباً في المنصة. نفس هاجس `PasswordResetController`.
         */
        $hash = $user?->password ?? '$2y$12$'.str_repeat('0', 53);

        if ($user === null || ! Hash::check((string) $request->input('password'), $hash)) {
            LoginThrottle::hit($identifier);

            throw ValidationException::withMessages([
                'identifier' => 'البيانات غير صحيحة.',
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'identifier' => $user->status === 'banned'
                    ? 'هذا الحساب محظور.'
                    : 'هذا الحساب موقوف مؤقتاً.',
            ]);
        }

        LoginThrottle::clear($identifier);

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->tokenResponse($user, $request->input('device_name'));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }

    /** خروج من هذا الجهاز — الرمز الحالي وحده يُحذف */
    public function logout(Request $request): JsonResponse
    {
        $this->tokens->revokeCurrent($request->user());

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    /**
     * خروج من كل الأجهزة.
     *
     * نقطة نهاية مستقلة لأنها فعلٌ مختلف: «فقدتُ جهازي» لا «انتهيت
     * الآن». ودمجهما في مسار واحد بعَلَم يجعل الخطأ في العَلَم يُخرج
     * المستخدم من كل شيء بلا قصد.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $count = $this->tokens->revokeAll($request->user());

        return response()->json([
            'message' => 'تم تسجيل الخروج من كل الأجهزة.',
            'revoked' => $count,
        ]);
    }

    // ─── الداخل ───

    /**
     * الهاتف أو البريد من حقل واحد.
     *
     * التطبيع يقرّر: ما يفهمه `YemeniPhone` رقمٌ، وما عداه بريد. وهو ما
     * يجعل `0771234567` و`+967771234567` يصلان إلى نفس الحساب — القيد
     * الفريد على العمود المطبَّع، والبحث يجب أن يطابقه.
     */
    private function findByIdentifier(string $identifier): ?User
    {
        $phone = YemeniPhone::normalize($identifier);

        return $phone !== null
            ? User::query()->where('phone', $phone)->first()
            : User::query()->where('email', $identifier)->first();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function tokenResponse(User $user, mixed $deviceName, int $status = 200, array $extra = []): JsonResponse
    {
        // القصّ هنا لا في القاعدة: RegisterRequest لا يعرف الحقل، فيصل
        // خاماً من الطلب إلى عمود name في personal_access_tokens
        $name = is_string($deviceName) && trim($deviceName) !== ''
            ? mb_substr(trim($deviceName), 0, 60)
            : null;

        $issued = $this->tokens->issue($user, $name);

        return response()->json([
            'data' => new UserResource($user->fresh()),
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['expires_at'],
            ...$extra,
        ], $status);
    }

    /**
     * مقطع من العنوان لا العنوان — نفس قرار RegisterController في الويب:
     * الوعد بأن لا IP يُخزَّن يخصّ سجلّ السلوك، وهذا حدّ إساءة على جدول
     * رموز.
     */
    private function ipPrefix(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $parts = explode('.', $ip);

        return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.0.0" : null;
    }
}
